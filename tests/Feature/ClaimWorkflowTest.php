<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private $pm;
    private $programmer;
    private $clientUser;
    private $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pm = User::create([
            'name' => 'Project Manager',
            'email' => 'pm@test.com',
            'password' => bcrypt('password'),
            'role' => 'project_manager'
        ]);

        $this->programmer = User::create([
            'name' => 'Programmer 1',
            'email' => 'prog@test.com',
            'password' => bcrypt('password'),
            'role' => 'programmer'
        ]);

        $this->clientUser = User::create([
            'name' => 'Client User',
            'email' => 'client@test.com',
            'password' => bcrypt('password'),
            'role' => 'client'
        ]);

        $this->ticket = Ticket::create([
            'title' => 'Issue for Claim',
            'description' => 'Detailed description of issue',
            'status' => 'escalated_to_pm',
            'user_id' => $this->clientUser->id
        ]);
    }

    public function test_pm_can_release_ticket_for_claim()
    {
        $response = $this->actingAs($this->pm)
            ->postJson("/api/tickets/{$this->ticket->ticket_id}/release-for-claim", [
                'notes' => 'Releasing ticket for team claim',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('waiting_programmer', $this->ticket->fresh()->status);
    }

    public function test_programmer_can_view_and_claim_available_ticket()
    {
        // PM releases first
        $this->ticket->update([
            'status' => 'waiting_programmer',
        ]);

        // Programmer fetches available tickets
        $availResponse = $this->actingAs($this->programmer)
            ->getJson('/api/tickets/available');
        $availResponse->assertStatus(200);
        $availResponse->assertJsonCount(1);

        // Programmer claims ticket
        $claimResponse = $this->actingAs($this->programmer)
            ->postJson("/api/tickets/{$this->ticket->ticket_id}/claim");

        $claimResponse->assertStatus(200);
        $this->assertEquals('waiting_pm_approval', $this->ticket->fresh()->status);
        $this->assertEquals($this->programmer->id, $this->ticket->fresh()->claimed_programmer_id);
    }

    public function test_pm_can_approve_claim_request()
    {
        $this->ticket->update([
            'status' => 'waiting_pm_approval',
            'claimed_programmer_id' => $this->programmer->id,
        ]);

        $response = $this->actingAs($this->pm)
            ->postJson("/api/tickets/{$this->ticket->ticket_id}/approve-claim", [
                'notes' => 'Claim approved by PM',
                'estimated_hours' => 6,
            ]);

        $response->assertStatus(200);
        $this->assertEquals('assigned', $this->ticket->fresh()->status);
        
        $this->assertDatabaseHas('ticket_assignments', [
            'ticket_id' => $this->ticket->id,
            'programmer_id' => $this->programmer->id,
            'pm_id' => $this->pm->id,
        ]);
    }
}
