<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\User\ClientInsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The admin Client Insights page (ClientInsightsService), cached for ten minutes. Bump the key's
 * version whenever the payload's shape changes, so a deploy never serves the old shape to new code.
 */
class ClientInsightsController extends Controller
{
    public function __invoke(ClientInsightsService $clients): JsonResponse
    {
        return response()->json(Cache::remember('admin:client-insights:v18', 600, fn () => $clients->summary()));
    }

    /** GET /admin/client-insights/missed-reply — "What a missed reply costs" alone, for the main dashboard. */
    public function missedReply(ClientInsightsService $clients): JsonResponse
    {
        return response()->json(Cache::remember('admin:client-insights:missed-reply:v1', 600, fn () => $clients->missedReplySummary()));
    }

    /** GET /admin/client-insights/replies?date_start=&date_end= — the reply section for a date range, all time without dates. */
    public function replies(Request $request, ClientInsightsService $clients): JsonResponse
    {
        $data = $request->validate([
            'date_start' => 'nullable|required_with:date_end|date_format:Y-m-d',
            'date_end' => 'nullable|required_with:date_start|date_format:Y-m-d|after_or_equal:date_start',
        ]);
        $start = $data['date_start'] ?? null;
        $end = $data['date_end'] ?? null;

        return response()->json(Cache::remember(
            'admin:client-insights:replies:v3:'.($start ? "{$start}:{$end}" : 'all'),
            600,
            fn () => $clients->repliesBetween($start, $end),
        ));
    }
}
