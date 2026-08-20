<?php

namespace App\Natcon\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drops the Next.js ISR cache for a convention's public landing page.
 *
 * ─── Why this exists ────────────────────────────────────────────────────────
 *
 * /natcon/{year} is server-rendered and cached. The announcements fetch sets its
 * own revalidate window, so publishing an announcement did not show up until the
 * window expired — and because Next serves stale-while-revalidate, the FIRST
 * request after expiry still gets the old page and only triggers the rebuild.
 * Measured on production: an announcement published at 09:00 was invisible until
 * 09:05, across three intervening page loads. An admin reasonably reads that as
 * "the save didn't work".
 *
 * ─── Why it is inline and not queued ────────────────────────────────────────
 *
 * There is no queue worker on api2 — the only background runner is the
 * scheduler's natcon:drain-outbox cron. A dispatched job would sit in the `jobs`
 * table for ever, so this has to happen in the request. Announcements and
 * sponsors are edited a handful of times per convention, so one short outbound
 * POST on those writes costs nothing.
 *
 * ─── Why it can never break a save ──────────────────────────────────────────
 *
 * The content is already committed by the time this runs. A frontend that is
 * slow, redeploying or misconfigured must not turn a successful edit into a 500,
 * so every failure is swallowed and logged. When the secret is not configured
 * this is a no-op and the ISR window remains the (slower) fallback — which is
 * exactly the behaviour before this class existed.
 */
class LandingCachePurger
{
    /**
     * Purge one convention year. A null year is a no-op rather than a guess:
     * revalidating the wrong path is worse than leaving the cache to expire.
     */
    public function purgeYear(?int $year): void
    {
        if (! $year) {
            return;
        }

        $secret = (string) config('services.frontend.revalidation_secret');
        $base = rtrim((string) config('services.frontend.url'), '/');

        if ($secret === '' || $base === '') {
            return;
        }

        try {
            $res = Http::timeout((int) config('services.frontend.revalidation_timeout', 5))
                ->withHeaders(['x-revalidation-token' => $secret])
                ->post($base.'/api/revalidate', ['slug' => "natcon/{$year}"]);

            // Http does not throw on 4xx/5xx without ->throw(), and a 401 here
            // means the two secrets disagree — worth a log line, because the
            // symptom otherwise is just "the page is slow to update again".
            if ($res->failed()) {
                Log::warning('natcon: landing revalidate rejected', [
                    'year' => $year,
                    'status' => $res->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('natcon: landing revalidate failed', [
                'year' => $year,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
