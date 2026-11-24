<?php

namespace App\Mcp\Tools\Admin;

use App\Mcp\Policies\AdminPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Support\Asks;
use App\Mcp\Support\McpActor;
use App\Mcp\Tools\FhTool;
use App\Services\Audience\AiVisibilityService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * How AI assistants see and send people to the website: the admin AI
 * Visibility page's own report (AiVisibilityService over `ai_events`), so
 * Claude and the page agree. Real pages only; requests from scanners posing as
 * AI bots are left out and only counted. Admins only, like the page.
 */
#[Name('fh_ai_visibility')]
#[Title('Filipino Homes: AI assistant visibility')]
#[Description('How AI assistants (ChatGPT, Perplexity, Claude, Gemini, Copilot...) see and send people to the website, for some days (the last 30 by default): views (an assistant opened a page to answer someone), crawls (an AI search index read a page) and clicks (a person arrived from an assistant), per assistant and for the top pages, and optionally per day. The same numbers as the admin AI Visibility page. Read-only.')]
final class FhAiVisibility extends FhTool
{
    protected function policy(): McpPolicy
    {
        return new AdminPolicy;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...Asks::schema($schema, []),
            'sort' => $schema->string()->enum(AiVisibilityService::SORTS)->default('views')->description('Order the top pages by views, clicks or crawls (default views).'),
            'top' => $schema->integer()->min(1)->max(50)->default(15)->description('How many top pages (default 15).'),
            'daily' => $schema->boolean()->description('Also give views, crawls and clicks per day (the AI Visibility trend chart).'),
        ];
    }

    protected function rules(): array
    {
        return [
            ...Asks::rules([]),
            'sort' => ['nullable', Rule::in(AiVisibilityService::SORTS)],
            'top' => ['nullable', 'integer', 'min:1', 'max:50'],
            'daily' => ['nullable', 'boolean'],
        ];
    }

    protected function answer(array $input, McpActor $actor): array
    {
        $range = Asks::range($input);
        $top = (int) ($input['top'] ?? 15);
        $sort = (string) ($input['sort'] ?? 'views');

        // Days as the AI Visibility page counts them. Two scans of ai_events (overview(), not the page's
        // six-scan report()); a range that has ended cannot change, so it keeps longer.
        $ttl = $range->end < now()->toDateString() ? now()->addHours(6) : now()->addMinutes(10);
        $report = Cache::remember("mcp:ai-visibility:v1:{$range->start}:{$range->end}:{$sort}:{$top}", $ttl, fn (): array => app(AiVisibilityService::class)->overview(
            Carbon::parse($range->start)->startOfDay(),
            Carbon::parse($range->end)->endOfDay(),
            $top,
            $sort,
        ));

        $answer = [
            'days' => ['from' => $range->start, 'to' => $range->end],
            'totals' => $report['totals'],
            'assistants' => $report['sources'],
            'top_pages' => array_map(fn (array $page): array => [
                'page' => $page['path'],
                'listing' => $page['title'],
                'views' => $page['views'],
                'clicks' => $page['clicks'],
                'crawls' => $page['crawls'],
            ], $report['top_pages']),
            'pages_seen' => $report['pages_seen'],
            'scanner_requests_left_out' => $report['suspicious_total'],
            'how_counted' => 'view = an assistant opened the page to answer a person (ChatGPT-User, Perplexity-User, Claude-User, MistralAI-User); it is a proxy, since no assistant reports how often it shows a page. crawl = an AI search index read the page (OAI-SearchBot, PerplexityBot, Claude-SearchBot). click = a person arrived on the page from an assistant, once per visitor per page per day; exact. Gemini and Copilot only show as clicks: they do not announce themselves when they read a page. scanner_requests_left_out = requests for paths no real page has (secrets files, admin panels) from scanners sending an AI user agent; not counted anywhere above. The same clicks also appear as their own sources (chatgpt, gemini...) in fh_traffic.',
        ];

        if ((bool) ($input['daily'] ?? false)) {
            // One scan (the dashboard card's summary()), real pages only, like the totals above.
            $answer['daily'] = Cache::remember("mcp:ai-visibility-daily:v1:{$range->start}:{$range->end}", $ttl, function () use ($range): array {
                $trend = app(AiVisibilityService::class)->summary(
                    Carbon::parse($range->start)->startOfDay(),
                    Carbon::parse($range->end)->endOfDay(),
                )['trend'];

                return [
                    'dates' => array_column($trend, 'date'),
                    'views' => array_column($trend, 'view'),
                    'crawls' => array_column($trend, 'crawl'),
                    'clicks' => array_column($trend, 'click'),
                    'how_counted' => 'Per day, days with no AI activity left out; scanner requests not counted.',
                ];
            });
        }

        return $answer;
    }
}
