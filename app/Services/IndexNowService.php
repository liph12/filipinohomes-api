<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * IndexNow client. Forwards URL submissions to the Next.js layer
 * which holds the IndexNow key and proxies to api.indexnow.org.
 *
 * Why proxy through the frontend rather than calling IndexNow
 * directly? The key file lives on the public web origin (Next.js),
 * and crawlers verify the key location on that host before
 * accepting submissions. Keeping the IndexNow API call on the same
 * origin avoids having to publish a separate key file from the
 * Laravel host.
 */
class IndexNowService
{
    /**
     * Build the absolute public URL for a listing slug.
     */
    public function listingUrl(string $slug): string
    {
        return $this->siteUrl() . '/' . ltrim($slug, '/');
    }

    /**
     * Build the absolute public URL for an agent profile.
     */
    public function agentUrl(string $slug): string
    {
        return $this->siteUrl() . '/agents/' . ltrim($slug, '/');
    }

    /**
     * Build the absolute public URL for a blog post.
     */
    public function blogUrl(string $slug): string
    {
        return $this->siteUrl() . '/blogs/' . ltrim($slug, '/');
    }

    /**
     * Build the absolute public URL for a project page.
     */
    public function projectUrl(string $slug): string
    {
        return $this->siteUrl() . '/projects/' . ltrim($slug, '/');
    }

    /**
     * Build the absolute public URL for a "near {facility}" SEO page:
     *   /{for-sale|for-rent}/{type-slug}/near-{facility_slug}/in-{city}-{province}
     * mirroring the frontend facilities sitemap shard
     * (src/app/api/sitemap/facilities/[page]/route.ts). Category/type/city/
     * province arrive as raw display names (e.g. "For Sale", "Condominium",
     * "Cebu City", "Cebu") straight from facility_listing_counts. Returns
     * null for a category with no URL tier (e.g. Foreclosure).
     */
    public function nearFacilityUrl(
        string $category,
        string $type,
        string $facilitySlug,
        string $city,
        string $province,
    ): ?string {
        $categorySlug = match ($category) {
            'For Sale' => 'for-sale',
            'For Rent' => 'for-rent',
            default    => null,
        };
        if ($categorySlug === null || $facilitySlug === '' || $city === '' || $province === '') {
            return null;
        }

        return sprintf(
            '%s/%s/%s/near-%s/in-%s-%s',
            $this->siteUrl(),
            $categorySlug,
            Str::slug($type),
            $facilitySlug,
            Str::slug($city),
            Str::slug($province),
        );
    }

    /**
     * Submit one or more absolute URLs to the Next.js IndexNow
     * proxy. Returns true on 2xx, false on any other outcome.
     * Failures are logged at the `info` level (IndexNow is a
     * fire-and-forget optimisation — never throw into the caller's
     * write path).
     */
    public function submit(array $urls): bool
    {
        if (!config('services.indexnow.enabled')) {
            return false;
        }
        $endpoint = (string) config('services.indexnow.submit_endpoint');
        $secret = (string) config('services.indexnow.submit_secret');
        if ($endpoint === '' || $secret === '') {
            Log::info('IndexNow not configured', ['endpoint' => $endpoint !== '', 'secret' => $secret !== '']);
            return false;
        }
        $urls = array_values(array_unique(array_filter($urls, 'is_string')));
        if ($urls === []) {
            return false;
        }

        try {
            $res = Http::timeout((int) config('services.indexnow.timeout', 8))
                ->withHeaders([
                    'x-indexnow-secret' => $secret,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, ['urls' => $urls]);

            if ($res->successful()) {
                return true;
            }
            Log::info('IndexNow submit failed', [
                'status' => $res->status(),
                'body' => $res->body(),
                'urls' => $urls,
            ]);
            return false;
        } catch (Throwable $e) {
            Log::info('IndexNow submit threw', [
                'message' => $e->getMessage(),
                'urls' => $urls,
            ]);
            return false;
        }
    }

    private function siteUrl(): string
    {
        return rtrim((string) config('services.indexnow.site_url', 'https://filipinohomes.com'), '/');
    }
}
