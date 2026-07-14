<?php

namespace App\Console\Commands;

use App\Support\MetroAreaRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the market-stats snapshot (median/average price, median
 * price-per-sqm, listing counts) per (category × property type ×
 * city|province [× bedroom count]) for the market-stats module on typed
 * location money pages. Province cohorts additionally split into
 * `province_urban` / `province_rural` segment scopes, classified per city
 * via {@see MetroAreaRegistry::classify()}, so metro pricing isn't blended
 * into one province-wide median.
 *
 * Monthly snapshot semantics: only the CURRENT month's rows are replaced;
 * prior months are kept so the frontend can show month-over-month movement
 * from the second month onward.
 *
 * Segment rows are pair-gated: an urban (or rural) cohort is stored only
 * when both it AND its counterpart have ≥ MIN_SEGMENT_LISTING_COUNT priced
 * listings, so a province without a real second side (an all-urban metro,
 * an all-rural province) emits no segment rows. That count is a storage
 * floor; display floors stay frontend-owned. Base city/province cohorts
 * keep the ≥ 1 behavior.
 *
 * Effective city = COALESCE(properties.geo_city_id, address barangay's
 * city_id) — identical to the `city_id` filter in Listing::scopeFilter and
 * the locationCounts aggregate, so stats match on-page result counts.
 * Price sanity floors mirror the frontend's price-range guards (sale ≥
 * ₱100K, rent ≥ ₱1K) so placeholder prices don't poison the medians.
 */
class ComputeMarketStats extends Command
{
    protected $signature = 'seo:compute-market-stats';

    protected $description = 'Recompute monthly market stats (median/avg price, ppsqm) per city/province cohort.';

    private const SALE_PRICE_FLOOR = 100_000;
    private const RENT_PRICE_FLOOR = 1_000;
    private const MIN_FLOOR_AREA_SQM = 10;

    /** Storage floor per urban/rural segment side (see pairing guard below). */
    private const MIN_SEGMENT_LISTING_COUNT = 3;

    public function handle(): int
    {
        $now = Carbon::now();
        $month = $now->copy()->startOfMonth()->toDateString();

        // Raw rows under the same publicly-listed predicate as the other
        // seo:compute-* aggregates. Aggregation happens in PHP (a median
        // needs the full value list; ~15K rows fits comfortably in memory).
        $rows = DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->leftJoin('barangays as addr_b', 'addr_b.id', '=', 'properties.address_id')
            ->join('cities', 'cities.id', '=', DB::raw(
                'COALESCE(properties.geo_city_id, addr_b.city_id)'
            ))
            ->join('provinces', 'provinces.id', '=', 'cities.province_id')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            ->whereNull('property_attributes.deleted_at')
            ->where('listings.visibility', 'public')
            ->where(function ($q) {
                $q->whereNull('listings.verification_status')
                    ->orWhere('listings.verification_status', '!=', 'flagged');
            })
            ->whereIn('categories.name', ['For Sale', 'For Rent'])
            ->whereNotNull('listings.price')
            ->select(
                'categories.name as category',
                'property_types.name as type',
                'cities.id as city_id',
                'cities.name as city',
                'cities.type as city_type',
                'provinces.id as province_id',
                'provinces.name as province',
                'listings.price',
                'property_attributes.floor_area',
                'property_attributes.bedroom_count',
            )
            ->get();

        // cohortKey => ['meta' => [...], 'prices' => [], 'ppsqm' => []]
        $cohorts = [];
        $add = function (array $meta, string $key, float $price, ?float $ppsqm) use (&$cohorts) {
            if (! isset($cohorts[$key])) {
                $cohorts[$key] = ['meta' => $meta, 'prices' => [], 'ppsqm' => []];
            }
            $cohorts[$key]['prices'][] = $price;
            if ($ppsqm !== null) {
                $cohorts[$key]['ppsqm'][] = $ppsqm;
            }
        };

        foreach ($rows as $r) {
            $price = (float) $r->price;
            $floor = $r->category === 'For Sale' ? self::SALE_PRICE_FLOOR : self::RENT_PRICE_FLOOR;
            if ($price < $floor) {
                continue;
            }

            $area = (float) ($r->floor_area ?? 0);
            $ppsqm = $area >= self::MIN_FLOOR_AREA_SQM ? $price / $area : null;

            // Bedroom segment rows only where the count is meaningful: the
            // frontend links them to the 1–4 bedroom subtype pages, and only
            // Condominium/House carry real bedroom data.
            $bedroom = (int) ($r->bedroom_count ?? 0);
            $bedroomSegment = in_array($r->type, ['Condominium', 'House'], true)
                && $bedroom >= 1 && $bedroom <= 4
                ? $bedroom
                : null;

            $cityMeta = [
                'category' => $r->category, 'type' => $r->type, 'scope' => 'city',
                'city_id' => (int) $r->city_id, 'city' => $r->city,
                'province_id' => (int) $r->province_id, 'province' => $r->province,
            ];
            $provMeta = [
                'category' => $r->category, 'type' => $r->type, 'scope' => 'province',
                'city_id' => null, 'city' => null,
                'province_id' => (int) $r->province_id, 'province' => $r->province,
            ];

            // Urban/rural segmentation of the province cohort. classify()
            // returns null for junk POI rows (cities.type = 2), which stay
            // out of segments but still count toward city/province.
            $segment = MetroAreaRegistry::classify($r->province, $r->city, (int) $r->city_type);

            $targets = [['c', $cityMeta], ['p', $provMeta]];
            if ($segment !== null) {
                $targets[] = [
                    $segment === 'urban' ? 'pu' : 'pr',
                    array_merge($provMeta, ['scope' => "province_{$segment}"]),
                ];
            }

            foreach ($targets as [$tag, $meta]) {
                $geo = $tag === 'c' ? $r->city_id : $r->province_id;
                $base = "{$r->category}|{$r->type}|{$tag}|{$geo}";
                $add($meta + ['bedroom_count' => null], "$base|all", $price, $ppsqm);
                if ($bedroomSegment !== null) {
                    $add(
                        $meta + ['bedroom_count' => $bedroomSegment],
                        "$base|$bedroomSegment",
                        $price,
                        $ppsqm,
                    );
                }
            }
        }

        $median = function (array $values): float {
            sort($values);
            $n = count($values);
            $mid = intdiv($n, 2);

            return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
        };

        $inserts = [];
        foreach ($cohorts as $key => $cohort) {
            $prices = $cohort['prices'];
            if (count($prices) === 0) {
                continue;
            }
            // Pairing guard: an urban/rural segment only means anything as a
            // pair, so a side is stored only when it AND its counterpart both
            // clear the segment floor. Single-sided provinces self-suppress —
            // no hardcoded exception list.
            $scope = $cohort['meta']['scope'];
            if (str_starts_with($scope, 'province_')) {
                if (count($prices) < self::MIN_SEGMENT_LISTING_COUNT) {
                    continue;
                }
                $counterpartKey = $scope === 'province_urban'
                    ? str_replace('|pu|', '|pr|', $key)
                    : str_replace('|pr|', '|pu|', $key);
                $counterpart = $cohorts[$counterpartKey]['prices'] ?? [];
                if (count($counterpart) < self::MIN_SEGMENT_LISTING_COUNT) {
                    continue;
                }
            }
            $ppsqm = $cohort['ppsqm'];
            $inserts[] = $cohort['meta'] + [
                'month' => $month,
                'listing_count' => count($prices),
                'median_price' => round($median($prices), 2),
                'avg_price' => round(array_sum($prices) / count($prices), 2),
                'median_ppsqm' => count($ppsqm) > 0 ? round($median($ppsqm), 2) : null,
                'ppsqm_count' => count($ppsqm),
                'computed_at' => $now,
            ];
        }

        DB::transaction(function () use ($inserts, $month) {
            // Replace only the current month; prior months are the history
            // that powers month-over-month display.
            DB::table('market_stats')->where('month', $month)->delete();
            foreach (array_chunk($inserts, 500) as $chunk) {
                DB::table('market_stats')->insert($chunk);
            }
        });

        $this->info(sprintf('Wrote %d market-stat row(s) for %s.', count($inserts), $month));

        return self::SUCCESS;
    }
}
