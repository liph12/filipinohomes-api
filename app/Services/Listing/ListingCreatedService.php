<?php

namespace App\Services\Listing;

use Illuminate\Support\Facades\DB;

/**
 * "Created Listings" — a time series of how many listings were created per
 * bucket (day or month) of listings.created_at, honoring the same
 * date/location/agent scope as the other Listing Insights services.
 */
class ListingCreatedService extends ListingInsightsService
{
    /**
     * Returns a multi-series time-line (Source-Breakdown style):
     *   {
     *     dates: string[],              // one per non-empty bucket, chronological
     *     total_series: number[],       // all categories combined, per bucket
     *     categories: [{ key, label, data: number[] }],  // per-category, per bucket
     *     total: number,                // grand total across the window
     *   }
     */
    public function createdTimeline(array $params, ?array $agentIds = null): array
    {
        $this->agentIds = $agentIds;
        $this->dateStart = $params['date_start'] ?? null;
        $this->dateEnd = $params['date_end'] ?? null;
        $this->provinceId = isset($params['province_id']) && $params['province_id'] !== null ? (int) $params['province_id'] : null;
        $this->cityId = isset($params['city_id']) && $params['city_id'] !== null ? (int) $params['city_id'] : null;
        $this->island = is_string($params['island'] ?? null) ? $params['island'] : null;
        $this->region = is_string($params['region'] ?? null) ? $params['region'] : null;
        $this->barangayId = isset($params['barangay_id']) && $params['barangay_id'] !== null ? (int) $params['barangay_id'] : null;
        $this->resolveScopeProvinceIds();

        $granularity = in_array($params['granularity'] ?? 'day', ['day', 'month'], true)
            ? $params['granularity']
            : 'day';
        $bucketExpr = $granularity === 'month'
            ? "DATE_FORMAT(listings.created_at, '%Y-%m-01')"
            : 'DATE(listings.created_at)';

        $rows = $this->baseListingQuery()
            ->select(
                DB::raw("$bucketExpr as bucket"),
                DB::raw("SUM(CASE WHEN categories.name = 'For Sale' THEN 1 ELSE 0 END) as for_sale"),
                DB::raw("SUM(CASE WHEN categories.name = 'For Rent' THEN 1 ELSE 0 END) as for_rent"),
                DB::raw("SUM(CASE WHEN categories.name = 'Foreclosure' THEN 1 ELSE 0 END) as foreclosure"),
                DB::raw('COUNT(listings.id) as c')
            )
            ->groupByRaw($bucketExpr)
            ->orderByRaw($bucketExpr)
            ->get();

        $dates = [];
        $totalSeries = [];
        $forSale = [];
        $forRent = [];
        $foreclosure = [];
        $total = 0;
        foreach ($rows as $r) {
            $dates[] = (string) $r->bucket;
            $totalSeries[] = (int) $r->c;
            $forSale[] = (int) $r->for_sale;
            $forRent[] = (int) $r->for_rent;
            $foreclosure[] = (int) $r->foreclosure;
            $total += (int) $r->c;
        }

        return [
            'dates' => $dates,
            'total_series' => $totalSeries,
            'categories' => [
                ['key' => 'for_sale', 'label' => 'For Sale', 'data' => $forSale],
                ['key' => 'for_rent', 'label' => 'For Rent', 'data' => $forRent],
                ['key' => 'foreclosure', 'label' => 'Foreclosure', 'data' => $foreclosure],
            ],
            'total' => $total,
            'meta' => [
                'granularity' => $granularity,
                'date_start' => $this->dateStart,
                'date_end' => $this->dateEnd,
                'province_id' => $this->provinceId,
                'city_id' => $this->cityId,
            ],
        ];
    }
}
