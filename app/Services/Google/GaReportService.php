<?php

namespace App\Services\Google;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Website-analytics report assembled from the GA4 Data API.
 *
 * One call = one cached payload (10 min) holding every section the admin
 * dashboard renders: totals ± vs-previous, daily trend, top pages, channels,
 * normalized sources (facebook.com collapsed from its m./l./lm./web.
 * variants), countries, cities, devices, and the four lead events. Each
 * optional section degrades to null on its own failure — a partial GA outage
 * renders a partial dashboard, never a 500.
 *
 * Realtime is cached separately (45 s) because it changes by the minute
 * while the report window doesn't.
 */
class GaReportService
{
    /** Lead events fired by the frontend's gaEvent() helper. */
    public const LEAD_EVENTS = ['submit_inquiry', 'click_phone', 'click_whatsapp', 'click_email'];

    /** Dashboard/auth/system paths excluded from "top pages". */
    private const INTERNAL_PATH_RE = '#^/(admin|dashboard|secretary|developer|login|logout|register|auth|complete-profile|account|preview|agent|natcon/admin)(/|$|\?)#';

    /** Past this many points the daily trend collapses to weekly sums. */
    private const TREND_COLLAPSE_AT = 45;

    public function __construct(private GaDataService $ga)
    {
    }

    public function configured(): bool
    {
        return $this->ga->configured();
    }

    /**
     * @param  string  $from  Y-m-d (Asia/Manila)
     * @param  string  $to  Y-m-d (Asia/Manila)
     */
    public function websiteReport(string $from, string $to): array
    {
        return Cache::remember("ga:web-report:{$from}:{$to}", 600, function () use ($from, $to) {
            $range = [['startDate' => $from, 'endDate' => $to]];

            // Previous window of identical inclusive length, ending the day
            // before `from` — powers every change_vs_previous percentage.
            $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
            $prevTo = Carbon::parse($from)->subDay();
            $prevFrom = $prevTo->copy()->subDays($days - 1);
            $prevRange = [[
                'startDate' => $prevFrom->toDateString(),
                'endDate' => $prevTo->toDateString(),
            ]];

            $totals = $this->section(fn () => $this->totals($range));
            $prevTotals = $this->section(fn () => $this->totals($prevRange));

            return [
                'range' => [
                    'from' => $from,
                    'to' => $to,
                    'prev_from' => $prevFrom->toDateString(),
                    'prev_to' => $prevTo->toDateString(),
                ],
                'totals' => $totals,
                'change_vs_previous' => $this->changes($totals, $prevTotals),
                'trend' => $this->section(fn () => $this->trend($range)),
                'top_pages' => $this->section(fn () => $this->topPages($range)),
                'channels' => $this->section(fn () => $this->channels($range)),
                'sources' => $this->section(fn () => $this->sources($range)),
                'countries' => $this->section(fn () => $this->countries($range)),
                'cities' => $this->section(fn () => $this->cities($range)),
                'devices' => $this->section(fn () => $this->devices($range)),
                'lead_events' => $this->section(fn () => $this->leadEvents($range)),
            ];
        });
    }

    /** Active users right now + the pages they're on. */
    public function realtime(): array
    {
        return Cache::remember('ga:realtime', 45, function () {
            $total = $this->ga->runRealtimeReport([
                'metrics' => [['name' => 'activeUsers']],
            ]);

            $pages = $this->section(fn () => collect($this->rows($this->ga->runRealtimeReport([
                'dimensions' => [['name' => 'unifiedScreenName']],
                'metrics' => [['name' => 'activeUsers']],
                'limit' => 8,
            ])))->map(fn ($r) => [
                'page' => $r['dims'][0] ?? '',
                'active_users' => (int) ($r['metrics'][0] ?? 0),
            ])->values()->all());

            return [
                'active_users' => (int) ($total['rows'][0]['metricValues'][0]['value'] ?? 0),
                'pages' => $pages,
                'as_of' => now('Asia/Manila')->toIso8601String(),
            ];
        });
    }

    // ── Sections ────────────────────────────────────────────────────────

    private function totals(array $range): array
    {
        $report = $this->ga->runReport([
            'dateRanges' => $range,
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews'],
                ['name' => 'newUsers'],
                ['name' => 'averageSessionDuration'],
                ['name' => 'engagementRate'],
            ],
        ]);
        $m = collect($report['rows'][0]['metricValues'] ?? [])->pluck('value')->all();

        return [
            'active_users' => (int) ($m[0] ?? 0),
            'sessions' => (int) ($m[1] ?? 0),
            'pageviews' => (int) ($m[2] ?? 0),
            'new_users' => (int) ($m[3] ?? 0),
            'avg_session_duration_sec' => (int) round((float) ($m[4] ?? 0)),
            'engagement_rate' => round((float) ($m[5] ?? 0), 4),
        ];
    }

    private function trend(array $range): array
    {
        $rows = $this->rows($this->ga->runReport([
            'dateRanges' => $range,
            'dimensions' => [['name' => 'date']],
            'metrics' => [['name' => 'activeUsers']],
            'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
            'limit' => 366,
        ]));

        $points = collect($rows)->map(fn ($r) => [
            'date' => Carbon::createFromFormat('Ymd', $r['dims'][0])->toDateString(),
            'active_users' => (int) ($r['metrics'][0] ?? 0),
        ])->values();

        // Long ranges collapse day → ISO-week sums so the line stays legible.
        if ($points->count() > self::TREND_COLLAPSE_AT) {
            $weekly = $points
                ->groupBy(fn ($p) => Carbon::parse($p['date'])->startOfWeek()->toDateString())
                ->map(fn ($group, $week) => [
                    'date' => $week,
                    'active_users' => $group->sum('active_users'),
                ])
                ->values();

            return ['granularity' => 'week', 'points' => $weekly->all()];
        }

        return ['granularity' => 'day', 'points' => $points->all()];
    }

    private function topPages(array $range): array
    {
        $rows = $this->rows($this->ga->runReport([
            'dateRanges' => $range,
            'dimensions' => [['name' => 'pagePath']],
            'metrics' => [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
            'orderBys' => [['desc' => true, 'metric' => ['metricName' => 'screenPageViews']]],
            'limit' => 60,
        ]));

        return collect($rows)
            ->filter(fn ($r) => ! preg_match(self::INTERNAL_PATH_RE, $r['dims'][0] ?? ''))
            ->map(fn ($r) => [
                'path' => $r['dims'][0] ?? '',
                'views' => (int) ($r['metrics'][0] ?? 0),
                'active_users' => (int) ($r['metrics'][1] ?? 0),
            ])
            ->take(40)
            ->values()
            ->all();
    }

    private function channels(array $range): array
    {
        $rows = $this->rows($this->ga->runReport([
            'dateRanges' => $range,
            'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
            'metrics' => [['name' => 'sessions']],
            'orderBys' => [['desc' => true, 'metric' => ['metricName' => 'sessions']]],
            'limit' => 10,
        ]));

        return collect($rows)->map(fn ($r) => [
            'channel' => $r['dims'][0] ?? '',
            'sessions' => (int) ($r['metrics'][0] ?? 0),
        ])->values()->all();
    }

    private function sources(array $range): array
    {
        $rows = $this->rows($this->ga->runReport([
            'dateRanges' => $range,
            'dimensions' => [['name' => 'sessionSource']],
            'metrics' => [['name' => 'sessions']],
            'orderBys' => [['desc' => true, 'metric' => ['metricName' => 'sessions']]],
            'limit' => 40,
        ]));

        // Collapse referrer variants (m./l./lm./web.facebook.com → facebook.com,
        // any instagram host → instagram.com) so "how many came from Facebook"
        // is one honest row, then re-rank.
        return collect($rows)
            ->map(fn ($r) => [
                'source' => $this->normalizeSource($r['dims'][0] ?? ''),
                'sessions' => (int) ($r['metrics'][0] ?? 0),
            ])
            ->groupBy('source')
            ->map(fn ($group, $source) => [
                'source' => $source,
                'sessions' => $group->sum('sessions'),
            ])
            ->sortByDesc('sessions')
            ->take(20)
            ->values()
            ->all();
    }

    private function countries(array $range): array
    {
        $rows = $this->rows($this->ga->runReport([
            'dateRanges' => $range,
            'dimensions' => [['name' => 'country'], ['name' => 'countryId']],
            'metrics' => [['name' => 'activeUsers']],
            'orderBys' => [['desc' => true, 'metric' => ['metricName' => 'activeUsers']]],
            'limit' => 12,
        ]));

        return collect($rows)->map(fn ($r) => [
            'country' => $r['dims'][0] ?? '',
            'iso' => strtolower($r['dims'][1] ?? ''),
            'active_users' => (int) ($r['metrics'][0] ?? 0),
        ])->values()->all();
    }

    private function cities(array $range): array
    {
        $rows = $this->rows($this->ga->runReport([
            'dateRanges' => $range,
            'dimensions' => [['name' => 'city'], ['name' => 'country']],
            'metrics' => [['name' => 'activeUsers']],
            'orderBys' => [['desc' => true, 'metric' => ['metricName' => 'activeUsers']]],
            'limit' => 30,
        ]));

        return collect($rows)
            ->filter(fn ($r) => ($r['dims'][0] ?? '') !== '(not set)')
            ->map(fn ($r) => [
                'city' => $r['dims'][0] ?? '',
                'country' => $r['dims'][1] ?? '',
                'active_users' => (int) ($r['metrics'][0] ?? 0),
            ])->values()->all();
    }

    private function devices(array $range): array
    {
        $rows = $this->rows($this->ga->runReport([
            'dateRanges' => $range,
            'dimensions' => [['name' => 'deviceCategory']],
            'metrics' => [['name' => 'activeUsers']],
            'orderBys' => [['desc' => true, 'metric' => ['metricName' => 'activeUsers']]],
            'limit' => 5,
        ]));

        return collect($rows)->map(fn ($r) => [
            'device' => $r['dims'][0] ?? '',
            'active_users' => (int) ($r['metrics'][0] ?? 0),
        ])->values()->all();
    }

    private function leadEvents(array $range): array
    {
        $rows = $this->rows($this->ga->runReport([
            'dateRanges' => $range,
            'dimensions' => [['name' => 'eventName']],
            'metrics' => [['name' => 'eventCount']],
            'dimensionFilter' => [
                'filter' => [
                    'fieldName' => 'eventName',
                    'inListFilter' => ['values' => self::LEAD_EVENTS],
                ],
            ],
        ]));

        $counts = collect($rows)->mapWithKeys(fn ($r) => [
            ($r['dims'][0] ?? '') => (int) ($r['metrics'][0] ?? 0),
        ]);

        return collect(self::LEAD_EVENTS)
            ->mapWithKeys(fn ($event) => [$event => (int) $counts->get($event, 0)])
            ->all();
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** Run one section; a failure yields null instead of aborting the report. */
    private function section(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, array{dims: string[], metrics: string[]}> */
    private function rows(array $report): array
    {
        return collect($report['rows'] ?? [])->map(fn ($row) => [
            'dims' => collect($row['dimensionValues'] ?? [])->pluck('value')->all(),
            'metrics' => collect($row['metricValues'] ?? [])->pluck('value')->all(),
        ])->all();
    }

    private function changes(?array $totals, ?array $prev): ?array
    {
        if (! $totals || ! $prev) {
            return null;
        }

        $pct = function (string $key) use ($totals, $prev): ?float {
            $cur = (float) ($totals[$key] ?? 0);
            $old = (float) ($prev[$key] ?? 0);
            if ($old <= 0) {
                return $cur > 0 ? 100.0 : 0.0;
            }

            return round((($cur - $old) / $old) * 100, 1);
        };

        return [
            'active_users_pct' => $pct('active_users'),
            'sessions_pct' => $pct('sessions'),
            'pageviews_pct' => $pct('pageviews'),
            'new_users_pct' => $pct('new_users'),
            'engagement_rate_pct' => $pct('engagement_rate'),
        ];
    }

    private function normalizeSource(string $source): string
    {
        $s = strtolower(trim($source));
        if ($s === '' || $s === '(direct)' || $s === '(not set)') {
            return 'direct';
        }
        $s = preg_replace('/^www\./', '', $s) ?? $s;
        if (preg_match('/(^|\.)facebook\.com$/', $s)) {
            return 'facebook.com';
        }
        if (str_contains($s, 'instagram')) {
            return 'instagram.com';
        }

        return $s;
    }
}
