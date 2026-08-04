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
                'description' => 'Test Description',
                'priority' => 'high'
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('tickets', ['title' => 'Test Ticket']);
        
        $createdTicket = Ticket::where('title', 'Test Ticket')->first();
        $this->assertMatchesRegularExpression('/^TCK-\d{6}-\d{4}$/', $createdTicket->ticket_id);

        // Programmer trying to create ticket
        $response2 = $this->actingAs($this->programmer)
            ->postJson('/api/tickets', [
                'title' => 'Illegal Ticket',
                'description' => 'Should fail',
                'priority' => 'low'
            ]);

        $response2->assertStatus(403);
    }

    public function test_pm_can_assign_ticket_but_others_cannot()
    {
        // Create a ticket
        $ticket = Ticket::create([
            'title' => 'Test Ticket',
            'description' => 'Test Description',
            'priority' => 'medium',
            'status' => 'open',
            'created_by_id' => $this->serviceDesk->id
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
            'priority' => 'medium',
            'status' => 'open',
            'created_by_id' => $this->serviceDesk->id
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
}
