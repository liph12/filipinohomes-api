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
        return response()->json(Cache::remember('admin:client-insights:v16', 600, fn () => $clients->summary()));
    }

    /** GET /admin/client-insights/replies?date_start=&date_end= — the reply section for a date range. */
    public function replies(Request $request, ClientInsightsService $clients): JsonResponse
    {
        $data = $request->validate([
            'date_start' => 'required|date_format:Y-m-d',
            'date_end' => 'required|date_format:Y-m-d|after_or_equal:date_start',
        ]);

        return response()->json(Cache::remember(
            "admin:client-insights:replies:v1:{$data['date_start']}:{$data['date_end']}",
            600,
            fn () => $clients->repliesBetween($data['date_start'], $data['date_end']),
        ));
    }
}
