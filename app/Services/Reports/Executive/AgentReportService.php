<?php

namespace App\Services\Reports\Executive;

use App\Services\Reports\Executive\Sections\Table;
use App\Support\OfficeRegionMap;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The AI Report's Agents page: who gets leads, who answers them, where (Cebu
 * first), which teams are working and which have gone quiet.
 *
 * Every number is a registry metric, and every table cell carries the
 * {metric, filters} its drill lists — folded in PHP from row sets fetched
 * once (ReportContext), never re-counted in SQL of its own. An office focus
 * (`$office`) narrows the whole page to the agents of one office region and
 * their leads; team counts can't narrow to an office and stay FH-wide.
 *
 * Built once per range + office and cached like the summary (a lock per key,
 * refresh only when older than a minute).
 */
final class AgentReportService
{
    public const CACHE_PREFIX = 'exec-report:v1:agents:';

    /** Teams smaller than this are listed apart instead of ranked — one active member is not a rate. */
    public const RANK_MIN_MEMBERS = 5;

    /** The region the page spotlights when no focus is picked — FH's home market. */
    public const SPOTLIGHT = 'cebu';

    private const TOP = 10;

    private const RANKED = 5;

    /** "Best at answering" and "fastest reply" need this many leads / answers to rank an agent. */
    private const MIN_SAMPLE = 3;

    private const MAX_TTL = 1800;

    private const REFRESH_AFTER = 60;

    private const SLOW_MS = 8000;

    public function __construct(
        private readonly ExecutiveReportService $report,
        private readonly RowHydrator $rows,
        private readonly Geography $geo,
    ) {}

    /**
     * The cached page for a range and an optional office focus.
     *
     * @throws LockTimeoutException when another build of the same key runs too long
     */
    public function page(ReportRange $range, Carbon $now, ?string $office = null, bool $refresh = false): array
    {
        $key = self::CACHE_PREFIX.$range->key().':'.($office ?? 'all');
        $usable = fn (?array $page) => $page !== null
            && ! ($refresh && $now->getTimestamp() - $page['meta']['built_at'] >= self::REFRESH_AFTER);

        $page = Cache::get($key);
        if ($usable($page)) {
            return $this->served($page, true);
        }

        $built = false;
        $page = Cache::lock($key.':build', 300)->block(150, function () use ($key, $range, $now, $office, $usable, &$built) {
            $page = Cache::get($key);
            if ($usable($page)) {
                return $page;
            }

            $started = hrtime(true);
            $page = $this->build($range, $now, $office);
            $page['meta'] = [
                'built_at' => $now->getTimestamp(),
                'generated_at' => $now->copy()->setTimezone(ReportRange::TZ)->toIso8601String(),
                'compute_ms' => (int) round((hrtime(true) - $started) / 1e6),
                'timings_ms' => $page['timings_ms'],
                'ttl' => min($range->ttl($now), self::MAX_TTL),
            ];
            unset($page['timings_ms']);
            if ($page['meta']['compute_ms'] > self::SLOW_MS) {
                Log::warning('executive_report.slow_build', ['page' => 'agents', 'range' => $range->key(), 'office' => $office]
                    + array_intersect_key($page['meta'], array_flip(['compute_ms', 'timings_ms'])));
            }
            Cache::put($key, $page, $page['meta']['ttl']);
            $built = true;

            return $page;
        });

        return $this->served($page, ! $built);
    }

    /** The page, uncached, with `timings_ms` per part (the profiler prints them). */
    public function build(ReportRange $range, Carbon $now, ?string $office = null): array
    {
        $c = $this->report->context($range, $now, $office !== null ? ['office_region' => $office] : []);
        $previous = $range->previous();

        $timings = [];
        $timed = function (string $part, callable $build) use (&$timings) {
            $started = hrtime(true);
            $result = $build();
            $timings[$part] = (int) round((hrtime(true) - $started) / 1e6);

            return $result;
        };

        $measures = $timed('measures', fn () => $this->measures($c));

        return [
            'range' => $range->toArray() + ['previous' => ['start' => $previous->start, 'end' => $previous->end]],
            'as_of' => $now->copy()->setTimezone(ReportRange::TZ)->toIso8601String(),
            'data_since' => $this->report->dataSince($range),
            'office' => $office === null ? null : ['key' => $office, 'label' => self::officeLabel($office)],
            'overview' => $timed('overview', fn () => $this->overview($c)),
            'leads' => $timed('leads', fn () => $this->leads($c, $measures)),
            'regions' => $timed('regions', fn () => $this->regions($c, $measures, $office)),
            'teams' => $timed('teams', fn () => $this->teams($c, $measures)),
            'quiet' => $timed('quiet', fn () => $this->quiet($c)),
            'top' => $timed('top', fn () => $this->top($c, $measures)),
            'notes' => $timed('notes', fn () => $this->notes($c)),
            'timings_ms' => $timings,
        ];
    }

    // ── Sections ────────────────────────────────────────────────────────────

    private function overview(ReportContext $c): array
    {
        return [
            'stats' => [
                $c->stat('agents.active', compare: true),
                $c->stat('agents.with_leads', compare: true),
                $c->stat('leads.all', compare: true),
                $c->stat('leads.answered'),
                $c->stat('leads.waiting'),
                $c->stat('agents.dormant_30d'),
            ],
            'roster' => $c->stat('agents.headcount'),
            'reached' => $c->stat('leads.reached'),
            'first_reply' => $c->stat('inquiries.first_reply_minutes'),
        ];
    }

    private function leads(ReportContext $c, array $m): array
    {
        $roleAgents = $c->agentRoleIds();
        $spread = array_fill_keys(array_map('strval', array_keys(LeadQueries::BUCKETS)), 0);
        foreach ($m['all'] as $agent => $n) {
            if ($agent !== '' && isset($roleAgents[(int) $agent])) {
                $spread[LeadQueries::bucketOf($n)]++;
            }
        }

        return [
            'stats' => [
                $c->stat('leads.all', compare: true),
                $c->stat('agents.with_leads', compare: true),
                $c->stat('leads.reached'),
                $c->stat('leads.answered'),
                $c->stat('inquiries.first_reply_minutes'),
                $c->stat('leads.waiting'),
                $c->stat('inquiries.hanging.client_waiting'),
                $c->stat('leads.to_idle_agents', compare: true),
            ],
            'sources' => [
                $c->stat('inquiries.received', compare: true),
                $c->stat('direct.received', compare: true),
                $c->stat('briefs.received'),
                $c->stat('open_house.registrations'),
            ],
            'spread' => array_map(fn ($bucket, $agents) => [
                'bucket' => (string) $bucket, // '1' comes back from the array as an int
                'label' => match ((string) $bucket) {
                    '1' => '1 lead',
                    '10-plus' => '10 or more',
                    default => str_replace('-', '–', (string) $bucket).' leads',
                },
                'agents' => $agents,
                'filters' => $c->filtersFor('agents.with_leads') + ['leads' => (string) $bucket],
            ], array_keys($spread), $spread),
            'by_team' => $this->leadsByTeam($c, $m),
        ];
    }

    /** Teams by their leads, with how many were answered — teams with leads, then "No team". */
    private function leadsByTeam(ReportContext $c, array $m): array
    {
        $by = fn (array $perAgent) => $c->byTeam($perAgent);
        $all = $by($m['all']);
        $reached = $by($m['reached']);
        $answered = $by($m['answered']);
        $waiting = $by($m['waiting']);

        $rows = [];
        foreach ($all as $team => $n) {
            $rows[] = [
                'team' => ['id' => (int) $team, 'name' => $team === 0 ? 'No team' : $c->teams()->name((int) $team)],
                'leads' => $n,
                'reached' => $reached[$team] ?? 0,
                'answered' => $answered[$team] ?? 0,
                'rate' => self::pct($answered[$team] ?? 0, $reached[$team] ?? 0),
                'waiting' => $waiting[$team] ?? 0,
                'filters' => $c->filters + ['team_id' => (int) $team],
            ];
        }
        usort($rows, fn ($a, $b) => [$a['team']['id'] === 0, $b['leads'], (string) $a['team']['name']] <=> [$b['team']['id'] === 0, $a['leads'], (string) $b['team']['name']]);

        return [
            'columns' => [
                Table::col('team', 'Team', 'team'),
                Table::col('leads', 'Leads', 'number', 'leads.all'),
                Table::col('reached', 'Chat leads', 'number', 'leads.reached'),
                Table::col('answered', 'Answered', 'number', 'leads.answered'),
                Table::col('rate', 'Answered %', 'percent'),
                Table::col('waiting', 'Never answered', 'number', 'leads.waiting'),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * The office regions side by side — or, under a focus, that one region.
     * The spotlight is Cebu: the agents based at its offices, and separately
     * the inquiries about properties in Cebu province (a property's place,
     * not the agent's office — both are named on the page).
     */
    private function regions(ReportContext $c, array $m, ?string $office): array
    {
        $byOffice = fn (iterable $rows) => ReportContext::byOffice(ReportContext::countBy($rows, 'office'));
        $agents = fn (string $key) => ReportContext::byOffice(array_count_values(array_map('strval', array_values($c->agents($key)))));
        $counts = [
            'headcount' => $agents('agents.headcount'),
            'active' => $agents('agents.active'),
            'posting' => $agents('agents.posting'),
            'leads' => $byOffice($c->leadRows()),
            'reached' => self::add($byOffice($c->lifecycle()->filter(fn ($r) => (int) $r->reached_agent === 1)), $byOffice($m['direct'])),
            'answered' => self::add(
                $byOffice($c->lifecycle()->filter(fn ($r) => (int) $r->reached_agent === 1 && $r->last_side_at !== null)),
                $byOffice($m['direct']->filter(fn ($r) => (int) $r->answered === 1)),
            ),
            'waiting' => self::add(
                $byOffice($c->lifecycle()->where('bucket', 'no_reply')),
                $byOffice($m['direct']->filter(fn ($r) => (int) $r->waiting === 1)),
            ),
        ];

        $rows = [];
        foreach ([...OfficeRegionMap::REGIONS, 'unassigned'] as $key) {
            $row = ['office' => self::officeLabel($key), 'key' => $key];
            foreach ($counts as $column => $values) {
                $row[$column] = $values[$key] ?? 0;
            }
            $row['active_rate'] = self::pct($row['active'], $row['headcount']);
            $row['answered_rate'] = self::pct($row['answered'], $row['reached']);
            if ($row['headcount'] + $row['leads'] + $row['active'] > 0) {
                $rows[] = $row + ['filters' => $c->filters + ['office_region' => $key]];
            }
        }
        usort($rows, fn ($a, $b) => [$b['headcount'], $b['leads']] <=> [$a['headcount'], $a['leads']]);

        $spotlightKey = $office ?? self::SPOTLIGHT;
        $spotlight = collect($rows)->firstWhere('key', $spotlightKey);

        return [
            'table' => [
                'columns' => [
                    Table::col('office', 'Office region', 'text'),
                    Table::col('headcount', 'Agents', 'number', 'agents.headcount'),
                    Table::col('active', 'Working', 'number', 'agents.active'),
                    Table::col('active_rate', 'Working %', 'percent'),
                    Table::col('leads', 'Leads', 'number', 'leads.all'),
                    Table::col('answered', 'Answered', 'number', 'leads.answered'),
                    Table::col('answered_rate', 'Answered %', 'percent'),
                    Table::col('waiting', 'Never answered', 'number', 'leads.waiting'),
                    Table::col('posting', 'Posted listings', 'number', 'agents.posting'),
                ],
                'rows' => $rows,
            ],
            'spotlight' => [
                'key' => $spotlightKey,
                'label' => self::officeLabel($spotlightKey),
                'row' => $spotlight,
                'property' => $this->propertyLeads($c, $spotlightKey),
            ],
            'unassigned' => (int) ($counts['headcount']['unassigned'] ?? 0),
        ];
    }

    /**
     * Inquiries about properties in the spotlight's province — the other lens
     * on "how much is in Cebu". Only offices that are a province by name have
     * one; others (Metro Manila, Gensan…) get null.
     */
    private function propertyLeads(ReportContext $c, string $office): ?array
    {
        if ($office === 'unassigned') {
            return null;
        }
        $raw = array_search(strtolower(OfficeRegionMap::label($office)), array_map('strtolower', $this->geo->provinceNames()), true);
        $province = $raw === false ? null : $this->geo->canonicalProvince((int) $raw);
        if ($province === null) {
            return null;
        }
        $byProvince = $c->byProvince(ReportContext::countBy($c->inquiries(), 'province_id'));

        return [
            'province' => $this->geo->provinceName($province),
            'value' => $byProvince[$province] ?? 0,
            'of' => $c->inquiries()->count(),
            'metric' => 'inquiries.received',
            'filters' => $c->filters + ['province_id' => $province],
        ];
    }

    /**
     * Teams ranked by the share of their members working — a fair measure for a
     * team of 8 and a team of 246 alike. Teams under RANK_MIN_MEMBERS are listed
     * apart; quiet teams (nobody working) are their own list.
     */
    private function teams(ReportContext $c, array $m): array
    {
        $members = $c->byTeam(array_fill_keys(array_keys($c->agents('agents.headcount')), 1));
        $active = $c->byTeam(array_fill_keys(array_keys($c->agents('agents.active')), 1));
        $values = [
            'listings' => $c->byTeam(ReportContext::countBy($c->listingsAdded(), 'agent_id')),
            'deals' => $c->byTeam(ReportContext::countBy($c->deals(), 'agent_id')),
            'leads' => $c->byTeam($m['all']),
            'reached' => $c->byTeam($m['reached']),
            'answered' => $c->byTeam($m['answered']),
            'waiting' => $c->byTeam($m['waiting']),
        ];

        $teamIds = array_keys($c->teams()->teams());
        $rows = [];
        foreach ($this->rows->teams($teamIds, $members, $active) as $row) {
            $rows[] = $this->teamRow($row, $row['team']['id'], $values, $c);
        }
        $noTeam = $this->teamRow([
            'team' => ['id' => 0, 'name' => 'No team'],
            'leaders' => null,
            'members' => $members[0] ?? 0,
            'active' => $active[0] ?? 0,
            'filters' => ['team_id' => 0],
        ], 0, $values, $c);

        $rows = array_values(array_filter($rows, fn ($r) => $r['members'] + $r['active'] + $r['listings'] + $r['deals'] + $r['leads'] > 0));
        usort($rows, fn ($a, $b) => [$b['active_rate'] ?? -1, $b['active'], (string) $a['team']['name']] <=> [$a['active_rate'] ?? -1, $a['active'], (string) $b['team']['name']]);

        $ranked = array_values(array_filter($rows, fn ($r) => $r['members'] >= self::RANK_MIN_MEMBERS));
        $most = array_slice($ranked, 0, self::RANKED);
        $mostIds = array_column(array_column($most, 'team'), 'id');
        $least = array_slice(array_reverse(array_values(array_filter($ranked, fn ($r) => ! in_array($r['team']['id'], $mostIds, true)))), 0, self::RANKED);

        return [
            'stats' => [
                $c->stat('teams.total'),
                $c->stat('teams.active', compare: true),
                $c->stat('teams.inactive'),
                $c->stat('teams.no_leader'),
            ],
            'rank_min_members' => self::RANK_MIN_MEMBERS,
            'most_active' => $most,
            'least_active' => $least,
            'quiet' => array_values(array_filter($rows, fn ($r) => $r['members'] > 0 && $r['active'] === 0)),
            'small' => array_values(array_filter($rows, fn ($r) => $r['members'] > 0 && $r['members'] < self::RANK_MIN_MEMBERS && $r['active'] > 0)),
            'no_team' => $noTeam,
            'table' => [
                'columns' => [
                    Table::col('team', 'Team', 'team'),
                    Table::col('leaders', 'Leader', 'text'),
                    Table::col('members', 'Agents', 'number', 'agents.headcount'),
                    Table::col('active', 'Working', 'number', 'agents.active'),
                    Table::col('active_rate', 'Working %', 'percent'),
                    Table::col('leads', 'Leads', 'number', 'leads.all'),
                    Table::col('answered_rate', 'Answered %', 'percent'),
                    Table::col('waiting', 'Never answered', 'number', 'leads.waiting'),
                    Table::col('listings', 'Listings added', 'number', 'listings.added'),
                    Table::col('deals', 'Deals', 'number', 'deals.total'),
                ],
                'rows' => [...$rows, $noTeam],
            ],
        ];
    }

    private function teamRow(array $row, int $teamId, array $values, ReportContext $c): array
    {
        foreach ($values as $column => $byTeam) {
            $row[$column] = $byTeam[$teamId] ?? 0;
        }
        $row['active_rate'] = self::pct($row['active'], $row['members']);
        $row['answered_rate'] = self::pct($row['answered'], $row['reached']);
        $row['filters'] = $c->filters + $row['filters'];

        return $row;
    }

    private function quiet(ReportContext $c): array
    {
        return [
            'stats' => [
                $c->stat('agents.gone_quiet', compare: true),
                $c->stat('agents.dormant_30d'),
                $c->stat('agents.never_used'),
                $c->stat('agents.dormant_used'),
                $c->stat('agents.unanswered'),
            ],
        ];
    }

    /** Top agents, five ways — agents only (staff accounts aren't ranked). */
    private function top(ReportContext $c, array $m): array
    {
        $roleAgents = $c->agentRoleIds();
        $eligible = fn (array $counts) => array_filter($counts, fn ($n, $agent) => $agent !== '' && isset($roleAgents[(int) $agent]), ARRAY_FILTER_USE_BOTH);

        $listings = ReportContext::countBy($c->listingsAdded(), 'agent_id');
        $deals = ReportContext::countBy($c->deals(), 'agent_id');

        $rates = [];
        foreach ($eligible($m['reached']) as $agent => $reached) {
            if ($reached >= self::MIN_SAMPLE) {
                $rates[$agent] = ($m['answered'][$agent] ?? 0) / $reached;
            }
        }
        $replies = [];
        foreach ($c->lifecycle() as $r) {
            if ((int) $r->reached_agent === 1 && $r->first_agent_at !== null && $r->agent_id !== null && isset($roleAgents[(int) $r->agent_id])) {
                $replies[(int) $r->agent_id][] = MetricEngine::firstReplyMinutes($r->reviewed_at, $r->received_at, $r->first_agent_at);
            }
        }
        $firstReply = [];
        foreach ($replies as $agent => $minutes) {
            if (count($minutes) >= self::MIN_SAMPLE) {
                $firstReply[$agent] = Table::median(PricePerSqm::median($minutes), count($minutes));
            }
        }

        $byRate = array_keys($rates);
        usort($byRate, fn ($a, $b) => [$rates[$b], $m['answered'][$b] ?? 0, (string) $a] <=> [$rates[$a], $m['answered'][$a] ?? 0, (string) $b]);
        $byReply = array_keys($firstReply);
        // Quickest first; between equals, the one with more answers.
        usort($byReply, fn ($a, $b) => [$firstReply[$a]['value'], -$firstReply[$a]['sample'], (string) $a] <=> [$firstReply[$b]['value'], -$firstReply[$b]['sample'], (string) $b]);

        $tops = [
            'leads' => array_map('intval', ReportContext::ranked($eligible($m['all']), self::TOP)),
            'answering' => array_map('intval', array_slice($byRate, 0, self::TOP)),
            'fastest' => array_map('intval', array_slice($byReply, 0, self::TOP)),
            'listings' => array_map('intval', ReportContext::ranked($eligible($listings), self::TOP)),
            'deals' => array_map('intval', ReportContext::ranked($eligible($deals), self::TOP)),
        ];

        $ids = array_values(array_unique(array_merge(...array_values($tops))));
        $people = collect($this->rows->agents($ids))->keyBy(fn ($row) => $row['agent']['id']);
        $rows = [];
        foreach ($ids as $id) {
            $person = $people->get($id);
            if ($person === null) {
                continue;
            }
            $rows[] = [
                'agent' => $person['agent'],
                'office' => $person['office'],
                'leads' => $m['all'][$id] ?? 0,
                'answered_rate' => self::pct($m['answered'][$id] ?? 0, $m['reached'][$id] ?? 0),
                'first_reply' => $firstReply[$id] ?? null,
                'listings' => $listings[$id] ?? 0,
                'deals' => $deals[$id] ?? 0,
                'filters' => $c->filters + ['agent_id' => $id],
            ];
        }

        return [
            'columns' => [
                Table::col('agent', 'Agent', 'agent'),
                Table::col('office', 'Office region', 'text'),
                Table::col('leads', 'Leads', 'number', 'leads.all'),
                Table::col('answered_rate', 'Answered %', 'percent'),
                Table::col('first_reply', 'Typical first reply', 'minutes', 'inquiries.first_reply_minutes'),
                Table::col('listings', 'Listings added', 'number', 'listings.added'),
                Table::col('deals', 'Deals', 'number', 'deals.total'),
            ],
            'rows' => $rows,
            'tops' => $tops,
            'min_sample' => self::MIN_SAMPLE,
        ];
    }

    private function notes(ReportContext $c): array
    {
        $roster = (int) $c->stat('agents.headcount')['value'];
        $unassigned = count(array_filter($c->agents('agents.headcount'), fn ($region) => ReportContext::officeOf($region) === 'unassigned'));

        return [
            ['key' => 'roster', 'title' => 'Which agents count', 'text' => "The roster is every agent account whose status is active — {$roster} now — including accounts the Leuterio Realty sync created that were never opened. Rankings leave out admin and staff accounts."],
            ['key' => 'working', 'title' => 'Working', 'text' => 'An agent is working when they signed in, sent a message, posted or edited something in these dates. Agents who stay signed in are not logged again, so a quiet-looking agent may still read their messages.'],
            ['key' => 'leads', 'title' => 'Where leads come from', 'text' => 'Four places: a client asking about a listing (chats since Mar 22, 2026), a message sent straight to the agent from their profile or My Page (same chats), a Buyer & Seller Link brief (since Nov 4, 2026) and an open-house registration (Buyer Forms, since Jul 2, 2026). Messages from fellow agents or staff are not leads.'],
            ['key' => 'replies', 'title' => 'Answered', 'text' => 'Only replies in the FH chat are seen: for a listing inquiry, a reply by the agent, a team leader or an admin; for a message sent to the agent, a reply by the agent. Calls, texts and messaging apps cannot be seen, so "never answered" can include clients the agent reached another way. Briefs and registrations are answered outside FH, so they are not in the answer rate.'],
            ['key' => 'teams', 'title' => 'Teams', 'text' => 'Team membership is copied from Leuterio Realty when an agent signs in, so agents who have not signed in since teams started (Apr 23, 2026) show as "No team". Each agent counts for one team — their current one.'],
            ['key' => 'regions', 'title' => 'Office regions', 'text' => "An agent's office region is set when they sign in. {$unassigned} agents on the roster have none yet, so they appear under \"No office region\"."],
        ];
    }

    // ── Shared measures ─────────────────────────────────────────────────────

    /**
     * Per-agent lead counts folded once and shared by every section: all
     * leads, chat leads that reached the agent, answered, never answered —
     * each the exact rows of its leads.* metric.
     */
    private function measures(ReportContext $c): array
    {
        $leads = $c->leadRows();
        $life = $c->lifecycle();
        $direct = $leads->where('source', 'direct')->values();

        return [
            'all' => ReportContext::countBy($leads, 'agent_id'),
            'direct' => $direct,
            'reached' => self::add(
                ReportContext::countBy($life->filter(fn ($r) => (int) $r->reached_agent === 1), 'agent_id'),
                ReportContext::countBy($direct, 'agent_id'),
            ),
            'answered' => self::add(
                ReportContext::countBy($life->filter(fn ($r) => (int) $r->reached_agent === 1 && $r->last_side_at !== null), 'agent_id'),
                ReportContext::countBy($direct->filter(fn ($r) => (int) $r->answered === 1), 'agent_id'),
            ),
            'waiting' => self::add(
                ReportContext::countBy($life->where('bucket', 'no_reply'), 'agent_id'),
                ReportContext::countBy($direct->filter(fn ($r) => (int) $r->waiting === 1), 'agent_id'),
            ),
        ];
    }

    public static function officeLabel(string $office): string
    {
        return $office === 'unassigned' ? 'No office region' : OfficeRegionMap::label($office);
    }

    /** Key-wise sum of two count maps. */
    private static function add(array $a, array $b): array
    {
        foreach ($b as $key => $n) {
            $a[$key] = ($a[$key] ?? 0) + $n;
        }

        return $a;
    }

    private static function pct(int $part, int $whole): ?int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : null;
    }

    private function served(array $page, bool $cached): array
    {
        $page['meta']['cached'] = $cached;
        unset($page['meta']['built_at']);

        return $page;
    }
}
