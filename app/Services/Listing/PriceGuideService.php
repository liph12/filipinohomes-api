<?php

namespace App\Services\Listing;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Price Guide engine: what properties in an area typically list for, from OUR OWN LISTINGS.
 * Today it answers ASA-GPT's "how much is a studio in Lahug?" (PriceCommandService); it can back a
 * public page too.
 *
 * For a city (optionally narrowed to one barangay, and to listings with given amenities) it answers,
 * per For Sale / For Rent, per property type, per unit size ("Studio", "1 Bedroom" … "4+ Bedroom"):
 * how many listings, and the LOW / TYPICAL / HIGH asking price — the 25th percentile, the median and
 * the 75th percentile. Percentiles, not min/max, so one mistyped or wishful price can't define the
 * range; the true lowest and highest ride along. Everything is ASKING prices of live public listings,
 * never a valuation.
 *
 * Same publicly-listed predicate as the other public aggregates (ComputeMarketStats): public
 * visibility, not flagged, active agent, the same price floors so placeholder prices stay out, and the
 * same effective city / barangay rules (a map pin wins over the typed address).
 *
 * Reads are cached for six hours per city; narrowing happens on the cached rows.
 */
class PriceGuideService
{
    public const TTL_SECONDS = 6 * 3600;

    /** A tier (or a type's "All" row) needs this many listings to be shown — fewer is noise, not a range. */
    public const MIN_TIER_LISTINGS = 3;

    /** A city needs this many public listings to get a guide. */
    public const MIN_AREA_LISTINGS = 10;

    private const SALE_PRICE_FLOOR = 100_000;

    private const RENT_PRICE_FLOOR = 1_000;

    private const MIN_FLOOR_AREA_SQM = 10;

    private const CATEGORIES = ['For Sale', 'For Rent'];

    /** Types whose listings carry a meaningful bedroom count (the others get an "All" row only). */
    private const BEDROOM_TYPES = ['Condominium', 'House', 'Apartment'];

    /**
     * Every city with enough public listings for a guide, most listings first.
     *
     * @return array<int, array{city_id:int, city:string, province_id:int, province:string, listings:int, sale_listings:int, rent_listings:int, barangays:int}>
     */
    public function areas(): array
    {
        return Cache::remember('price-guide:areas', self::TTL_SECONDS, function () {
            $rows = $this->baseQuery()
                ->select(
                    'cities.id as city_id',
                    'cities.name as city',
                    'provinces.id as province_id',
                    'provinces.name as province',
                    DB::raw('COUNT(*) as listings'),
                    DB::raw("SUM(CASE WHEN categories.name = 'For Sale' THEN 1 ELSE 0 END) as sale_listings"),
                    DB::raw("SUM(CASE WHEN categories.name = 'For Rent' THEN 1 ELSE 0 END) as rent_listings"),
                    DB::raw('COUNT(DISTINCT eff_b.id) as barangays'),
                )
                ->groupBy('cities.id', 'cities.name', 'provinces.id', 'provinces.name')
                ->having('listings', '>=', self::MIN_AREA_LISTINGS)
                ->orderByDesc('listings')
                ->orderBy('cities.name')
                ->get();

            return $rows->map(fn ($r) => [
                'city_id' => (int) $r->city_id,
                'city' => (string) $r->city,
                'province_id' => (int) $r->province_id,
                'province' => (string) $r->province,
                'listings' => (int) $r->listings,
                'sale_listings' => (int) $r->sale_listings,
                'rent_listings' => (int) $r->rent_listings,
                'barangays' => (int) $r->barangays,
            ])->values()->all();
        });
    }

    /**
     * Every place a question can be about — each city with a guide and each of its barangays with enough
     * listings. Cities first (most listings first), then barangays.
     *
     * @return array<int, array{city_id:int, city:string, province:string, barangay_id:int|null, barangay:string|null, listings:int}>
     */
    public function places(): array
    {
        return Cache::remember('price-guide:places', self::TTL_SECONDS, function () {
            $cities = array_map(fn (array $a) => [
                'city_id' => $a['city_id'], 'city' => $a['city'], 'province' => $a['province'],
                'barangay_id' => null, 'barangay' => null, 'listings' => $a['listings'],
            ], $this->areas());
            $cityIds = array_column($cities, 'city_id');
            if ($cityIds === []) {
                return [];
            }

            $rows = $this->baseQuery()
                ->whereIn('cities.id', $cityIds)
                ->whereNotNull('eff_b.id')
                ->select('cities.id as city_id', 'cities.name as city', 'provinces.name as province', 'eff_b.id as barangay_id', 'eff_b.name as barangay', DB::raw('COUNT(*) as listings'))
                ->groupBy('cities.id', 'cities.name', 'provinces.name', 'eff_b.id', 'eff_b.name')
                ->having('listings', '>=', self::MIN_TIER_LISTINGS)
                ->orderByDesc('listings')
                ->get();

            $barangays = $rows->map(fn ($r) => [
                'city_id' => (int) $r->city_id, 'city' => (string) $r->city, 'province' => (string) $r->province,
                'barangay_id' => (int) $r->barangay_id, 'barangay' => (string) $r->barangay, 'listings' => (int) $r->listings,
            ])->all();

            return array_merge($cities, $barangays);
        });
    }

    /**
     * The guide for one city, or one barangay of it, optionally only listings with every given amenity.
     * Null when the area has no public listings at all.
     *
     * @return array<string, mixed>|null
     */
    public function guide(int $cityId, ?int $barangayId = null, array $amenities = []): ?array
    {
        $city = $this->cityRows($cityId);
        if ($city === null) {
            return null;
        }

        $rows = $city['rows'];
        $barangay = null;
        if ($barangayId !== null) {
            $rows = array_values(array_filter($rows, fn (array $r) => $r['barangay_id'] === $barangayId));
            if ($rows === []) {
                return null;
            }
            $barangay = ['barangay_id' => $barangayId, 'barangay' => $rows[0]['barangay']];
        }

        // Amenities narrow the set: a listing must have EVERY one asked for ("pool and parking" = both).
        // "Parking" is the garage count, the rest are the listing's amenity names; matched case-insensitively.
        $wanted = array_values(array_unique(array_filter(array_map(fn ($a) => mb_strtolower(trim((string) $a)), $amenities))));
        if ($wanted !== []) {
            $rows = array_values(array_filter($rows, fn (array $r) => array_diff($wanted, $r['amenities']) === []));
        }

        // Barangays of the city with enough listings for their own guide, most listings first.
        $perBarangay = [];
        foreach ($city['rows'] as $r) {
            if ($r['barangay_id'] === null) {
                continue;
            }
            $perBarangay[$r['barangay_id']] ??= ['barangay_id' => $r['barangay_id'], 'barangay' => $r['barangay'], 'listings' => 0];
            $perBarangay[$r['barangay_id']]['listings']++;
        }
        $barangays = array_values(array_filter($perBarangay, fn (array $b) => $b['listings'] >= self::MIN_TIER_LISTINGS));
        usort($barangays, fn (array $a, array $b) => [$b['listings'], $a['barangay']] <=> [$a['listings'], $b['barangay']]);

        $sections = [];
        foreach (self::CATEGORIES as $category) {
            $inCategory = array_values(array_filter($rows, fn (array $r) => $r['category'] === $category));
            if ($inCategory === []) {
                continue;
            }

            $byType = [];
            foreach ($inCategory as $r) {
                $byType[$r['type']][] = $r;
            }
            uasort($byType, fn (array $a, array $b) => count($b) <=> count($a));

            $types = [];
            foreach ($byType as $type => $typeRows) {
                $tiers = [];
                $all = $this->stats('All sizes', $typeRows);
                if ($all !== null) {
                    $tiers[] = $all;
                }
                if (in_array($type, self::BEDROOM_TYPES, true)) {
                    $byTier = [];
                    foreach ($typeRows as $r) {
                        if ($r['tier'] !== null) {
                            $byTier[$r['tier']][] = $r;
                        }
                    }
                    foreach (self::tierOrder() as $tier) {
                        $s = isset($byTier[$tier]) ? $this->stats($tier, $byTier[$tier]) : null;
                        if ($s !== null) {
                            $tiers[] = $s;
                        }
                    }
                }
                if ($tiers !== []) {
                    $types[] = ['type' => $type, 'listings' => count($typeRows), 'tiers' => $tiers];
                }
            }

            if ($types === []) {
                continue;
            }
            $overall = $this->stats('All', $inCategory);
            $sections[] = [
                'category' => $category,
                'listings' => count($inCategory),
                'low' => $overall['low'] ?? null,
                'median' => $overall['median'] ?? null,
                'high' => $overall['high'] ?? null,
                'types' => $types,
            ];
        }

        return [
            'area' => $city['area'] + ($barangay ?? ['barangay_id' => null, 'barangay' => null]),
            'computed_at' => $city['computed_at'],
            'totals' => [
                'listings' => count($rows),
                'sale_listings' => count(array_filter($rows, fn (array $r) => $r['category'] === 'For Sale')),
                'rent_listings' => count(array_filter($rows, fn (array $r) => $r['category'] === 'For Rent')),
                'barangays_covered' => count(array_unique(array_filter(array_column($rows, 'barangay_id')))),
            ],
            'sections' => $sections,
            'barangays' => $barangayId === null ? $barangays : [],
            'amenities' => $wanted,
        ];
    }

    /** The amenity names a question can ask for: the reference list plus "Parking" (the garage count). */
    public function amenityVocabulary(): array
    {
        return Cache::remember('price-guide:amenities', self::TTL_SECONDS, function () {
            $names = DB::table('amenities')->orderBy('name')->pluck('name')->map(fn ($n) => (string) $n)->all();

            return array_values(array_unique(array_merge(['Parking'], $names)));
        });
    }

    /** The unit-size tiers, smallest first. */
    public static function tierOrder(): array
    {
        return ['Studio', '1 Bedroom', '2 Bedroom', '3 Bedroom', '4+ Bedroom'];
    }

    /**
     * Low / typical / high for one set of listings, or null when there are too few to mean anything.
     *
     * @param  array<int, array{price: float, ppsqm: float|null}>  $rows
     * @return array<string, mixed>|null
     */
    private function stats(string $label, array $rows): ?array
    {
        if (count($rows) < self::MIN_TIER_LISTINGS) {
            return null;
        }
        $prices = array_column($rows, 'price');
        sort($prices);
        $ppsqm = array_values(array_filter(array_column($rows, 'ppsqm'), fn ($v) => $v !== null));
        sort($ppsqm);

        return [
            'tier' => $label,
            'listings' => count($prices),
            'low' => round(self::percentile($prices, 0.25)),
            'median' => round(self::percentile($prices, 0.5)),
            'high' => round(self::percentile($prices, 0.75)),
            'min' => round($prices[0]),
            'max' => round($prices[count($prices) - 1]),
            'median_ppsqm' => $ppsqm !== [] ? round(self::percentile($ppsqm, 0.5)) : null,
            'ppsqm_listings' => count($ppsqm),
        ];
    }

    /** Linear-interpolated percentile of an already-sorted list. */
    private static function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 1) {
            return (float) $sorted[0];
        }
        $pos = ($n - 1) * $p;
        $lo = (int) floor($pos);
        $hi = (int) ceil($pos);
        $frac = $pos - $lo;

        return (float) $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * $frac;
    }

    /**
     * Every public listing of the city as a slim row, cached: category, type, tier, price, ₱/sqm,
     * barangay, amenities.
     *
     * @return array{area: array, computed_at: string, rows: array<int, array>}|null
     */
    private function cityRows(int $cityId): ?array
    {
        return Cache::remember("price-guide:city:v2:{$cityId}", self::TTL_SECONDS, function () use ($cityId) {
            $rows = $this->baseQuery()
                ->where('cities.id', $cityId)
                ->select(
                    'cities.name as city',
                    'provinces.id as province_id',
                    'provinces.name as province',
                    'categories.name as category',
                    'property_types.name as type',
                    'property_subtypes.name as subtype',
                    'property_attributes.bedroom_count',
                    'property_attributes.floor_area',
                    'property_attributes.garage_count',
                    'properties.amenities',
                    'listings.price',
                    'eff_b.id as barangay_id',
                    'eff_b.name as barangay',
                )
                ->get();

            if ($rows->isEmpty()) {
                return null;
            }

            $first = $rows->first();
            $slim = [];
            foreach ($rows as $r) {
                $price = (float) $r->price;
                $area = (float) ($r->floor_area ?? 0);
                $slim[] = [
                    'category' => (string) $r->category,
                    'type' => (string) $r->type,
                    'tier' => self::tierOf((string) $r->subtype, $r->bedroom_count === null ? null : (int) $r->bedroom_count),
                    'price' => $price,
                    'ppsqm' => $area >= self::MIN_FLOOR_AREA_SQM ? $price / $area : null,
                    'barangay_id' => $r->barangay_id === null ? null : (int) $r->barangay_id,
                    'barangay' => $r->barangay === null ? null : (string) $r->barangay,
                    'amenities' => self::amenitiesOf($r->amenities, (int) ($r->garage_count ?? 0)),
                ];
            }

            return [
                'area' => [
                    'city_id' => $cityId,
                    'city' => (string) $first->city,
                    'province_id' => (int) $first->province_id,
                    'province' => (string) $first->province,
                ],
                'computed_at' => Carbon::now()->toIso8601String(),
                'rows' => $slim,
            ];
        });
    }

    /**
     * A listing's amenities as lowercase names, with "parking" added when it has a garage.
     *
     * @return array<int, string>
     */
    private static function amenitiesOf(mixed $json, int $garages): array
    {
        $list = is_array($json) ? $json : (is_string($json) ? (json_decode($json, true) ?: []) : []);
        $names = array_map(fn ($n) => mb_strtolower(trim((string) $n)), array_filter($list, 'is_scalar'));
        if ($garages > 0) {
            $names[] = 'parking';
        }

        return array_values(array_unique(array_filter($names)));
    }

    /** "Studio" from the subtype; otherwise the bedroom count, 4 and up folded together; null = not sized. */
    private static function tierOf(string $subtype, ?int $bedrooms): ?string
    {
        if (strcasecmp(trim($subtype), 'Studio') === 0) {
            return 'Studio';
        }
        if ($bedrooms === null || $bedrooms < 1) {
            return null;
        }

        return $bedrooms >= 4 ? '4+ Bedroom' : "{$bedrooms} Bedroom";
    }

    /** Live public listings with their effective city and barangay — the shared predicate. */
    private function baseQuery(): Builder
    {
        return DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('agents', function ($j) {
                $j->on('agents.id', '=', 'listings.agent_id')
                    ->where('agents.status', 'active')
                    ->whereNull('agents.deleted_at');
            })
            ->leftJoin('barangays as addr_b', 'addr_b.id', '=', 'properties.address_id')
            ->join('cities', 'cities.id', '=', DB::raw('COALESCE(properties.geo_city_id, addr_b.city_id)'))
            ->join('provinces', 'provinces.id', '=', 'cities.province_id')
            // Effective barangay: the pin wins; the typed barangay counts only when it agrees with the pin's city.
            ->leftJoin('barangays as eff_b', 'eff_b.id', '=', DB::raw(
                'COALESCE(properties.geo_barangay_id, '
                .'CASE WHEN properties.geo_city_id IS NULL OR addr_b.city_id = properties.geo_city_id '
                .'THEN properties.address_id ELSE NULL END)'
            ))
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
            ->whereIn('categories.name', self::CATEGORIES)
            ->whereNotNull('listings.price')
            // The same price floors as the market stats, so placeholder prices stay out.
            ->where(function ($q) {
                $q->where(fn ($s) => $s->where('categories.name', 'For Sale')->where('listings.price', '>=', self::SALE_PRICE_FLOOR))
                    ->orWhere(fn ($s) => $s->where('categories.name', 'For Rent')->where('listings.price', '>=', self::RENT_PRICE_FLOOR));
            });
    }
}
