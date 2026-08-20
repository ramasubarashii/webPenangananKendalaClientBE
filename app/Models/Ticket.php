<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $fillable = [
        'ticket_id',
        'title',
        'description',
        'attachment_path',
        'status',
        'category',
        'priority',
        'user_id',
        'claimed_programmer_id',
        'internal_notes',
        'assigned_to_role',
        // Walk-in / non-user reporter fields
        'reporter_name',
        'reporter_contact',
        'contact_method',
        'contact_method_notes',
    ];

    /**
     * Boot the model events.
     */
    protected static function booted()
    {
        static::creating(function ($ticket) {
            $prefix = 'TCK-' . date('Ym') . '-';

            // Database row lock to prevent duplicate ticket IDs during concurrent submissions
            $latest = self::where('ticket_id', 'like', $prefix . '%')
                ->orderBy('ticket_id', 'desc')
                ->lockForUpdate()
                ->first();

            if ($latest) {
                $parts = explode('-', $latest->ticket_id);
                $sequence = intval(end($parts)) + 1;
            } else {
                $sequence = 1;
            }

            $ticket->ticket_id = $prefix . str_pad($sequence, 4, '0', STR_PAD_LEFT);
        });
    }

    public function getRouteKeyName()
    {
        return 'ticket_id';
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function claimedProgrammer()
    {
        return $this->belongsTo(User::class, 'claimed_programmer_id');
    }

    public function assignments()
    {
        return $this->hasMany(TicketAssignment::class, 'ticket_id');
    }

    public function progressLogs()
    {
        return $this->hasMany(ProgressLog::class, 'ticket_id');
    }
}
