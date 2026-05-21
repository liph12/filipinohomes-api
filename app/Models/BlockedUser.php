<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Auditing\LogsActivity;
use OwenIt\Auditing\Contracts\Auditable;

class BlockedUser extends Model implements Auditable
{
    use LogsActivity;

    protected string $auditCategory = 'users';
    protected array $auditLabelAttributes = ['reason'];

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
