<?php

namespace App\Services;

use App\Models\Chat;
use Illuminate\Support\Carbon;

/**
 * Daily cap on NEW conversation creation per authenticated user.
 *
 * Background — a single client (`ngenenelson2` on prod, 2026-06-03)
 * blasted advance-fee fraud copy to 7+ different agents in a few
 * hours because nothing throttled how many distinct conversations
 * a user could open per day. This service caps it at 10 across
 * every POST /chats type combined (listing inquiries, "Message Me"
 * agent-direct, …).
 *
 * Follow-up messages inside an existing accepted conversation
 * (POST /messages) are NOT counted — those are intra-conversation
 * activity, not new-recipient outreach.
 *
 * Usage is counted straight from the `chats` rows the user created
 * today, not from a cache counter. The cache-counter version leaked
 * on prod: Laravel Cloud runs several instances that don't share
 * one cache, so each kept its own tally and a scammer opened 43
 * chats in a day (anthonydouglassus, 2026-11-15) while being
 * "blocked" seven times in between. The rows are the source of
 * truth, so the count holds on any number of instances.
 *
 * Admins bypass at the caller (controller) level — they're never
 * counted and never throttled, so support / moderation paths
 * remain unconstrained. The caller also takes a row lock on the
 * sender before checking, so parallel requests can't all pass.
 */
class ChatRateLimitService
{
    /**
     * Maximum NEW conversations a single non-admin user can open
     * per calendar day. Hard-coded for now; if per-role limits are
     * needed later (e.g. agents 20/day), this moves to a settings
     * table — see the plan's "Out of scope".
     */
    public const DAILY_LIMIT = 10;

    /**
     * The cap's "day" is the Philippine one. The app clock is UTC,
     * which would otherwise reset the cap at 8:00 AM local time.
     */
    private const TIMEZONE = 'Asia/Manila';

    /**
     * Conversations the user has opened since local midnight.
     * Soft-deleted chats still count, so deleting a spammer's
     * chats doesn't hand their slots back.
     */
    public function used(int $userId): int
    {
        return Chat::withTrashed()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $this->startOfDay())
            ->count();
    }

    /**
     * How many slots the user has left today. Returns 0 when the
     * cap is reached; callers should reject the request and emit
     * an audit row (see AuditSecurityService::recordRateLimitHit).
     */
    public function remaining(int $userId): int
    {
        return max(0, self::DAILY_LIMIT - $this->used($userId));
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
     * Snapshot of the user's current state. Useful for a future
     * GET /chats/daily-remaining endpoint (deferred — see plan
     * "Out of scope") and for inclusion in 429 response bodies.
     *
     * @return array{used:int, limit:int, remaining:int}
     */
    public function snapshot(int $userId): array
    {
        $used = $this->used($userId);
        return [
            'used'      => $used,
            'limit'     => self::DAILY_LIMIT,
            'remaining' => max(0, self::DAILY_LIMIT - $used),
        ];
    }

    /**
     * Local midnight, expressed in the app timezone that created_at
     * is stored in. Query bindings don't convert timezones, so the
     * conversion has to happen here.
     */
    private function startOfDay(): Carbon
    {
        return now(self::TIMEZONE)->startOfDay()->setTimezone(config('app.timezone'));
    }
}
