<?php

namespace App\Services;

use App\Models\AgentReview;
use App\Models\User;
use Carbon\Carbon;

/**
 * Decides the initial status for a freshly submitted review. Returns
 * 'flagged' when any heuristic trips so the row sits invisible until
 * an admin reviews it; otherwise 'visible' (auto-publish per locked
 * product decision).
 *
 * Trigger heuristics:
 *   - velocity: same agent received > 5 reviews in the last 24 hours
 *     across all clients. Catches review-bombing / coordinated attacks.
 *   - young account: the reviewing client signed up less than 14 days
 *     ago. Catches throwaway accounts created to rate-bomb.
 */
class ReviewAntiAbuseService
{
    private const VELOCITY_AGENT_LIMIT = 5;
    private const VELOCITY_WINDOW_HOURS = 24;
    private const YOUNG_ACCOUNT_DAYS = 14;

    public function initialStatus(int $clientUserId, int $agentUserId): string
    {
        if ($this->velocitySpike($agentUserId)) {
            return 'flagged';
        }
        if ($this->youngClient($clientUserId)) {
            return 'flagged';
        }
        return 'visible';
    }

    private function velocitySpike(int $agentUserId): bool
    {
        return AgentReview::where('agent_user_id', $agentUserId)
            ->where('created_at', '>=', Carbon::now()->subHours(self::VELOCITY_WINDOW_HOURS))
            ->count() >= self::VELOCITY_AGENT_LIMIT;
    }

    private function youngClient(int $clientUserId): bool
    {
        $createdAt = User::where('id', $clientUserId)->value('created_at');
        if (!$createdAt) {
            return false;
        }
        return Carbon::parse($createdAt)->diffInDays(Carbon::now()) < self::YOUNG_ACCOUNT_DAYS;
    }
}
