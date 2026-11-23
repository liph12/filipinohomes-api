<?php

namespace App\Services\Listing;

use App\Services\Reports\Executive\ReportRange;
use Illuminate\Support\Facades\DB;

/**
 * "Created Listings" — a time series of how many listings were created per
 * bucket (day, week or month) of listings.created_at, honoring the same
 * date/location/agent scope as the other Listing Insights services.
 *
 * Buckets are Asia/Manila calendar days: a listing added at 23:30 on Oct 31
 * Manila time belongs to Oct 31 and to October, not to the UTC day it was
 * stored under.
 */
class ListingCreatedService extends ListingInsightsService
{
    /**
     * Returns a multi-series time-line (Source-Breakdown style):
     *   {
     *     dates: string[],              // one per bucket (non-empty ones, or every one with `fill`), chronological
     *     total_series: number[],       // all categories combined, per bucket
     *     categories: [{ key, label, data: number[] }],  // per-category, per bucket
     *     total: number,                // grand total across the window
     *   }
     *
     * `granularity`: day | week (Sunday-first) | month | auto (days for a window
     * of 62 days or fewer, otherwise months). `fill`: include empty buckets.
     */
    public function createdTimeline(array $params, ?array $agentIds = null): array
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

        $range = $this->scopeRange();
        $requested = in_array($params['granularity'] ?? 'day', ['day', 'week', 'month', 'auto'], true)
            ? $params['granularity']
            : 'day';
        $granularity = $requested === 'auto' ? $this->autoGranularity($range) : $requested;

        // One grouped pass per Manila day; weeks and months fold from it in PHP.
        $dayExpr = $this->manilaDayExpr('listings.created_at');
        $rows = $this->slimListingQuery()
            ->select(
                DB::raw("{$dayExpr} as bucket_day"),
                DB::raw("SUM(CASE WHEN categories.name = 'For Sale' THEN 1 ELSE 0 END) as for_sale"),
                DB::raw("SUM(CASE WHEN categories.name = 'For Rent' THEN 1 ELSE 0 END) as for_rent"),
                DB::raw("SUM(CASE WHEN categories.name = 'Foreclosed' THEN 1 ELSE 0 END) as foreclosure"),
                DB::raw('COUNT(listings.id) as c')
            )
            ->groupByRaw($dayExpr)
            ->orderByRaw($dayExpr)
            ->get();

        /** @var array<string, array{for_sale: int, for_rent: int, foreclosure: int, c: int}> $buckets */
        $buckets = [];
        $firstDay = null;
        $lastDay = null;
        foreach ($rows as $r) {
            if ($r->bucket_day === null) {
                continue;
            }
            $day = (string) $r->bucket_day;
            $firstDay ??= $day;
            $lastDay = $day;
            $key = $this->foldDay($day, $granularity);
            $buckets[$key] ??= ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0, 'c' => 0];
            $buckets[$key]['for_sale'] += (int) $r->for_sale;
            $buckets[$key]['for_rent'] += (int) $r->for_rent;
            $buckets[$key]['foreclosure'] += (int) $r->foreclosure;
            $buckets[$key]['c'] += (int) $r->c;
        }

        // A window with a first and last day fills from them — even when nothing was created in it, so
        // a quiet week is a row of zeros, not an empty chart. Without them (all time, or no start) the
        // data's own first and last day stand in, and with no data there is nothing to span.
        if (! empty($params['fill']) && ($range === null || ! $range->isInverted())) {
            $from = $range !== null && ! $range->openStart ? $range->start : $firstDay;
            $to = $range?->end ?? $lastDay;
            if ($from !== null && $to !== null) {
                foreach ($this->bucketKeys($from, $to, $granularity) as $key) {
                    $buckets[$key] ??= ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0, 'c' => 0];
                }
            }
        }
        ksort($buckets);

        $dates = array_keys($buckets);
        $forSale = array_column($buckets, 'for_sale');
        $forRent = array_column($buckets, 'for_rent');
        $foreclosure = array_column($buckets, 'foreclosure');
        $totalSeries = array_column($buckets, 'c');

        return [
            'dates' => $dates,
            'total_series' => $totalSeries,
            'categories' => [
                ['key' => 'for_sale', 'label' => 'For Sale', 'data' => $forSale],
                ['key' => 'for_rent', 'label' => 'For Rent', 'data' => $forRent],
                ['key' => 'foreclosure', 'label' => 'Foreclosed', 'data' => $foreclosure],
            ],
            'total' => array_sum($totalSeries),
            'meta' => [
                'granularity' => $granularity,
                'requested_granularity' => $requested,
                'timezone' => ReportRange::TZ,
                'date_start' => $this->dateStart,
                'date_end' => $this->dateEnd,
                'province_id' => $this->provinceId,
                'city_id' => $this->cityId,
            ],
        ];
    }

}
