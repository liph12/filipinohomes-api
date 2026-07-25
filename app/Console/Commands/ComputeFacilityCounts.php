<?php

namespace App\Console\Commands;

use App\Models\Facility;
use App\Services\Seo\FacilityCountService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;

/**
 * Recomputes, per geocoded facility, how many public+active listings fall within
 * the radius — grouped by (category × property type), gated at MIN_LISTINGS.
 * Writes to `facility_listing_counts` so the sitemap + gating read a precomputed
 * table instead of running the unindexed radius scan per request. Scheduled
 * daily; mirrors `seo:compute-modifier-thresholds`.
 *
 * The radius-count query itself lives in FacilityCountService so this nightly
 * rebuild, the admin create-form preview, and the admin single-facility
 * recompute all execute the identical predicate (no preview-vs-nightly drift).
 */
class ComputeFacilityCounts extends Command
{
    protected $signature = 'seo:compute-facility-counts';

    protected $description = 'Recompute per-facility nearby-listing counts for "near {facility}" SEO pages.';

    // Public (not private) on purpose: the admin SEO overview displays these
    // and FacilityCountService/SeoCommandRegistry reference them — always the
    // real constants, never copies (the July 2026 floor-drift rule).
    public const RADIUS_KM = 1.5;

    public const MIN_LISTINGS = 10;

    public function handle(FacilityCountService $counts): int
    {
        $now = Carbon::now();
        $facilities = Facility::query()->active()->geocoded()->get();

        $rows = [];
        foreach ($facilities as $f) {
            foreach ($counts->rowsFor($f, $now) as $row) {
                $rows[] = $row;
            }
        }

        DB::transaction(function () use ($rows) {
            DB::table('facility_listing_counts')->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('facility_listing_counts')->insert($chunk);
            }
        });

        $this->info(sprintf(
            'Scanned %d facilit%s; wrote %d cohort count(s).',
            $facilities->count(),
            $facilities->count() === 1 ? 'y' : 'ies',
            count($rows),
        ));

        return self::SUCCESS;
    }
}
