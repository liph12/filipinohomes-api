<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Auditing\LogsActivity;
use OwenIt\Auditing\Contracts\Auditable;

class Conversation extends Model implements Auditable
{
    use SoftDeletes;
    use LogsActivity;

    protected string $auditCategory = 'inquiries';

    protected $fillable = ['chat_id', 'status', 'agent_user_id', 'reviewed_by', 'reviewed_at', 'closed_at', 'closed_by'];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function agentUser()
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function messages()
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'conversation_users')
            ->withPivot(
                'last_read_at',
                'last_notified_at',
                'archived_at',
                'removed_at',
                'purged_at',
            )
            ->withTimestamps();
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }
}
