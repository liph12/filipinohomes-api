<?php

namespace App\Natcon\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the registration layer from natcon-api-v2.
 *
 * ─── The two-backend situation, from this side ───────────────────────────────
 *
 * This repo owns the awardee ROSTER and publishes it at /api/natcon/awardees.
 * v2 owns REGISTRATIONS — who filled the form in, who is bringing guests, who
 * is VVIP — so the public API can only answer those questions by asking.
 *
 * Keyed by EMAIL: it is the only identifier the two systems share, and the same
 * key v2's own roster sync matches on.
 *
 * ⚠️ FAILS OPEN, always. A public endpoint that 500s because another service is
 *    down is a worse outcome than one that serves the roster without the
 *    registration block — the roster is the thing people came for, and the
 *    caller is told which it got through `meta.registration`.
 *
 * Cached for five minutes, matching the public endpoint's own window: one
 * request per five minutes per year reaches v2 no matter how the page is
 * hammered.
 */
class NatconRegClient
{
    private const TTL = 300;

    /**
     * Registration rows for one convention year, keyed by lowercase email.
     *
     * @return array<string, array<string, mixed>>|null  null when v2 could not be reached
     */
    public function byEmail(int $year): ?array
    {
        $cacheKey = "natcon:reg-status:{$year}";

        // A failure is NOT cached: v2 coming back up should show through on the
        // next request, not five minutes later.
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $token = (string) config('app.fh_service_token', '');
        $base  = rtrim((string) config('natcon.reg.base_url', ''), '/');

        if ($token === '' || $base === '') {
            Log::warning('natcon.reg_client.not_configured', ['year' => $year]);

            return null;
        }

        try {
            $response = Http::timeout((int) config('natcon.reg.timeout', 10))
                ->acceptJson()
                ->withHeaders(['X-FH-Service-Token' => $token])
                ->get($base.'/service/registrants', ['year' => $year]);

            if (! $response->successful()) {
                Log::warning('natcon.reg_client.http_error', [
                    'year'   => $year,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $rows = $response->json('data');

            if (! is_array($rows)) {
                return null;
            }

            $byEmail = [];

            foreach ($rows as $row) {
                $email = mb_strtolower(trim((string) ($row['email'] ?? '')));

                if ($email !== '') {
                    $byEmail[$email] = $row;
                }
            }

            Cache::put($cacheKey, $byEmail, self::TTL);

            return $byEmail;
        } catch (\Throwable $e) {
            Log::warning('natcon.reg_client.failed', [
                'year'  => $year,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
