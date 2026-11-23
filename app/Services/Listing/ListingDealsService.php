<?php

namespace App\Services\Listing;

use App\Services\Reports\Executive\MetricRegistry;
use App\Services\Reports\Executive\ReportRange;
use Illuminate\Support\Facades\DB;

/**
 * Listing Insights → Deals: listings sold, rented or leased, counted by the day
 * the deal CLOSED — `properties.status_change_date`, the date the agent typed —
 * not the day the listing was added.
 *
 * It is the Executive Report's definition (ReportQueries::deals()): the same
 * listing chain, the same three statuses, the same half-open day window. For
 * the same dates and no place filter the two pages show the same numbers.
 * Where they differ on purpose:
 *   - All time exists here. It counts every sold / rented / leased listing,
 *     including those recorded before FH kept a deal date (`totals.undated`).
 *   - A province or city filter is one raw id here; the report folds duplicate
 *     place rows together.
 *   - A team leader sees their team's deals.
 *
 * Deal dates exist only from MetricRegistry::DATA_SINCE['deals']. A window that
 * starts earlier is `partial`: it can only ever show the deals recorded since.
 */
class ListingDealsService extends ListingInsightsService
{
    /** How many places the "where" list shows. */
    private const TOP_LOCATIONS = 8;

    /**
     * @param  array<string, mixed>  $filters  date_start, date_end, province_id, city_id, … as configure() reads them
     * @return array<string, mixed>
     */
    public function deals(array $filters, ?array $agentIds = null): array
    {
        $this->configure($filters, $agentIds);
        $this->dateBasis = 'deal';

        $since = MetricRegistry::DATA_SINCE['deals'];
        $range = $this->scopeRange();

        // Places: provinces, or the cities of the chosen province. Nothing to rank under one city.
        $byCity = $this->provinceId !== null;
        $ranked = $this->cityId === null;
        $locId = $byCity ? $this->cityIdExpr() : $this->provinceIdExpr();
        $locName = $byCity ? $this->cityNameExpr() : $this->provinceNameExpr();

        $query = ($ranked ? $this->baseListingQuery() : $this->slimListingQuery())
            ->whereIn('properties.status', self::TRANSACTION_STATUSES);
        $select = ['properties.status_change_date as deal_day', 'properties.status as status', DB::raw('COUNT(listings.id) as n')];
        $group = 'properties.status_change_date, properties.status';
        if ($ranked) {
            $select[] = DB::raw("{$locId} as loc_id");
            $select[] = DB::raw("{$locName} as loc_name");
            $group .= ", {$locId}, {$locName}";
        }
        $rows = $query->select($select)->groupByRaw($group)->get();

        $totals = ['sold' => 0, 'rented' => 0, 'leased' => 0];
        $undated = 0;
        $days = [];
        $places = [];
        foreach ($rows as $r) {
            $status = (string) $r->status;
            $n = (int) $r->n;
            $totals[$status] += $n;
            if ($r->deal_day === null) {
                $undated += $n;
            } else {
                $day = substr((string) $r->deal_day, 0, 10);
                $days[$day][$status] = ($days[$day][$status] ?? 0) + $n;
            }
            if ($ranked && $r->loc_id !== null) {
                $id = (int) $r->loc_id;
                $places[$id] ??= ['id' => $id, 'name' => (string) $r->loc_name, 'sold' => 0, 'rented' => 0, 'leased' => 0, 'total' => 0];
                $places[$id][$status] += $n;
                $places[$id]['total'] += $n;
            }
        }

        usort($places, fn (array $a, array $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        $previous = null;
        if ($range !== null && $range->hasPrevious() && $range->previous()->start >= $since) {
            $previous = $this->totalsFor($range->previous());
        }

        return [
            'since' => $since,
            'range' => $this->rangeMeta(),
            // Deal dates start at $since: all time, or a window reaching back before it, can't be complete.
            'partial' => $range === null || $range->openStart || $range->start < $since,
            'totals' => $totals + ['total' => array_sum($totals), 'undated' => $undated],
            'previous' => $previous,
            'series' => $this->series($days, $range),
            'top_locations' => array_map(
                fn (array $p) => $p + ['level' => $byCity ? 'city' : 'province'],
                array_slice($places, 0, self::TOP_LOCATIONS),
            ),
        ];
    }

    /**
     * Deals per bucket, zero-filled between the first and last day of the
     * window (or of the data, when the window has no first day).
     *
     * @param  array<string, array<string, int>>  $days  day => status => n
     * @return array{granularity: string, dates: list<string>, sold: list<int>, rented: list<int>, leased: list<int>}
     */
    private function series(array $days, ?ReportRange $range): array
    {
        $granularity = $this->autoGranularity($range);

        /** @var array<string, array{sold: int, rented: int, leased: int}> $buckets */
        $buckets = [];
        foreach ($days as $day => $byStatus) {
            $key = $this->foldDay((string) $day, $granularity);
            $buckets[$key] ??= ['sold' => 0, 'rented' => 0, 'leased' => 0];
            foreach ($byStatus as $status => $n) {
                $buckets[$key][$status] += $n;
            }
        }

        if ($range === null || ! $range->isInverted()) {
            $dated = array_keys($days);
            sort($dated);
            // A window's own first and last day span it even with no deals in it; otherwise the data's.
            $from = $range !== null && ! $range->openStart ? $range->start : ($dated[0] ?? null);
            $to = $range?->end ?? ($dated !== [] ? $dated[count($dated) - 1] : null);
            if ($from !== null && $to !== null) {
                foreach ($this->bucketKeys($from, $to, $granularity) as $key) {
                    $buckets[$key] ??= ['sold' => 0, 'rented' => 0, 'leased' => 0];
                }
            }
        }
        ksort($buckets);

        return [
            'granularity' => $granularity,
            'dates' => array_keys($buckets),
            'sold' => array_column($buckets, 'sold'),
            'rented' => array_column($buckets, 'rented'),
            'leased' => array_column($buckets, 'leased'),
        ];
    }

    /**
     * The status counts of deals closed inside another window, under the same
     * place and team scope.
     *
     * @return array{sold: int, rented: int, leased: int, total: int}
     */
    private function totalsFor(ReportRange $window): array
    {
        $row = $this->withWindow($window, fn () => $this->slimListingQuery()
            ->whereIn('properties.status', self::TRANSACTION_STATUSES)
            ->selectRaw("
                SUM(CASE WHEN properties.status = 'sold' THEN 1 ELSE 0 END) as sold,
                SUM(CASE WHEN properties.status = 'rented' THEN 1 ELSE 0 END) as rented,
                SUM(CASE WHEN properties.status = 'leased' THEN 1 ELSE 0 END) as leased
            ")
            ->first());

        $counts = ['sold' => (int) ($row->sold ?? 0), 'rented' => (int) ($row->rented ?? 0), 'leased' => (int) ($row->leased ?? 0)];

        return $counts + ['total' => array_sum($counts)];
    }
}
