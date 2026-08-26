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
     * Purge one convention year's page and the data behind it.
     *
     * ⚠️ The PATH is not enough on its own, and assuming it was cost an hour of
     *    "why is this not showing". Next keeps two caches: revalidatePath drops
     *    the page's rendered output, but each fetch also keeps its own Data
     *    Cache entry keyed by URL. Purge only the path and the page re-renders
     *    and reads exactly the same stale payload straight back. Three recaps
     *    sat in the API, live, invisible, for precisely that reason.
     *
     * So both go: the path, and the tags for every fetch that page makes.
     *
     * A null year still sends the tags. The year only narrows the path and the
     * per-year tags; the recaps list is global and must refresh regardless.
     */
    public function purgeYear(?int $year): void
    {
        $tags = ['natcon-event', 'natcon-recaps'];

        if ($year) {
            $tags[] = "natcon-announcements-{$year}";
            $tags[] = "natcon-sponsors-{$year}";
            $tags[] = "natcon-gallery-{$year}";
        } else {
            // Without a year, widen to the un-suffixed tags rather than guessing.
            $tags[] = 'natcon-announcements';
            $tags[] = 'natcon-sponsors';
            $tags[] = 'natcon-gallery';
        }

        $this->send($year ? "natcon/{$year}" : null, $tags);
    }

    /**
     * The PUBLIC albums gallery (/albums and every /albums/{slug}).
     *
     * One tag, not per-album: every public-album fetch on the frontend carries
     * `gallery-albums`, so a photo edit deep in a sub-album refreshes the list
     * page's covers and counts too — and the caller never has to work out
     * which ancestors changed. Path purge is a belt-and-braces for /albums.
     */
    public function purgeAlbums(?string $slug = null): void
    {
        $this->send('albums', ['gallery-albums']);

        if ($slug) {
            $this->send("albums/{$slug}", []);
        }
    }

    /**
     * Recordings of past conventions.
     *
     * natcon_recaps has no event FK — the conventions run back to 2012 and those
     * years have no event row — so a recap shows on EVERY year's landing page.
     * One tag refreshes all of them; doing this by path would mean knowing which
     * years have pages and purging each, and silently missing any that were
     * added later.
     */
    public function purgeRecaps(): void
    {
        $this->send(null, ['natcon-recaps']);
    }

    /**
     * @param  array<string>  $tags
     */
    private function send(?string $slug, array $tags): void
    {
        $secret = (string) config('services.frontend.revalidation_secret');
        $base = rtrim((string) config('services.frontend.url'), '/');

        if ($secret === '' || $base === '') {
            return;
        }

        $payload = ['tags' => array_values(array_unique($tags))];

        if ($slug !== null) {
            $payload['slug'] = $slug;
        }

        try {
            $res = Http::timeout((int) config('services.frontend.revalidation_timeout', 5))
                ->withHeaders(['x-revalidation-token' => $secret])
                ->post($base.'/api/revalidate', $payload);

            // Http does not throw on 4xx/5xx without ->throw(), and a 401 here
            // means the two secrets disagree — worth a log line, because the
            // symptom otherwise is just "the page is slow to update again".
            if ($res->failed()) {
                Log::warning('natcon: landing revalidate rejected', [
                    'slug' => $slug,
                    'tags' => $payload['tags'],
                    'status' => $res->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('natcon: landing revalidate failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
