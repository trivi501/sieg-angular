<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    use Auditable;

    protected $fillable = [
        'user_id', 'assigned_to', 'title', 'description', 'url', 'image', 'priority', 'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function comments()
    {
        return $this->hasMany(TicketComment::class, 'ticket_id');
    }
}
