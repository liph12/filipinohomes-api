<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentReview;
use Illuminate\Support\Facades\DB;

/**
 * Keeps agents.avg_rating + agents.total_reviews in sync with the
 * visible rows on agent_reviews. Called from AgentReview::saved /
 * deleted boot hooks. Single grouped aggregate per agent, cheap
 * enough to run inline on each write.
 */
class AgentRatingRollupService
{
    public function recomputeFor(int $agentUserId): void
    {
        $row = AgentReview::query()
            ->selectRaw('AVG(overall_rating) as avg_r, COUNT(*) as total')
            ->where('agent_user_id', $agentUserId)
            ->where('status', 'visible')
            ->first();

        $avg = $row?->avg_r !== null ? round((float) $row->avg_r, 2) : null;
        $total = (int) ($row?->total ?? 0);

        // The agents.user_id column links to users.id. Updating in bulk
        // is fine — at most one agent row per user_id.
        Agent::where('user_id', $agentUserId)->update([
            'avg_rating' => $avg,
            'total_reviews' => $total,
        ]);
    }
}
