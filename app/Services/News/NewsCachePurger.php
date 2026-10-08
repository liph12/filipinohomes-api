<?php

namespace App\Services\News;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drops a news article's cached copy on both sides of the stack, so an edit
 * made on the external HomesPhNews CMS is live here immediately instead of
 * after the TTL.
 *
 * ─── Why this exists at all ─────────────────────────────────────────────────
 *
 * The article itself is edited on a partner system we don't own (see
 * HomesPhNewsController's own docblock) — there is no "on save" hook to wire
 * this into the way PageBuilderCachePurger or Natcon's LandingCachePurger are.
 * This is the manual equivalent: an editor presses "refresh" here right after
 * publishing upstream, instead of waiting out HomesPhNewsController::show()'s
 * own cache AND the frontend's 5-minute ISR window on top of it.
 *
 * That stacked staleness is exactly how a stale share preview happens: Oct
 * 2026 caught Facebook scraping a news article seconds after its hero photo
 * was swapped upstream, inside the (then 30-minute) server cache window —
 * FB cached the OLD image reference and kept it long after the edit went
 * live everywhere else.
 *
 * Mirrors PageBuilderCachePurger: one POST to /api/revalidate with the page
 * path AND the identifier — purging only one side would leave the OTHER
 * cache (the Data Cache entry the frontend's own fetch keeps, keyed by URL)
 * serving the same stale payload right back.
 *
 * Best-effort and silent when the frontend URL/secret are unset (local dev).
 */
class NewsCachePurger
{
    /**
     * @param  string  $identifier  Whatever HomesPhNewsController::show() was
     *                              called with — slug or UUID, same string the
     *                              article's own `/news/{identifier}` URL and
     *                              Laravel cache key use. Purging the Next.js
     *                              side only works when this IS the slug (the
     *                              frontend only ever fetches by slug) — a
     *                              UUID-only purge still clears our own
     *                              Laravel cache, but leave the frontend one
     *                              to expire on its own 5-minute ISR window.
     */
    public function purge(string $identifier): void
    {
        Cache::forget('homesphnews:article:'.$identifier);

        $this->send("news/{$identifier}", ["news-{$identifier}"]);
    }

    /**
     * @param  array<string>  $tags
     */
    private function send(string $slug, array $tags): void
    {
        $secret = (string) config('services.frontend.revalidation_secret');
        $base = rtrim((string) config('services.frontend.url'), '/');

        if ($secret === '' || $base === '') {
            return;
        }

        try {
            $res = Http::timeout((int) config('services.frontend.revalidation_timeout', 5))
                ->withHeaders(['x-revalidation-token' => $secret])
                ->post($base.'/api/revalidate', ['slug' => $slug, 'tags' => $tags]);

            if ($res->failed()) {
                Log::warning('news: revalidate rejected', [
                    'slug' => $slug,
                    'tags' => $tags,
                    'status' => $res->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('news: revalidate failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
