<?php

namespace App\Services\Seo;

use Cron\CronExpression;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for the SEO pipeline commands: which artisan
 * commands exist, when they're scheduled, and which derived table each
 * feeds. Read by three consumers so none of them can drift:
 *
 *   - routes/console.php      → iterates scheduled() to register the crons
 *   - SeoCommandController    → whitelist for manual triggers + next-run times
 *   - SeoInventoryController  → freshness/floor display on the admin overview
 *
 * Adding a 5th compute command = one entry here; scheduling, the API
 * whitelist, next-run display, and the overview all pick it up with zero
 * edits elsewhere. (Before this class the cron times lived only as literals
 * in routes/console.php — the same class of duplication that caused the
 * July 2026 sitemap floor-drift incident.)
 *
 * Floors/radius reference the commands' own public consts — never copies.
 */
class SeoCommandRegistry
{
    public const COMMANDS = [
        'seo:compute-modifier-thresholds' => [
            'cron'        => '30 3 * * *',
            'label'       => 'Affordable thresholds',
            'description' => 'Percentile price ceilings behind the "affordable" modifier pages, per city and province.',
            'table'       => 'modifier_price_thresholds',
        ],
        'seo:compute-facility-counts' => [
            'cron'        => '0 4 * * *',
            'label'       => 'Near-facility counts',
            'description' => 'Nearby-listing counts per curated facility (1.5 km radius) — drives the "near {facility}" pages and their sitemap shard.',
            'table'       => 'facility_listing_counts',
        ],
        'seo:compute-barangay-counts' => [
            'cron'        => '30 4 * * *',
            'label'       => 'Barangay counts',
            'description' => 'Listing counts per barangay — drives the barangay location pages and their sitemap shard.',
            'table'       => 'barangay_listing_counts',
        ],
        'seo:compute-market-stats' => [
            'cron'        => '0 5 * * *',
            'label'       => 'Market stats',
            'description' => 'Monthly median/average price aggregates for the market-statistics blocks on money pages.',
            'table'       => 'market_stats',
        ],
        'seo:snapshot-tiers' => [
            'cron'        => '30 5 * * *',
            'label'       => 'Tier inventory snapshot',
            'description' => 'Records per-tier URL/row counts after the nightly computes so day-over-day deltas are trackable.',
            'table'       => 'seo_tier_snapshots',
        ],
        'facilities:geocode-missing' => [
            'cron'        => null, // on-demand only
            'label'       => 'Geocode missing facilities',
            'description' => 'Fills lat/lng for facilities without coordinates via Google Geocoding (~$0.005 per facility).',
            'table'       => null,
        ],
        'facilities:scan-candidates' => [
            'cron'        => null, // on-demand only (review-queue workflow)
            'label'       => 'Scan facility candidates (OSM)',
            'description' => 'Discovers named malls/universities/hospitals on OpenStreetMap in every city with listings, scores each against the ≥10-listings floor, and fills the Candidates review queue. Resumable — repeat runs continue where the last stopped.',
            'table'       => 'facility_candidates',
        ],
    ];

    /** @return array<string, array> every registered command keyed by signature */
    public static function all(): array
    {
        return self::COMMANDS;
    }

    /** @return array<string, array> only commands with a cron expression */
    public static function scheduled(): array
    {
        return array_filter(self::COMMANDS, fn (array $meta) => $meta['cron'] !== null);
    }

    /** Whitelist gate for manual triggers — never pass client strings to Artisan unchecked. */
    public static function isRunnable(string $command): bool
    {
        return array_key_exists($command, self::COMMANDS);
    }

    public static function meta(string $command): ?array
    {
        return self::COMMANDS[$command] ?? null;
    }

    /** Next scheduled run in app timezone; null for on-demand-only commands. */
    public static function nextRunAt(string $command): ?Carbon
    {
        $cron = self::COMMANDS[$command]['cron'] ?? null;
        if ($cron === null) {
            return null;
        }

        $tz = new \DateTimeZone(config('app.timezone'));

        return Carbon::instance(
            (new CronExpression($cron))->getNextRunDate('now', 0, false, $tz->getName())
        );
    }
}
