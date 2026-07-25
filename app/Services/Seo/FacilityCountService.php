<?php

namespace App\Services\Seo;

use App\Console\Commands\ComputeFacilityCounts;
use App\Models\Facility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE within-radius listing-count query for "near {facility}" SEO pages,
 * extracted from ComputeFacilityCounts so that three consumers execute the
 * IDENTICAL predicate and can never drift:
 *
 *   1. the nightly seo:compute-facility-counts command (full rebuild)
 *   2. the admin create-form preview ("14 listings within 1.5 km — clears
 *      the ≥10 floor" BEFORE saving a new facility)
 *   3. the admin single-facility recompute (seconds instead of a full run,
 *      so a newly added school's page counts exist without waiting for 04:00)
 *
 * The predicate MIRRORS the live public listings query
 * (Listing::publiclyListed()->filter()->nearPoint(), ListingController@index)
 * EXACTLY so the precomputed badge matches the on-page "X Results":
 * visibility public + not flagged, agent-status gate, all soft-delete tables
 * excluded (listings/properties/property_attributes), and NO property.status
 * filter (the public browse leaves active_only opt-in, so it shows all
 * statuses). For Sale / For Rent only (Foreclosure has no URL slug in the
 * frontend).
 */
class FacilityCountService
{
    /**
     * Within-radius listing counts grouped by category × type for one point.
     * Bounding-box prefilter + haversine refine.
     *
     * @param int $minListings HAVING floor. Writes use the command's canonical
     *                         MIN_LISTINGS; the admin preview passes 1 so
     *                         below-floor cohorts are still visible ("6 — this
     *                         page won't go live").
     */
    public function cohortCounts(float $lat, float $lng, ?int $minListings = null): Collection
    {
        $minListings ??= ComputeFacilityCounts::MIN_LISTINGS;
        $radiusKm = ComputeFacilityCounts::RADIUS_KM;
        $latDelta = abs($radiusKm / 111.045);
        $cos = cos(deg2rad($lat));
        $lngDelta = abs($radiusKm / (111.045 * (abs($cos) < 1e-9 ? 1e-9 : $cos)));

        $latExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties.geo_coordinates, '$.lat')) AS DECIMAL(12,8))";
        $lngExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties.geo_coordinates, '$.lng')) AS DECIMAL(12,8))";

        return DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            // Agent-status gate: mirror Listing::scopePubliclyListed()'s
            // whereHas('agent', active) so an inactive/resigned/deactivated (or
            // soft-deleted) agent's listings leave the near-facility counts too.
            // INNER JOIN on the PK (agent_id NOT NULL + FK) never fans out.
            ->join('agents', function ($j) {
                $j->on('agents.id', '=', 'listings.agent_id')
                  ->where('agents.status', 'active')
                  ->whereNull('agents.deleted_at');
            })
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            // Exclude soft-deleted rows on every SoftDeletes table the live
            // Eloquent query filters via the trait + whereHas chain. The raw
            // DB::table query bypasses the trait, so without these it counted
            // deleted listings — the main source of the badge over-count
            // (e.g. 84 vs the page's 65 near SM J Mall).
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            ->whereNull('property_attributes.deleted_at')
            ->where('listings.visibility', 'public')
            ->where(function ($q) {
                $q->whereNull('listings.verification_status')
                  ->orWhere('listings.verification_status', '!=', 'flagged');
            })
            // No property.status filter: the public browse/search index counts
            // all statuses (active_only is opt-in and not passed there), so the
            // badge must too. Filtering status=active here under-represented the
            // live page in the other direction.
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
            ->having('total', '>=', $minListings)
            ->get();
    }

    /**
     * Pre-save verdict for the admin facility form: every cohort (floor 1, so
     * thin cohorts are visible) + whether any cohort clears the sitemap floor.
     */
    public function previewCounts(float $lat, float $lng): array
    {
        $cohorts = $this->cohortCounts($lat, $lng, 1);
        $maxTotal = (int) $cohorts->max('total');

        return [
            'cohorts'      => $cohorts->values(),
            'max_total'    => $maxTotal,
            'floor'        => ComputeFacilityCounts::MIN_LISTINGS,
            'radius_km'    => ComputeFacilityCounts::RADIUS_KM,
            'clears_floor' => $maxTotal >= ComputeFacilityCounts::MIN_LISTINGS,
        ];
    }

    /**
     * Build the floor-gated facility_listing_counts insert rows for one
     * facility. Shared by the nightly full rebuild and recomputeFacility().
     */
    public function rowsFor(Facility $facility, Carbon $computedAt): array
    {
        $rows = [];
        foreach ($this->cohortCounts((float) $facility->lat, (float) $facility->lng) as $c) {
            $rows[] = [
                'facility_id'       => $facility->id,
                'facility_slug'     => $facility->slug,
                'facility_name'     => $facility->name,
                // Denormalized so the frontend search index (which reads only
                // /sitemap/facility-counts) can match former names. Raw insert
                // bypasses the model cast, so encode explicitly.
                'aliases'           => $facility->aliases ? json_encode($facility->aliases) : null,
                'facility_category' => $facility->category,
                'city'              => $facility->city,
                'province'          => $facility->province,
                'category'          => $c->category,
                'type'              => $c->type,
                'total'             => (int) $c->total,
                'computed_at'       => $computedAt,
            ];
        }

        return $rows;
    }

    /**
     * Recompute ONE facility's count rows in place (delete its rows, insert
     * fresh) — seconds, versus minutes for the full nightly rebuild. Used by
     * the admin page right after adding/editing a facility so its pages can
     * go live without waiting for 04:00. Inactive/ungeocoded facilities get
     * their rows removed and nothing re-inserted (mirrors the nightly query's
     * active()->geocoded() scope).
     *
     * @return int number of cohort rows written
     */
    public function recomputeFacility(Facility $facility): int
    {
        $eligible = $facility->is_active && $facility->lat !== null && $facility->lng !== null;
        $rows = $eligible ? $this->rowsFor($facility, Carbon::now()) : [];

        DB::transaction(function () use ($facility, $rows) {
            DB::table('facility_listing_counts')->where('facility_id', $facility->id)->delete();
            if ($rows !== []) {
                DB::table('facility_listing_counts')->insert($rows);
            }
        });

        return count($rows);
    }
}
