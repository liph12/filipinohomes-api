<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Daily cap on NEW conversation creation per authenticated user.
 *
 * Background — a single client (`ngenenelson2` on prod, 2026-06-03)
 * blasted advance-fee fraud copy to 7+ different agents in a few
 * hours because nothing throttled how many distinct conversations
 * a user could open per day. This service caps it at 10 across
 * BOTH surfaces combined:
 *
 *   - POST /chats with type=listing  (listing inquiries)
 *   - POST /chats with type=agent    ("Message Me" agent-direct)
 *
 * Follow-up messages inside an existing accepted conversation
 * (POST /messages) are NOT counted — those are intra-conversation
 * activity, not new-recipient outreach.
 *
 * Storage pattern lifted from app/Services/OpenAI/CacheService.php
 * (the proven daily-quota for /openai/daily-limit): Laravel cache
 * with `now()->endOfDay()` TTL, so the counter naturally resets at
 * server midnight without a cron sweep.
 *
 * Admins bypass at the caller (controller) level — they're never
 * counted and never throttled, so support / moderation paths
 * remain unconstrained.
 */
class ChatRateLimitService
{
    /**
     * Maximum NEW conversations a single non-admin user can open
     * per calendar day (server timezone). Hard-coded for now; if
     * per-role limits are needed later (e.g. agents 20/day), this
     * moves to a settings table — see the plan's "Out of scope".
     */
    public const DAILY_LIMIT = 10;

    /**
     * Cache key prefix. Final shape: `chat_daily_new_chats:{userId}`.
     */
    private const CACHE_PREFIX = 'chat_daily_new_chats';

    /**
     * How many slots the user has left today. Returns 0 when the
     * cap is reached; callers should reject the request and emit
     * an audit row (see AuditSecurityService::recordRateLimitHit).
     */
    public function remaining(int $userId): int
    {
        $used = (int) Cache::get($this->key($userId), 0);
        return max(0, self::DAILY_LIMIT - $used);
    }

    /**
     * True when the user has consumed all DAILY_LIMIT slots for
     * the day. Inverse of remaining > 0.
     */
    public function exhausted(int $userId): bool
    {
        return $this->remaining($userId) <= 0;
    }

    /**
     * Atomically increment the per-user per-day counter and return
     * the new used-count. Caller invokes ONLY after a successful
     * new-chat creation — find-or-create misses (re-inquiry on the
     * same listing/agent) reuse the existing Chat row and must NOT
     * count toward the daily cap.
     *
     * Seeds the counter at 0 with an endOfDay TTL on first hit so
     * the increment lands on a value that auto-expires at midnight.
     */
    public function recordNewChat(int $userId): int
    {
        $key = $this->key($userId);
        if (!Cache::has($key)) {
            Cache::put($key, 0, now()->endOfDay());
        }
        return (int) Cache::increment($key);
    }

    /**
     * Snapshot of the user's current state. Useful for a future
     * GET /chats/daily-remaining endpoint (deferred — see plan
     * "Out of scope") and for inclusion in 429 response bodies.
     *
     * @return array{used:int, limit:int, remaining:int}
     */
    public function snapshot(int $userId): array
    {
        $used = (int) Cache::get($this->key($userId), 0);
        return [
            'used'      => $used,
            'limit'     => self::DAILY_LIMIT,
            'remaining' => max(0, self::DAILY_LIMIT - $used),
        ];
    }

    private function key(int $userId): string
    {
        return self::CACHE_PREFIX . ':' . $userId;
    }
}
