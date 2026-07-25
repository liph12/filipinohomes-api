<?php

namespace App\Console\Commands;

use App\Services\Seo\SeoTierStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persists today's per-tier inventory numbers (via SeoTierStatsService — the
 * same aggregates the admin Overview shows live) into seo_tier_snapshots.
 * Scheduled right after the nightly compute pipeline so each snapshot
 * reflects that night's rebuild. History powers day-over-day deltas —
 * "facilities tier lost 120 URLs since yesterday" — the regression alarm the
 * July 2026 phantom-shard incident showed we were missing.
 *
 * Upserts on (snapshot_date, tier), so manual re-runs on the same day are
 * safe and simply refresh today's row.
 */
class SnapshotSeoTiers extends Command
{
    protected $signature = 'seo:snapshot-tiers';

    protected $description = 'Record per-tier SEO inventory counts for day-over-day delta tracking.';

    public function handle(SeoTierStatsService $stats): int
    {
        $today = now()->toDateString();
        $written = 0;

        foreach ($stats->tiers() as $tier) {
            DB::table('seo_tier_snapshots')->updateOrInsert(
                ['snapshot_date' => $today, 'tier' => $tier['key']],
                [
                    'row_count'        => $tier['row_count'],
                    'eligible_count'   => $tier['eligible_count'],
                    // tiers() emits ISO 8601 for JS consumers; MySQL's
                    // timestamp column wants Y-m-d H:i:s — normalize back.
                    'last_computed_at' => $tier['last_computed_at']
                        ? Carbon::parse($tier['last_computed_at'])->toDateTimeString()
                        : null,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]
            );
            $written++;
        }

        $this->info("Snapshotted {$written} tier(s) for {$today}.");

        return self::SUCCESS;
    }
}
