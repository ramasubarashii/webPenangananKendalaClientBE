<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketAssignment extends Model
{
    protected $fillable = [
        'ticket_id',
        'pm_id',
        'programmer_id',
        'estimated_hours',
        'estimated_unit',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function pm()
    {
        return $this->belongsTo(User::class, 'pm_id');
    }

    public function programmer()
    {
        return $this->belongsTo(User::class, 'programmer_id');
    }
}
