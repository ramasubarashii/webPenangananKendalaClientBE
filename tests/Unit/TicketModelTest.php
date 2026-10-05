<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Ticket;
use App\Models\ProgressLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_belongs_to_user()
    {
        $user = User::create([
            'name' => 'Owner User',
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
            'role' => 'client',
        ]);

        $ticket = Ticket::create([
            'title' => 'Unit Test Issue',
            'description' => 'Test Desc',
            'status' => 'open',
            'user_id' => $user->id,
        ]);

        $this->assertInstanceOf(User::class, $ticket->creator);
        $this->assertEquals($user->id, $ticket->creator->id);
    }

    public function test_ticket_has_many_progress_logs()
    {
        $user = User::create([
            'name' => 'Log User',
            'email' => 'log@example.com',
            'password' => bcrypt('password'),
            'role' => 'programmer',
        ]);

        $ticket = Ticket::create([
            'title' => 'Ticket with logs',
            'description' => 'Test Desc',
            'status' => 'in_progress',
            'user_id' => $user->id,
        ]);

        ProgressLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'new_status' => 'in_progress',
            'notes' => 'First log entry',
        ]);

        $this->assertCount(1, $ticket->progressLogs);
        $this->assertEquals('First log entry', $ticket->progressLogs->first()->notes);
    }
}
