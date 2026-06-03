<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes Audit rows for security-relevant events that aren't model
 * mutations and therefore can't go through the LogsActivity trait:
 *
 *   - rate_limit_hit   — user exceeded a daily / hourly cap
 *   - user_blocked     — admin/agent/TL blocked a client
 *   - user_unblocked   — corresponding unblock action
 *
 * Mirrors the defensive pattern in AuditAuthService::recordLogin
 * and AuditMailService::recordSent — every write is wrapped in a
 * try/catch so a bookkeeping miss never propagates into the actual
 * rate-limit / block flow. If auditing fails, log a warning and
 * move on; the rate-limit / block has already taken effect.
 *
 * All rows land under the new `security` category, which the
 * activity-logs UI's Security Events tab pre-scopes to alongside
 * `auth` + `mailer` + `system`.
 */
class AuditSecurityService
{
    /**
     * Record that a user hit a server-enforced rate limit. Fired
     * from the ChatController gate when the daily new-chat counter
     * is exhausted, and from any future caller that wants its own
     * cap visible in the audit feed.
     *
     * @param  User    $user        the user who hit the cap
     * @param  string  $kind        machine-readable source, e.g. 'inquiry_daily_cap'
     * @param  string  $description human sentence shown in the activity feed
     * @param  array   $context     extra structured fields stored in new_values
     */
    public function recordRateLimitHit(
        User $user,
        string $kind,
        string $description,
        array $context = [],
    ): void {
        try {
            Audit::create([
                'user_id'        => $user->id,
                'user_type'      => User::class,
                'user_role'      => $user->role?->name,
                'user_name'      => $user->name,
                'event'          => 'rate_limit_hit',
                'category'       => 'security',
                'source'         => $kind,
                'auditable_type' => User::class,
                'auditable_id'   => $user->id,
                'subject_label'  => $user->name,
                'description'    => $description,
                'ip_address'     => request()?->ip(),
                'user_agent'     => request()?->userAgent(),
                'url'            => request()?->fullUrl(),
                'old_values'     => null,
                'new_values'     => $context,
            ]);
        } catch (Throwable $e) {
            Log::warning('Security audit (rate_limit_hit) write failed', [
                'user_id' => $user->id,
                'kind'    => $kind,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record a block action. Scope distinguishes a per-agent block
     * (blocked user can't contact this specific agent) from a global
     * block (admin-issued, blocked user can't contact any agent).
     *
     * @param  User    $blocker        actor performing the block (admin/agent/TL)
     * @param  User    $blocked        target user being blocked
     * @param  string  $scope          'per_agent' | 'global'
     * @param  ?int    $agentUserId    target agent's user_id when scope=per_agent; null when scope=global
     * @param  ?string $reason         optional moderator note
     */
    public function recordBlock(
        User $blocker,
        User $blocked,
        string $scope,
        ?int $agentUserId,
        ?string $reason = null,
    ): void {
        try {
            $scopeLabel = $scope === 'global' ? 'platform-wide' : 'this agent';
            $description = sprintf(
                '%s blocked %s (%s)',
                $blocker->name,
                $blocked->name,
                $scopeLabel,
            );

            Audit::create([
                'user_id'        => $blocker->id,
                'user_type'      => User::class,
                'user_role'      => $blocker->role?->name,
                'user_name'      => $blocker->name,
                'event'          => 'user_blocked',
                'category'       => 'security',
                'source'         => 'block_' . $scope,
                'auditable_type' => User::class,
                'auditable_id'   => $blocked->id,
                'subject_label'  => $blocked->name,
                'description'    => $description,
                'ip_address'     => request()?->ip(),
                'user_agent'     => request()?->userAgent(),
                'url'            => request()?->fullUrl(),
                'old_values'     => null,
                'new_values'     => [
                    'scope'          => $scope,
                    'agent_user_id'  => $agentUserId,
                    'blocked_user_id' => $blocked->id,
                    'reason'         => $reason,
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('Security audit (user_blocked) write failed', [
                'blocker_id' => $blocker->id,
                'blocked_id' => $blocked->id,
                'scope'      => $scope,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record an unblock action. Symmetric counterpart to recordBlock.
     */
    public function recordUnblock(
        User $unblocker,
        User $unblocked,
        string $scope,
        ?int $agentUserId,
    ): void {
        try {
            $scopeLabel = $scope === 'global' ? 'platform-wide' : 'this agent';
            $description = sprintf(
                '%s unblocked %s (%s)',
                $unblocker->name,
                $unblocked->name,
                $scopeLabel,
            );

            Audit::create([
                'user_id'        => $unblocker->id,
                'user_type'      => User::class,
                'user_role'      => $unblocker->role?->name,
                'user_name'      => $unblocker->name,
                'event'          => 'user_unblocked',
                'category'       => 'security',
                'source'         => 'unblock_' . $scope,
                'auditable_type' => User::class,
                'auditable_id'   => $unblocked->id,
                'subject_label'  => $unblocked->name,
                'description'    => $description,
                'ip_address'     => request()?->ip(),
                'user_agent'     => request()?->userAgent(),
                'url'            => request()?->fullUrl(),
                'old_values'     => null,
                'new_values'     => [
                    'scope'           => $scope,
                    'agent_user_id'   => $agentUserId,
                    'unblocked_user_id' => $unblocked->id,
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('Security audit (user_unblocked) write failed', [
                'unblocker_id' => $unblocker->id,
                'unblocked_id' => $unblocked->id,
                'scope'        => $scope,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
