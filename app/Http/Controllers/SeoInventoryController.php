<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Services\Seo\SeoTierStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregation for the admin SEO Manage Overview tile: per-tier
 * inventory + freshness, facility registry health, and recent snapshots.
 * Cached briefly (the aggregates hit four derived tables); RunSeoCommand
 * busts the cache when a run finishes so freshness updates immediately.
 */
class SeoInventoryController extends Controller
{
    public const CACHE_KEY = 'admin:seo:overview';

    public function overview(SeoTierStatsService $stats): JsonResponse
    {
        $payload = Cache::remember(self::CACHE_KEY, 600, function () use ($stats) {
            return [
                'tiers'      => $stats->tiers(),
                'facilities' => [
                    'total'          => Facility::count(),
                    'active'         => Facility::active()->count(),
                    'geocoded'       => Facility::active()->geocoded()->count(),
                    'missing_coords' => Facility::active()
                        ->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lng'))
                        ->count(),
                ],
                // Last few snapshot days so the Overview can show a simple
                // "vs yesterday" once history accumulates (alarms = Phase 2).
                'snapshots' => DB::table('seo_tier_snapshots')
                    ->where('snapshot_date', '>=', now()->subDays(8)->toDateString())
                    ->orderBy('snapshot_date')
                    ->get(['snapshot_date', 'tier', 'row_count', 'eligible_count']),
                'generated_at' => now()->toIso8601String(),
            ];
        });

        return response()->json(['data' => $payload]);
    }
}
