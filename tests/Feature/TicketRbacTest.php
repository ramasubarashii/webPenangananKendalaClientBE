<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\ProgressLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketRbacTest extends TestCase
{
    use RefreshDatabase;

    private $serviceDesk;
    private $pm;
    private $programmer;
    private $otherProgrammer;
    private $owner;
    private $clientUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serviceDesk = User::create([
            'name' => 'Service Desk',
            'email' => 'servicedesk@test.com',
            'password' => bcrypt('password'),
            'role' => 'service_desk'
        ]);

        $this->pm = User::create([
            'name' => 'Project Manager',
            'email' => 'pm@test.com',
            'password' => bcrypt('password'),
            'role' => 'project_manager'
        ]);

        $this->programmer = User::create([
            'name' => 'Programmer 1',
            'email' => 'prog1@test.com',
            'password' => bcrypt('password'),
            'role' => 'programmer'
        ]);

        $this->otherProgrammer = User::create([
            'name' => 'Programmer 2',
            'email' => 'prog2@test.com',
            'password' => bcrypt('password'),
            'role' => 'programmer'
        ]);

        $this->owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@test.com',
            'password' => bcrypt('password'),
            'role' => 'owner'
        ]);

        $this->clientUser = User::create([
            'name' => 'Test Client',
            'email' => 'client@test.com',
            'password' => bcrypt('password'),
            'role' => 'client'
        ]);
    }

    public function test_unauthenticated_user_cannot_access_tickets()
    {
        $response = $this->getJson('/api/tickets');
        $response->assertStatus(401);
    }

    public function test_service_desk_can_create_ticket_but_programmer_cannot()
    {
        // Service Desk creating ticket
        $response = $this->actingAs($this->serviceDesk)
            ->postJson('/api/tickets', [
                'title' => 'Test Ticket',
                'description' => 'Test Description'
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('tickets', ['title' => 'Test Ticket']);
        
        $createdTicket = Ticket::where('title', 'Test Ticket')->first();
        $this->assertMatchesRegularExpression('/^TCK-\d{6}-\d{4}$/', $createdTicket->ticket_id);

        // Programmer trying to create ticket
        $response2 = $this->actingAs($this->programmer)
            ->postJson('/api/tickets', [
                'title' => 'Illegal Ticket',
                'description' => 'Should fail'
            ]);

        $response2->assertStatus(403);
    }

    public function test_pm_can_assign_ticket_but_others_cannot()
    {
        // Create a ticket
        $ticket = Ticket::create([
            'title' => 'Test Ticket',
            'description' => 'Test Description',
            'status' => 'open',
            'category' => 'Software',
            'user_id' => $this->serviceDesk->id
        ]);

        // Service Desk trying to assign
        $response = $this->actingAs($this->serviceDesk)
            ->postJson("/api/tickets/{$ticket->ticket_id}/assign", [
                'programmer_id' => $this->programmer->id,
                'estimated_hours' => 5
            ]);
        $response->assertStatus(403);

        // PM assigns
        $response2 = $this->actingAs($this->pm)
            ->postJson("/api/tickets/{$ticket->ticket_id}/assign", [
                'programmer_id' => $this->programmer->id,
                'estimated_hours' => 4.5
            ]);
        $response2->assertStatus(200);
        $this->assertDatabaseHas('ticket_assignments', [
            'ticket_id' => $ticket->id,
            'programmer_id' => $this->programmer->id,
            'estimated_hours' => 4.5
        ]);
    }

    public function test_programmer_can_only_update_status_of_assigned_tickets()
    {
        // Create ticket
        $ticket = Ticket::create([
            'title' => 'Test Ticket',
            'description' => 'Test Description',
            'status' => 'open',
            'category' => 'Software',
            'user_id' => $this->serviceDesk->id
        ]);

        // Assign to programmer
        TicketAssignment::create([
            'ticket_id' => $ticket->id,
            'pm_id' => $this->pm->id,
            'programmer_id' => $this->programmer->id,
            'estimated_hours' => 10
        ]);
        $ticket->update(['status' => 'assigned']);

        // Other programmer tries to start it -> should fail
        $response = $this->actingAs($this->otherProgrammer)
            ->postJson("/api/tickets/{$ticket->ticket_id}/status", [
                'status' => 'in_progress',
                'notes' => 'Starting analysis'
            ]);
        $response->assertStatus(403);

        // Assigned programmer starts it -> should succeed
        $response2 = $this->actingAs($this->programmer)
            ->postJson("/api/tickets/{$ticket->ticket_id}/status", [
                'status' => 'in_progress',
                'notes' => 'Starting analysis'
            ]);
        $response2->assertStatus(200);
        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'status' => 'in_progress'
        ]);
    }

    public function test_client_ticketing_actions()
    {
        // Client can create a ticket without selecting a category
        $response = $this->actingAs($this->clientUser)
            ->postJson('/api/client/tickets', [
                'title' => 'Client Issue',
                'description' => 'Cannot access portal',
            ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('tickets', [
            'title' => 'Client Issue',
            'user_id' => $this->clientUser->id
        ]);

        $ticket = Ticket::where('title', 'Client Issue')->first();

        // Client can view their tickets list
        $response2 = $this->actingAs($this->clientUser)
            ->getJson('/api/client/tickets');
        $response2->assertStatus(200);
        $response2->assertJsonCount(1);

        // Client can view their specific ticket details
        $response3 = $this->actingAs($this->clientUser)
            ->getJson("/api/client/tickets/{$ticket->ticket_id}");
        $response3->assertStatus(200);
        $response3->assertJsonPath('title', 'Client Issue');

        // Other users cannot access client specific ticket details
        $response4 = $this->actingAs($this->programmer)
            ->getJson("/api/client/tickets/{$ticket->ticket_id}");
        $response4->assertStatus(403);
    }

    public function test_service_desk_can_escalate_to_pm_and_role_visibility()
    {
        // 1. Create a raw open ticket
        $openTicket = Ticket::create([
            'title' => 'Open Ticket',
            'description' => 'Unreviewed problem',
            'status' => 'open',
            'user_id' => $this->clientUser->id
        ]);

        // 2. Programmer trying to escalate -> should fail
        $response1 = $this->actingAs($this->programmer)
            ->postJson("/api/tickets/{$openTicket->ticket_id}/escalate", [
                'status' => 'ESCALATED_TO_PM',
                'internal_notes' => 'Some analysis notes',
                'assigned_to_role' => 'PM',
            ]);
        $response1->assertStatus(403);

        // 3. Service Desk escalates with category assignment -> should succeed
        $response2 = $this->actingAs($this->serviceDesk)
            ->postJson("/api/tickets/{$openTicket->ticket_id}/escalate", [
                'status' => 'ESCALATED_TO_PM',
                'internal_notes' => 'Service Desk analysis notes',
                'assigned_to_role' => 'PM',
                'category' => 'Hardware',
            ]);
        $response2->assertStatus(200);
        $this->assertDatabaseHas('tickets', [
            'id' => $openTicket->id,
            'status' => 'escalated_to_pm',
            'internal_notes' => 'Service Desk analysis notes',
            'assigned_to_role' => 'PM',
            'category' => 'Hardware',
        ]);

        // 4. Create another raw open ticket
        $newOpenTicket = Ticket::create([
            'title' => 'Another Open Ticket',
            'description' => 'Unreviewed problem 2',
            'status' => 'open',
            'category' => 'Software',
            'user_id' => $this->clientUser->id
        ]);

        // 5. Test Role Visibility on API:
        // PM Index -> should see escalated_to_pm but NOT open
        $pmResponse = $this->actingAs($this->pm)->getJson('/api/tickets');
        $pmResponse->assertStatus(200);
        $pmTickets = $pmResponse->json();
        $this->assertCount(1, $pmTickets);
        $this->assertEquals($openTicket->ticket_id, $pmTickets[0]['ticket_id']);

        // Owner Index -> should see escalated_to_pm but NOT open
        $ownerResponse = $this->actingAs($this->owner)->getJson('/api/tickets');
        $ownerResponse->assertStatus(200);
        $ownerTickets = $ownerResponse->json();
        $this->assertCount(1, $ownerTickets);
        $this->assertEquals($openTicket->ticket_id, $ownerTickets[0]['ticket_id']);

        // Service Desk Index -> should see BOTH (both open and escalated_to_pm)
        $sdResponse = $this->actingAs($this->serviceDesk)->getJson('/api/tickets');
        $sdResponse->assertStatus(200);
        $sdTickets = $sdResponse->json();
        $this->assertCount(2, $sdTickets);
    }

    public function test_internal_logs_filtered_from_client_endpoint()
    {
        $ticket = Ticket::create([
            'title' => 'Security Log Test',
            'description' => 'Testing audit log separation',
            'status' => 'open',
            'user_id' => $this->clientUser->id
        ]);

        // Create internal log
        ProgressLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->serviceDesk->id,
            'new_status' => 'escalated_to_pm',
            'notes' => 'Internal escalation details for staff only',
            'is_internal' => true
        ]);

        // Create public reply log
        ProgressLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->serviceDesk->id,
            'new_status' => 'escalated_to_pm',
            'notes' => 'Hello Client, your issue has been received and escalated.',
            'is_internal' => false
        ]);

        // Client request -> should ONLY see public log
        $clientResponse = $this->actingAs($this->clientUser)
            ->getJson("/api/client/tickets/{$ticket->ticket_id}");
        $clientResponse->assertStatus(200);
        $logs = $clientResponse->json('progress_logs');
        $this->assertCount(1, $logs);
        $this->assertEquals('Hello Client, your issue has been received and escalated.', $logs[0]['notes']);

        // Internal staff request -> should see ALL logs (both internal and public)
        $staffResponse = $this->actingAs($this->serviceDesk)
            ->getJson("/api/tickets/{$ticket->ticket_id}");
        $staffResponse->assertStatus(200);
        $staffLogs = $staffResponse->json('progress_logs');
        $this->assertCount(2, $staffLogs);
    }

    public function test_cannot_add_log_on_closed_or_rejected_ticket()
    {
        $closedTicket = Ticket::create([
            'title' => 'Closed Ticket Test',
            'description' => 'Already finished',
            'status' => 'closed',
            'user_id' => $this->clientUser->id
        ]);

        $response = $this->actingAs($this->serviceDesk)
            ->postJson("/api/tickets/{$closedTicket->ticket_id}/logs", [
                'notes' => 'Attempting note on closed ticket',
                'is_internal' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Tidak dapat menambahkan catatan atau pesan pada tiket yang sudah ditutup atau ditolak.');
    }

    public function test_pm_review_ok_and_not_ok()
    {
        $ticket = Ticket::create([
            'title' => 'Feature Implementation',
            'description' => 'Need testing',
            'status' => 'resolved',
            'user_id' => $this->clientUser->id
        ]);

        // 1. PM Reviews TIDAK OK -> status reverts to in_progress
        $notOkResponse = $this->actingAs($this->pm)
            ->postJson("/api/tickets/{$ticket->ticket_id}/pm-review", [
                'decision' => 'not_ok',
                'notes' => 'Bug found in login form',
            ]);

        $notOkResponse->assertStatus(200);
        $this->assertEquals('in_progress', $ticket->fresh()->status);

        // 2. PM Reviews OK -> status remains resolved
        $okResponse = $this->actingAs($this->pm)
            ->postJson("/api/tickets/{$ticket->ticket_id}/pm-review", [
                'decision' => 'ok',
                'notes' => 'Fix verified cleanly',
            ]);

        $okResponse->assertStatus(200);
        $this->assertEquals('resolved', $ticket->fresh()->status);
    }

    public function test_pm_escalate_to_owner_and_owner_decision()
    {
        $ticket = Ticket::create([
            'title' => 'Critical Scope Expansion',
            'description' => 'Extra server budget needed',
            'status' => 'escalated_to_pm',
            'user_id' => $this->clientUser->id
        ]);

        // 1. PM Escalates to Owner
        $escalateResponse = $this->actingAs($this->pm)
            ->postJson("/api/tickets/{$ticket->ticket_id}/escalate-owner", [
                'notes' => 'Requires Owner budget approval for high-capacity cloud server',
            ]);

        $escalateResponse->assertStatus(200);
        $this->assertEquals('escalated_to_owner', $ticket->fresh()->status);

        // 2. Owner Approves decision
        $ownerResponse = $this->actingAs($this->owner)
            ->postJson("/api/tickets/{$ticket->ticket_id}/owner-decision", [
                'decision' => 'approved',
                'notes' => 'Budget approved up to $500',
            ]);

        $ownerResponse->assertStatus(200);
        $this->assertEquals('escalated_to_pm', $ticket->fresh()->status);
    }
}
