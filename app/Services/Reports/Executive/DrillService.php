<?php

namespace App\Services\Reports\Executive;

use App\Support\OfficeRegionMap;
use App\Support\RegionMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * "Who is behind this number?" — one endpoint for every number in the report.
 *
 * The list is the metric's own builder from {@see MetricEngine::source()},
 * counted for `meta.total` and paged for `rows`, so the total equals the
 * number the admin clicked. Paging groups by the counted id with an aggregate
 * sort, which stays correct where a record appears in several joined rows
 * (a listing with two sold claims, a client with three inquiries).
 */
final class DrillService
{
    public const MAX_PER_PAGE = 200;

    public function __construct(
        private readonly MetricEngine $engine,
        private readonly RowHydrator $rows,
        private readonly Geography $geo,
        private readonly TeamDirectory $teams,
        private readonly PeopleQueries $people,
    ) {}

    /**
     * @param  array<string, string|int>  $filters  validated against the metric
     */
    public function drill(string $key, ReportRange $range, Carbon $now, array $filters, int $page = 1, int $perPage = 25, ?string $sort = null, ?string $dir = null): array
    {
        $def = MetricRegistry::get($key);
        $this->assertFilters($key, $filters);

        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $page);

        $cacheKey = 'exec-report:v1:drill:'.$range->key().':'.sha1(json_encode([$key, $filters, $page, $perPage, $sort, $dir]));
        // An "as of now" list (hanging inquiries, dormant agents) moves with
        // the clock even for a past range, so it never keeps past 10 minutes.
        $ttl = $def['as_of'] === 'now' ? min(600, $range->ttl($now)) : $range->ttl($now);

        return Cache::remember($cacheKey, $ttl, function () use ($def, $key, $range, $now, $filters, $page, $perPage, $sort, $dir) {
            $result = $def['family'] === 'team'
                ? $this->teamPage($key, $range, $now, $filters, $page, $perPage)
                : $this->sqlPage($def, $key, $range, $now, $filters, $page, $perPage, $sort, $dir);

            return [
                'metric' => $key,
                'label' => $def['label'],
                'definition' => $def['hint'],
                'entity' => $def['entity'],
                'as_of' => $def['as_of'],
                'range' => $range->toArray(),
                'filters' => (object) $filters,
                'filter_labels' => (object) $this->filterLabels($filters),
                'columns' => $this->rows->columns($def['entity'], $key),
                'rows' => $result['rows'],
                'sort' => $result['sort'],
                'meta' => [
                    'total' => $result['total'],
                    'page' => $page,
                    'per_page' => $perPage,
                    'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
                ],
                'generated_at' => $now->copy()->setTimezone(ReportRange::TZ)->toIso8601String(),
            ];
        });
    }

    /** 422-worthy: a filter the metric's family doesn't understand. */
    public function assertFilters(string $key, array $filters): void
    {
        $allowed = MetricRegistry::filters($key);
        foreach (array_keys($filters) as $filter) {
            if (! in_array($filter, $allowed, true)) {
                throw new InvalidArgumentException("The filter '{$filter}' does not apply to '{$key}'.");
            }
        }
    }

    private function sqlPage(array $def, string $key, ReportRange $range, Carbon $now, array $filters, int $page, int $perPage, ?string $sort, ?string $dir): array
    {
        [$base, $id] = $this->engine->source($key, $range, $now, $filters);
        $total = (int) (clone $base)->distinct()->count($id);

        $sorts = $this->sorts($def, $key);
        $sortName = $sort !== null && isset($sorts[$sort]) ? $sort : array_key_first($sorts);
        [$expr, $defaultDir] = $sorts[$sortName];
        $direction = in_array($dir, ['asc', 'desc'], true) ? $dir : $defaultDir;

        $select = ["{$id} as id", "MAX({$expr}) as s"];
        $lifecycle = $def['family'] === 'lifecycle';
        if ($def['family'] === 'lead') {
            $select[] = 'MAX(ld.since) as waiting_since';
        }
        if ($lifecycle) {
            $select[] = 'MAX('.self::WAITING_SINCE.') as waiting_since';
            $select[] = 'MAX(t.received_at) as received_at';
            $select[] = 'MAX(t.reviewed_at) as reviewed_at';
            $select[] = 'MAX(t.first_agent_at) as first_agent_at';
        }
        if ($key === 'claims.sold_unmarked' || $key === 'claims.reserved_unmarked') {
            $select[] = 'MAX(sc.said_at) as said_at';
        }

        $pageRows = (clone $base)
            ->selectRaw(implode(', ', $select))
            ->groupBy(DB::raw($id))
            // NULLs last either way, so "never online" doesn't masquerade as most recent.
            ->orderByRaw("CASE WHEN MAX({$expr}) IS NULL THEN 1 ELSE 0 END")
            ->orderBy('s', $direction)
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->get();

        $ids = $pageRows->pluck('id')->map(fn ($v) => (int) $v)->all();

        $rows = match ($def['entity']) {
            'listing', 'deal' => $this->rows->listings($ids, $def['entity'], $key, $pageRows->mapWithKeys(
                fn ($r) => isset($r->said_at) ? [(int) $r->id => ['said' => $this->iso($r->said_at)]] : [(int) $r->id => []]
            )->all()),
            'inquiry' => $this->rows->inquiries($ids, $lifecycle ? $pageRows->mapWithKeys(fn ($r) => [(int) $r->id => array_filter([
                'waiting_since' => $key === 'inquiries.first_reply_minutes' ? null : $r->waiting_since,
                'first_reply' => $key === 'inquiries.first_reply_minutes' && $r->first_agent_at
                    ? MetricEngine::firstReplyMinutes($r->reviewed_at, $r->received_at, $r->first_agent_at) : null,
            ], fn ($v) => $v !== null)])->all() : [], $now),
            'lead' => $this->rows->leads($ids, $pageRows->mapWithKeys(fn ($r) => [(int) $r->id => $r->waiting_since])->filter()->all(), $now),
            'user' => $this->rows->users($ids),
            'agent' => $this->rows->agents($ids),
        };

        return ['rows' => $rows, 'total' => $total, 'sort' => ['by' => $sortName, 'dir' => $direction]];
    }

    /**
     * When a hanging inquiry started waiting, per bucket: the moderation queue
     * since it arrived; "never answered" since it reached the agent; "client
     * waiting" since the client's last message; "gone quiet" since the agent
     * side's last message.
     */
    private const WAITING_SINCE = "CASE t.bucket
        WHEN 'pending_review' THEN t.conversation_created_at
        WHEN 'no_reply' THEN COALESCE(t.reviewed_at, t.conversation_created_at)
        WHEN 'client_waiting' THEN t.last_client_at
        WHEN 'stalled' THEN t.last_side_at
        ELSE t.received_at END";

    /** [sort name => [SQL expression, default direction]]; the first is the default. */
    private function sorts(array $def, string $key): array
    {
        if ($key === 'claims.sold_unmarked' || $key === 'claims.reserved_unmarked') {
            return ['said' => ['sc.said_at', 'desc'], 'price' => ['listings.price', 'desc'], 'added' => ['listings.created_at', 'desc']];
        }

        return match (true) {
            $def['family'] === 'lead' => in_array($key, ['leads.waiting', 'direct.waiting'], true)
                // Longest waiting first.
                ? ['waiting' => ['ld.since', 'asc'], 'received' => ['ld.at', 'desc']]
                : ['received' => ['ld.at', 'desc']],
            $def['family'] === 'lifecycle' && str_starts_with($key, 'inquiries.hanging.'), $key === 'inquiries.stalled' => [
                // Longest waiting first: the earliest "waiting since".
                'waiting' => [self::WAITING_SINCE, 'asc'],
                'received' => ['t.received_at', 'desc'],
            ],
            $def['family'] === 'lifecycle' => ['received' => ['t.received_at', 'desc']],
            $def['entity'] === 'deal' => ['deal_date' => ['properties.status_change_date', 'desc'], 'price' => ['listings.price', 'desc']],
            $def['entity'] === 'listing' => ['added' => ['listings.created_at', 'desc'], 'price' => ['listings.price', 'desc']],
            $def['entity'] === 'inquiry', $key === 'inquiries.unique_clients' => ['received' => ['chats.created_at', 'desc']],
            $def['entity'] === 'agent' => in_array($key, ['agents.dormant_30d', 'agents.never_used', 'agents.dormant_used'], true)
                // Longest gone first (never-online agents sort last, see the NULLs rule).
                ? ['last_online' => ['u.last_online_at', 'asc'], 'joined' => ['agents.created_at', 'desc']]
                : ['last_online' => ['u.last_online_at', 'desc'], 'joined' => ['agents.created_at', 'desc']],
            $def['entity'] === 'user' => in_array($key, ['usage.new_clients', 'usage.unfinished_signups'], true)
                ? ['joined' => ['u.created_at', 'desc'], 'last_online' => ['u.last_online_at', 'desc']]
                : ['last_online' => ['u.last_online_at', 'desc'], 'joined' => ['u.created_at', 'desc']],
        };
    }

    private function teamPage(string $key, ReportRange $range, Carbon $now, array $filters, int $page, int $perPage): array
    {
        $teamIds = $this->engine->teamIds($key, $range, $now, $filters);

        $headcount = $this->countByTeam($this->people->headcount()->pluck('agents.id')->all());
        $active = $this->countByTeam($this->people->activeAgents($range)->pluck('agents.id')->all());

        usort($teamIds, fn ($a, $b) => [$active[$b] ?? 0, $headcount[$b] ?? 0, $this->teams->name($a)]
            <=> [$active[$a] ?? 0, $headcount[$a] ?? 0, $this->teams->name($b)]);

        $slice = array_slice($teamIds, ($page - 1) * $perPage, $perPage);

        return [
            'rows' => $this->rows->teams($slice, $headcount, $active),
            'total' => count($teamIds),
            'sort' => ['by' => 'active', 'dir' => 'desc'],
        ];
    }

    /** @return array<int, int> team id => how many of $agentIds it is credited with */
    public function countByTeam(array $agentIds): array
    {
        $out = [];
        foreach ($agentIds as $agentId) {
            $team = $this->teams->teamOf((int) $agentId);
            if ($team !== null) {
                $out[$team] = ($out[$team] ?? 0) + 1;
            }
        }

        return $out;
    }

    /** Human words for the applied filters ("Cebu City", "Team Alpha", …). */
    public function filterLabels(array $filters): array
    {
        $labels = [];
        foreach ($filters as $key => $value) {
            $labels[$key] = match ($key) {
                'city_id' => (int) $value === 0 ? 'No city' : ($this->geo->cityName($this->geo->cityGroup((int) $value)) ?? "City #{$value}"),
                'province_id' => (int) $value === 0 ? 'No province' : ($this->geo->provinceName((int) $value) ?? "Province #{$value}"),
                'psa_region' => $value === Geography::UNKNOWN_REGION ? 'Unknown region' : RegionMap::label((string) $value),
                'island' => ucfirst((string) $value),
                'office_region' => $value === 'unassigned' ? 'No office region' : OfficeRegionMap::label((string) $value),
                'team_id' => (int) $value === 0 ? 'No team' : ($this->teams->name((int) $value) ?? "Team #{$value}"),
                'agent_id' => $this->agentName((int) $value),
                'type_id' => (int) $value === 0 ? 'Unspecified type' : (DB::table('property_types')->where('id', (int) $value)->value('name') ?? "Type #{$value}"),
                'project_id' => (int) $value === 0 ? 'Not in a project' : (DB::table('projects')->where('id', (int) $value)->value('name') ?? "Project #{$value}"),
                'category' => ReportQueries::CATEGORIES[$value] ?? (string) $value,
                'status' => ucfirst((string) $value),
                'listing_id' => DB::table('listings')->where('id', (int) $value)->value('name') ?? "Listing #{$value}",
                'role' => ucfirst((string) $value),
                'leads' => match ((string) $value) {
                    '1' => '1 lead',
                    '10-plus' => '10 or more leads',
                    default => str_replace('-', '–', (string) $value).' leads',
                },
                default => (string) $value,
            };
        }

        return $labels;
    }

    private function agentName(int $agentId): string
    {
        $row = DB::table('agents')->leftJoin('users as u', 'u.id', '=', 'agents.user_id')
            ->where('agents.id', $agentId)->first(['agents.first_name', 'agents.last_name', 'u.name']);
        $name = $row ? trim(trim((string) $row->first_name).' '.trim((string) $row->last_name)) : '';

        return $name !== '' ? $name : ($row->name ?? "Agent #{$agentId}");
    }

    private function iso($value): ?string
    {
        return $value ? Carbon::parse((string) $value, 'UTC')->setTimezone(ReportRange::TZ)->toIso8601String() : null;
    }
}
