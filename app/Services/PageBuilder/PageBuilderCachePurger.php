<?php

namespace App\Services\PageBuilder;

use App\Models\PageBuilder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells the Next.js site to drop its cached copy of an agent website after
 * the builder saves, so /website/{slug} shows the new banner, position and
 * copy immediately instead of after the ISR window (10 min page, 1 h fetch).
 *
 * Mirrors Natcon\Services\LandingCachePurger: one POST to /api/revalidate
 * with the page path (`slug`) AND the fetch-cache tag the page's data loader
 * registers (`page-builder-{slug}`) — purging only the path would re-render
 * against the still-cached API payload.
 *
 * Best-effort and silent when the frontend URL/secret are unset (local dev).
 */
class PageBuilderCachePurger
{
    /** Purge the site for this page (and, on a slug change, the old slug). */
    public function purge(PageBuilder $page, ?string $previousSlug = null): void
    {
        $slugs = array_values(array_unique(array_filter([$page->slug, $previousSlug])));

        foreach ($slugs as $slug) {
            $tags = ["page-builder-{$slug}"];
            $this->send("website/{$slug}", $tags);
            $this->send("website/{$slug}/properties", $tags);
        }
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
                Log::warning('page-builder: website revalidate rejected', [
                    'slug' => $slug,
                    'tags' => $tags,
                    'status' => $res->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('page-builder: website revalidate failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
