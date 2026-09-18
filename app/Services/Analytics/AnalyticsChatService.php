<?php

namespace App\Services\Analytics;

use App\Services\Google\GaDataService;
use App\Services\Google\GaReportService;
use Illuminate\Support\Carbon;
use OpenAI;
use Throwable;

/**
 * FH Analytics Assistant — an OpenAI tool loop over the GA4/Search Console
 * data layer (the same pattern as FHI Global's assistant, scoped to website
 * analytics only).
 *
 * Trust boundary: the model narrates; the NUMBERS the frontend charts come
 * only from `data` (the raw tool payloads), never from model text. The model
 * itself sees a trimmed `for_model` view of each tool result (top-N slices)
 * to keep tokens bounded, and the loop hard-stops at MAX_ROUNDS with
 * tool_choice=none so it must answer.
 */
class AnalyticsChatService
{
    private const MAX_ROUNDS = 5;
    private const MAX_HISTORY = 16;

    public function __construct(
        private GaReportService $reports,
        private GaDataService $ga,
    ) {
    }

    public function configured(): bool
    {
        return $this->reports->configured() && (bool) config('services.openai.key');
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{reply: string, tools_used: string[], data: array<string, mixed>}
     */
    public function chat(array $messages): array
    {
        $client = OpenAI::client(config('services.openai.key'));
        $model = config('services.openai.chat_model', 'gpt-5.4-mini');

        $thread = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ...array_slice($messages, -self::MAX_HISTORY),
        ];

        $toolsUsed = [];
        $data = [];
        $reply = '';

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $finalRound = $round === self::MAX_ROUNDS - 1;

            $response = $client->chat()->create([
                'model' => $model,
                'temperature' => 0.1,
                'messages' => $thread,
                'tools' => $this->toolDefinitions(),
                'tool_choice' => $finalRound ? 'none' : 'auto',
            ]);

            $choice = $response->choices[0];
            $toolCalls = $choice->message->toolCalls ?? [];

            if (empty($toolCalls)) {
                $reply = trim((string) $choice->message->content);
                break;
            }

            $thread[] = [
                'role' => 'assistant',
                'content' => $choice->message->content ?? '',
                'tool_calls' => array_map(fn ($tc) => [
                    'id' => $tc->id,
                    'type' => 'function',
                    'function' => ['name' => $tc->function->name, 'arguments' => $tc->function->arguments],
                ], $toolCalls),
            ];

            foreach ($toolCalls as $tc) {
                $name = $tc->function->name;
                $args = json_decode($tc->function->arguments ?? '{}', true) ?: [];
                $toolsUsed[] = $name;

                [$forModel, $full] = $this->runTool($name, $args);
                if ($full !== null) {
                    $data[$name] = $full; // the frontend charts from this, not from model text
                }

                $thread[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tc->id,
                    'content' => mb_substr(json_encode($forModel), 0, 24000),
                ];
            }
        }

        return [
            'reply' => $this->scrub($reply),
            'tools_used' => array_values(array_unique($toolsUsed)),
            'data' => $data,
        ];
    }

    /** @return array{0: array, 1: array|null} [trimmed for-model view, full payload for the UI] */
    private function runTool(string $name, array $args): array
    {
        try {
            return match ($name) {
                'website_traffic' => $this->websiteTraffic($args),
                'search_keywords' => $this->searchKeywords($args),
                default => [['error' => "Unknown tool: {$name}"], null],
            };
        } catch (Throwable $e) {
            // Errors return as data, never thrown — the model explains them.
            return [['error' => mb_substr($e->getMessage(), 0, 300)], null];
        }
    }

    private function websiteTraffic(array $args): array
    {
        [$from, $to] = $this->resolveRange($args);
        $report = $this->reports->websiteReport($from, $to);
        $report['realtime'] = $this->section(fn () => $this->reports->realtime());

        $forModel = [
            'range' => $report['range'],
            'totals' => $report['totals'],
            'change_vs_previous' => $report['change_vs_previous'],
            'top_pages' => array_slice($report['top_pages'] ?? [], 0, 10),
            'sources' => array_slice($report['sources'] ?? [], 0, 10),
            'channels' => $report['channels'],
            'countries' => array_slice($report['countries'] ?? [], 0, 6),
            'devices' => $report['devices'],
            'lead_events' => $report['lead_events'],
            'realtime_active_users' => $report['realtime']['active_users'] ?? null,
        ];

        return [$forModel, $report];
    }

    private function searchKeywords(array $args): array
    {
        [$from, $to] = $this->resolveRange($args);
        $limit = min(max((int) ($args['limit'] ?? 15), 1), 25);

        $queries = $this->ga->gscQuery([
            'startDate' => $from,
            'endDate' => $to,
            'dimensions' => ['query'],
            'rowLimit' => $limit,
        ])['rows'] ?? [];

        $pages = $this->ga->gscQuery([
            'startDate' => $from,
            'endDate' => $to,
            'dimensions' => ['page'],
            'rowLimit' => $limit,
        ])['rows'] ?? [];

        $mapRow = fn ($r) => [
            'key' => $r['keys'][0] ?? '',
            'clicks' => (int) ($r['clicks'] ?? 0),
            'impressions' => (int) ($r['impressions'] ?? 0),
            'avg_position' => round((float) ($r['position'] ?? 0), 1),
        ];

        $payload = [
            'range' => ['from' => $from, 'to' => $to],
            'top_queries' => array_map($mapRow, $queries),
            'top_pages' => array_map($mapRow, $pages),
        ];

        return [$payload, $payload];
    }

    /** @return array{0: string, 1: string} */
    private function resolveRange(array $args): array
    {
        $today = Carbon::now('Asia/Manila')->startOfDay();
        $to = isset($args['to_date']) ? Carbon::parse($args['to_date']) : $today;
        $from = isset($args['from_date']) ? Carbon::parse($args['from_date']) : $to->copy()->subDays(6);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $to = $to->min($today);
        $from = $from->max($to->copy()->subDays(399));

        return [$from->toDateString(), $to->toDateString()];
    }

    private function toolDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'website_traffic',
                    'description' => 'Google Analytics website report for a date range: totals (visitors, sessions, pageviews, new users, engagement) with change vs the previous period, daily trend, top pages, traffic sources (facebook.com etc.), channels, countries, devices, lead events, and realtime active users.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'from_date' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (Asia/Manila). Defaults to 6 days before to_date.'],
                            'to_date' => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (Asia/Manila). Defaults to today.'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_keywords',
                    'description' => 'Google Search Console: the search queries and pages that earned Google clicks/impressions for a date range, with average position.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'from_date' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD.'],
                            'to_date' => ['type' => 'string', 'description' => 'End date YYYY-MM-DD.'],
                            'limit' => ['type' => 'integer', 'description' => 'Rows per list (max 25).'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function systemPrompt(): string
    {
        $today = Carbon::now('Asia/Manila')->format('l, M j, Y');

        return <<<PROMPT
You are FH Analytics, the website-analytics assistant for Filipino Homes (filipinohomes.com), a Philippine real-estate portal. Today is {$today} (Asia/Manila).

RULES
- ALWAYS answer from tool results. NEVER invent, estimate, or extrapolate a number. If a value is missing or a tool errors, say so plainly.
- ONE PERIOD RULES THE ANSWER: resolve the user's period once (e.g. "last week", "yesterday", "this month") and use it for every number in the answer. Say the exact date range you used.
- When change_vs_previous is available, include it inline, e.g. "12,340 visitors (up 8% vs the previous period)".
- Plain text only. No markdown symbols (no #, *, backticks, tables). Short lines. Numbers formatted with commas.
- Keep scope: a question about visitors doesn't need search keywords, and vice versa. "Full report" = website_traffic plus search_keywords.
- The dashboard already charts the data you return — do not enumerate every row; summarize what matters and call out the notable movers.
- If Google Analytics is not configured or a tool returns an error, say the analytics connection is pending on the server — never claim the topic is outside your data.

FULL REPORT SHAPE (when asked for a daily/weekly/monthly report)
WEBSITE REPORT — <range>
VISITORS: totals + change vs previous
TOP SOURCES: top 3 with sessions
TOP PAGES: top 3 with views
GEOGRAPHY: top 2 countries
LEADS: the four lead actions
SEARCH: top 3 Google queries by clicks (only if search data was requested/available)
Close with one factual observation, no advice unless asked.
PROMPT;
    }

    /** Light markdown scrub — the prompt forbids it, this enforces it. */
    private function scrub(string $text): string
    {
        $clean = preg_replace([
            '/^#{1,6}\s*/m',
            '/\*\*(.*?)\*\*/s',
            '/\*(.*?)\*/s',
            '/`{1,3}/',
        ], ['', '$1', '$1', ''], $text) ?? $text;

        return trim($clean);
    }

    private function section(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return null;
        }
    }
}
