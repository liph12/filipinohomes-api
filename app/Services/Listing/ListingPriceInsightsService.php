<?php

namespace App\Services\Listing;

use Illuminate\Support\Facades\DB;

/**
 * Admin Listing Insights → "Price Insights": asking prices by PLACE (province, city or barangay) — how
 * many listings, the average and the median price, and the average and median price per square metre.
 *
 * Same hygiene as the public price guide (PriceGuideService): public, unflagged listings with a real
 * price (For Sale ≥ ₱100k, For Rent ≥ ₱1k, so placeholder prices stay out), and ₱/sqm only where the
 * area is at least 10 sqm — floor area for buildings, lot area for land. Medians are reported beside
 * averages because a few ₱200M mansions move an average a long way and a median not at all.
 *
 * Scope (province / city / barangay / island / region, dates, the team lead's agents) comes from the
 * shared insights base, so this tile follows the map drill like every other.
 */
class ListingPriceInsightsService extends ListingInsightsService
{
    public const LEVELS = ['province', 'city', 'barangay'];

    public const CATEGORIES = ['For Sale', 'For Rent'];

    private const SALE_PRICE_FLOOR = 100_000;

    private const RENT_PRICE_FLOOR = 1_000;

    private const MIN_AREA_SQM = 10;

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function byPlace(array $params, ?array $agentIds = null): array
    {
        $this->bindScope($params, $agentIds);
        $level = in_array($params['level'] ?? null, self::LEVELS, true) ? $params['level'] : 'city';
        $category = in_array($params['category'] ?? null, self::CATEGORIES, true) ? $params['category'] : 'For Sale';
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
            ->whereNotNull(DB::raw($idExpr))
            ->get();

        $places = [];
        foreach ($rows as $r) {
            $key = (int) $r->place_id;
            $places[$key] ??= ['place_id' => $key, 'place_name' => (string) $r->place_name, 'parent_name' => $r->parent_name !== null ? (string) $r->parent_name : null, 'prices' => [], 'ppsqm' => []];
            $price = (float) $r->price;
            $places[$key]['prices'][] = $price;
            $ppsqm = self::perSqm($price, (string) $r->type, $r->floor_area, $r->lot_area);
            if ($ppsqm !== null) {
                $places[$key]['ppsqm'][] = $ppsqm;
            }
        }

        $data = [];
        foreach ($places as $p) {
            $data[] = [
                'place_id' => $p['place_id'],
                'place_name' => $p['place_name'],
                'parent_name' => $p['parent_name'],
                'listings' => count($p['prices']),
            ] + self::stats($p['prices'], $p['ppsqm']);
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
                'category' => $category,
                'type' => $type !== '' ? $type : null,
                'places' => count($places),
                'listings' => $rows->count(),
                'ppsqm_listings' => array_sum(array_map(fn (array $p) => count($p['ppsqm']), $places)),
                'overall' => self::stats(array_map(fn ($r) => (float) $r->price, $rows->all()), array_values(array_filter(array_map(fn ($r) => self::perSqm((float) $r->price, (string) $r->type, $r->floor_area, $r->lot_area), $rows->all()), fn ($v) => $v !== null))),
            ],
        ];
    }

    /** The section tile's numbers: median price and median ₱/sqm for sale and for rent, within the scope. */
    public function totals(array $params, ?array $agentIds = null): array
    {
        $this->bindScope($params, $agentIds);
        $out = [];
        foreach (self::CATEGORIES as $category) {
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

    /** Public, unflagged, really-priced listings of one category, with their property type and areas. */
    private function pricedQuery(string $category, string $type)
    {
        return $this->baseListingQuery()
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->where('listings.visibility', 'public')
            ->where(fn ($q) => $q->whereNull('listings.verification_status')->orWhere('listings.verification_status', '!=', 'flagged'))
            ->where('categories.name', $category)
            ->whereNotNull('listings.price')
            ->where('listings.price', '>=', $category === 'For Rent' ? self::RENT_PRICE_FLOOR : self::SALE_PRICE_FLOOR)
            ->when($type !== '', fn ($q) => $q->where('property_types.name', $type));
    }

    /** ₱ per sqm: lot area for land, floor area for everything else; null below 10 sqm or when unknown. */
    private static function perSqm(float $price, string $type, mixed $floorArea, mixed $lotArea): ?float
    {
        $area = strcasecmp(trim($type), 'Land') === 0 ? (float) ($lotArea ?? 0) : (float) ($floorArea ?? 0);

        return $area >= self::MIN_AREA_SQM ? $price / $area : null;
    }

    /** @return array{avg_price: ?float, median_price: ?float, min_price: ?float, max_price: ?float, avg_ppsqm: ?float, median_ppsqm: ?float, ppsqm_listings: int} */
    private static function stats(array $prices, array $ppsqm): array
    {
        sort($prices);
        sort($ppsqm);

        return [
            'avg_price' => $prices ? round(array_sum($prices) / count($prices)) : null,
            'median_price' => $prices ? round(self::median($prices)) : null,
            'min_price' => $prices ? $prices[0] : null,
            'max_price' => $prices ? $prices[count($prices) - 1] : null,
            'avg_ppsqm' => $ppsqm ? round(array_sum($ppsqm) / count($ppsqm)) : null,
            'median_ppsqm' => $ppsqm ? round(self::median($ppsqm)) : null,
            'ppsqm_listings' => count($ppsqm),
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
