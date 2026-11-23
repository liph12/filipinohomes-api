<?php

namespace App\Services\Listing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The Top 10 certificates an agent has earned: every finished month (from April 2026, when the new
 * website went live) in which they were among the ten agents who created the most listings — the same
 * ranking as Top Listing Creators → Top 10 Agents. The certificates themselves are drawn in the browser
 * (src/utils/agentCertificate.ts); this only says which months, newest first.
 */
class AgentAwardCertificatesService
{
    /** First month with data: April 2026. */
    private const FIRST = '2026-04-01';

    public function __construct(private ListingTopCreatorsService $creators) {}

    /** @return array<int, array{year: int, month: int, rank: int}> */
    public function forAgent(int $agentId): array
    {
        $out = [];
        // Finished months only — the current one's Top 10 can still change.
        $month = now('Asia/Manila')->startOfMonth()->subMonth();
        $first = Carbon::parse(self::FIRST, 'Asia/Manila');

        while ($month->gte($first)) {
            $rank = array_search($agentId, $this->topTen($month), true);
            if ($rank !== false) {
                $out[] = ['year' => $month->year, 'month' => $month->month, 'rank' => $rank + 1];
            }
            $month = $month->subMonth();
        }

        return $out;
    }

    /** Agent ids of one month's Top 10, in rank order. A finished month barely moves: kept a day. */
    private function topTen(Carbon $month): array
    {
        return Cache::remember("top10-agents:{$month->format('Y-m')}:v1", 86400, fn () => array_map(
            fn ($row) => (int) $row['agent_id'],
            $this->creators->topCreators('agent', $month->toDateString(), $month->copy()->endOfMonth()->toDateString(), 10)['data'],
        ));
    }
}
