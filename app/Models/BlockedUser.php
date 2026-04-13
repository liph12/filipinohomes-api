<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedUser extends Model
{
    protected $fillable = ['agent_user_id', 'blocked_user_id', 'blocked_by', 'reason'];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function blockedUser()
    {
        return $this->belongsTo(User::class, 'blocked_user_id');
    }

    public function blockedByUser()
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }
}
