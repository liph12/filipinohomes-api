<?php

namespace App\Http\Controllers;

use App\Models\Ad;
use App\Models\AdAnalytics;
use App\Services\AdServingService;
use App\Http\Resources\AdResource;
use App\Http\Resources\AdSectionResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicAdController extends Controller
{
    public function __construct(
        private AdServingService $adServingService
    ) {}

    public function show(string $key)
    {
        $result = $this->adServingService->getAdsForSection($key);
        $ads = $result['ads'];
        $loopDuration = $result['loop_duration'];
        $section = \App\Models\AdSection::where('key', $key)->first();

        if ($ads->isEmpty()) {
            return response()->json([
                'data' => [],
                'section' => $section ? new AdSectionResource($section) : null,
                'loop_duration' => $loopDuration,
            ]);
        }

        return response()->json([
            'data' => AdResource::collection($ads),
            'section' => $section ? new AdSectionResource($section) : null,
            'loop_duration' => $loopDuration,
        ]);
    }

    public function trackImpression(Request $request, int $id)
    {
        $ad = Ad::find($id);
        if (!$ad) {
            return response()->json(['message' => 'Ad not found'], 404);
        }

        $deviceId = $request->input('device_id');
        
        if (!$deviceId) {
            return response()->json(['success' => false, 'message' => 'device_id required'], 422);
        }

        $cacheKey = "{$deviceId}_{$id}_imp";
        $now = now('Asia/Manila');
        $geo = $this->getGeoData($request);

        $analytics = AdAnalytics::firstOrCreate(
            [
                'ad_id' => $id,
                'country' => $geo['country'],
                'state' => $geo['state'],
                'city' => $geo['city'],
                'created_hour_at' => $now->format('H'),
                'created_date_at' => $now->toDateString(),
            ],
            [
                'impressions' => 0,
                'total_impressions' => 0,
                'clicks' => 0,
                'total_clicks' => 0,
            ]
        );
        $analytics->increment('total_impressions');

        if (!Cache::has($cacheKey)) {
            $analytics->increment('impressions');

            $ttl = $this->getCacheTtl($ad);
            Cache::put($cacheKey, true, $ttl);
        }

        return response()->json(['success' => true]);
    }

    public function trackClick(Request $request, int $id)
    {
        $ad = Ad::find($id);
        if (!$ad) {
            return response()->json(['message' => 'Ad not found'], 404);
        }

        $deviceId = $request->input('device_id');
        if (!$deviceId) {
            return response()->json(['success' => false, 'message' => 'device_id required'], 422);
        }

        $impCacheKey = "{$deviceId}_{$id}_imp";
        $clickCacheKey = "{$deviceId}_{$id}_click";
        $now = now('Asia/Manila');
        $ttl = $this->getCacheTtl($ad);
        $geo = $this->getGeoData($request);

        $lookupKeys = [
            'ad_id' => $id,
            'country' => $geo['country'],
            'state' => $geo['state'],
            'city' => $geo['city'],
            'created_hour_at' => $now->format('H'),
            'created_date_at' => $now->toDateString(),
        ];

        $defaults = [
            'impressions' => 0,
            'total_impressions' => 0,
            'clicks' => 0,
            'total_clicks' => 0,
        ];

        // If no impression was recorded yet, record it now
        if (!Cache::has($impCacheKey)) {
            $analytics = AdAnalytics::firstOrCreate($lookupKeys, $defaults);
            $analytics->increment('impressions');
            Cache::put($impCacheKey, true, $ttl);
        }

        // Always increment total_clicks
        $analytics = AdAnalytics::firstOrCreate($lookupKeys, $defaults);
        $analytics->increment('total_clicks');

        // Click dedup logic
        $cached = Cache::get($clickCacheKey);

        if (!$cached) {
            // First click — record it
            $analytics = AdAnalytics::firstOrCreate($lookupKeys, $defaults);
            $analytics->increment('clicks');

            Cache::put($clickCacheKey, [
                'hour' => $now->format('H'),
                'date' => $now->toDateString(),
            ], $ttl);
        } else {
            $cachedHour = $cached['hour'];
            $cachedDate = $cached['date'];
            $currentHour = $now->format('H');
            $currentDate = $now->toDateString();

            $sameHour = $cachedHour === $currentHour;
            $sameDay = $cachedDate === $currentDate;

            if (!$sameHour || !$sameDay) {
                // Different hour OR different day — allow click
                $analytics = AdAnalytics::firstOrCreate(
                    [
                        ...$lookupKeys,
                        'created_hour_at' => $currentHour,
                        'created_date_at' => $currentDate,
                    ],
                    $defaults
                );
                $analytics->increment('clicks');

                Cache::put($clickCacheKey, [
                    'hour' => $currentHour,
                    'date' => $currentDate,
                ], $ttl);
            }
            // Same hour + same day → no action (total_clicks already incremented above)
        }

        return response()->json([
            'success' => true,
            'click_url' => $ad->click_url,
        ]);
    }

    private function getGeoData(Request $request): array
    {
        $userInfo = $request->input("user_info");

        // The frontend doesn't always send user_info (server-side
        // renders, guest tokens, OG-image bots, ad-blockers that
        // strip the payload). Bail with nulls instead of indexing
        // into null — these endpoints are fire-and-forget trackers
        // and shouldn't 500 just because we can't geo-tag the row.
        if (!is_array($userInfo) || empty($userInfo['ip'])) {
            return [
                'country' => null,
                'state'   => null,
                'city'    => null,
            ];
        }

        $cacheKey = "geo_ip_{$userInfo['ip']}";

        return Cache::remember($cacheKey, now()->addHours(24), function () use ($userInfo) {
            return [
                'country' => $userInfo['country'] ?? null,
                'state'   => $userInfo['region']  ?? null,
                'city'    => $userInfo['city']    ?? null,
            ];
        });
    }

    private function getCacheTtl(Ad $ad): \DateTimeInterface
    {
        $endsAt = $ad->campaign?->ends_at;

        if ($endsAt && $endsAt->isFuture()) {
            return $endsAt;
        }

        // Fallback: 30 days if no campaign end date
        return now('Asia/Manila')->addDays(30);
    }

    public function getAnalytics(Request $request)
    {
        $gr = $request->group;
        $hours = Ad::getAnalytics($gr)->get();

        return response()->json($hours);
    }
}
