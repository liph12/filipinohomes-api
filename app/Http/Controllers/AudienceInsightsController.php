<?php

namespace App\Http\Controllers;

use App\Services\Audience\AudienceGeographyService;
use App\Services\Audience\AudienceInsightsService;
use App\Services\Audience\EngagementOverviewService;
use App\Services\Audience\GeoTopChartsService;
use App\Services\Audience\SourceBreakdownService;
use App\Services\Audience\TrafficSourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller for the admin "Audience Insights" page. Resolves the date
 * range and delegates each card to its dedicated service, named to match the
 * front-end components (EngagementOverview, TrafficSource, SourceBreakdown,
 * AudienceGeography, GeoTopCharts). Admin-only (routes gated by
 * RoleMiddleware:admin).
 */
class AudienceInsightsController extends Controller
{
    public function show(
        Request $request,
        EngagementOverviewService $overview,
        TrafficSourceService $trafficSource,
        SourceBreakdownService $sourceBreakdown
    ): JsonResponse {
        [$start, $end] = $this->range($request, $overview);
        $overview->range($start, $end);

        return response()->json([
            'size'         => $overview->size(),
            'acquisition'  => $trafficSource->range($start, $end)->channels(),
            'trend'        => $overview->trend(),
            'source_trend' => $sourceBreakdown->range($start, $end)->build(),
            'meta'         => ['from' => $start, 'to' => $end],
        ]);
    }

    /**
     * Geography breakdown on its own endpoint so the AudienceGeography +
     * GeoTopCharts cards can filter by an independent date range. Admin-only
     * (route-gated).
     */
    public function geographyShow(
        Request $request,
        AudienceGeographyService $geography,
        GeoTopChartsService $geoCharts
    ): JsonResponse {
        [$start, $end] = $this->range($request, $geography);
        // Optional drill-down: scope states & cities to one country.
        $country = $request->validate(['country' => 'nullable|string|max:32'])['country'] ?? null;

        return response()->json([
            'geography' => $geography->range($start, $end)->breakdown($country),
            'trend'     => $geoCharts->range($start, $end)->build(),
            'meta'      => ['from' => $start, 'to' => $end],
        ]);
    }

    /**
     * Validate + resolve the date window shared by every endpoint. No date_start
     * → all-time (from the earliest record). The frontend ships a default, so
     * that only kicks in when the user clears the filter.
     *
     * @return array{0:string,1:string} [start, end] as 'Y-m-d'
     */
    private function range(Request $request, AudienceInsightsService $service): array
    {
        $validated = $request->validate([
            'date_start' => 'nullable|date',
            'date_end'   => 'nullable|date|after_or_equal:date_start',
        ]);

        return [
            $validated['date_start'] ?? $service->earliestDate(),
            $validated['date_end']   ?? now()->toDateString(),
        ];
    }
}
