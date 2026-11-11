<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\Executive\Analysis\ExecutiveAnalysisService;
use App\Services\Reports\Executive\DrillService;
use App\Services\Reports\Executive\ExecutiveReportService;
use App\Services\Reports\Executive\ExplorerService;
use App\Services\Reports\Executive\Geography;
use App\Services\Reports\Executive\MetricRegistry;
use App\Services\Reports\Executive\ReportQueries;
use App\Services\Reports\Executive\ReportRange;
use App\Services\Reports\Executive\SoldClaims\SoldClaimReport;
use App\Support\OfficeRegionMap;
use App\Support\RegionMap;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The admin Executive Report (/admin/insights-analytics/executive-report):
 * one page on how FH is used and performing, where every number opens the
 * records behind it.
 *
 * Dates are Asia/Manila calendar days (`date_start` / `date_end`, Y-m-d, both
 * included); without them the report covers the last 30 days. A day after
 * today is clamped to today, and a range covers at most 366 days.
 */
class ExecutiveReportController extends Controller
{
    /** Drill filters that are ids (0 selects the "none" bucket where it has one). */
    private const ID_FILTERS = ['city_id', 'province_id', 'team_id', 'agent_id', 'type_id', 'project_id', 'listing_id'];

    /** GET /admin/executive-report?date_start=&date_end=&refresh=1 */
    public function summary(Request $request, ExecutiveReportService $report): JsonResponse
    {
        $range = $this->range($request);

        try {
            return response()->json($report->summary($range, Carbon::now(), $request->boolean('refresh')));
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'The report for these dates is still being built — try again in a minute.'], 503);
        }
    }

    /**
     * GET /admin/executive-report/drill?metric=&date_start=&date_end=&page=&per_page=&sort=&dir=&<filter>=
     * The records behind one number; `meta.total` equals the number clicked.
     */
    public function drill(Request $request, DrillService $drill): JsonResponse
    {
        $data = $request->validate([
            'metric' => ['required', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.DrillService::MAX_PER_PAGE],
            'sort' => ['nullable', 'string', 'max:32'],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);
        if (! MetricRegistry::has($data['metric'])) {
            throw ValidationException::withMessages(['metric' => 'Unknown metric.']);
        }
        $range = $this->range($request);
        $filters = $this->filters($request);

        try {
            return response()->json($drill->drill(
                $data['metric'], $range, Carbon::now(), $filters,
                (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25), $data['sort'] ?? null, $data['dir'] ?? null,
            ));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['filters' => $e->getMessage()]);
        }
    }

    /**
     * GET /admin/executive-report/explorer?type=all|<id>&province_id=&city_id=&project_id=&date_start=&date_end=
     * The Market explorer: boxes by type → town → project → units.
     */
    public function explorer(Request $request, ExplorerService $explorer): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'regex:/^(all|\d{1,9})$/'],
            'province_id' => ['nullable', 'integer', 'min:0'],
            'city_id' => ['nullable', 'integer', 'min:0'],
            'project_id' => ['nullable', 'integer', 'min:0'],
        ]);
        $range = $this->range($request);

        $path = [];
        if (($data['type'] ?? null) !== null) {
            $path['type'] = $data['type'] === 'all' ? 'all' : (int) $data['type'];
        }
        foreach (['province_id', 'city_id', 'project_id'] as $key) {
            if (($data[$key] ?? null) !== null) {
                $path[$key] = (int) $data[$key];
            }
        }
        if (isset($path['project_id']) && ! isset($path['city_id'])) {
            throw ValidationException::withMessages(['project_id' => 'Pick a town before a project.']);
        }
        if (isset($path['city_id']) && ! isset($path['type'])) {
            $path['type'] = 'all';
        }

        return response()->json($explorer->explore($range, Carbon::now(), $path));
    }

    /**
     * GET /admin/executive-report/sold-claims?tier=strong|medium&team_id=&agent_id=&page=&per_page=&date_start=&date_end=
     * Listings an agent said were sold (or reserved) and are still not marked — one row per listing.
     */
    public function soldClaims(Request $request, SoldClaimReport $claims): JsonResponse
    {
        $data = $request->validate([
            'tier' => ['nullable', 'in:strong,medium'],
            'team_id' => ['nullable', 'integer', 'min:0'],
            'agent_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.SoldClaimReport::MAX_PER_PAGE],
        ]);
        $range = $this->range($request);
        $filters = array_map('intval', array_filter(
            ['agent_id' => $data['agent_id'] ?? null, 'team_id' => $data['team_id'] ?? null],
            fn ($v) => $v !== null,
        ));
        $tier = $data['tier'] ?? 'strong';
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 25);

        return response()->json(Cache::remember(
            'exec-report:v1:sold-claims:'.$range->key().':'.sha1(json_encode([$tier, $filters, $page, $perPage])),
            300,
            fn () => $claims->page($range, Carbon::now(), $tier, $filters, $page, $perPage),
        ));
    }

    /** GET /admin/executive-report/analysis?date_start=&date_end= — the saved AI analysis; never calls OpenAI. */
    public function analysis(Request $request, ExecutiveAnalysisService $ai): JsonResponse
    {
        return response()->json($ai->stored($this->range($request), Carbon::now()));
    }

    /**
     * POST /admin/executive-report/analysis {date_start, date_end, regenerate?} — write (or reuse) it.
     * Always 200 with a `status` (ok | unavailable | error | busy | limited); the route is throttled.
     */
    public function generateAnalysis(Request $request, ExecutiveAnalysisService $ai): JsonResponse
    {
        $data = $request->validate(['regenerate' => ['nullable', 'boolean']]);

        return response()->json($ai->generate(
            $this->range($request), Carbon::now(), $request->user()?->id, (bool) ($data['regenerate'] ?? false),
        ));
    }

    /** The requested Manila-day range, or the last 30 days. */
    private function range(Request $request): ReportRange
    {
        $data = $request->validate([
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $end = $data['date_end'] ?? ReportRange::today();
        $start = $data['date_start'] ?? Carbon::createFromFormat('!Y-m-d', $end, ReportRange::TZ)->subDays(29)->format('Y-m-d');

        try {
            return ReportRange::resolve($start, $end);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['date_start' => $e->getMessage()]);
        }
    }

    /** The drill filters present on the request, validated (whether they suit the metric is the drill's check). */
    private function filters(Request $request): array
    {
        $rules = array_fill_keys(self::ID_FILTERS, ['nullable', 'integer', 'min:0']) + [
            'psa_region' => ['nullable', Rule::in([...RegionMap::REGIONS, Geography::UNKNOWN_REGION])],
            'island' => ['nullable', Rule::in(['luzon', 'visayas', 'mindanao'])],
            'office_region' => ['nullable', Rule::in([...OfficeRegionMap::REGIONS, 'unassigned'])],
            'category' => ['nullable', Rule::in(array_keys(ReportQueries::CATEGORIES))],
            'status' => ['nullable', 'string', 'max:16', 'alpha_dash'],
            'role' => ['nullable', 'string', 'max:16', 'alpha_dash'],
        ];
        $filters = array_filter($request->validate($rules), fn ($v) => $v !== null && $v !== '');

        foreach (self::ID_FILTERS as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = (int) $filters[$key];
            }
        }
        ksort($filters);

        return $filters;
    }
}
