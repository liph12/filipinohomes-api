<?php

namespace App\Http\Controllers;

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
        $cacheKey = 'homesphnews:list:' . md5(http_build_query($query));

        $cached = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($baseUrl, $apiKey, $query) {
            try {
                $response = Http::withHeaders([
                    'X-Site-Key' => $apiKey,
                    'Accept' => 'application/json',
                ])
                    ->timeout(15)
                    ->get($baseUrl . '/external/articles', $query);

                return [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ];
            } catch (\Throwable $e) {
                Log::warning('HomesPhNews list fetch failed', [
                    'error' => $e->getMessage(),
                ]);

                return [
                    'status' => 502,
                    'body' => ['message' => 'Upstream news service unavailable.'],
                ];
            }
        });

        return response()->json($cached['body'], $cached['status']);
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

        $cacheKey = 'homesphnews:article:' . $identifier;

        $cached = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($baseUrl, $apiKey, $identifier) {
            try {
                $response = Http::withHeaders([
                    'X-Site-Key' => $apiKey,
                    'Accept' => 'application/json',
                ])
                    ->timeout(15)
                    ->get($baseUrl . '/external/articles/' . rawurlencode($identifier));

                return [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ];
            } catch (\Throwable $e) {
                Log::warning('HomesPhNews article fetch failed', [
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'status' => 502,
                    'body' => ['message' => 'Upstream news service unavailable.'],
                ];
            }
        });

        return response()->json($cached['body'], $cached['status']);
    }
}
