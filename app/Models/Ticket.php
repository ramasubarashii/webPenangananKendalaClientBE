<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $fillable = [
        'title',
        'description',
        'attachment_path',
        'priority',
        'status',
        'created_by_id',
    ];

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
