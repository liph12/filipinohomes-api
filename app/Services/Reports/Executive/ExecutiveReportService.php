<?php

namespace App\Services\Reports\Executive;

use App\Services\Reports\Executive\Sections\AgentsSection;
use App\Services\Reports\Executive\Sections\DealsSection;
use App\Services\Reports\Executive\Sections\InquiriesSection;
use App\Services\Reports\Executive\Sections\PlacesSection;
use App\Services\Reports\Executive\Sections\TeamsSection;
use App\Services\Reports\Executive\Sections\TrendSection;
use App\Services\Reports\Executive\Sections\UsageSection;
use App\Services\Reports\Executive\SoldClaims\SoldClaimScanner;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The Executive Report's summary for one date range: the At-a-glance tiles,
 * six sections, the trend and the data notes.
 *
 * Built once per range and cached — past ranges and current ones alike for at
 * most 30 minutes, because several figures (hanging inquiries, dormant agents)
 * are measured NOW even inside a past range. A per-range lock makes a second
 * admin opening the same dates wait for the first build instead of starting
 * another; "Refresh" rebuilds only a summary older than a minute.
 */
final class ExecutiveReportService
{
    public const CACHE_PREFIX = 'exec-report:v1:summary:';

    private const MAX_TTL = 1800;

    private const REFRESH_AFTER = 60;

    /** A build slower than this is logged (executive_report.slow_build). */
    private const SLOW_MS = 8000;

    public function __construct(
        private readonly MetricEngine $engine,
        private readonly ReportQueries $q,
        private readonly PeopleQueries $people,
        private readonly Geography $geo,
        private readonly TeamDirectory $teams,
        private readonly UsageSection $usage,
        private readonly AgentsSection $agents,
        private readonly TeamsSection $teamsSection,
        private readonly InquiriesSection $inquiries,
        private readonly DealsSection $deals,
        private readonly PlacesSection $places,
        private readonly TrendSection $trend,
        private readonly SoldClaimScanner $scanner,
        private readonly LeadQueries $leads,
    ) {}

    public function context(ReportRange $range, Carbon $now, array $filters = []): ReportContext
    {
        return new ReportContext($range, $now, $this->engine, $this->q, $this->people, $this->geo, $this->teams, $this->leads, $filters);
    }

    /**
     * @throws LockTimeoutException when another build of the same range runs too long
     */
    public function summary(ReportRange $range, Carbon $now, bool $refresh = false): array
    {
        $key = self::CACHE_PREFIX.$range->key();
        $usable = fn (?array $report) => $report !== null
            && ! ($refresh && $now->getTimestamp() - $report['meta']['built_at'] >= self::REFRESH_AFTER);

        $report = Cache::get($key);
        if ($usable($report)) {
            return $this->served($report, true);
        }

        $built = false;
        $report = Cache::lock($key.':build', 300)->block(150, function () use ($key, $range, $now, $usable, &$built) {
            $report = Cache::get($key);
            if ($usable($report)) {
                return $report; // another request built it while this one waited
            }

            $started = hrtime(true);
            $report = $this->build($range, $now);
            $report['meta'] = [
                'built_at' => $now->getTimestamp(),
                'generated_at' => $now->copy()->setTimezone(ReportRange::TZ)->toIso8601String(),
                'compute_ms' => (int) round((hrtime(true) - $started) / 1e6),
                'timings_ms' => $report['timings_ms'],
                'ttl' => min($range->ttl($now), self::MAX_TTL),
            ];
            unset($report['timings_ms']);
            if ($report['meta']['compute_ms'] > self::SLOW_MS) {
                // The production host cuts a request at ~20 s; past this, a build is heading there.
                Log::warning('executive_report.slow_build', ['range' => $range->key()] + array_intersect_key($report['meta'], array_flip(['compute_ms', 'timings_ms'])));
            }
            Cache::put($key, $report, $report['meta']['ttl']);
            $built = true;

            return $report;
        });

        return $this->served($report, ! $built);
    }

    /**
     * The summary, with `timings_ms` — how long each part took. A part pays
     * for the row sets it is first to need (they are shared after), so the
     * slow part points at the slow query.
     */
    public function build(ReportRange $range, Carbon $now): array
    {
        $c = $this->context($range, $now);
        $previous = $range->previous();

        $timings = [];
        $timed = function (string $part, callable $build) use (&$timings) {
            $started = hrtime(true);
            $result = $build();
            $timings[$part] = (int) round((hrtime(true) - $started) / 1e6);

            return $result;
        };

        $kpis = $timed('kpis', fn () => array_map(fn ($key) => $c->stat($key, compare: true), MetricRegistry::KPIS));
        $sections = [
            'usage' => $timed('usage', fn () => $this->usage->build($c)),
            'agents' => $timed('agents', fn () => $this->agents->build($c)),
            'teams' => $timed('teams', fn () => $this->teamsSection->build($c)),
            'inquiries' => $timed('inquiries', fn () => $this->inquiries->build($c)),
            'deals' => $timed('deals', fn () => $this->deals->build($c)),
            'places' => $timed('places', fn () => $this->places->build($c)),
        ];
        $trend = $timed('trend', fn () => $this->trend->build($c));
        $notes = $timed('notes', fn () => $this->notes());

        return [
            'range' => $range->toArray() + ['previous' => ['start' => $previous->start, 'end' => $previous->end]],
            'as_of' => $now->copy()->setTimezone(ReportRange::TZ)->toIso8601String(),
            'data_since' => $this->dataSince($range),
            'kpis' => $kpis,
            'sections' => $sections,
            'trend' => $trend,
            'notes' => $notes,
            'timings_ms' => $timings,
        ];
    }

    private function served(array $report, bool $cached): array
    {
        $report['meta']['cached'] = $cached;
        unset($report['meta']['built_at']);

        return $report;
    }

    /** Each source's first day of data, flagged where the range starts earlier. */
    public function dataSince(ReportRange $range): array
    {
        $out = [];
        foreach (MetricRegistry::DATA_SINCE as $source => $since) {
            $out[] = [
                'source' => $source,
                'label' => MetricRegistry::DATA_SINCE_LABELS[$source],
                'since' => $since,
                'partial' => $range->start < $since,
            ];
        }

        return $out;
    }

    /** What FH can't answer, and how the numbers are counted — shown at the foot of the report. */
    private function notes(): array
    {
        $scan = $this->scanner->freshness();

        return [
            ['key' => 'bookings', 'title' => 'Bookings and reservations', 'text' => 'FH does not record bookings or reservations. A listing marked Sold — with the deal date the agent typed — is the closest signal. Replies saying "reserved" or "may buyer na" are counted under Said reserved.'],
            ['key' => 'developers', 'title' => 'Developers', 'text' => 'Listings carry a project but no developer, so figures are per project. To see one developer, open its projects in the Market explorer.'],
            ['key' => 'occupation', 'title' => 'Client occupation', 'text' => 'Only buyer briefs (since Nov 4, 2026) ask for a client\'s occupation, so it is not reported yet.'],
            ['key' => 'prices', 'title' => 'Prices', 'text' => 'All prices are asking prices; FH records no closing price. ₱/sqm uses the floor area (the lot area for land) and skips listings under 10 sqm or with an unbelievable price.'],
            ['key' => 'places', 'title' => 'Places', 'text' => 'A listing is placed by its project\'s location first, then its barangay — the Listings Heatmap\'s rule, so town figures match the map. Inquiry Analytics places inquiries by map pin, so its regional split can differ slightly.'],
            ['key' => 'replies', 'title' => 'Replies', 'text' => 'Only replies in the FH chat are seen. Calls, texts and messenger apps are not, so "never answered" can include clients the agent reached another way.'],
            ['key' => 'logins', 'title' => 'Sign-ins', 'text' => 'Agents who stay signed in are not logged again, so sign-in counts undercount. "Active" also counts messages, listings and edits.'],
            ['key' => 'teams', 'title' => 'Teams', 'text' => 'Each agent counts for one team: their current team (the lowest-numbered one when they are in several). Past activity follows the agent to their team today.'],
            ['key' => 'claims', 'title' => 'Said sold, still listed', 'text' => $scan['scanned_at'] === null
                ? 'Agent replies have not been read yet — this list fills in after the first scan.'
                : 'Agent replies are read every hour (last read '.Carbon::parse($scan['scanned_at'], 'UTC')->setTimezone(ReportRange::TZ)->format('M j, g:i A').').'
                    .($scan['caught_up'] ? '' : ' Older chats are still being read, so the list may be incomplete.'),
            ],
        ];
    }
}
