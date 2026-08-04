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
        'priority',
        'status',
        'created_by_id',
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
        return $this->belongsTo(User::class, 'created_by_id');
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
