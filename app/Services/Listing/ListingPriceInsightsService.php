<?php

namespace App\Services\Listing;

use Illuminate\Support\Facades\DB;

/**
 * Admin Listing Insights → "Price Insights": asking prices by PLACE (province, city or barangay) — how
 * many listings, the average and the median price, and the average and median price per square metre.
 *
 * Same hygiene as the public price guide (PriceGuideService): public, unflagged listings with a real
 * price (For Sale and Foreclosure ≥ ₱100k, For Rent ≥ ₱1k, so placeholder prices stay out; "All" is the
 * three together, each against its own floor), and ₱/sqm only where the
 * area is at least 10 sqm — floor area for buildings, lot area for land. Medians are reported beside
 * averages because a few ₱200M mansions move an average a long way and a median not at all.
 *
 * Scope (province / city / barangay / island / region, dates, the team lead's agents) comes from the
 * shared insights base, so this tile follows the map drill like every other.
 */
class ListingPriceInsightsService extends ListingInsightsService
{
    public const LEVELS = ['province', 'city', 'barangay'];

    /** Selectable categories; anything else (incl. none / "All") means all three together. */
    public const CATEGORIES = ['For Sale', 'For Rent', 'Foreclosure'];

    private const SALE_PRICE_FLOOR = 100_000;

    private const RENT_PRICE_FLOOR = 1_000;

    /**
     * Ceilings, the other side of the floors: a ₱150B house (CEB-16102) and an ₱84B one (NOR-15747) are
     * typos that alone dragged the house average to ₱60M; the dearest real listing is under ₱1B.
     */
    private const SALE_PRICE_CEILING = 5_000_000_000;

    private const RENT_PRICE_CEILING = 10_000_000;

    private const MIN_AREA_SQM = 10;

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function byPlace(array $params, ?array $agentIds = null): array
    {
        $this->bindScope($params, $agentIds);
        $level = in_array($params['level'] ?? null, self::LEVELS, true) ? $params['level'] : 'city';
        $category = in_array($params['category'] ?? null, self::CATEGORIES, true) ? $params['category'] : '';
        $type = trim((string) ($params['type'] ?? ''));

        [$idExpr, $nameExpr] = match ($level) {
            'province' => [$this->provinceIdExpr(), $this->provinceNameExpr()],
            'barangay' => ['barangays.id', 'barangays.name'],
            default => [$this->cityIdExpr(), $this->cityNameExpr()],
        };

        $rows = $this->pricedQuery($category, $type)
            ->select(
                DB::raw("{$idExpr} as place_id"),
                DB::raw("{$nameExpr} as place_name"),
                DB::raw($level === 'barangay' ? $this->cityNameExpr().' as parent_name' : ($level === 'city' ? $this->provinceNameExpr().' as parent_name' : 'NULL as parent_name')),
                'listings.price',
                'property_types.name as type',
                'property_attributes.floor_area',
                'property_attributes.lot_area',
            )
            // with_unplaced (barangay level, used by the drill-down under a city): listings with no
            // barangay set land in a "No barangay set" bucket (place_id 0), so a city's barangay rows
            // add up to the city's count instead of silently dropping project-only listings.
            ->when(! ($level === 'barangay' && ! empty($params['with_unplaced'])), fn ($q) => $q->whereNotNull(DB::raw($idExpr)))
            ->get();

        $places = [];
        foreach ($rows as $r) {
            $key = (int) $r->place_id;
            $places[$key] ??= ['place_id' => $key, 'place_name' => $r->place_id === null ? 'No barangay set' : (string) $r->place_name, 'parent_name' => $r->parent_name !== null ? (string) $r->parent_name : null, 'prices' => [], 'ppsqm' => [], 'areas' => []];
            $price = (float) $r->price;
            $places[$key]['prices'][] = $price;
            $ppsqm = self::perSqm($price, (string) $r->type, $r->floor_area, $r->lot_area);
            if ($ppsqm !== null) {
                $places[$key]['ppsqm'][] = $ppsqm;
                $places[$key]['areas'][] = self::area((string) $r->type, $r->floor_area, $r->lot_area);
            }
        }

        $data = [];
        foreach ($places as $p) {
            $data[] = [
                'place_id' => $p['place_id'],
                'place_name' => $p['place_name'],
                'parent_name' => $p['parent_name'],
                'listings' => count($p['prices']),
            ] + self::stats($p['prices'], $p['ppsqm'], $p['areas']);
        }

        $sort = (string) ($params['sort_by'] ?? 'listings');
        $sortable = ['listings', 'avg_price', 'median_price', 'avg_ppsqm', 'median_ppsqm', 'min_price', 'max_price'];
        if (! in_array($sort, $sortable, true)) {
            $sort = 'listings';
        }
        usort($data, fn (array $a, array $b) => [(float) ($b[$sort] ?? -1), $a['place_name']] <=> [(float) ($a[$sort] ?? -1), $b['place_name']]);

        $limit = (int) ($params['limit'] ?? 0);
        if ($limit > 0) {
            $data = array_slice($data, 0, $limit);
        }

        return [
            'data' => $data,
            'meta' => [
                'level' => $level,
                'category' => $category !== '' ? $category : null,
                'type' => $type !== '' ? $type : null,
                'places' => count($places),
                'listings' => $rows->count(),
                'ppsqm_listings' => array_sum(array_map(fn (array $p) => count($p['ppsqm']), $places)),
                'overall' => self::stats(
                    array_map(fn ($r) => (float) $r->price, $rows->all()),
                    array_merge(...array_column($places, 'ppsqm')),
                    array_merge(...array_column($places, 'areas')),
                ),
            ],
        ];
    }

    /**
     * The listings behind one place row (the drill-down's last level): priced listings in the scope,
     * dearest first, with their own ₱/sqm. barangay_id 0 means "no barangay set" inside the city_id.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int}}
     */
    public function listings(array $params, ?array $agentIds = null): array
    {
        $unplaced = isset($params['barangay_id']) && (int) $params['barangay_id'] === 0;
        if ($unplaced) {
            $params['barangay_id'] = null;
        }
        $this->bindScope($params, $agentIds);
        $category = in_array($params['category'] ?? null, self::CATEGORIES, true) ? $params['category'] : '';
        $type = trim((string) ($params['type'] ?? ''));
        $limit = max(1, min(500, (int) ($params['limit'] ?? 200)));

        $query = $this->pricedQuery($category, $type)->when($unplaced, fn ($q) => $q->whereNull('barangays.id'));
        $total = (clone $query)->count();
        $rows = $query
            ->select(
                'listings.id', 'listings.code', 'listings.name', 'listings.slug', 'listings.price',
                'property_types.name as type', 'property_attributes.floor_area', 'property_attributes.lot_area',
                DB::raw('barangays.name as barangay_name'),
            )
            ->orderByDesc('listings.price')
            ->limit($limit)
            ->get();

        return [
            'data' => $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'slug' => $r->slug,
                'type' => $r->type,
                'barangay_name' => $r->barangay_name,
                'price' => (float) $r->price,
                'area' => ($a = self::area((string) $r->type, $r->floor_area, $r->lot_area)) > 0 ? $a : null,
                'ppsqm' => ($v = self::perSqm((float) $r->price, (string) $r->type, $r->floor_area, $r->lot_area)) !== null ? round($v) : null,
            ])->all(),
            'meta' => ['total' => $total, 'limit' => $limit],
        ];
    }

    /**
     * Average asking price by property type, and inside each type by subtype and by furnishing — the
     * admin main dashboard's "Prices by Type". One category at a time (a sale price and a monthly rent
     * do not average together); same hygiene and ₱/sqm rule as byPlace().
     *
     * @return array{category: string, types: list<array<string, mixed>>}
     */
    public function byType(array $params, ?array $agentIds = null): array
    {
        $this->bindScope([], $agentIds);
        $category = in_array($params['category'] ?? null, self::CATEGORIES, true) ? $params['category'] : 'For Sale';

        $rows = $this->pricedQuery($category, '')
            ->leftJoin('furnishings', 'furnishings.id', '=', 'properties.furnishing_id')
            ->select('listings.price', 'property_types.name as type', 'property_subtypes.name as subtype',
                'furnishings.name as furnishing', 'property_attributes.floor_area', 'property_attributes.lot_area',
                'property_attributes.bedroom_count', 'property_attributes.bathroom_count', 'property_attributes.garage_count')
            ->get();

        $bucket = fn () => ['prices' => [], 'ppsqm' => [], 'areas' => []];
        $types = [];
        foreach ($rows as $r) {
            $type = (string) $r->type;
            $price = (float) $r->price;
            $ppsqm = self::perSqm($price, $type, $r->floor_area, $r->lot_area);
            $area = $ppsqm !== null ? self::area($type, $r->floor_area, $r->lot_area) : null;
            $furn = (string) ($r->furnishing ?: 'Not specified');
            // The ways a type can be cut: by subtype, and by room counts (bucketed; land has no rooms).
            $dims = [
                'subtypes' => (string) ($r->subtype ?: 'Other'),
                'bedrooms' => self::roomBucket($r->bedroom_count, ['Studio / 0', '1', '2', '3', '4', '5+']),
                'bathrooms' => self::roomBucket($r->bathroom_count, ['None', '1', '2', '3', '4+']),
                'garage' => self::roomBucket($r->garage_count, ['None', '1', '2+']),
                'lot_area' => self::areaBucket($r->lot_area, self::LOT_BUCKETS),
                'floor_area' => self::areaBucket($r->floor_area, self::FLOOR_BUCKETS),
            ];
            $types[$type] ??= ['all' => $bucket(), 'furnishing' => [], 'dims' => []];
            $targets = [&$types[$type]['all'], &$types[$type]['furnishing'][$furn]];
            foreach ($dims as $dim => $key) {
                $targets[] = &$types[$type]['dims'][$dim][$key]['all'];
                $targets[] = &$types[$type]['dims'][$dim][$key]['furnishing'][$furn];
            }
            foreach ($targets as &$b) {
                $b ??= $bucket();
                $b['prices'][] = $price;
                if ($ppsqm !== null) {
                    $b['ppsqm'][] = $ppsqm;
                    $b['areas'][] = $area;
                }
            }
            unset($b, $targets);
        }

        $row = fn (string $name, array $b) => ['name' => $name, 'listings' => count($b['prices'])] + self::stats($b['prices'], $b['ppsqm'], $b['areas']);
        $list = function (array $buckets) use ($row) {
            $out = [];
            foreach ($buckets as $name => $b) {
                $out[] = $row((string) $name, $b);
            }
            usort($out, fn ($x, $y) => $y['listings'] <=> $x['listings']);

            return $out;
        };

        $out = [];
        foreach (['House', 'Condominium', 'Land', 'Commercial'] as $t) {
            $b = $types[$t] ?? ['all' => $bucket(), 'furnishing' => [], 'dims' => []];
            // Each row carries its own furnishing split: "House and Lot · Finish", "3 bedrooms · Finish" …
            $cut = function (string $dim, ?array $order) use ($b, $row, $list) {
                $groups = $b['dims'][$dim] ?? [];
                $rows = [];
                foreach ($groups as $name => $g) {
                    $rows[] = $row((string) $name, $g['all']) + ['furnishing' => $list($g['furnishing'] ?? [])];
                }
                if ($order === null) {
                    usort($rows, fn ($x, $y) => $y['listings'] <=> $x['listings']);
                } else {
                    usort($rows, fn ($x, $y) => array_search($x['name'], $order, true) <=> array_search($y['name'], $order, true));
                }

                return $rows;
            };
            // The cuts that mean something for each type (the owner's call): rooms for homes, lot area
            // for land, lot and floor area for commercial.
            $views = self::TYPE_VIEWS[$t];
            $order = [
                'subtypes' => null,
                'bedrooms' => ['Studio / 0', '1', '2', '3', '4', '5+'],
                'bathrooms' => ['None', '1', '2', '3', '4+'],
                'garage' => ['None', '1', '2+'],
                'lot_area' => array_merge(array_column(self::LOT_BUCKETS, 1), ['Not specified']),
                'floor_area' => array_merge(array_column(self::FLOOR_BUCKETS, 1), ['Not specified']),
            ];
            $cuts = [];
            foreach ($views as $v) {
                $cuts[$v] = $cut($v, $order[$v]);
            }
            $out[] = $row($t, $b['all']) + $cuts + ['views' => $views, 'furnishing' => $list($b['furnishing']), 'photo' => $this->typePhoto($category, $t)];
        }

        return ['category' => $category, 'types' => $out];
    }

    /** Card artwork for a type: the cover photo of its most-viewed priced listing in this category. */
    private function typePhoto(string $category, string $type): ?string
    {
        $raw = $this->pricedQuery($category, '')
            ->where('property_types.name', $type)
            ->whereRaw('COALESCE(JSON_LENGTH(listings.featured_photo), 0) > 0')
            ->orderByDesc('listings.clicks')
            ->value('listings.featured_photo');
        $photos = is_string($raw) ? json_decode($raw, true) : $raw;
        $first = is_array($photos) ? ($photos[0] ?? null) : null;
        // Entries are plain urls, or objects carrying one.
        $url = is_array($first) ? ($first['url'] ?? $first['original'] ?? null) : $first;

        return is_string($url) && str_starts_with($url, 'http') ? $url : null;
    }

    /** The section tile's numbers: median price and median ₱/sqm for sale and for rent, within the scope. */
    public function totals(array $params, ?array $agentIds = null): array
    {
        $this->bindScope($params, $agentIds);
        $out = [];
        foreach (['For Sale', 'For Rent'] as $category) {
            $rows = $this->pricedQuery($category, '')
                ->select('listings.price', 'property_types.name as type', 'property_attributes.floor_area', 'property_attributes.lot_area')
                ->get();
            $prices = array_map(fn ($r) => (float) $r->price, $rows->all());
            $ppsqm = array_values(array_filter(array_map(fn ($r) => self::perSqm((float) $r->price, (string) $r->type, $r->floor_area, $r->lot_area), $rows->all()), fn ($v) => $v !== null));
            $out[$category === 'For Sale' ? 'sale' : 'rent'] = ['listings' => count($prices)] + self::stats($prices, $ppsqm);
        }

        return $out;
    }

    private function bindScope(array $params, ?array $agentIds): void
    {
        $this->agentIds = $agentIds;
        $this->dateStart = $params['date_start'] ?? null;
        $this->dateEnd = $params['date_end'] ?? null;
        $this->provinceId = isset($params['province_id']) && $params['province_id'] !== null ? (int) $params['province_id'] : null;
        $this->cityId = isset($params['city_id']) && $params['city_id'] !== null ? (int) $params['city_id'] : null;
        $this->barangayId = isset($params['barangay_id']) && $params['barangay_id'] !== null ? (int) $params['barangay_id'] : null;
        $this->island = is_string($params['island'] ?? null) ? $params['island'] : null;
        $this->region = is_string($params['region'] ?? null) ? $params['region'] : null;
        $this->resolveScopeProvinceIds();
    }

    /** Public, unflagged, really-priced listings of one category ('' = all), with their property type and areas. */
    private function pricedQuery(string $category, string $type)
    {
        return $this->baseListingQuery()
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->where('listings.visibility', 'public')
            ->where(fn ($q) => $q->whereNull('listings.verification_status')->orWhere('listings.verification_status', '!=', 'flagged'))
            ->when($category !== '', fn ($q) => $q->where('categories.name', $category))
            ->whereNotNull('listings.price')
            // Each listing against its own category's floor: rent ≥ ₱1k, sale and foreclosure ≥ ₱100k.
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->where('categories.name', 'For Rent')->whereBetween('listings.price', [self::RENT_PRICE_FLOOR, self::RENT_PRICE_CEILING]))
                ->orWhere(fn ($r) => $r->where('categories.name', '!=', 'For Rent')->whereBetween('listings.price', [self::SALE_PRICE_FLOOR, self::SALE_PRICE_CEILING])))
            ->when($type !== '', fn ($q) => $q->where('property_types.name', $type));
    }

    /** The area a listing is priced on: lot area for land, floor area for everything else (0 when unknown). */
    private static function area(string $type, mixed $floorArea, mixed $lotArea): float
    {
        return strcasecmp(trim($type), 'Land') === 0 ? (float) ($lotArea ?? 0) : (float) ($floorArea ?? 0);
    }

    private const TYPE_VIEWS = [
        'House' => ['subtypes', 'bedrooms', 'bathrooms', 'garage'],
        'Condominium' => ['subtypes', 'bedrooms', 'bathrooms', 'garage'],
        'Land' => ['subtypes', 'lot_area'],
        'Commercial' => ['subtypes', 'lot_area', 'floor_area'],
    ];

    /** [upper bound in sqm (exclusive), label] — the last bound is open-ended. */
    private const LOT_BUCKETS = [
        [100, 'Under 100 sqm'], [300, '100–299 sqm'], [1_000, '300–999 sqm'], [5_000, '1,000–4,999 sqm'],
        [10_000, '5,000–9,999 sqm'], [50_000, '1–5 hectares'], [PHP_INT_MAX, '5+ hectares'],
    ];

    private const FLOOR_BUCKETS = [
        [50, 'Under 50 sqm'], [200, '50–199 sqm'], [500, '200–499 sqm'], [1_000, '500–999 sqm'], [PHP_INT_MAX, '1,000+ sqm'],
    ];

    /** An area into its bucket label; none (or under 1 sqm) is "Not specified". */
    private static function areaBucket(mixed $sqm, array $buckets): string
    {
        $a = (float) ($sqm ?? 0);
        if ($a < 1) {
            return 'Not specified';
        }
        foreach ($buckets as [$max, $label]) {
            if ($a < $max) {
                return $label;
            }
        }

        return end($buckets)[1];
    }

    /** A room count into its bucket label: 0 → first label, N → "N", at or past the last → the "N+" label. */
    private static function roomBucket(mixed $count, array $labels): string
    {
        $n = max(0, (int) ($count ?? 0));

        return $labels[min($n, count($labels) - 1)];
    }

    /** ₱ per sqm on that area; null below 10 sqm or when unknown. */
    private static function perSqm(float $price, string $type, mixed $floorArea, mixed $lotArea): ?float
    {
        $area = self::area($type, $floorArea, $lotArea);

        return $area >= self::MIN_AREA_SQM ? $price / $area : null;
    }

    /** @return array{avg_price: ?float, median_price: ?float, min_price: ?float, max_price: ?float, avg_ppsqm: ?float, median_ppsqm: ?float, ppsqm_listings: int, min_area: ?float, max_area: ?float} */
    private static function stats(array $prices, array $ppsqm, array $areas = []): array
    {
        sort($prices);
        sort($ppsqm);
        sort($areas);

        return [
            'avg_price' => $prices ? round(array_sum($prices) / count($prices)) : null,
            'median_price' => $prices ? round(self::median($prices)) : null,
            'min_price' => $prices ? $prices[0] : null,
            'max_price' => $prices ? $prices[count($prices) - 1] : null,
            'avg_ppsqm' => $ppsqm ? round(array_sum($ppsqm) / count($ppsqm)) : null,
            'median_ppsqm' => $ppsqm ? round(self::median($ppsqm)) : null,
            'ppsqm_listings' => count($ppsqm),
            // Size range over the same listings the ₱/sqm uses (usable area ≥ 10 sqm).
            'min_area' => $areas ? $areas[0] : null,
            'max_area' => $areas ? $areas[count($areas) - 1] : null,
        ];
    }

    /** @param list<float> $sorted */
    private static function median(array $sorted): float
    {
        $n = count($sorted);
        $mid = intdiv($n, 2);

        return $n % 2 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2;
    }
}
