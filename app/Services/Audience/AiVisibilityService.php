<?php

namespace App\Services\Audience;

use App\Models\AiEvent;
use App\Support\VariantUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Classifies and reports AI-assistant visibility (see the ai_events
 * migration for what each kind means and why "view" is only a proxy).
 *
 * The user-agent tokens below are the ones each vendor documents. Keep the
 * VIEW/CRAWL token list in step with the `has` matcher in the web's
 * src/proxy.ts — the proxy only runs (and so only reports) for those agents.
 */
class AiVisibilityService
{
    /** User-triggered fetches: the assistant opened the page to answer someone. */
    private const VIEW_AGENTS = [
        'ChatGPT-User' => 'chatgpt',
        'Perplexity-User' => 'perplexity',
        'Claude-User' => 'claude',
        'MistralAI-User' => 'mistral',
    ];

    /** Search-index crawlers: the page is being made findable inside the assistant. */
    private const CRAWL_AGENTS = [
        'OAI-SearchBot' => 'chatgpt',
        'PerplexityBot' => 'perplexity',
        'Claude-SearchBot' => 'claude',
    ];

    /**
     * utm_source / referrer-host needles for clicks. ChatGPT tags every
     * outbound link with utm_source=chatgpt.com; the rest are referrers.
     * Order matters only for overlaps — `gemini.google` must be matched
     * here, before anything generic would read it as Google search.
     * The keys double as visits.channel values (lowercase, one per
     * assistant) — the web's CHANNEL_META labels them.
     */
    private const CLICK_NEEDLES = [
        'chatgpt' => ['chatgpt', 'openai'],
        'perplexity' => ['perplexity'],
        'gemini' => ['gemini.google', 'bard.google', 'gemini'],
        'copilot' => ['copilot'],
        'claude' => ['claude.ai', 'claude'],
        'meta-ai' => ['meta.ai'],
        'deepseek' => ['deepseek'],
        'grok' => ['grok'],
        'mistral' => ['chat.mistral', 'mistral'],
    ];

    /** @return array{kind: string, source: string, agent: string}|null */
    public function classifyAgent(string $userAgent): ?array
    {
        foreach (['view' => self::VIEW_AGENTS, 'crawl' => self::CRAWL_AGENTS] as $kind => $agents) {
            foreach ($agents as $token => $source) {
                if (stripos($userAgent, $token) !== false) {
                    return ['kind' => $kind, 'source' => $source, 'agent' => $token];
                }
            }
        }

        return null;
    }

    /** The assistant a visitor came from, or null when it wasn't one. */
    public function classifyClick(?string $utmSource, ?string $referrer): ?string
    {
        $candidates = [strtolower(trim((string) $utmSource))];
        $host = strtolower((string) parse_url((string) $referrer, PHP_URL_HOST));
        if ($host !== '') {
            $candidates[] = $host;
        }

        foreach ($candidates as $haystack) {
            if ($haystack === '') {
                continue;
            }
            foreach (self::CLICK_NEEDLES as $source => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($haystack, $needle)) {
                        return $source;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Path only, lowercased (slugs are lowercase, so /Foo and /foo are one
     * page), no query/fragment, trimmed to the column width.
     */
    public function normalizePath(string $path): string
    {
        $path = strtolower((string) parse_url($path, PHP_URL_PATH));
        $path = '/'.ltrim($path, '/');
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        return substr($path, 0, 512);
    }

    public function record(string $kind, string $source, string $path, ?string $agent = null): void
    {
        AiEvent::create([
            'kind' => $kind,
            'source' => $source,
            'agent' => $agent,
            'path' => $this->normalizePath($path),
        ]);
    }

    /** Top-pages sort keys (each an aggregate column of the query). */
    public const SORTS = ['views', 'clicks', 'crawls'];

    /**
     * The admin "AI Visibility" report for [from, to] (inclusive days).
     * Top pages are paginated ($perPage a page) and sorted by `$sort`.
     *
     * @return array<string, mixed>
     */
    public function report(Carbon $from, Carbon $to, int $page = 1, int $perPage = 50, string $sort = 'views'): array
    {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'views';
        $base = fn () => DB::table('ai_events')->whereBetween('created_at', [$from, $to]);

        $countsBy = fn (string $column) => $base()
            ->select($column, 'kind', DB::raw('COUNT(*) as c'))
            ->groupBy($column, 'kind')
            ->get();

        $totals = array_fill_keys(AiEvent::KINDS, 0);
        $sources = [];
        foreach ($countsBy('source') as $row) {
            $totals[$row->kind] = ($totals[$row->kind] ?? 0) + (int) $row->c;
            $sources[$row->source] ??= ['source' => $row->source, 'view' => 0, 'crawl' => 0, 'click' => 0];
            $sources[$row->source][$row->kind] = (int) $row->c;
        }
        $sources = collect($sources)
            ->sortByDesc(fn ($s) => $s['view'] + $s['click'] * 2 + $s['crawl'] / 10)
            ->values()
            ->all();

        $trend = [];
        foreach ($base()
            ->select(DB::raw('DATE(created_at) as d'), 'kind', DB::raw('COUNT(*) as c'))
            ->groupBy('d', 'kind')
            ->orderBy('d')
            ->get() as $row) {
            $trend[$row->d] ??= ['date' => $row->d, 'view' => 0, 'crawl' => 0, 'click' => 0];
            $trend[$row->d][$row->kind] = (int) $row->c;
        }

        $totalPages = (int) $base()->distinct()->count('path');
        $lastPage = max(1, (int) ceil($totalPages / $perPage));
        $page = min(max(1, $page), $lastPage);

        // Ties fall through the other two measures, then path, so a page
        // boundary never shuffles rows between requests.
        $tieBreak = array_values(array_diff(self::SORTS, [$sort]));
        $pages = $base()
            ->select(
                'path',
                DB::raw("SUM(CASE WHEN kind = 'view' THEN 1 ELSE 0 END) as views"),
                DB::raw("SUM(CASE WHEN kind = 'click' THEN 1 ELSE 0 END) as clicks"),
                DB::raw("SUM(CASE WHEN kind = 'crawl' THEN 1 ELSE 0 END) as crawls"),
            )
            ->groupBy('path')
            ->orderByDesc($sort)
            ->orderByDesc($tieBreak[0])
            ->orderByDesc($tieBreak[1])
            ->orderBy('path')
            ->forPage($page, $perPage)
            ->get();

        // Listing pages live at the site root (/{slug}); name them so the
        // table reads "Cafe ice top shop for sale", not a bare slug.
        $slugs = $pages->pluck('path')
            ->map(fn ($p) => ltrim($p, '/'))
            ->filter(fn ($s) => $s !== '' && ! str_contains($s, '/'))
            ->values();
        $listings = $slugs->isEmpty()
            ? collect()
            : DB::table('listings')->whereIn('slug', $slugs)->whereNull('deleted_at')
                ->get(['id', 'slug', 'name', 'featured_photo', 'photos_variants_generated_at'])
                ->keyBy('slug');

        $topPages = $pages->map(function ($row) use ($listings) {
            $listing = $listings->get(ltrim($row->path, '/'));

            return [
                'path' => $row->path,
                'title' => $listing->name ?? null,
                'listing_id' => $listing->id ?? null,
                'photo' => $listing ? $this->thumbnail($listing) : null,
                'views' => (int) $row->views,
                'clicks' => (int) $row->clicks,
                'crawls' => (int) $row->crawls,
            ];
        })->all();

        return [
            'totals' => $totals,
            'sources' => $sources,
            'trend' => array_values($trend),
            'top_pages' => $topPages,
            'top_pages_meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalPages,
                'last_page' => $lastPage,
                'sort' => $sort,
            ],
            'meta' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ];
    }

    /** First listing photo, as the 320px variant when variants exist. */
    private function thumbnail(object $listing): ?string
    {
        $photos = json_decode((string) $listing->featured_photo, true);
        $url = is_array($photos) ? ($photos[0] ?? null) : null;
        if (! is_string($url) || $url === '') {
            return null;
        }

        return $listing->photos_variants_generated_at
            ? (VariantUrl::variantUrl($url, 320) ?? $url)
            : $url;
    }
}
