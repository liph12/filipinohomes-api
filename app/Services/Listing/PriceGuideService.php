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

    public const SALE_PRICE_FLOOR = 100_000;

    public const RENT_PRICE_FLOOR = 1_000;

    private const MIN_FLOOR_AREA_SQM = 10;

    /** Gross yields outside this band come from mispriced or mismatched listings, not from the market; they are not shown. */
    private const MIN_YIELD_PCT = 1.0;

    private const MAX_YIELD_PCT = 15.0;

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
     * What a typed place is — a barangay ("Lahug" → Cebu City, Cebu) or a city ("Cebu City") — with the
     * ids a search can widen to. Null when unknown. "Lahug, Cebu City" is tried whole, then piece by piece.
     *
     * @return array{kind: 'barangay'|'city', city_id: int, city: string, province: string}|null
     */
    public function placeOf(string $place): ?array
    {
        $norm = fn (string $s) => trim(preg_replace('/\s+/', ' ', preg_replace('/\b(city|municipality)\b/', '', preg_replace('/[^a-z0-9ñ ]/u', ' ', mb_strtolower($s)))));
        $candidates = array_values(array_unique(array_filter(array_map($norm, array_merge([$place], explode(',', $place))))));
        if ($candidates === []) {
            return null;
        }
        $places = $this->places();
        // Whole string first, then each comma piece; at each, a city wins over a barangay of the same name.
        foreach ($candidates as $want) {
            foreach ($places as $p) {
                if ($p['barangay'] === null && $norm($p['city']) === $want) {
                    return ['kind' => 'city', 'city_id' => $p['city_id'], 'city' => $p['city'], 'province' => $p['province']];
                }
            }
            $hits = array_values(array_filter($places, fn ($p) => $p['barangay'] !== null && (
                $norm($p['barangay']) === $want || $norm(preg_replace('/\(.*?\)/', '', $p['barangay'])) === $want
            )));
            if ($hits !== []) {
                usort($hits, fn ($a, $b) => $b['listings'] <=> $a['listings']);

                return ['kind' => 'barangay', 'city_id' => $hits[0]['city_id'], 'city' => $hits[0]['city'], 'province' => $hits[0]['province']];
            }
        }

        return null;
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

    /**
     * Where ONE listing sits against its own market, for ASA-GPT's cards and its "is this a good deal?"
     * answer: the typical (median) asking price of its peers — same category, property type and unit
     * size, in its barangay, widening to the city and then dropping the size until there are enough
     * peers to mean anything — and how far this listing's price is from it. Null when the listing is
     * not a live public one, or nothing comparable is listed anywhere in its city.
     *
     * @return array<string, mixed>|null
     */
    public function contextForListing(int $listingId): ?array
    {
        $r = $this->baseQuery()
            ->where('listings.id', $listingId)
            ->select(
                'cities.id as city_id',
                'cities.name as city',
                'eff_b.id as barangay_id',
                'eff_b.name as barangay',
                'categories.name as category',
                'property_types.name as type',
                'property_subtypes.name as subtype',
                'property_attributes.bedroom_count',
                'property_attributes.floor_area',
                'listings.price',
                'listings.created_at',
            )
            ->first();
        if ($r === null) {
            return null;
        }
        $city = $this->cityRows((int) $r->city_id);
        if ($city === null) {
            return null;
        }

        $price = (float) $r->price;
        $area = (float) ($r->floor_area ?? 0);
        $ppsqm = $area >= self::MIN_FLOOR_AREA_SQM ? $price / $area : null;
        $tier = self::tierOf((string) $r->subtype, $r->bedroom_count === null ? null : (int) $r->bedroom_count);
        $barangayId = $r->barangay_id === null ? null : (int) $r->barangay_id;

        // Narrowest first: this barangay + this size, the city + this size, then without the size.
        $tries = [];
        if ($barangayId !== null) {
            $tries[] = ['barangay', true];
        }
        $tries[] = ['city', true];
        if ($tier !== null) {
            if ($barangayId !== null) {
                $tries[] = ['barangay', false];
            }
            $tries[] = ['city', false];
        }

        foreach ($tries as [$scope, $withTier]) {
            $peers = array_values(array_filter($city['rows'], fn (array $x) => $x['category'] === $r->category
                && $x['type'] === $r->type
                && ($scope === 'city' || $x['barangay_id'] === $barangayId)
                && (! $withTier || $tier === null || $x['tier'] === $tier)));
            // The listing itself is one of the peers; a market of one is no market.
            if (count($peers) < self::MIN_TIER_LISTINGS + 1) {
                continue;
            }
            $stats = $this->stats($withTier && $tier !== null ? $tier : 'All sizes', $peers);
            if ($stats === null) {
                continue;
            }
            $median = (float) $stats['median'];
            $diff = $median > 0 ? (int) round(($price - $median) / $median * 100) : null;

            // Gross rental yield, for a For Sale listing: what the same kind of unit rents for here,
            // times twelve, over the price. The area's own yield uses the typical sale price instead.
            $rentMedian = null;
            $yield = null;
            $areaYield = null;
            if ($r->category === 'For Sale') {
                $rentPeers = array_values(array_filter($city['rows'], fn (array $x) => $x['category'] === 'For Rent'
                    && $x['type'] === $r->type
                    && ($scope === 'city' || $x['barangay_id'] === $barangayId)
                    && (! $withTier || $tier === null || $x['tier'] === $tier)));
                $rentStats = $this->stats('rent', $rentPeers);
                if ($rentStats !== null && $price > 0 && $median > 0) {
                    $rentMedian = (float) $rentStats['median'];
                    $yield = round($rentMedian * 12 / $price * 100, 1);
                    $areaYield = round($rentMedian * 12 / $median * 100, 1);
                    // A ₱500K "studio" against ₱18K rents is a mispriced listing, not a 43% yield.
                    if ($yield < self::MIN_YIELD_PCT || $yield > self::MAX_YIELD_PCT || $areaYield < self::MIN_YIELD_PCT || $areaYield > self::MAX_YIELD_PCT) {
                        $rentMedian = $yield = $areaYield = null;
                    }
                }
            }

            return [
                'scope' => $scope,
                'area' => $scope === 'barangay' ? "{$r->barangay}, {$r->city}" : (string) $r->city,
                'city' => (string) $r->city,
                'barangay' => $scope === 'barangay' ? (string) $r->barangay : null,
                'city_id' => (int) $r->city_id,
                'barangay_id' => $scope === 'barangay' ? $barangayId : null,
                'category' => (string) $r->category,
                'type' => (string) $r->type,
                'tier' => $stats['tier'],
                'listings' => $stats['listings'],
                'low' => $stats['low'],
                'median' => $stats['median'],
                'high' => $stats['high'],
                'median_ppsqm' => $stats['median_ppsqm'],
                'price' => round($price),
                'ppsqm' => $ppsqm === null ? null : round($ppsqm),
                'diff_pct' => $diff,
                'verdict' => $diff === null ? 'near' : ($diff <= -8 ? 'below' : ($diff >= 8 ? 'above' : 'near')),
                // Peers other than this listing, either side of its price.
                'cheaper' => count(array_filter($peers, fn (array $x) => $x['price'] < $price)),
                'pricier' => count(array_filter($peers, fn (array $x) => $x['price'] > $price)),
                'listed_at' => (string) $r->created_at,
                'rent_median' => $rentMedian === null ? null : round($rentMedian),
                'yield_pct' => $yield,
                'area_yield_pct' => $areaYield,
                'demand' => $this->demand($listingId, $peers, (string) $r->created_at),
            ];
        }

        return null;
    }

    /**
     * How much interest a listing draws against its peers: all-time views per day listed (listings.clicks)
     * versus the peers' median rate, views in the last 30 days where the daily log has them, saves and
     * inquiries. "hot" is twice the peers' rate, "quiet" is half of it after a month on the market.
     *
     * @param  array<int, array{listing_id:int, views_per_day:float}>  $peers
     * @return array<string, mixed>
     */
    private function demand(int $listingId, array $peers, string $listedAt): array
    {
        return Cache::remember("price-guide:demand:v1:{$listingId}", 600, function () use ($listingId, $peers, $listedAt) {
            $me = null;
            $rates = [];
            foreach ($peers as $p) {
                if ($p['listing_id'] === $listingId) {
                    $me = $p['views_per_day'];
                } else {
                    $rates[] = $p['views_per_day'];
                }
            }
            sort($rates);
            $peerRate = $rates === [] ? null : self::percentile($rates, 0.5);
            $ratio = $me !== null && $peerRate !== null && $peerRate > 0 ? round($me / $peerRate, 1) : null;
            $days = (int) Carbon::parse($listedAt)->diffInDays(now());

            // Each of these tables is newer than the core schema; a missing one costs its number, not the card.
            $count = function (callable $q): int {
                try {
                    return (int) $q();
                } catch (\Throwable) {
                    return 0;
                }
            };
            $views30 = $count(fn () => DB::table('listing_view_days')->where('listing_id', $listingId)->where('date', '>=', now()->subDays(30)->toDateString())->sum('views'));
            $favorites = $count(fn () => DB::table('favorites')->where('listing_id', $listingId)->count());
            $inquiries30 = $count(fn () => DB::table('listing_inquiries')->where('listing_id', $listingId)->where('created_at', '>=', now()->subDays(30))->count());

            $label = 'steady';
            if ($ratio !== null && $ratio >= 2) {
                $label = 'hot';
            } elseif ($ratio !== null && $ratio <= 0.5 && $days >= 30) {
                $label = 'quiet';
            }

            return [
                'label' => $label,
                'views_per_day' => $me === null ? null : round($me, 2),
                'peer_views_per_day' => $peerRate === null ? null : round($peerRate, 2),
                'ratio' => $ratio,
                'views_30d' => $views30,
                'favorites' => $favorites,
                'inquiries_30d' => $inquiries30,
                'days_listed' => $days,
            ];
        });
    }

    /**
     * An area at a glance for ASA-GPT's "tell me about Lahug": the guide plus who lists most there.
     *
     * @return array{guide: array<string, mixed>, agents: array<int, array{id:int, name:string, listings:int}>}|null
     */
    public function snapshot(int $cityId, ?int $barangayId = null): ?array
    {
        $guide = $this->guide($cityId, $barangayId);
        if ($guide === null || $guide['sections'] === []) {
            return null;
        }
        return ['guide' => $guide, 'agents' => $this->topAgents($cityId, $barangayId), 'yields' => $this->yields($cityId, $barangayId)];
    }

    /**
     * The agents with the most live public listings in an area (a city, or one barangay of it).
     *
     * @return array<int, array{id:int, name:string, listings:int}>
     */
    public function topAgents(int $cityId, ?int $barangayId = null, int $limit = 3): array
    {
        return Cache::remember("price-guide:agents:{$cityId}:".($barangayId ?? 'all').":{$limit}", self::TTL_SECONDS, function () use ($cityId, $barangayId, $limit) {
            $q = $this->baseQuery()->where('cities.id', $cityId);
            if ($barangayId !== null) {
                $q->where('eff_b.id', $barangayId);
            }

            return $q->select('agents.id', 'agents.first_name', 'agents.last_name', DB::raw('COUNT(*) as listings'))
                ->groupBy('agents.id', 'agents.first_name', 'agents.last_name')
                ->orderByDesc('listings')
                ->limit($limit)
                ->get()
                ->map(fn ($a) => ['id' => (int) $a->id, 'name' => trim("{$a->first_name} {$a->last_name}"), 'listings' => (int) $a->listings])
                ->values()
                ->all();
        });
    }

    /**
     * The city's slim listing rows (see cityRows), for callers that compute their own statistics —
     * the valuation and the rent-or-buy comparison. Empty when the city has no public listings.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(int $cityId): array
    {
        return $this->cityRows($cityId)['rows'] ?? [];
    }

    /**
     * The typical sale price and the typical rent of the SAME kind of unit in an area — the pair a
     * rent-or-buy comparison needs. Narrowest scope first: the barangay and the unit size, then the
     * barangay at any size, then the city at the size, then the city; each side needs $min listings.
     *
     * @return array{scope:'barangay'|'city', tier:string, sale:array<string,mixed>, rent:array<string,mixed>}|null
     */
    public function pairFor(int $cityId, ?int $barangayId, string $type, ?string $tier, int $min = 5): ?array
    {
        $rows = array_values(array_filter($this->rows($cityId), fn (array $r) => $r['type'] === $type));
        if ($rows === []) {
            return null;
        }
        $attempts = [];
        if ($barangayId !== null) {
            if ($tier !== null) {
                $attempts[] = ['barangay', $tier];
            }
            $attempts[] = ['barangay', null];
        }
        if ($tier !== null) {
            $attempts[] = ['city', $tier];
        }
        $attempts[] = ['city', null];

        foreach ($attempts as [$scope, $t]) {
            $set = array_values(array_filter($rows, fn (array $r) => ($scope === 'city' || $r['barangay_id'] === $barangayId) && ($t === null || $r['tier'] === $t)));
            $sale = array_values(array_filter($set, fn (array $r) => $r['category'] === 'For Sale'));
            $rent = array_values(array_filter($set, fn (array $r) => $r['category'] === 'For Rent'));
            if (count($sale) < $min || count($rent) < $min) {
                continue;
            }
            $s = $this->stats($t ?? 'All sizes', $sale);
            $r = $this->stats($t ?? 'All sizes', $rent);
            if ($s === null || $r === null || $s['median'] <= 0 || $r['median'] <= 0) {
                continue;
            }
            $y = $r['median'] * 12 / $s['median'] * 100;
            if ($y < self::MIN_YIELD_PCT || $y > self::MAX_YIELD_PCT) {
                continue; // mismatched sizes or placeholder prices, not a market
            }

            return ['scope' => $scope, 'tier' => $t ?? 'All sizes', 'sale' => $s, 'rent' => $r];
        }

        return null;
    }

    /** Low / typical / high of a caller's own row set (same rules as the guide's tiers). */
    public function statsOf(string $label, array $rows): ?array
    {
        return $this->stats($label, $rows);
    }

    /** Linear-interpolated percentile of a list (sorted here). */
    public static function percentileOf(array $values, float $p): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        if ($values === []) {
            return null;
        }
        sort($values);

        return self::percentile($values, $p);
    }

    /**
     * Gross rental yields from the guide's own numbers: per property type in the area (typical rent ×12
     * over typical sale price), and — for a whole city — the barangays where condominiums yield most.
     *
     * @return array{types: array<int, array{type:string, yield_pct:float, sale_median:float, rent_median:float}>, barangays: array<int, array{barangay:string, yield_pct:float, sale_listings:int, rent_listings:int}>}
     */
    public function yields(int $cityId, ?int $barangayId = null): array
    {
        $city = $this->cityRows($cityId);
        if ($city === null) {
            return ['types' => [], 'barangays' => []];
        }
        $rows = $barangayId === null ? $city['rows'] : array_values(array_filter($city['rows'], fn (array $r) => $r['barangay_id'] === $barangayId));

        // Like for like: a yield is computed per unit size (studios' rent over studios' price, never the
        // rent of studios over the price of 3-bedrooms), then the sizes' yields are combined by their
        // median. Sizes with too few listings on either side are skipped; implausible results are dropped.
        $yieldOf = function (array $set): ?array {
            $perTier = [];
            $sales = 0;
            $rents = 0;
            foreach (array_merge(self::tierOrder(), [null]) as $tier) {
                $inTier = array_values(array_filter($set, fn (array $r) => $r['tier'] === $tier));
                $sale = $this->stats($tier ?? 'All sizes', array_values(array_filter($inTier, fn (array $r) => $r['category'] === 'For Sale')));
                $rent = $this->stats($tier ?? 'All sizes', array_values(array_filter($inTier, fn (array $r) => $r['category'] === 'For Rent')));
                if ($sale === null || $rent === null || $sale['median'] <= 0) {
                    continue;
                }
                $y = $rent['median'] * 12 / $sale['median'] * 100;
                if ($y < self::MIN_YIELD_PCT || $y > self::MAX_YIELD_PCT) {
                    continue;
                }
                $perTier[] = ['yield' => $y, 'sale' => $sale, 'rent' => $rent];
                $sales += $sale['listings'];
                $rents += $rent['listings'];
            }
            if ($perTier === []) {
                return null;
            }
            usort($perTier, fn (array $a, array $b) => $a['yield'] <=> $b['yield']);
            $mid = $perTier[intdiv(count($perTier), 2)];
            // The quoted buy/rent pair is the size that gives the middle yield, so the three numbers agree.
            return [
                'yield_pct' => round(self::percentile(array_column($perTier, 'yield'), 0.5), 1),
                'sale_median' => $mid['sale']['median'],
                'rent_median' => $mid['rent']['median'],
                'tier' => $mid['sale']['tier'],
                'sale_listings' => $sales,
                'rent_listings' => $rents,
            ];
        };

        $types = [];
        foreach (array_unique(array_column($rows, 'type')) as $type) {
            $y = $yieldOf(array_values(array_filter($rows, fn (array $r) => $r['type'] === $type)));
            if ($y !== null) {
                $types[] = ['type' => $type] + $y;
            }
        }
        usort($types, fn (array $a, array $b) => $b['sale_listings'] <=> $a['sale_listings']);

        $barangays = [];
        if ($barangayId === null) {
            $byB = [];
            foreach ($city['rows'] as $r) {
                if ($r['barangay_id'] !== null && $r['type'] === 'Condominium') {
                    $byB[$r['barangay_id']][] = $r;
                }
            }
            foreach ($byB as $set) {
                $y = $yieldOf($set);
                // A ranking needs more than a handful on each side, or one odd listing tops the list.
                if ($y !== null && $y['sale_listings'] >= 5 && $y['rent_listings'] >= 5) {
                    $barangays[] = ['barangay' => $set[0]['barangay']] + $y;
                }
            }
            usort($barangays, fn (array $a, array $b) => $b['yield_pct'] <=> $a['yield_pct']);
            $barangays = array_slice($barangays, 0, 5);
        }

        return ['types' => $types, 'barangays' => $barangays];
    }

    /**
     * The listing's latest price CUT, from the audit log — "₱500K off, 12 days ago" on the card and
     * in the deal verdict. Null when the last change was a rise, older than the window, or there is
     * none. The log stores the old and new price as JSON on each `updated` row.
     *
     * @return array{old: float, new: float, drop: float, drop_pct: int, at: string, days_ago: int}|null
     */
    public function priceChangeFor(int $listingId, int $withinDays = 180): ?array
    {
        return Cache::remember("price-guide:change:v1:{$listingId}", 600, function () use ($listingId, $withinDays) {
            $row = DB::table('audits')
                ->where('auditable_type', 'App\\Models\\Listing')
                ->where('auditable_id', $listingId)
                ->where('event', 'updated')
                ->where('new_values', 'like', '%"price"%')
                ->orderByDesc('id')
                ->first(['old_values', 'new_values', 'created_at']);
            if ($row === null) {
                return null;
            }
            $old = (float) (json_decode((string) $row->old_values, true)['price'] ?? 0);
            $new = (float) (json_decode((string) $row->new_values, true)['price'] ?? 0);
            $days = (int) Carbon::parse($row->created_at)->diffInDays(now());
            if ($old <= 0 || $new <= 0 || $new >= $old || $days > $withinDays) {
                return null;
            }

            return [
                'old' => round($old),
                'new' => round($new),
                'drop' => round($old - $new),
                'drop_pct' => (int) round(($old - $new) / $old * 100),
                'at' => Carbon::parse($row->created_at)->toDateString(),
                'days_ago' => $days,
            ];
        });
    }

    /**
     * Listings whose price was cut in the last N days, biggest cut first — "show me listings that
     * dropped price this month". Reads the window of `updated` audit rows (indexed by event +
     * created_at) and keeps the ones whose price went down.
     *
     * @return array<int, int> listing ids
     */
    public function priceDropListingIds(int $days): array
    {
        $days = max(1, min(365, $days));

        return Cache::remember("price-guide:drops:v1:{$days}", 600, function () use ($days) {
            $rows = DB::table('audits')
                ->where('event', 'updated')
                ->where('created_at', '>=', now()->subDays($days))
                ->where('auditable_type', 'App\\Models\\Listing')
                ->where('new_values', 'like', '%"price"%')
                ->orderByDesc('id')
                ->get(['auditable_id', 'old_values', 'new_values']);
            $best = [];
            foreach ($rows as $r) {
                $id = (int) $r->auditable_id;
                if (isset($best[$id])) {
                    continue; // newest change per listing decides
                }
                $old = (float) (json_decode((string) $r->old_values, true)['price'] ?? 0);
                $new = (float) (json_decode((string) $r->new_values, true)['price'] ?? 0);
                $best[$id] = $old > 0 && $new > 0 && $new < $old ? ($old - $new) / $old : -1;
            }
            $drops = array_filter($best, fn (float $pct) => $pct > 0);
            arsort($drops);

            return array_keys($drops);
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
        return Cache::remember("price-guide:city:v4:{$cityId}", self::TTL_SECONDS, function () use ($cityId) {
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
                    'property_attributes.lot_area',
                    'property_attributes.garage_count',
                    'properties.amenities',
                    'listings.price',
                    'listings.id as listing_id',
                    'listings.clicks',
                    'listings.created_at',
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
                // The size a ₱/sqm is measured on: floor area, or the lot for land and lot-only listings.
                $area = (float) ($r->floor_area ?? 0);
                if ($area < self::MIN_FLOOR_AREA_SQM && in_array((string) $r->type, ['Land', 'Commercial'], true)) {
                    $area = (float) ($r->lot_area ?? 0);
                }
                $slim[] = [
                    'listing_id' => (int) $r->listing_id,
                    // All-time views per day listed — the demand rate a listing is compared on.
                    'views_per_day' => round(((int) ($r->clicks ?? 0)) / max(1, Carbon::parse($r->created_at)->diffInDays(now())), 3),
                    'category' => (string) $r->category,
                    'type' => (string) $r->type,
                    'tier' => self::tierOf((string) $r->subtype, $r->bedroom_count === null ? null : (int) $r->bedroom_count),
                    'price' => $price,
                    'sqm' => $area >= self::MIN_FLOOR_AREA_SQM ? $area : null,
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
    public static function tierOf(string $subtype, ?int $bedrooms): ?string
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
