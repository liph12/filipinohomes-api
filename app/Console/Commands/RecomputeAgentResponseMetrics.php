<?php

namespace App\Console\Commands;

use App\Models\Agent;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecomputeAgentResponseMetrics extends Command
{
    protected $signature = 'agents:recompute-response-metrics {--agent= : Limit recomputation to a single agent id}';

    protected $description = 'Recompute response-time metrics (median first reply, SLA hit rate, unanswered rate) for each agent over a rolling 30-day window.';

    private const WINDOW_DAYS = 30;
    private const UNANSWERED_THRESHOLD_DAYS = 7;
    private const SLA_THRESHOLD_SECONDS = 3600;

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(self::WINDOW_DAYS);
        $unansweredThreshold = Carbon::now()->subDays(self::UNANSWERED_THRESHOLD_DAYS);

        $query = Agent::query()->whereNotNull('user_id');
        if ($agentId = $this->option('agent')) {
            $query->where('id', $agentId);
        }

        $processed = 0;
        $query->chunkById(200, function ($agents) use ($cutoff, $unansweredThreshold, &$processed) {
            foreach ($agents as $agent) {
                $this->recomputeForAgent($agent, $cutoff, $unansweredThreshold);
                $processed++;
            }
        });

        $this->info("Recomputed response metrics for {$processed} agent(s).");
        return self::SUCCESS;
    }

    private function recomputeForAgent(Agent $agent, Carbon $cutoff, Carbon $unansweredThreshold): void
    {
        $userId = $agent->user_id;

        $convs = DB::table('conversations as c')
            ->leftJoin('messages as m', function ($join) {
                $join->on('m.conversation_id', '=', 'c.id')
                    ->whereColumn('m.created_at', '>', 'c.reviewed_at')
                    ->whereColumn('m.user_id', '=', 'c.agent_user_id')
                    ->whereIn('m.status', ['active', 'updated']);
            })
            ->where('c.agent_user_id', $userId)
            ->whereNotNull('c.reviewed_at')
            ->where('c.reviewed_at', '>=', $cutoff)
            ->select('c.id', 'c.reviewed_at', DB::raw('MIN(m.created_at) as first_reply_at'))
            ->groupBy('c.id', 'c.reviewed_at')
            ->get();

        $deltas = [];
        $sampleSize = 0;
        $within1h = 0;
        $unanswered = 0;

        foreach ($convs as $conv) {
            $reviewedAt = Carbon::parse($conv->reviewed_at);

            if ($conv->first_reply_at === null) {
                if ($reviewedAt->lessThan($unansweredThreshold)) {
                    $unanswered++;
                    $sampleSize++;
                }
                // else: still in flight (<7d old, no reply yet) — exclude entirely
                continue;
            }

            $secs = (int) $reviewedAt->diffInSeconds(Carbon::parse($conv->first_reply_at));
            $deltas[] = $secs;
            $sampleSize++;
            if ($secs <= self::SLA_THRESHOLD_SECONDS) {
                $within1h++;
            }
        }

        $median = null;
        if (!empty($deltas)) {
            sort($deltas);
            $count = count($deltas);
            $mid = intdiv($count, 2);
            if ($count % 2 === 1) {
                $median = $deltas[$mid];
            } else {
                $median = ($deltas[$mid - 1] + $deltas[$mid]) / 2;
            }
        }

        $agent->forceFill([
            'median_first_response_seconds' => $median !== null ? (int) round($median) : null,
            'within_1h_response_pct'        => $sampleSize > 0
                ? round(100 * $within1h / $sampleSize, 2)
                : null,
            'unanswered_response_pct'       => $sampleSize > 0
                ? round(100 * $unanswered / $sampleSize, 2)
                : null,
            'response_sample_size'          => $sampleSize,
            'response_metrics_window_days'  => self::WINDOW_DAYS,
            'response_metrics_updated_at'   => Carbon::now(),
        ])->save();
    }
}
