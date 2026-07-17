<?php

namespace App\Services\Sitemap;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Computes cohort-relative price thresholds for the "affordable" programmatic-SEO
 * modifier and persists them to `modifier_price_thresholds`.
 *
 * Affordability is defined PER (category × property type × city) cohort, not as a
 * single peso figure, because price scales differ wildly by location and type. For
 * each cohort with enough inventory we take the Nth-percentile price (default 35th)
 * of the valid, public, active listings and store it as the ceiling. The
 * "affordable" page for that cohort is then simply the cohort filtered with
 * price_max = percentile_price.
 *
 * A second pass computes the same thresholds one level up, per
 * (category × property type × province) cohort. Province rows use the
 * city_id = 0 sentinel with an empty city label so consumers that match by
 * city slug skip them transparently.
 *
 * Guardrails:
 *  - MIN_SAMPLE: skip cohorts with too few valid-priced listings (a percentile
 *    below this is statistical noise → no affordable page is generated).
 *  - PRICE_FLOOR: ignore obviously-bad rows (₱0/typos) so they can't drag the
 *    percentile down. High outliers do not affect a low percentile, so no upper
 *    trim is needed.
 */
class ModifierThresholdService
{
    /** Percentile used for "affordable" (0.35 = below-typical, not merely median). */
    private const PERCENTILE = 0.35;

    /** Minimum valid-priced cohort size before a threshold is trustworthy. */
    private const MIN_SAMPLE = 40;

    /** Absolute sane minimum price; anything below is treated as a data error. */
    private const PRICE_FLOOR = 50000;

    /**
     * Per-property-type plausibility band for the affordable ceiling (₱). If the
     * computed percentile falls outside [min, max] the cohort is skipped — its
     * pricing is skewed (luxury-dominated, e.g. Parañaque condos at ₱27.5M, or
     * contaminated with down-payment/reservation amounts, e.g. ₱400k "houses"),
     * so an "affordable" page would mislead. Commercial is intentionally absent:
     * "affordable commercial property" is not a meaningful consumer search.
     * Keyed by property type name.
     */
    private const PRICE_BANDS = [
        'Condominium' => [800000, 8000000],
        'House'       => [900000, 9000000],
        'Land'        => [500000, 10000000],
    ];

    /**
     * Recompute every qualifying cohort and replace the table contents.
     *
     * @return array{cohorts_scanned:int, thresholds_written:int}
     */
    public function recompute(): array
    {
        $now = Carbon::now();

        // 1) Candidate cohorts: valid-priced public+active listings grouped by
        //    (category, type, city). Keep only cohorts above MIN_SAMPLE.
        $cohorts = $this->baseQuery()
            ->select(
                'listings.category_id as category_id',
                'categories.name as category',
                'property_types.id as property_type_id',
                'property_types.name as type',
                'cities.id as city_id',
                'cities.name as city',
                'provinces.id as province_id',
                'provinces.name as province',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy(
                'listings.category_id', 'categories.name',
                'property_types.id', 'property_types.name',
                'cities.id', 'cities.name',
                'provinces.id', 'provinces.name'
            )
            ->having('total', '>=', self::MIN_SAMPLE)
            ->get();

        $rows = [];
        foreach ($cohorts as $c) {
            $sampleSize = (int) $c->total;
            $price = $this->percentilePriceForCohort(
                (int) $c->category_id,
                (int) $c->property_type_id,
                (int) $c->city_id,
                $sampleSize
            );

            if ($price === null) {
                continue;
            }

            // Plausibility guard: skip cohorts whose percentile is implausible
            // for the property type (data skew). Commercial has no band → always
            // skipped. This is what makes "affordable" trustworthy per cohort.
            $band = self::PRICE_BANDS[$c->type] ?? null;
            if ($band === null || $price < $band[0] || $price > $band[1]) {
                continue;
            }

            $rows[] = [
                'modifier'         => 'affordable',
                'category_id'      => (int) $c->category_id,
                'property_type_id' => (int) $c->property_type_id,
                'city_id'          => (int) $c->city_id,
                'province_id'      => (int) $c->province_id,
                'category'         => $c->category,
                'type'             => $c->type,
                'city'             => $c->city,
                'province'         => $c->province,
                'percentile_price' => $price,
                'sample_size'      => $sampleSize,
                'computed_at'      => $now,
            ];
        }

        // 2) Province cohorts: the same pipeline one level up, grouped by
        //    (category, type, province) with the same MIN_SAMPLE and
        //    plausibility guards. Rows carry the city_id = 0 sentinel and an
        //    empty city label — province modifier pages match by province
        //    slug alone; city-slug consumers skip them.
        $provinceCohorts = $this->baseQuery()
            ->select(
                'listings.category_id as category_id',
                'categories.name as category',
                'property_types.id as property_type_id',
                'property_types.name as type',
                'provinces.id as province_id',
                'provinces.name as province',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy(
                'listings.category_id', 'categories.name',
                'property_types.id', 'property_types.name',
                'provinces.id', 'provinces.name'
            )
            ->having('total', '>=', self::MIN_SAMPLE)
            ->get();

        foreach ($provinceCohorts as $c) {
            $sampleSize = (int) $c->total;
            $price = $this->percentilePriceForProvince(
                (int) $c->category_id,
                (int) $c->property_type_id,
                (int) $c->province_id,
                $sampleSize
            );

            if ($price === null) {
                continue;
            }

            $band = self::PRICE_BANDS[$c->type] ?? null;
            if ($band === null || $price < $band[0] || $price > $band[1]) {
                continue;
            }

            $rows[] = [
                'modifier'         => 'affordable',
                'category_id'      => (int) $c->category_id,
                'property_type_id' => (int) $c->property_type_id,
                'city_id'          => 0,
                'province_id'      => (int) $c->province_id,
                'category'         => $c->category,
                'type'             => $c->type,
                'city'             => '',
                'province'         => $c->province,
                'percentile_price' => $price,
                'sample_size'      => $sampleSize,
                'computed_at'      => $now,
            ];
        }

        // Replace wholesale in a transaction so readers never see a half-rebuilt
        // table. The set is small (only dense cohorts qualify), so truncate+insert
        // is cheaper and simpler than a diff/upsert.
        DB::transaction(function () use ($rows) {
            DB::table('modifier_price_thresholds')->where('modifier', 'affordable')->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('modifier_price_thresholds')->insert($chunk);
            }
        });

        return [
            'cohorts_scanned'    => $cohorts->count() + $provinceCohorts->count(),
            'thresholds_written' => count($rows),
        ];
    }

    /**
     * The Nth-percentile price for one cohort, or null if it can't be computed.
     * Uses an ORDER BY + OFFSET pick (portable across MySQL versions, no window
     * function required) against the trimmed, valid-priced set.
     */
    private function percentilePriceForCohort(int $categoryId, int $typeId, int $cityId, int $sampleSize): ?float
    {
        // Re-count WITH the price floor applied (the candidate total did too, since
        // baseQuery() already floors), so the offset index matches the ordered set.
        $offset = (int) floor(self::PERCENTILE * max($sampleSize - 1, 0));

        $row = $this->baseQuery()
            ->where('listings.category_id', $categoryId)
            ->where('property_types.id', $typeId)
            ->where('cities.id', $cityId)
            ->orderBy('listings.price', 'asc')
            ->offset($offset)
            ->limit(1)
            ->value('listings.price');

        return $row !== null ? (float) $row : null;
    }

    /**
     * Province-scope twin of {@see percentilePriceForCohort}: the Nth-percentile
     * price across ALL cities of one province.
     */
    private function percentilePriceForProvince(int $categoryId, int $typeId, int $provinceId, int $sampleSize): ?float
    {
        $offset = (int) floor(self::PERCENTILE * max($sampleSize - 1, 0));

        $row = $this->baseQuery()
            ->where('listings.category_id', $categoryId)
            ->where('property_types.id', $typeId)
            ->where('provinces.id', $provinceId)
            ->orderBy('listings.price', 'asc')
            ->offset($offset)
            ->limit(1)
            ->value('listings.price');

        return $row !== null ? (float) $row : null;
    }

    /**
     * Base join + public/active/valid-price predicate shared by the cohort scan
     * and the per-cohort percentile pick. Mirrors Listing::scopePubliclyListed
     * (visibility=public, not flagged) + active property + a sane price floor.
     */
    private function baseQuery()
    {
        return DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('barangays', 'barangays.id', '=', 'properties.address_id')
            ->join('cities', 'cities.id', '=', 'barangays.city_id')
            ->join('provinces', 'provinces.id', '=', 'cities.province_id')
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
            // Affordable is a sale-side concept; rent prices are a different
            // scale and "affordable rent" skews misleading. Sale-only for v1.
            ->where('categories.name', 'For Sale')
            ->whereNotNull('listings.price')
            ->where('listings.price', '>=', self::PRICE_FLOOR);
    }
}
