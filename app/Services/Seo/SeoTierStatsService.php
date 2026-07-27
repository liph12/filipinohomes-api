<?php

namespace App\Services\Seo;

use App\Console\Commands\ComputeFacilityCounts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-tier inventory stats for the programmatic-SEO estate: row counts,
 * URL-eligible counts (floor applied), and freshness (MAX computed_at).
 * Shared by two consumers so the live dashboard and the nightly snapshot
 * can never disagree:
 *
 *   - SeoInventoryController@overview (cached 10 min) — the admin Overview tile
 *   - seo:snapshot-tiers — persists the same numbers into seo_tier_snapshots
 *     for day-over-day deltas
 *
 * Floors shown here reference the compute commands' public consts. The
 * barangay sitemap floor is FRONTEND-owned (see BARANGAY_SITEMAP_FLOOR).
 */
class SeoTierStatsService
{
    /**
     * The barangay tier stores every total ≥ 1; the ≥10 sitemap floor is
     * applied by the FRONTEND (src/lib/barangays.ts + the sitemap-barangays
     * shard both use 10). Displayed read-only — changing it is a code change
     * in the frontend repo, deliberately NOT an admin knob.
     */
    public const BARANGAY_SITEMAP_FLOOR = 10;

    /** @return array<int, array> one entry per tier */
    public function tiers(): array
    {
        return [
            $this->facilityTier(),
            $this->barangayTier(),
            $this->marketStatsTier(),
            $this->modifierTier(),
        ];
    }

    /**
     * Raw MAX(computed_at) from DB::table() is a bare "Y-m-d H:i:s" string
     * (UTC, no timezone designator) — JS `new Date()` treats that as LOCAL
     * time (8h skew for PH admins) and WebKit rejects it outright. Emit
     * ISO 8601 so every consumer parses it unambiguously.
     */
    private function iso(?string $raw): ?string
    {
        return $raw ? Carbon::parse($raw)->toIso8601String() : null;
    }

    private function facilityTier(): array
    {
        // Rows are floor-gated at write time (HAVING >= MIN_LISTINGS), so
        // every row is one URL-eligible (category × type × facility) cohort.
        $agg = DB::table('facility_listing_counts')
            ->selectRaw('COUNT(*) as row_count, MAX(computed_at) as last_computed_at')
            ->first();

        return [
            'key'              => 'facilities',
            'label'            => 'Near-facility pages',
            'command'          => 'seo:compute-facility-counts',
            'row_count'        => (int) ($agg->row_count ?? 0),
            'eligible_count'   => (int) ($agg->row_count ?? 0),
            'last_computed_at' => $this->iso($agg->last_computed_at),
            'floor'            => ComputeFacilityCounts::MIN_LISTINGS,
            'radius_km'        => ComputeFacilityCounts::RADIUS_KM,
            'note'             => 'Each row is one live "near {facility}" page cohort (already floor-gated at compute time).',
        ];
    }

    private function barangayTier(): array
    {
        $agg = DB::table('barangay_listing_counts')
            ->selectRaw('COUNT(*) as row_count, MAX(computed_at) as last_computed_at')
            ->first();
        $eligible = DB::table('barangay_listing_counts')
            ->where('total', '>=', self::BARANGAY_SITEMAP_FLOOR)
            ->count();

        return [
            'key'              => 'barangays',
            'label'            => 'Barangay pages',
            'command'          => 'seo:compute-barangay-counts',
            'row_count'        => (int) ($agg->row_count ?? 0),
            'eligible_count'   => $eligible,
            'last_computed_at' => $this->iso($agg->last_computed_at),
            'floor'            => self::BARANGAY_SITEMAP_FLOOR,
            'radius_km'        => null,
            'note'             => 'All totals ≥1 are stored; the ≥' . self::BARANGAY_SITEMAP_FLOOR . ' sitemap floor is applied by the frontend.',
        ];
    }

    private function marketStatsTier(): array
    {
        $agg = DB::table('market_stats')
            ->selectRaw('COUNT(*) as row_count, MAX(computed_at) as last_computed_at, MAX(month) as latest_month')
            ->first();
        $latestMonthRows = $agg->latest_month
            ? DB::table('market_stats')->where('month', $agg->latest_month)->count()
            : 0;
        // Market stats own no standalone URLs — they're embedded blocks on
        // money pages. "Eligible" = how many live stat blocks exist this
        // month: one per (scope × location × category × type) cohort (rows
        // additionally split by bedroom_count, hence rows > cohorts). A null
        // here rendered as "—" and read like the run wasn't visible.
        $liveBlocks = $agg->latest_month
            ? (int) DB::table('market_stats')
                ->where('month', $agg->latest_month)
                ->selectRaw('COUNT(DISTINCT scope, city_id, province_id, category, type) as c')
                ->value('c')
            : 0;

        return [
            'key'              => 'market_stats',
            'label'            => 'Market statistics',
            'command'          => 'seo:compute-market-stats',
            'row_count'        => $latestMonthRows,
            'eligible_count'   => $liveBlocks,
            'last_computed_at' => $this->iso($agg->last_computed_at),
            'floor'            => null,
            'radius_km'        => null,
            'note'             => 'Embedded on money pages (no standalone URLs) — eligible = live stat blocks this month (location × type × scope); rows split further by bedrooms.',
        ];
    }

    private function modifierTier(): array
    {
        $agg = DB::table('modifier_price_thresholds')
            ->selectRaw('COUNT(*) as row_count, MAX(computed_at) as last_computed_at')
            ->first();

        return [
            'key'              => 'modifiers',
            'label'            => 'Affordable thresholds',
            'command'          => 'seo:compute-modifier-thresholds',
            'row_count'        => (int) ($agg->row_count ?? 0),
            // Every stored row IS a live "affordable" ceiling cohort (the
            // compute already applies its ≥40-sample gate); the page itself
            // additionally needs ≥10 matching listings (query-counts floor).
            'eligible_count'   => (int) ($agg->row_count ?? 0),
            'last_computed_at' => $this->iso($agg->last_computed_at),
            'floor'            => null,
            'radius_km'        => null,
            'note'             => 'Each row is one "affordable" ceiling cohort; the live page additionally needs ≥10 matching listings (query-counts floor).',
        ];
    }
}
