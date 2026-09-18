<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Google Analytics Data API (+ Search Console) client — no SDK.
 *
 * The service account key ships as GA_SA_KEY_BASE64 (the JSON key file,
 * base64-encoded so the multiline private key survives .env formats) and the
 * account must be added as a Viewer on the GA4 property (GA4_PROPERTY_ID)
 * and, for gscQuery(), a Restricted user on the Search Console property
 * (GSC_SITE). We mint the RS256 service-account JWT locally (openssl) and
 * exchange it at Google's token endpoint — the same raw-HTTP style as
 * YouTubeService, so no google/apiclient dependency.
 *
 * Access tokens are cached ~58 min in the shared cache (file cache on api2)
 * so every PHP-FPM worker reuses one token per scope instead of re-minting.
 */
class GaDataService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const GA_SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
    private const GSC_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function configured(): bool
    {
        return (bool) (config('services.ga4.property_id') && $this->key());
    }

    /** @return array{client_email: string, private_key: string}|null */
    private function key(): ?array
    {
        $b64 = trim((string) config('services.ga4.sa_key_base64'));
        if ($b64 === '') {
            return null;
        }

        $json = base64_decode($b64, true);
        $parsed = $json !== false ? json_decode($json, true) : null;

        return is_array($parsed) && ! empty($parsed['client_email']) && ! empty($parsed['private_key'])
            ? ['client_email' => $parsed['client_email'], 'private_key' => $parsed['private_key']]
            : null;
    }

    private function accessToken(string $scope): string
    {
        return Cache::remember('ga:token:'.md5($scope), 3500, function () use ($scope) {
            $key = $this->key();
            if (! $key) {
                throw new RuntimeException('Google service account is not configured.');
            }

            $b64url = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
            $now = time();
            $header = $b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $b64url(json_encode([
                'iss' => $key['client_email'],
                'scope' => $scope,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            $signature = '';
            if (! openssl_sign("{$header}.{$claims}", $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Failed to sign the Google service-account JWT.');
            }
            $jwt = "{$header}.{$claims}.".$b64url($signature);

            $response = Http::asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            $token = $response->json('access_token');
            if (! $response->successful() || ! $token) {
                throw new RuntimeException(
                    'Google auth failed: '.($response->json('error_description') ?? $response->status()),
                );
            }

            return $token;
        });
    }

    /** @return array GA4 Data API response (rows of dimensionValues/metricValues) */
    public function runReport(array $body): array
    {
        return $this->gaPost('runReport', $body);
    }

    /** @return array GA4 realtime response */
    public function runRealtimeReport(array $body): array
    {
        return $this->gaPost('runRealtimeReport', $body);
    }

    private function gaPost(string $method, array $body): array
    {
        $property = trim((string) config('services.ga4.property_id'));
        if ($property === '') {
            throw new RuntimeException('GA4_PROPERTY_ID is not configured.');
        }

        $response = Http::withToken($this->accessToken(self::GA_SCOPE))
            ->timeout(20)
            ->post("https://analyticsdata.googleapis.com/v1beta/properties/{$property}:{$method}", $body);

        if (! $response->successful()) {
            throw new RuntimeException(
                'GA report failed: '.($response->json('error.message') ?? $response->status()),
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Search Console query (same service account, webmasters scope).
     *
     * @return array{rows?: array<int, array{keys?: string[], clicks?: float, impressions?: float, ctr?: float, position?: float}>}
     */
    public function gscQuery(array $body): array
    {
        $site = trim((string) config('services.gsc.site'));

        $response = Http::withToken($this->accessToken(self::GSC_SCOPE))
            ->timeout(20)
            ->post('https://searchconsole.googleapis.com/webmasters/v3/sites/'.rawurlencode($site).'/searchAnalytics/query', $body);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Search Console query failed: '.($response->json('error.message') ?? $response->status()),
            );
        }

        return $response->json() ?? [];
    }
}
