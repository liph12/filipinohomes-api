<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\OwnerPulseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The admin main dashboard's "Business pulse" (OwnerPulseService).
 *
 *  - GET /admin/dashboard/pulse            everything in one payload (older frontends), cached a minute
 *  - GET /admin/dashboard/pulse/core       the heavy cards in one payload (older frontends), cached a minute
 *  - GET /admin/dashboard/pulse/{section}  one card (OwnerPulseService::SECTIONS), live — each card
 *                                          shows as soon as its own data is ready
 */
class DashboardPulseController extends Controller
{
    public function __invoke(OwnerPulseService $pulse): JsonResponse
    {
        return response()->json(Cache::remember('admin:dashboard:pulse', 60, fn () => $pulse->pulse()));
    }

    public function core(OwnerPulseService $pulse): JsonResponse
    {
        return response()->json(Cache::remember('admin:dashboard:pulse:core', 60, fn () => $pulse->core()));
    }

    public function section(OwnerPulseService $pulse, string $section): JsonResponse
    {
        return response()->json($pulse->section($section));
    }
}
