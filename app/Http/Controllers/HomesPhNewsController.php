<?php

namespace App\Http\Controllers;

use App\Models\NewsAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side proxy for the HomesPhNews External Articles API.
 *
 * The browser never talks to HomesPhNews directly. This controller attaches
 * the X-Site-Key header server-side (so the partner API key stays out of
 * frontend bundles) and forwards calls to the upstream endpoints, bypassing
 * the partner API's Origin validation for localhost/preview environments.
 *
 * Upstream contract:
 *   GET {base_url}/external/articles                 → list
 *   GET {base_url}/external/articles/{identifier}    → single (slug or UUID)
 */
class HomesPhNewsController extends Controller
{
    /**
     * List articles. Forwards all query params to HomesPhNews.
     */
    public function index(Request $request): JsonResponse
    {
        $baseUrl = rtrim((string) config('services.homesphnews.base_url'), '/');
        $apiKey = (string) config('services.homesphnews.key');

        if ($baseUrl === '' || $apiKey === '') {
            return response()->json(
                ['message' => 'HomesPhNews integration is not configured.'],
                503
            );
        }

        $query = $request->query();
        // Cache key is scoped to the query string so different pages / filters
        // are cached independently. 5 minute TTL is a sensible default for
        // news lists — tune if needed.
        $cacheKey = 'homesphnews:list:'.md5(http_build_query($query));

        $cached = Cache::get($cacheKey);
        if ($cached === null) {
            try {
                $response = Http::withHeaders([
                    'X-Site-Key' => $apiKey,
                    'Accept' => 'application/json',
                ])
                    ->timeout(15)
                    ->get($baseUrl.'/external/articles', $query);

                $cached = [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ];
            } catch (\Throwable $e) {
                Log::warning('HomesPhNews list fetch failed', [
                    'error' => $e->getMessage(),
                ]);

                $cached = [
                    'status' => 502,
                    'body' => ['message' => 'Upstream news service unavailable.'],
                ];
            }

            // Only cache a successful response. Caching a transient upstream
            // error (5xx / 502) would keep the news blank for the whole TTL even
            // after the upstream recovers — the "no news until later" bug.
            if ($cached['status'] >= 200 && $cached['status'] < 300) {
                Cache::put($cacheKey, $cached, now()->addMinutes(5));
            }
        }

        $body = $cached['body'];
        if (is_array($body)) {
            $this->applyMetricsToListResponse($body);
        }

        return response()->json($body, $cached['status']);
    }

    /**
     * Fetch a single article by slug or UUID.
     */
    public function show(string $identifier): JsonResponse
    {
        $baseUrl = rtrim((string) config('services.homesphnews.base_url'), '/');
        $apiKey = (string) config('services.homesphnews.key');

        if ($baseUrl === '' || $apiKey === '') {
            return response()->json(
                ['message' => 'HomesPhNews integration is not configured.'],
                503
            );
        }

        $cacheKey = 'homesphnews:article:'.$identifier;

        $cached = Cache::get($cacheKey);
        if ($cached === null) {
            try {
                $response = Http::withHeaders([
                    'X-Site-Key' => $apiKey,
                    'Accept' => 'application/json',
                ])
                    ->timeout(15)
                    ->get($baseUrl.'/external/articles/'.rawurlencode($identifier));

                $cached = [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ];
            } catch (\Throwable $e) {
                Log::warning('HomesPhNews article fetch failed', [
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);

                $cached = [
                    'status' => 502,
                    'body' => ['message' => 'Upstream news service unavailable.'],
                ];
            }

            // Only cache a successful response so a transient upstream error
            // doesn't stick for the whole TTL.
            if ($cached['status'] >= 200 && $cached['status'] < 300) {
                Cache::put($cacheKey, $cached, now()->addMinutes(30));
            }
        }

        $body = $cached['body'];
        if (is_array($body)) {
            $this->applyMetricsToSingleResponse($body, $identifier);
        }

        return response()->json($body, $cached['status']);
    }

    /**
     * Track an impression (once per device per day).
     */
    public function trackImpression(Request $request, string $identifier): JsonResponse
    {
        $recorded = $this->recordEvent($request, $identifier, 'impression');
        $stats = $this->getCurrentStats($identifier);

        return response()->json([
            'success' => true,
            'recorded' => $recorded,
            'identifier' => $identifier,
            'impressions' => $stats['impressions'],
            'clicks' => $stats['clicks'],
        ]);
    }

    /**
     * Track a click (once per device per day).
     */
    public function trackClick(Request $request, string $identifier): JsonResponse
    {
        $clickRecorded = $this->recordEvent($request, $identifier, 'click');
        // A click implies visibility; ensure at least one daily impression is tracked too.
        $this->recordEvent($request, $identifier, 'impression');

        $stats = $this->getCurrentStats($identifier);

        return response()->json([
            'success' => true,
            'recorded' => $clickRecorded,
            'identifier' => $identifier,
            'impressions' => $stats['impressions'],
            'clicks' => $stats['clicks'],
        ]);
    }

    private function applyMetricsToListResponse(array &$body): void
    {
        $articles = null;

        if (isset($body['data']) && is_array($body['data']) && isset($body['data']['data']) && is_array($body['data']['data'])) {
            $articles = &$body['data']['data'];
        } elseif (isset($body['data']) && is_array($body['data']) && array_is_list($body['data'])) {
            $articles = &$body['data'];
        } elseif (array_is_list($body)) {
            $articles = &$body;
        }

        if (! is_array($articles)) {
            return;
        }

        $identifiers = [];
        foreach ($articles as $article) {
            if (! is_array($article)) {
                continue;
            }
            $identifier = $this->extractIdentifier($article);
            if ($identifier !== null) {
                $identifiers[] = $identifier;
            }
        }

        if (empty($identifiers)) {
            return;
        }

        $metrics = NewsAnalytics::query()
            ->whereIn('identifier', array_values(array_unique($identifiers)))
            ->get()
            ->keyBy('identifier');

        foreach ($articles as &$article) {
            if (! is_array($article)) {
                continue;
            }
            $identifier = $this->extractIdentifier($article);
            if ($identifier === null) {
                continue;
            }

            /** @var NewsAnalytics|null $metric */
            $metric = $metrics->get($identifier);
            if ($metric === null) {
                continue;
            }

            $article['views'] = number_format((int) $metric->impressions).' views';
            $article['views_count'] = (int) $metric->clicks;
        }
    }

    private function applyMetricsToSingleResponse(array &$body, string $fallbackIdentifier): void
    {
        if (! isset($body['article']) || ! is_array($body['article'])) {
            return;
        }

        $article = &$body['article'];
        $identifier = $this->extractIdentifier($article) ?? $fallbackIdentifier;
        $metric = NewsAnalytics::query()->where('identifier', $identifier)->first();

        if (! $metric) {
            return;
        }

        $article['views'] = number_format((int) $metric->impressions).' views';
        $article['views_count'] = (int) $metric->clicks;
    }

    private function extractIdentifier(array $article): ?string
    {
        $slug = isset($article['slug']) ? trim((string) $article['slug']) : '';
        if ($slug !== '') {
            return $slug;
        }

        $id = isset($article['id']) ? trim((string) $article['id']) : '';

        return $id !== '' ? $id : null;
    }

    private function getDeviceId(Request $request): string
    {
        $headerDeviceId = trim((string) $request->header('X-Device-Id', ''));
        if ($headerDeviceId !== '') {
            return $headerDeviceId;
        }

        $bodyDeviceId = trim((string) $request->input('device_id', ''));
        if ($bodyDeviceId !== '') {
            return $bodyDeviceId;
        }

        $fallback = ($request->ip() ?? 'unknown').'|'.((string) $request->userAgent() ?: 'ua');

        return hash('sha256', $fallback);
    }

    private function recordEvent(Request $request, string $identifier, string $type): bool
    {
        $normalizedIdentifier = trim((string) $identifier);
        if ($normalizedIdentifier === '') {
            return false;
        }

        $deviceId = $this->getDeviceId($request);
        $cacheKey = "homesphnews:{$type}:{$normalizedIdentifier}:{$deviceId}";

        if (Cache::has($cacheKey)) {
            return false;
        }

        $metric = NewsAnalytics::query()->firstOrCreate(
            ['identifier' => $normalizedIdentifier],
            ['impressions' => 0, 'clicks' => 0],
        );

        if ($type === 'impression') {
            $metric->increment('impressions');
        } else {
            $metric->increment('clicks');
        }

        Cache::put($cacheKey, true, now()->addYear());

        return true;
    }

    private function getCurrentStats(string $identifier): array
    {
        $metric = NewsAnalytics::query()->where('identifier', $identifier)->first();
        if (! $metric) {
            return ['impressions' => 0, 'clicks' => 0];
        }

        return [
            'impressions' => (int) $metric->impressions,
            'clicks' => (int) $metric->clicks,
        ];
    }
}
