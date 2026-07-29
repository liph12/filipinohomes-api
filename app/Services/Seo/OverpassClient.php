<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Minimal OpenStreetMap Overpass client for the facility-candidate scanner.
 * One job: named malls / universities+colleges / hospitals inside a bbox.
 *
 * Etiquette (verified against production instances 2026-07-29): public
 * instances allow ~2 concurrent slots per IP with short cooldowns — callers
 * MUST run sequentially and sleep between queries (the scan command paces
 * with --sleep, default 2500ms). Identifying User-Agent required. Failure
 * modes handled here:
 *   - 429/504 → retried with growing backoff (never retries a 400 = QL bug)
 *   - "server too busy" HTML bodies arrive even with HTTP 200 → JSON-decode
 *     is the real success check
 *   - HTTP 200 + a `remark` containing "runtime error" = the [timeout:25]
 *     expired mid-query → PARTIAL results; treated as failure (throw) so the
 *     caller never stores a truncated city.
 * After repeated primary-endpoint failures the fallback instance takes over
 * for the rest of the run.
 */
class OverpassClient
{
    /** Consecutive primary failures before flipping to the fallback endpoint. */
    private const FALLBACK_AFTER_FAILURES = 3;

    private int $primaryFailures = 0;
    private bool $useFallback = false;

    /**
     * Named facility POIs inside a bounding box.
     *
     * @return array<int, array{osm_type: string, osm_id: int, name: string, category: string, lat: float, lng: float}>
     * @throws RuntimeException on unrecoverable failure for this bbox
     */
    public function poisInBbox(float $south, float $west, float $north, float $east): array
    {
        $bbox = sprintf('%.6f,%.6f,%.6f,%.6f', $south, $west, $north, $east);
        $queryTimeout = (int) config('services.overpass.query_timeout', 25);

        // shop=mall covers SM/Ayala/Robinsons; amenity university|college the
        // campuses; amenity=hospital the hospitals. Named-only — an unnamed
        // POI can't become a "near {facility}" page. Deliberately NOT
        // amenity=marketplace (palengke noise) or building=mall (duplicates).
        $ql = <<<QL
[out:json][timeout:{$queryTimeout}];
(
  nwr["shop"="mall"]["name"]({$bbox});
  nwr["amenity"~"^(university|college)$"]["name"]({$bbox});
  nwr["amenity"="hospital"]["name"]({$bbox});
);
out tags center;
QL;

        $payload = $this->execute($ql, $queryTimeout);

        $pois = [];
        foreach ($payload['elements'] ?? [] as $el) {
            $name = trim((string) ($el['tags']['name'] ?? ''));
            $lat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $lng = $el['lon'] ?? $el['center']['lon'] ?? null;
            if ($name === '' || $lat === null || $lng === null) {
                continue;
            }

            $tags = $el['tags'];
            $category = match (true) {
                ($tags['shop'] ?? null) === 'mall' => 'mall',
                in_array($tags['amenity'] ?? null, ['university', 'college'], true) => 'school',
                ($tags['amenity'] ?? null) === 'hospital' => 'hospital',
                default => null,
            };
            if ($category === null) {
                continue;
            }

            $pois[] = [
                'osm_type' => (string) $el['type'],
                'osm_id'   => (int) $el['id'],
                'name'     => $name,
                'category' => $category,
                'lat'      => (float) $lat,
                'lng'      => (float) $lng,
            ];
        }

        return $pois;
    }

    /** POST the QL with retries/backoff and the full failure-mode checklist. */
    private function execute(string $ql, int $queryTimeout): array
    {
        $attempts = 3;
        $lastError = 'unknown';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            // Last-chance attempt goes straight to the fallback instance —
            // otherwise a persistently busy primary fails the entire city
            // (and city #1 is always our biggest inventory).
            $onFallback = $this->useFallback
                || ($attempt === $attempts && $this->primaryFailures > 0);
            $endpoint = $onFallback
                ? (string) config('services.overpass.fallback_endpoint')
                : (string) config('services.overpass.endpoint');

            try {
                $response = Http::timeout((int) config('services.overpass.timeout', 60))
                    ->withHeaders(['User-Agent' => (string) config('services.overpass.user_agent')])
                    ->asForm()
                    ->post($endpoint, ['data' => $ql]);

                if ($response->status() === 400) {
                    // QL syntax error — retrying is pointless and impolite.
                    throw new RuntimeException('Overpass rejected the query (400): ' . substr($response->body(), 0, 300));
                }

                $payload = $response->json();
                $decoded = is_array($payload);

                if ($response->successful() && $decoded) {
                    // HTTP 200 can still carry a partial result when the
                    // server-side [timeout] expired mid-run.
                    $remark = (string) ($payload['remark'] ?? '');
                    if (stripos($remark, 'runtime error') !== false) {
                        $lastError = "partial result: {$remark}";
                        // The server-side [timeout] expired mid-query — give
                        // the retry a bigger budget (capped safely below the
                        // 60s HTTP client timeout).
                        $newTimeout = min(50, $queryTimeout * 2);
                        if ($newTimeout > $queryTimeout) {
                            $ql = str_replace("[timeout:{$queryTimeout}]", "[timeout:{$newTimeout}]", $ql);
                            $queryTimeout = $newTimeout;
                        }
                    } else {
                        if (! $onFallback) {
                            $this->primaryFailures = 0;
                        }

                        return $payload;
                    }
                } else {
                    // 429/504, or a "too busy" HTML body (even on HTTP 200).
                    $lastError = 'HTTP ' . $response->status() . ($decoded ? '' : ' (non-JSON body)');
                }

                if ($response->status() === 429) {
                    sleep(30); // explicit slot-cooldown wait before the backoff sleep
                }
            } catch (RuntimeException $e) {
                throw $e; // the 400 above
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }

            if (! $onFallback) {
                $this->noteFailure();
            }
            if ($attempt < $attempts) {
                sleep($attempt * 10);
            }
        }

        throw new RuntimeException("Overpass query failed after {$attempts} attempts: {$lastError}");
    }

    private function noteFailure(): void
    {
        if ($this->useFallback) {
            return;
        }
        if (++$this->primaryFailures >= self::FALLBACK_AFTER_FAILURES) {
            $this->useFallback = true;
            Log::warning('OverpassClient: switching to fallback endpoint after repeated primary failures.');
        }
    }
}
