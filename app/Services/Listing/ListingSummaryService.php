<?php

namespace App\Services\Listing;

use App\Services\Reports\Executive\ReportRange;
use App\Support\IslandMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Compact overview numbers for the Listing Insights page (headline tiles and
 * the mix / where / quality sections): totals, category split, per-type counts,
 * per-island counts, visibility, audit status and this-month created counts.
 *
 * Everything counts listings ADDED inside the requested window (Manila days;
 * no window = all time). `previous` repeats the category counts for the period
 * before it, so the page can show a change.
 *
 * Every key the page already read is still here with the same meaning — the API
 * deploys before the frontend, so an older bundle must keep working.
 */
class ListingSummaryService extends ListingInsightsService
{
    public function summary(array $params = [], ?array $agentIds = null): array
    {
        $this->agentIds = $agentIds;
        $this->dateStart = $params['date_start'] ?? null;
        $this->dateEnd = $params['date_end'] ?? null;
        $this->dateBasis = 'created';
        $this->provinceId = isset($params['province_id']) && $params['province_id'] !== null ? (int) $params['province_id'] : null;
        $this->cityId = isset($params['city_id']) && $params['city_id'] !== null ? (int) $params['city_id'] : null;
        $this->island = is_string($params['island'] ?? null) ? $params['island'] : null;
        $this->region = is_string($params['region'] ?? null) ? $params['region'] : null;
        $this->barangayId = isset($params['barangay_id']) && $params['barangay_id'] !== null ? (int) $params['barangay_id'] : null;
        $this->scopeCityIds = null;
        $this->resolveScopeProvinceIds();

        // "This month" is the Manila calendar month, as a UTC instant.
        $monthStart = Carbon::now(ReportRange::TZ)->startOfMonth()->utc()->format('Y-m-d H:i:s');

        $provinceId = $this->provinceIdExpr();
        $cityId = $this->cityIdExpr();
        $provinceName = $this->provinceNameExpr();

        // ── One grouped pass: every counter, per (province, city). The distinct
        //    province / city counts and the island split are folded from these
        //    rows in PHP, which saves a second full-join pass over the listings. ──
        $rows = $this->baseListingQuery()
            ->selectRaw("
                {$provinceId} as province_id,
                {$cityId} as city_id,
                {$provinceName} as province_name,
                COUNT(listings.id) as total,
                SUM(CASE WHEN categories.name = 'For Sale' THEN 1 ELSE 0 END) as for_sale,
                SUM(CASE WHEN categories.name = 'For Rent' THEN 1 ELSE 0 END) as for_rent,
                SUM(CASE WHEN categories.name = 'Foreclosed' THEN 1 ELSE 0 END) as foreclosure,
                SUM(CASE WHEN properties.status = 'sold' THEN 1 ELSE 0 END) as sold,
                SUM(CASE WHEN properties.status = 'rented' THEN 1 ELSE 0 END) as rented,
                SUM(CASE WHEN properties.status = 'leased' THEN 1 ELSE 0 END) as leased,
                SUM(CASE WHEN listings.visibility = 'public' THEN 1 ELSE 0 END) as public_count,
                SUM(CASE WHEN listings.verification_status = 'fully_verified' THEN 1 ELSE 0 END) as fully_verified,
                SUM(CASE WHEN listings.verification_status = 'verified' THEN 1 ELSE 0 END) as verified,
                SUM(CASE WHEN listings.verification_status = 'pending_review' THEN 1 ELSE 0 END) as pending_review,
                SUM(CASE WHEN listings.verification_status = 'flagged' THEN 1 ELSE 0 END) as flagged,
                SUM(CASE WHEN listings.verification_status IS NULL THEN 1 ELSE 0 END) as unaudited,
                SUM(CASE WHEN listings.created_at >= ? THEN 1 ELSE 0 END) as month_total,
                SUM(CASE WHEN categories.name = 'For Sale' AND listings.created_at >= ? THEN 1 ELSE 0 END) as month_for_sale,
                SUM(CASE WHEN categories.name = 'For Rent' AND listings.created_at >= ? THEN 1 ELSE 0 END) as month_for_rent,
                SUM(CASE WHEN categories.name = 'Foreclosed' AND listings.created_at >= ? THEN 1 ELSE 0 END) as month_foreclosure
            ", [$monthStart, $monthStart, $monthStart, $monthStart])
            ->groupByRaw("{$provinceId}, {$cityId}, {$provinceName}")
            ->get();

        $sum = array_fill_keys([
            'total', 'for_sale', 'for_rent', 'foreclosure', 'sold', 'rented', 'leased', 'public_count',
            'fully_verified', 'verified', 'pending_review', 'flagged', 'unaudited',
            'month_total', 'month_for_sale', 'month_for_rent', 'month_foreclosure',
        ], 0);
        $provinces = [];
        $cities = [];
        $islands = ['luzon' => 0, 'visayas' => 0, 'mindanao' => 0];
        foreach ($rows as $r) {
            foreach ($sum as $key => $_) {
                $sum[$key] += (int) $r->{$key};
            }
            if ($r->province_id !== null) {
                $provinces[(int) $r->province_id] = true;
            }
            if ($r->city_id !== null) {
                $cities[(int) $r->city_id] = true;
            }
            if ($r->province_name) {
                $island = IslandMap::islandOf($r->province_name);
                if ($island && array_key_exists($island, $islands)) {
                    $islands[$island] += (int) $r->total;
                }
            }
        }

        // ── Per property type (House / Condominium / Land / Commercial). ──
        $typeRows = $this->slimListingQuery()
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->select('property_types.name as type', DB::raw('COUNT(listings.id) as c'))
            ->groupBy('property_types.name')
            ->get();
        $types = ['house' => 0, 'condominium' => 0, 'land' => 0, 'commercial' => 0];
        foreach ($typeRows as $r) {
            $k = strtolower(trim((string) $r->type));
            if (array_key_exists($k, $types)) {
                $types[$k] = (int) $r->c;
            }
        }

        // ── The window and the period before it. ──
        $range = $this->scopeRange();
        $previous = $range !== null && $range->hasPrevious()
            ? $this->categoryCounts($range->previous())
            : null;

        return [
            'total' => $sum['total'],
            'provinces' => count($provinces),
            'cities' => count($cities),
            'for_sale' => $sum['for_sale'],
            'for_rent' => $sum['for_rent'],
            'foreclosure' => $sum['foreclosure'],
            'sold' => $sum['sold'],
            'rented' => $sum['rented'],
            'leased' => $sum['leased'],
            'types' => $types,
            'islands' => $islands,
            'month' => [
                'total' => $sum['month_total'],
                'for_sale' => $sum['month_for_sale'],
                'for_rent' => $sum['month_for_rent'],
                'foreclosure' => $sum['month_foreclosure'],
            ],
            'range' => $this->rangeMeta(),
            'previous' => $previous,
            // Anything not public is private (the column's default).
            'visibility' => [
                'public' => $sum['public_count'],
                'private' => $sum['total'] - $sum['public_count'],
            ],
            'audit' => [
                'fully_verified' => $sum['fully_verified'],
                'verified' => $sum['verified'],
                'pending_review' => $sum['pending_review'],
                'flagged' => $sum['flagged'],
                'unaudited' => $sum['unaudited'],
            ],
        ];
    }

    /**
     * The category counts of listings added inside another window, under the
     * same place and team scope.
     *
     * @return array{total: int, for_sale: int, for_rent: int, foreclosure: int}
     */
    private function categoryCounts(ReportRange $window): array
    {
        $row = $this->withWindow($window, fn () => $this->slimListingQuery()
            ->selectRaw("
                COUNT(listings.id) as total,
                SUM(CASE WHEN categories.name = 'For Sale' THEN 1 ELSE 0 END) as for_sale,
                SUM(CASE WHEN categories.name = 'For Rent' THEN 1 ELSE 0 END) as for_rent,
                SUM(CASE WHEN categories.name = 'Foreclosed' THEN 1 ELSE 0 END) as foreclosure
            ")
            ->first());

        return [
            'total' => (int) ($row->total ?? 0),
            'for_sale' => (int) ($row->for_sale ?? 0),
            'for_rent' => (int) ($row->for_rent ?? 0),
            'foreclosure' => (int) ($row->foreclosure ?? 0),
        ];
    }
}
