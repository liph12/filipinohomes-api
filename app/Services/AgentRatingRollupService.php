<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Support\Facades\DB;

/**
 * Keeps agents.avg_rating + agents.total_reviews in sync with the
 * visible rows on agent_reviews. Called from AgentReview::saved /
 * deleted boot hooks. Single grouped aggregate per agent, cheap
 * enough to run inline on each write.
 *
 * IMPORTANT: aggregates count only reviews authored by users with
 * role.name = 'client'. Reviews left by other agents (peer reviews)
 * and admins are still rendered on the agent profile with the
 * "Inquired as agent" badge for transparency, but they don't count
 * toward the public headline avg / total or the leaderboards. That
 * keeps the score a signal of *client* experience rather than peer
 * collegiality. The list-only inclusion preserves disclosure; the
 * count exclusion protects the score from peer review-bombing.
 */
class AgentRatingRollupService
{
    public function recomputeFor(int $agentUserId): void
    {
        $row = DB::table('agent_reviews as r')
            ->join('users as u', 'u.id', '=', 'r.client_user_id')
            ->leftJoin('roles as ro', 'ro.id', '=', 'u.role_id')
            ->where('r.agent_user_id', $agentUserId)
            ->where('r.status', 'visible')
            ->where('ro.name', 'client')
            ->selectRaw('AVG(r.overall_rating) as avg_r, COUNT(*) as total')
            ->first();

        $avg = $row?->avg_r !== null ? round((float) $row->avg_r, 2) : null;
        $total = (int) ($row?->total ?? 0);

        Agent::where('user_id', $agentUserId)->update([
            'avg_rating' => $avg,
            'total_reviews' => $total,
        ]);
    }
}
