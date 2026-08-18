<?php

namespace App\Http\Controllers;

use App\Http\Resources\AdResource;
use App\Http\Resources\AdSectionResource;
use App\Models\Ad;
use App\Models\AdAnalytics;
use App\Models\AdSection;
use App\Services\AdServingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicAdController extends Controller
{
    /**
     * Stand-in for a location we could not resolve.
     *
     * ─── Why a placeholder and not NULL, and not the column default ──────────
     *
     * getGeoData() used to return null for all three columns, and ad_analytics
     * declares country/state/city NOT NULL. A column default only applies when
     * the column is OMITTED from the INSERT — passing an explicit NULL is still
     * an explicit NULL — so every untagged impression died on an integrity
     * violation. It ran at roughly 700–1,000 failures a day and every one of
     * them was an ad view that never reached the analytics table, which is why
     * the ads figures read low.
     *
     * NULL is not the fix either, even with the columns made nullable. These
     * values are firstOrCreate() lookup keys, and in SQL `country = NULL` is
     * never true — so every untagged impression would MISS the existing row and
     * insert another one, growing the table without bound and splitting one
     * ad's numbers across thousands of duplicates.
     *
     * The column defaults ('PH' / 'Manila' / 'Manila City') are worse still:
     * they would file untraceable traffic as Manila and quietly invent a
     * geographic story the ads team would then plan against. "Unknown" is the
     * honest answer, it groups, and it matches itself.
     */
    private const GEO_UNKNOWN = 'Unknown';

    public function __construct(
        private AdServingService $adServingService
    ) {}

    public function show(string $key)
    {
        $result = $this->adServingService->getAdsForSection($key);
        $ads = $result['ads'];
        $loopDuration = $result['loop_duration'];
        $section = AdSection::where('key', $key)->first();

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
        if (! $ad) {
            return response()->json(['message' => 'Ad not found'], 404);
        }

        $deviceId = $request->input('device_id');

        if (! $deviceId) {
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

        if (! Cache::has($cacheKey)) {
            $analytics->increment('impressions');

            $ttl = $this->getCacheTtl($ad);
            Cache::put($cacheKey, true, $ttl);
        }

        return response()->json(['success' => true]);
    }

    public function trackClick(Request $request, int $id)
    {
        $ad = Ad::find($id);
        if (! $ad) {
            return response()->json(['message' => 'Ad not found'], 404);
        }

        $deviceId = $request->input('device_id');
        if (! $deviceId) {
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
        if (! Cache::has($impCacheKey)) {
            $analytics = AdAnalytics::firstOrCreate($lookupKeys, $defaults);
            $analytics->increment('impressions');
            Cache::put($impCacheKey, true, $ttl);
        }

        // Always increment total_clicks
        $analytics = AdAnalytics::firstOrCreate($lookupKeys, $defaults);
        $analytics->increment('total_clicks');

        // Click dedup logic
        $cached = Cache::get($clickCacheKey);

        if (! $cached) {
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

            if (! $sameHour || ! $sameDay) {
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
        $userInfo = $request->input('user_info');

        // The frontend doesn't always send user_info (server-side
        // renders, guest tokens, OG-image bots, ad-blockers that
        // strip the payload). Bail with the placeholder instead of
        // indexing into null — these endpoints are fire-and-forget
        // trackers and shouldn't 500 just because we can't geo-tag
        // the row.
        if (! is_array($userInfo) || empty($userInfo['ip'])) {
            return [
                'country' => self::GEO_UNKNOWN,
                'state' => self::GEO_UNKNOWN,
                'city' => self::GEO_UNKNOWN,
            ];
        }

        $cacheKey = "geo_ip_{$userInfo['ip']}";

        return Cache::remember($cacheKey, now()->addHours(24), function () use ($userInfo) {
            // ?? then ?: — the key can be absent, and it can also be present
            // and empty, which a geo lookup returns more often than it returns
            // nothing at all.
            return [
                'country' => ($userInfo['country'] ?? null) ?: self::GEO_UNKNOWN,
                'state' => ($userInfo['region'] ?? null) ?: self::GEO_UNKNOWN,
                'city' => ($userInfo['city'] ?? null) ?: self::GEO_UNKNOWN,
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
        // UI filter names -> SQL grouping granularity. The frontend sends
        // hour | day | last_day | last_week | last_month | custom.
        $filter = $request->route('group');
        $groupBy = match ($filter) {
            'day', 'last_week', 'last_month', 'custom' => 'day',
            default => 'hour', // hour, last_day
        };

        // Scope to a single ad when the panel asks for one. Without this the
        // endpoint aggregates every ad's entire analytics history on each call,
        // which is fine locally but times out on production-sized data — the
        // reason the drilldown showed nothing. start/end were also being dropped
        // before (only the group was passed), so date filtering never applied.
        $adId = $request->query('ad_id');
        $start = $request->query('start');
        $end = $request->query('end');

        $ads = Ad::query()
            ->when($adId, fn ($q) => $q->whereKey($adId))
            ->getAnalytics($groupBy, $start, $end)
            ->get();

        return response()->json($ads);
    }
}
