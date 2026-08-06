<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\ProgressLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Ticket::with(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs.user'])
            ->orderBy('created_at', 'desc');

        if ($user->role === 'programmer') {
            // Programmers can only see tickets assigned to them
            $query->whereHas('assignments', function ($q) use ($user) {
                $q->where('programmer_id', $user->id);
            });
        } elseif ($user->role === 'client') {
            // Clients can only see their own tickets
            $query->where('user_id', $user->id);
        } elseif ($user->role === 'project_manager' || $user->role === 'owner') {
            // PM and Owner only see escalated/assigned/progress/resolved/closed tickets, not raw open ones
            $query->where('status', '!=', 'open');
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        // Only Service Desk
        if ($request->user()->role !== 'service_desk') {
            return response()->json(['message' => 'Only Service Desk can create tickets.'], 403);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:5120', // Max 5MB
        ]);

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('attachments', 'public');
        }

        $ticket = Ticket::create([
            'title' => $request->title,
            'description' => $request->description,
            'attachment_path' => $attachmentPath,
            'status' => 'open',
            'user_id' => $request->user()->id,
        ]);

        // Create log
        ProgressLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'previous_status' => null,
            'new_status' => 'open',
            'notes' => 'Ticket created by Service Desk.',
        ]);

        return response()->json($ticket->load(['creator', 'progressLogs']), 201);
    }

    public function show(Request $request, $id)
    {
        $ticket = Ticket::where('ticket_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        $user = $request->user();

        // Strict access check for Programmer
        if ($user->role === 'programmer') {
            $isAssigned = TicketAssignment::where('ticket_id', $ticket->id)
                ->where('programmer_id', $user->id)
                ->exists();

            if (! $isAssigned) {
                return response()->json(['message' => 'Unauthorized. You are not assigned to this ticket.'], 403);
            }
        }

        return response()->json($ticket->load(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs.user']));
    }

    public function assign(Request $request, $id)
    {
        $ticket = Ticket::where('ticket_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        // Only PM
        if ($request->user()->role !== 'project_manager') {
            return response()->json(['message' => 'Only Project Managers can assign resources.'], 403);
        }

        $request->validate([
            'programmer_id' => 'required|exists:users,id',
            'estimated_hours' => 'required|numeric|min:0.1',
        ]);

        $programmer = User::find($request->programmer_id);
        if ($programmer->role !== 'programmer') {
            return response()->json(['message' => 'Assigned user must be a programmer.'], 422);
        }

        $oldStatus = $ticket->status;

        // Update or create ticket assignment
        TicketAssignment::updateOrCreate(
            ['ticket_id' => $ticket->id],
            [
                'pm_id' => $request->user()->id,
                'programmer_id' => $request->programmer_id,
                'estimated_hours' => $request->estimated_hours,
            ]
        );

        $ticket->update(['status' => 'assigned']);

        // Log the assignment
        ProgressLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'previous_status' => $oldStatus,
            'new_status' => 'assigned',
            'notes' => "Ticket assigned to programmer: {$programmer->name} by PM: {$request->user()->name}. Est. Hours: {$request->estimated_hours}.",
        ]);

        return response()->json([
            'message' => 'Ticket assigned successfully',
            'ticket' => $ticket->load(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs'])
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $ticket = Ticket::where('ticket_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        $user = $request->user();

        $request->validate([
            'status' => 'required|in:open,assigned,in_progress,resolved,closed,rejected',
            'notes' => 'required|string', // enforce explanation notes for status changes
        ]);

        $newStatus = $request->status;
        $oldStatus = $ticket->status;

        if ($newStatus === $oldStatus) {
            return response()->json(['message' => 'Status is already set to ' . $newStatus], 422);
        }

        // RBAC enforcement for status transition
        if ($newStatus === 'closed' || $newStatus === 'rejected') {
            // Only Service Desk can close/reject
            if ($user->role !== 'service_desk') {
                return response()->json(['message' => 'Only Service Desk can close or reject tickets.'], 403);
            }
        } elseif (in_array($newStatus, ['in_progress', 'resolved'])) {
            // Programmers can only update their assigned tickets
            if ($user->role === 'programmer') {
                $isAssigned = TicketAssignment::where('ticket_id', $ticket->id)
                    ->where('programmer_id', $user->id)
                    ->exists();

                if (! $isAssigned) {
                    return response()->json(['message' => 'You cannot update status of tickets not assigned to you.'], 403);
                }
            } elseif ($user->role !== 'project_manager') {
                return response()->json(['message' => 'Unauthorized to perform technical status update.'], 403);
            }
        } elseif ($newStatus === 'open') {
            if ($user->role !== 'service_desk') {
                return response()->json(['message' => 'Only Service Desk can reopen tickets.'], 403);
            }
        } elseif ($newStatus === 'assigned') {
            if ($user->role !== 'project_manager') {
                return response()->json(['message' => 'Only Project Managers can set ticket to assigned.'], 403);
            }
        }

        // Update the ticket
        $ticket->update(['status' => $newStatus]);

        // Log the status change
        ProgressLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'previous_status' => $oldStatus,
            'new_status' => $newStatus,
            'notes' => $request->notes,
        ]);

        return response()->json([
            'message' => 'Status updated successfully',
            'ticket' => $ticket->load(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs'])
        ]);
    }

    public function clientIndex(Request $request)
    {
        $tickets = Ticket::where('user_id', $request->user()->id)
            ->with(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs.user'])
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($tickets);
    }

    public function clientStore(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'nullable|string|in:Jaringan,Hardware,Software,Akun,Lainnya',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:5120',
        ]);

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('attachments', 'public');
        }

        $ticket = Ticket::create([
            'title' => $request->title,
            'description' => $request->description,
            'category' => $request->category ?? null,
            'attachment_path' => $attachmentPath,
            'status' => 'open',
            'user_id' => $request->user()->id,
        ]);

        ProgressLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'previous_status' => null,
            'new_status' => 'open',
            'notes' => 'Ticket filed by Client.',
        ]);

        return response()->json($ticket->load(['creator', 'progressLogs']), 201);
    }

    public function clientShow(Request $request, $id)
    {
        $ticket = Ticket::where('ticket_id', $id)
            ->where('user_id', $request->user()->id)
            ->with(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs.user'])
            ->first();

        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        return response()->json($ticket);
    }

    public function escalate(Request $request, $id)
    {
        // Only Service Desk can escalate
        if ($request->user()->role !== 'service_desk') {
            return response()->json(['message' => 'Hanya Service Desk yang dapat melakukan eskalasi tiket.'], 403);
        }

        $ticket = Ticket::where('ticket_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        $request->validate([
            'status' => 'required|string|in:ESCALATED_TO_PM,escalated_to_pm',
            'internal_notes' => 'required|string',
            'assigned_to_role' => 'required|string|in:PM,pm',
            'priority' => 'nullable|string|in:low,medium,high,Low,Medium,High,belum_ditentukan',
            'category' => 'nullable|string|in:Jaringan,Hardware,Software,Akun,Lainnya',
        ]);

        $oldStatus = $ticket->status;
        $updateData = [
            'status' => 'escalated_to_pm',
            'internal_notes' => $request->internal_notes,
            'assigned_to_role' => 'PM',
        ];

        if ($request->filled('priority')) {
            $updateData['priority'] = strtolower($request->priority);
        }

        if ($request->filled('category')) {
            $updateData['category'] = $request->category;
        }

        $ticket->update($updateData);

        ProgressLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'previous_status' => $oldStatus,
            'new_status' => 'escalated_to_pm',
            'notes' => 'Eskalasi ke PM dengan catatan: ' . substr($request->internal_notes, 0, 100),
        ]);

        return response()->json([
            'message' => 'Tiket berhasil dieskalasikan ke PM.',
            'ticket' => $ticket->load(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs'])
        ]);
    }
}
