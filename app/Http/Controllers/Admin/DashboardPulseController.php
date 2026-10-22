<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\OwnerPulseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/** The admin main dashboard's "Business pulse" (OwnerPulseService), cached for a minute. */
class DashboardPulseController extends Controller
{
    public function __invoke(OwnerPulseService $pulse): JsonResponse
    {
        return response()->json(Cache::remember('admin:dashboard:pulse', 60, fn () => $pulse->pulse()));
    }
}
