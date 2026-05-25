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

    protected $fillable = ['agent_user_id', 'blocked_user_id', 'blocked_by', 'scope', 'reason'];

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

    /**
     * Single source of truth for "is this user blocked from messaging
     * this agent?" — used by ChatController::store (new inquiries) and
     * MessageController::store (sending into an existing thread).
     *
     * A user is blocked when EITHER:
     *   - a global row exists (scope='global', agent_user_id NULL) for
     *     them — admin site-wide ban that applies across every agent.
     *   - a per-agent row exists (scope='per_agent', agent_user_id = $agent)
     *     for them — the classic agent-issued or team-leader-issued block
     *     scoped to one agent.
     */
    public static function isBlocking(int $blockedUserId, int $agentUserId): bool
    {
        return self::where('blocked_user_id', $blockedUserId)
            ->where(function ($q) use ($agentUserId) {
                $q->where('scope', 'global')
                  ->orWhere(function ($qq) use ($agentUserId) {
                      $qq->where('scope', 'per_agent')
                         ->where('agent_user_id', $agentUserId);
                  });
            })
            ->exists();
    }
}
