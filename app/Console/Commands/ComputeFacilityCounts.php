<?php

namespace App\Console\Commands;

use App\Models\Facility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;

/**
 * Recomputes, per geocoded facility, how many public+active listings fall within
 * the radius — grouped by (category × property type), gated at MIN_LISTINGS.
 * Writes to `facility_listing_counts` so the sitemap + gating read a precomputed
 * table instead of running the unindexed radius scan per request. Scheduled
 * daily; mirrors `seo:compute-modifier-thresholds`.
 */
class ComputeFacilityCounts extends Command
{
    protected $signature = 'seo:compute-facility-counts';

    protected $description = 'Recompute per-facility nearby-listing counts for "near {facility}" SEO pages.';

    private const RADIUS_KM = 1.5;

    private const MIN_LISTINGS = 10;

    public function handle(): int
    {
        $now = Carbon::now();
        $facilities = Facility::query()->active()->geocoded()->get();

        $rows = [];
        foreach ($facilities as $f) {
            foreach ($this->cohortCounts((float) $f->lat, (float) $f->lng) as $c) {
                $rows[] = [
                    'facility_id'       => $f->id,
                    'facility_slug'     => $f->slug,
                    'facility_name'     => $f->name,
                    'facility_category' => $f->category,
                    'city'              => $f->city,
                    'province'          => $f->province,
                    'category'          => $c->category,
                    'type'              => $c->type,
                    'total'             => (int) $c->total,
                    'computed_at'       => $now,
                ];
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

    /**
     * Within-radius listing counts grouped by category × type for one facility.
     * Bounding-box prefilter + haversine refine; public/active predicate mirrors
     * SitemapController::locationCounts. For Sale / For Rent only (Foreclosure
     * has no URL slug in the frontend).
     */
    private function cohortCounts(float $lat, float $lng)
    {
        $radiusKm = self::RADIUS_KM;
        $latDelta = abs($radiusKm / 111.045);
        $cos = cos(deg2rad($lat));
        $lngDelta = abs($radiusKm / (111.045 * (abs($cos) < 1e-9 ? 1e-9 : $cos)));

        $latExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties.geo_coordinates, '$.lat')) AS DECIMAL(12,8))";
        $lngExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties.geo_coordinates, '$.lng')) AS DECIMAL(12,8))";

        return DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->where('listings.visibility', 'public')
            ->where(function ($q) {
                $q->whereNull('listings.verification_status')
                  ->orWhere('listings.verification_status', '!=', 'flagged');
            })
            ->where('properties.status', 'active')
            ->whereIn('categories.name', ['For Sale', 'For Rent'])
            ->whereRaw("$latExpr BETWEEN ? AND ?", [$lat - $latDelta, $lat + $latDelta])
            ->whereRaw("$lngExpr BETWEEN ? AND ?", [$lng - $lngDelta, $lng + $lngDelta])
            ->whereRaw(
                "(6371 * acos(LEAST(1.0, GREATEST(-1.0, "
                . "cos(radians(?)) * cos(radians($latExpr)) * cos(radians($lngExpr) - radians(?)) "
                . "+ sin(radians(?)) * sin(radians($latExpr)))))) <= ?",
                [$lat, $lng, $lat, $radiusKm]
            )
            ->select(
                'categories.name as category',
                'property_types.name as type',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('categories.name', 'property_types.name')
            ->having('total', '>=', self::MIN_LISTINGS)
            ->get();
    }
}
