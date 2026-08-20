<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\ProgressLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Ticket::with(['creator', 'claimedProgrammer', 'assignments.programmer', 'assignments.pm', 'progressLogs.user'])
            ->orderBy('created_at', 'desc');

        if ($user->role === 'programmer') {
            // Programmers see tickets assigned to them OR tickets they have interacted with in logs
            $query->where(function ($q) use ($user) {
                $q->whereHas('assignments', function ($sq) use ($user) {
                    $sq->where('programmer_id', $user->id);
                })->orWhereHas('progressLogs', function ($sq) use ($user) {
                    $sq->where('user_id', $user->id);
                });
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
            'is_internal' => true,
        ]);

        return response()->json($ticket->load(['creator', 'progressLogs']), 201);
    }

    /**
     * Service Desk: Create a ticket on behalf of a NON-REGISTERED (walk-in) client.
     * POST /tickets/walk-in
     * The ticket is created by the Service Desk account (user_id = SD) and goes
     * directly to 'open' status — no pending_confirmation step needed.
     */
    public function storeWalkIn(Request $request)
    {
        if ($request->user()->role !== 'service_desk') {
            return response()->json(['message' => 'Hanya Service Desk yang dapat membuat tiket walk-in.'], 403);
        }

        $request->validate([
            'reporter_name'         => 'required|string|max:255',
            'reporter_contact'      => 'nullable|string|max:255',
            'contact_method'        => 'required|in:whatsapp,telepon,email,walk_in,lainnya',
            'contact_method_notes'  => 'nullable|string|max:255',
            'title'                 => 'required|string|max:255',
            'description'           => 'required|string',
            'category'              => 'nullable|string|in:Jaringan,Hardware,Software,Akun,Lainnya',
            'attachment'            => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:5120',
        ]);

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('attachments', 'public');
        }

        $contactMethodLabel = match($request->contact_method) {
            'whatsapp' => 'WhatsApp',
            'telepon'  => 'Telepon',
            'email'    => 'Email',
            'walk_in'  => 'Datang Langsung (Walk-in)',
            'lainnya'  => 'Lainnya: ' . ($request->contact_method_notes ?? '-'),
            default    => $request->contact_method,
        };

        $ticket = Ticket::create([
            'title'                 => $request->title,
            'description'           => $request->description,
            'category'              => $request->category ?? null,
            'attachment_path'       => $attachmentPath,
            'status'                => 'open',
            'user_id'               => $request->user()->id,
            'reporter_name'         => $request->reporter_name,
            'reporter_contact'      => $request->reporter_contact ?? null,
            'contact_method'        => $request->contact_method,
            'contact_method_notes'  => $request->contact_method_notes ?? null,
        ]);

        // Create audit log with [WALK_IN] marker for traceability
        ProgressLog::create([
            'ticket_id'       => $ticket->id,
            'user_id'         => $request->user()->id,
            'previous_status' => null,
            'new_status'      => 'open',
            'notes'           => '[WALK_IN] Tiket dibuat oleh Service Desk (' . $request->user()->name . ') untuk client non-sistem: ' . $request->reporter_name . '. Metode kontak: ' . $contactMethodLabel . '.',
            'is_internal'     => true,
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

        // Strict access check for Client role (Must use /api/client/tickets/{id})
        if ($user->role === 'client') {
            return response()->json(['message' => 'Unauthorized. Client must use public client endpoint.'], 403);
        }

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

    /**
     * Service Desk: Confirm or reject a pending_confirmation ticket from client.
     * POST /tickets/{id}/confirm
     * body: { action: 'confirm'|'reject', notes: string }
     */
    public function confirmTicket(Request $request, $id)
    {
        $ticket = Ticket::where('ticket_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if ($request->user()->role !== 'service_desk') {
            return response()->json(['message' => 'Hanya Service Desk yang dapat mengkonfirmasi tiket.'], 403);
        }

        if ($ticket->status !== 'pending_confirmation') {
            return response()->json(['message' => 'Hanya tiket berstatus menunggu konfirmasi yang dapat dikonfirmasi.'], 422);
        }

        $request->validate([
            'action' => 'required|in:confirm,reject',
            'notes'  => 'required|string',
        ]);

        $oldStatus = $ticket->status;

        if ($request->action === 'confirm') {
            $ticket->update(['status' => 'open']);
            ProgressLog::create([
                'ticket_id'       => $ticket->id,
                'user_id'         => $request->user()->id,
                'previous_status' => $oldStatus,
                'new_status'      => 'open',
                'notes'           => '[SD_CONFIRMED] Tiket dikonfirmasi oleh Service Desk. ' . $request->notes,
                'is_internal'     => false,
            ]);
            return response()->json([
                'message' => 'Tiket berhasil dikonfirmasi dan masuk ke antrian.',
                'ticket'  => $ticket->load(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs.user'])
            ]);
        } else {
            $ticket->update(['status' => 'rejected']);
            ProgressLog::create([
                'ticket_id'       => $ticket->id,
                'user_id'         => $request->user()->id,
                'previous_status' => $oldStatus,
                'new_status'      => 'rejected',
                'notes'           => '[SD_REJECTED] Tiket ditolak oleh Service Desk. Alasan: ' . $request->notes,
                'is_internal'     => false,
            ]);
            return response()->json([
                'message' => 'Tiket ditolak dan ditutup.',
                'ticket'  => $ticket->load(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs.user'])
            ]);
        }
    }

    /**
     * Project Manager: Update ticket priority at any time (while not closed/rejected).
     * POST /tickets/{id}/priority
     * body: { priority: 'low'|'medium'|'high'|'belum_ditentukan', notes: string }
     */
    public function updatePriority(Request $request, $id)
    {
        $ticket = Ticket::where('ticket_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if ($request->user()->role !== 'project_manager') {
            return response()->json(['message' => 'Hanya Project Manager yang dapat mengubah prioritas tiket.'], 403);
        }

        if (in_array($ticket->status, ['closed', 'rejected'])) {
            return response()->json(['message' => 'Prioritas tidak dapat diubah pada tiket yang sudah closed atau rejected.'], 422);
        }

        $request->validate([
            'priority' => 'required|in:low,medium,high,belum_ditentukan',
            'notes'    => 'nullable|string',
        ]);

        $oldPriority = $ticket->priority;
        $ticket->update(['priority' => $request->priority]);

        ProgressLog::create([
            'ticket_id'       => $ticket->id,
            'user_id'         => $request->user()->id,
            'previous_status' => $ticket->status,
            'new_status'      => $ticket->status,
            'notes'           => '[PRIORITY_UPDATE] PM mengubah prioritas dari ' . ($oldPriority ?? 'belum_ditentukan') . ' menjadi ' . $request->priority . ($request->notes ? '. Catatan: ' . $request->notes : '.'),
            'is_internal'     => true,
        ]);

        return response()->json([
            'message' => 'Prioritas tiket berhasil diperbarui.',
            'ticket'  => $ticket->load(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs.user'])
        ]);
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
            'programmer_id'  => 'required|exists:users,id',
            'estimated_hours'=> 'required|numeric|min:0.1',
            'estimated_unit' => 'nullable|in:hours,days',
            'priority'       => 'nullable|in:low,medium,high,belum_ditentukan',
        ]);

        $programmer = User::find($request->programmer_id);
        if ($programmer->role !== 'programmer') {
            return response()->json(['message' => 'Assigned user must be a programmer.'], 422);
        }

        $oldStatus = $ticket->status;
        $unit = $request->estimated_unit ?? 'hours';
        $unitLabel = $unit === 'days' ? 'Hari' : 'Jam';

        // Update or create ticket assignment
        TicketAssignment::updateOrCreate(
            ['ticket_id' => $ticket->id],
            [
                'pm_id'           => $request->user()->id,
                'programmer_id'   => $request->programmer_id,
                'estimated_hours' => $request->estimated_hours,
                'estimated_unit'  => $unit,
            ]
        );

        // Update priority if PM provided it
        if ($request->filled('priority')) {
            $ticket->update(['priority' => $request->priority, 'status' => 'assigned']);
        } else {
            $ticket->update(['status' => 'assigned']);
        }

        // Log the assignment
        ProgressLog::create([
            'ticket_id'       => $ticket->id,
            'user_id'         => $request->user()->id,
            'previous_status' => $oldStatus,
            'new_status'      => 'assigned',
            'notes'           => "Tiket ditugaskan ke programmer: {$programmer->name} oleh PM: {$request->user()->name}. Estimasi: {$request->estimated_hours} {$unitLabel}.",
            'is_internal'     => true,
        ]);

        return response()->json([
            'message' => 'Ticket assigned successfully',
            'ticket'  => $ticket->load(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs'])
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
            'status' => 'required|in:pending_confirmation,open,assigned,in_progress,pending_review,resolved,closed,rejected',
            'notes' => 'required|string',
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
        } elseif ($newStatus === 'pending_review') {
            // Only programmer assigned to this ticket can submit for PM review
            if ($user->role !== 'programmer') {
                return response()->json(['message' => 'Hanya Programmer yang dapat mengajukan tiket untuk direview PM.'], 403);
            }
            $isAssigned = TicketAssignment::where('ticket_id', $ticket->id)
                ->where('programmer_id', $user->id)
                ->exists();
            if (! $isAssigned) {
                return response()->json(['message' => 'Kamu tidak ditugaskan ke tiket ini.'], 403);
            }
            // Prevent submitting if not in_progress
            if ($oldStatus !== 'in_progress' && $oldStatus !== 'assigned') {
                return response()->json(['message' => 'Tiket hanya dapat diajukan review saat berstatus in_progress atau assigned.'], 422);
            }
        } elseif (in_array($newStatus, ['in_progress'])) {
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
        } elseif ($newStatus === 'resolved') {
            // Only PM can set resolved (via pmReview endpoint, not this one)
            // Block direct resolved from programmer
            if ($user->role === 'programmer') {
                return response()->json(['message' => 'Programmer tidak dapat langsung menyelesaikan tiket. Ajukan ke PM terlebih dahulu.'], 403);
            } elseif ($user->role !== 'project_manager' && $user->role !== 'service_desk') {
                return response()->json(['message' => 'Unauthorized to mark ticket as resolved.'], 403);
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
            'is_internal' => $request->has('is_internal') ? $request->boolean('is_internal') : true,
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
            'status' => 'pending_confirmation', // Waits for Service Desk confirmation
            'user_id' => $request->user()->id,
        ]);

        ProgressLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'previous_status' => null,
            'new_status' => 'pending_confirmation',
            'notes' => 'Tiket bantuan berhasil dibuat oleh Klien. Menunggu konfirmasi Service Desk.',
            'is_internal' => false,
        ]);

        return response()->json($ticket->load(['creator', 'progressLogs']), 201);
    }

    public function clientShow(Request $request, $id)
    {
        $ticket = Ticket::where('ticket_id', $id)
            ->where('user_id', $request->user()->id)
            ->with([
                'creator:id,name,email',
                'progressLogs' => function ($query) {
                    $query->where('is_internal', false)->with('user:id,name,role');
                }
            ])
            ->first();

        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        // Hide internal operational fields from client JSON response
        $ticket->makeHidden(['internal_notes', 'assigned_to_role']);

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
            'internal_notes' => 'required|string',
            'priority' => 'nullable|string|in:low,medium,high,Low,Medium,High,belum_ditentukan',
            'category' => 'nullable|string|in:Jaringan,Hardware,Software,Akun,Lainnya',
        ]);

        $oldStatus = $ticket->status;
        // Workflow: SD escalates → status becomes escalated_to_pm
        // The ticket is visible to PM AND also appears in Available Tickets for programmers.
        // PM does NOT need to do anything to forward it.
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
            'notes' => 'Eskalasi tiket ke Project Manager dan pool programmer dengan catatan: ' . substr($request->internal_notes, 0, 100),
            'is_internal' => true,
        ]);

        return response()->json([
            'message' => 'Tiket berhasil dieskalasikan ke Project Manager dan tersedia di Available Tickets programmer.',
            'ticket' => $ticket->load(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs'])
        ]);
    }

    public function addLog(Request $request, $id)
    {
        $ticket = Ticket::where('ticket_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if (in_array(strtolower($ticket->status), ['closed', 'rejected'])) {
            return response()->json(['message' => 'Tidak dapat menambahkan catatan atau pesan pada tiket yang sudah ditutup atau ditolak.'], 422);
        }

        $request->validate([
            'notes' => 'required|string',
            'is_internal' => 'required|boolean',
        ]);

        $log = ProgressLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'previous_status' => $ticket->status,
            'new_status' => $ticket->status,
            'notes' => $request->notes,
            'is_internal' => $request->boolean('is_internal'),
        ]);

        return response()->json([
            'message' => $request->boolean('is_internal') ? 'Catatan internal berhasil disimpan.' : 'Pesan balasan ke Klien berhasil dikirim.',
            'log' => $log->load('user'),
            'ticket' => $ticket->load(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs.user'])
        ]);
    }

    public function pmReview(Request $request, $id)
    {
        $ticket = Ticket::where('ticket_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if ($request->user()->role !== 'project_manager') {
            return response()->json(['message' => 'Hanya Project Manager yang dapat mereview hasil pengerjaan.'], 403);
        }

        $request->validate([
            'decision' => 'required|string|in:ok,not_ok,OK,TIDAK_OK,tidak_ok',
            'notes' => 'required|string',
        ]);

        // Guard: PM can only review tickets in pending_review status
        if ($ticket->status !== 'pending_review') {
            return response()->json([
                'message' => 'Review PM hanya dapat dilakukan pada tiket yang berstatus menunggu review (pending_review).'
            ], 422);
        }

        $decision = strtolower($request->decision);
        $oldStatus = $ticket->status;

        if ($decision === 'ok') {
            $newStatus = 'resolved';
            $ticket->update(['status' => $newStatus]);

            ProgressLog::create([
                'ticket_id' => $ticket->id,
                'user_id' => $request->user()->id,
                'previous_status' => $oldStatus,
                'new_status' => $newStatus,
                // Marker [PM_REVIEW_OK] used by frontend notification detection
                'notes' => '[PM_REVIEW_OK] Hasil pengerjaan disetujui oleh PM. Catatan PM: ' . $request->notes,
                'is_internal' => true,
            ]);

            return response()->json([
                'message' => 'Hasil pengerjaan programmer disetujui oleh PM (OK). Tiket siap diverifikasi oleh Service Desk.',
                'ticket' => $ticket->load(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs.user'])
            ]);
        } else {
            $newStatus = 'in_progress';
            $ticket->update(['status' => $newStatus]);

            ProgressLog::create([
                'ticket_id' => $ticket->id,
                'user_id' => $request->user()->id,
                'previous_status' => $oldStatus,
                'new_status' => $newStatus,
                // Marker [PM_REVIEW_TIDAK_OK] used by frontend notification detection
                'notes' => '[PM_REVIEW_TIDAK_OK] Perlu Perbaikan. Catatan PM: ' . $request->notes,
                'is_internal' => true,
            ]);

            return response()->json([
                'message' => 'Hasil pengerjaan ditolak oleh PM (TIDAK OK) dan dikembalikan ke programmer untuk perbaikan.',
                'ticket' => $ticket->load(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs.user'])
            ]);
        }
    }

    public function escalateOwner(Request $request, $id)
    {
        $ticket = Ticket::where('ticket_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if ($request->user()->role !== 'project_manager') {
            return response()->json(['message' => 'Hanya Project Manager yang dapat menyerahkan tiket ke Owner.'], 403);
        }

        $request->validate([
            'notes' => 'required|string',
        ]);

        $oldStatus = $ticket->status;
        $ticket->update([
            'status' => 'escalated_to_owner',
            'assigned_to_role' => 'OWNER',
        ]);

        ProgressLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'previous_status' => $oldStatus,
            'new_status' => 'escalated_to_owner',
            'notes' => 'Dieskalasikan ke Owner oleh PM untuk persetujuan/keputusan. Catatan PM: ' . $request->notes,
            'is_internal' => true,
        ]);

        return response()->json([
            'message' => 'Tiket berhasil diserahkan ke Owner untuk persetujuan.',
            'ticket' => $ticket->load(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs.user'])
        ]);
    }

    public function ownerDecision(Request $request, $id)
    {
        $ticket = Ticket::where('ticket_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Hanya Owner yang dapat memberikan keputusan tiket ini.'], 403);
        }

        $request->validate([
            'decision' => 'required|string|in:approved,resolved,rejected,returned_to_pm',
            'notes' => 'required|string',
        ]);

        $oldStatus = $ticket->status;
        $decision = strtolower($request->decision);

        if ($decision === 'approved') {
            $newStatus = 'escalated_to_pm';
            $assignedRole = 'PM';
            $logMarker = '[OWNER_DECISION_APPROVED]';
            $msg = 'Keputusan Owner: Disetujui (Approved). Tiket diteruskan kembali ke PM.';
        } elseif ($decision === 'resolved') {
            $newStatus = 'resolved';
            $assignedRole = 'SERVICE_DESK';
            $logMarker = '[OWNER_DECISION_RESOLVED]';
            $msg = 'Keputusan Owner: Disetujui & Selesaikan Langsung (Resolved). Tiket siap diverifikasi Service Desk.';
        } elseif ($decision === 'rejected') {
            $newStatus = 'rejected';
            $assignedRole = 'SERVICE_DESK';
            $logMarker = '[OWNER_DECISION_REJECTED]';
            $msg = 'Keputusan Owner: Ditolak (Rejected). Tiket ditolak secara permanen.';
        } else {
            $newStatus = 'escalated_to_pm';
            $assignedRole = 'PM';
            $logMarker = '[OWNER_DECISION_RETURNED]';
            $msg = 'Keputusan Owner: Dikembalikan ke PM dengan instruksi kajian ulang.';
        }

        $ticket->update([
            'status' => $newStatus,
            'assigned_to_role' => $assignedRole,
        ]);

        ProgressLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'previous_status' => $oldStatus,
            'new_status' => $newStatus,
            'notes' => $logMarker . ' Catatan Owner: ' . $request->notes,
            'is_internal' => true,
        ]);

        return response()->json([
            'message' => $msg,
            'ticket' => $ticket->load(['creator', 'assignments.programmer', 'assignments.pm', 'progressLogs.user'])
        ]);
    }

    /**
     * PM: Release ticket to programmer claim pool.
     * POST /tickets/{ticket}/release-for-claim
     */
    public function releaseForClaim(Request $request, $id)
    {
        if ($request->user()->role !== 'project_manager') {
            return response()->json(['message' => 'Hanya Project Manager yang dapat merilis tiket untuk claim.'], 403);
        }

        $request->validate([
            'notes' => 'required|string',
        ]);

        $ticket = Ticket::where('ticket_id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if ($ticket->status !== 'escalated_to_pm') {
            return response()->json([
                'message' => 'Hanya tiket berstatus escalated_to_pm yang dapat dirilis untuk claim programmer.',
            ], 422);
        }

        if (TicketAssignment::where('ticket_id', $ticket->id)->exists()) {
            return response()->json(['message' => 'Tiket sudah memiliki assignment developer.'], 422);
        }

        $oldStatus = $ticket->status;
        $ticket->update([
            'status' => 'waiting_programmer',
            'claimed_programmer_id' => null,
        ]);

        ProgressLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'previous_status' => $oldStatus,
            'new_status' => 'waiting_programmer',
            'notes' => '[RELEASE_FOR_CLAIM] Tiket dirilis ke pool programmer untuk claim. Catatan PM: ' . $request->notes,
            'is_internal' => true,
        ]);

        return response()->json([
            'message' => 'Tiket berhasil dirilis ke Available Tickets untuk programmer.',
            'ticket' => $ticket->load(['creator', 'claimedProgrammer', 'assignments.programmer', 'assignments.pm', 'progressLogs.user']),
        ]);
    }

    /**
     * Programmer: List tickets available for claim.
     * GET /tickets/available
     */
    public function availableTickets(Request $request)
    {
        if ($request->user()->role !== 'programmer') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Show tickets that are either escalated_to_pm or waiting_programmer,
        // have no claimed programmer, and no assignment yet.
        // This allows programmers to see tickets immediately after SD escalation
        // without PM needing to take any action.
        $tickets = Ticket::with(['creator', 'assignments', 'progressLogs.user'])
            ->whereIn('status', ['escalated_to_pm', 'waiting_programmer'])
            ->whereNull('claimed_programmer_id')
            ->whereDoesntHave('assignments')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($tickets);
    }

    /**
     * Programmer: Claim a ticket (waiting_programmer → waiting_pm_approval).
     * POST /tickets/{ticket}/claim
     */
    public function claimTicket(Request $request, $id)
    {
        if ($request->user()->role !== 'programmer') {
            return response()->json(['message' => 'Hanya Programmer yang dapat claim tiket.'], 403);
        }

        $user = $request->user();

        return DB::transaction(function () use ($id, $user) {
            $ticket = Ticket::where('ticket_id', $id)->lockForUpdate()->first();

            if (! $ticket) {
                return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
            }

            // Allow claim from both escalated_to_pm and waiting_programmer statuses
            if (! in_array($ticket->status, ['escalated_to_pm', 'waiting_programmer'])) {
                return response()->json(['message' => 'Tiket tidak tersedia untuk claim.'], 422);
            }

            if ($ticket->claimed_programmer_id) {
                return response()->json(['message' => 'Tiket sudah di-claim programmer lain.'], 422);
            }

            if (TicketAssignment::where('ticket_id', $ticket->id)->exists()) {
                return response()->json(['message' => 'Tiket sudah memiliki Assigned Developer.'], 422);
            }

            $oldStatus = $ticket->status;
            $ticket->update([
                'claimed_programmer_id' => $user->id,
                'status' => 'waiting_pm_approval',
            ]);

            ProgressLog::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'previous_status' => $oldStatus,
                'new_status' => 'waiting_pm_approval',
                'notes' => '[CLAIM_TICKET] Programmer ' . $user->name . ' melakukan claim tiket. Menunggu persetujuan PM.',
                'is_internal' => true,
            ]);

            return response()->json([
                'message' => 'Claim berhasil diajukan. Menunggu persetujuan PM.',
                'ticket' => $ticket->load(['creator', 'claimedProgrammer', 'assignments.programmer', 'assignments.pm', 'progressLogs.user']),
            ]);
        });
    }

    /**
     * PM: List tickets with pending claim approval.
     * GET /tickets/pending-claims
     */
    public function pendingClaimApprovals(Request $request)
    {
        if ($request->user()->role !== 'project_manager') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $tickets = Ticket::with(['creator', 'claimedProgrammer', 'assignments'])
            ->where('status', 'waiting_pm_approval')
            ->whereNotNull('claimed_programmer_id')
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json($tickets);
    }

    /**
     * PM: Approve programmer claim → assigned.
     * POST /tickets/{ticket}/approve-claim
     */
    public function approveClaim(Request $request, $id)
    {
        if ($request->user()->role !== 'project_manager') {
            return response()->json(['message' => 'Hanya Project Manager yang dapat menyetujui claim.'], 403);
        }

        $request->validate([
            'notes' => 'required|string',
            'estimated_hours' => 'nullable|numeric|min:0.1',
            'estimated_unit' => 'nullable|in:hours,days',
        ]);

        $pm = $request->user();

        return DB::transaction(function () use ($id, $pm, $request) {
            $ticket = Ticket::where('ticket_id', $id)->lockForUpdate()->first();

            if (! $ticket) {
                return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
            }

            if ($ticket->status !== 'waiting_pm_approval' || ! $ticket->claimed_programmer_id) {
                return response()->json(['message' => 'Tidak ada claim yang menunggu persetujuan pada tiket ini.'], 422);
            }

            if (TicketAssignment::where('ticket_id', $ticket->id)->exists()) {
                return response()->json(['message' => 'Tiket sudah memiliki Assigned Developer.'], 422);
            }

            $programmer = User::find($ticket->claimed_programmer_id);
            if (! $programmer || $programmer->role !== 'programmer') {
                return response()->json(['message' => 'Programmer claim tidak valid.'], 422);
            }

            $unit = $request->estimated_unit ?? 'hours';
            $estimatedHours = $request->estimated_hours ?? 0;
            $unitLabel = $unit === 'days' ? 'Hari' : 'Jam';

            TicketAssignment::create([
                'ticket_id' => $ticket->id,
                'pm_id' => $pm->id,
                'programmer_id' => $programmer->id,
                'estimated_hours' => $estimatedHours,
                'estimated_unit' => $unit,
            ]);

            $oldStatus = $ticket->status;
            $ticket->update([
                'status' => 'assigned',
                'claimed_programmer_id' => null,
            ]);

            ProgressLog::create([
                'ticket_id' => $ticket->id,
                'user_id' => $pm->id,
                'previous_status' => $oldStatus,
                'new_status' => 'assigned',
                'notes' => '[CLAIM_APPROVED] Claim disetujui PM. Developer ditugaskan: ' . $programmer->name . '. Estimasi: ' . $estimatedHours . ' ' . $unitLabel . '. Catatan PM: ' . $request->notes,
                'is_internal' => true,
            ]);

            return response()->json([
                'message' => 'Claim disetujui. Tiket ditugaskan ke ' . $programmer->name . ' dan muncul di My Tasks.',
                'ticket' => $ticket->load(['creator', 'claimedProgrammer', 'assignments.programmer', 'assignments.pm', 'progressLogs.user']),
            ]);
        });
    }

    /**
     * PM: Reject programmer claim → waiting_programmer.
     * POST /tickets/{ticket}/reject-claim
     */
    public function rejectClaim(Request $request, $id)
    {
        if ($request->user()->role !== 'project_manager') {
            return response()->json(['message' => 'Hanya Project Manager yang dapat menolak claim.'], 403);
        }

        $request->validate([
            'notes' => 'required|string',
        ]);

        $pm = $request->user();

        return DB::transaction(function () use ($id, $pm, $request) {
            $ticket = Ticket::where('ticket_id', $id)->lockForUpdate()->first();

            if (! $ticket) {
                return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
            }

            if ($ticket->status !== 'waiting_pm_approval' || ! $ticket->claimed_programmer_id) {
                return response()->json(['message' => 'Tidak ada claim yang menunggu persetujuan pada tiket ini.'], 422);
            }

            $programmer = User::find($ticket->claimed_programmer_id);
            $programmerName = $programmer?->name ?? 'Unknown';

            $oldStatus = $ticket->status;
            $ticket->update([
                'status' => 'waiting_programmer',
                'claimed_programmer_id' => null,
            ]);

            ProgressLog::create([
                'ticket_id' => $ticket->id,
                'user_id' => $pm->id,
                'previous_status' => $oldStatus,
                'new_status' => 'waiting_programmer',
                'notes' => '[CLAIM_REJECTED] Claim ditolak PM. Programmer ' . $programmerName . ' dibatalkan. Catatan PM: ' . $request->notes,
                'is_internal' => true,
            ]);

            return response()->json([
                'message' => 'Claim ditolak. Tiket kembali tersedia di Available Tickets.',
                'ticket' => $ticket->load(['creator', 'claimedProgrammer', 'assignments.programmer', 'assignments.pm', 'progressLogs.user']),
            ]);
        });
    }
}
