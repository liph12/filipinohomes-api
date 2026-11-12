<?php

namespace App\Services\Reports\Executive;

use App\Support\OfficeRegionMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One summary build: its range and clock, and what its sections share — row
 * sets fetched once and folded many ways, memoized metric values, and the
 * folds that turn raw ids into towns, regions, offices and teams.
 *
 * A breakdown is folded in PHP from the rows of the metric engine's own
 * builder (one query per row set instead of one per breakdown). Each fold is
 * the exact inverse of a drill filter, so a cell counts the rows its drill
 * lists — the summary parity test holds every table cell to that.
 *
 * `$filters` narrows the build: the Market explorer's slice (a type, a
 * province, a town, a project) or the Agents page's office focus. Every row
 * set and every stat takes the filters its metric family understands
 * (filtersFor()) — a team count can't narrow to an office, so it stays FH-wide
 * — and a stat carries them, so its drill lists the same records. A cell's
 * drill carries those filters plus its own.
 */
final class ReportContext
{
    /** "As of now" agent sets that still depend on the range (the range's leads, waiting now). */
    private const RANGE_BOUND_NOW = ['agents.unanswered'];

    private array $memo = [];

    public function __construct(
        public readonly ReportRange $range,
        public readonly Carbon $now,
        private readonly MetricEngine $engine,
        private readonly ReportQueries $q,
        private readonly PeopleQueries $people,
        private readonly Geography $geo,
        private readonly TeamDirectory $teams,
        private readonly LeadQueries $leads,
        public readonly array $filters = [],
    ) {}

    /** The context's filters that a metric's drill accepts. */
    public function filtersFor(string $key): array
    {
        return array_intersect_key($this->filters, array_flip(MetricRegistry::filters($key)));
    }

    // ── Stats ───────────────────────────────────────────────────────────────

    /**
     * A metric's tile: value, definition, flags — and, when $compare and the
     * metric is measured over the range, the previous period's value.
     */
    public function stat(string $key, bool $compare = false): array
    {
        $def = MetricRegistry::get($key);
        [$value, $sample] = $this->measure($key, $this->range);

        $stat = [
            'key' => $key,
            'label' => $def['label'],
            'hint' => $def['hint'],
            'unit' => $def['unit'],
            'kind' => $def['kind'],
            'as_of' => $def['as_of'],
            'better' => $def['better'],
            'value' => $value,
            'partial' => MetricRegistry::partial($key, $this->range),
        ];
        if (($filters = $this->filtersFor($key)) !== []) {
            $stat['filters'] = $filters; // the tile's drill narrows the same way
        }
        if ($def['kind'] === 'median') {
            $stat['sample'] = $sample;
        }
        if ($compare && $def['as_of'] === 'range') {
            $previous = $this->range->previous();
            [$before] = $this->measure($key, $previous);
            $stat['previous'] = $before;
            $stat['change_pct'] = self::changePct($value, $before);
            $stat['previous_partial'] = MetricRegistry::partial($key, $previous);
        }

        return $stat;
    }

    /** @return array{0: int|float|null, 1: int|null} the value and, for a median, its sample size */
    public function measure(string $key, ReportRange $range): array
    {
        return $this->memo['measure:'.$key.':'.$range->key()] ??= $this->compute($key, $range);
    }

    private function compute(string $key, ReportRange $range): array
    {
        $def = MetricRegistry::get($key);
        $current = $range->key() === $this->range->key();

        if ($def['family'] === 'agent') {
            return [count($this->agents($key, $range)), null];
        }
        if ($def['family'] === 'team') {
            return [count($this->teamIds($key, $range)), null];
        }
        if ($current && $def['family'] === 'lifecycle') {
            return $this->lifecycleMeasure($key);
        }
        if ($current && in_array($key, ['listings.ppsqm_sale', 'listings.ppsqm_rent'], true)) {
            $values = $this->priced($key)->pluck('ppsqm')->all();

            return [PricePerSqm::median($values), count($values)];
        }
        if ($def['kind'] === 'median') {
            $median = $this->engine->median($key, $range, $this->now, $this->filtersFor($key));

            return [$median['value'], $median['sample']];
        }

        return [$this->engine->value($key, $range, $this->now, $this->filtersFor($key)), null];
    }

    /** The lifecycle metrics, counted over the one lifecycle row set — MetricEngine::lifecycleSource()'s conditions. */
    private function lifecycleMeasure(string $key): array
    {
        $rows = $this->lifecycle();
        $reached = $rows->filter(fn ($r) => (int) $r->reached_agent === 1);

        if ($key === 'inquiries.first_reply_minutes') {
            $values = $reached->filter(fn ($r) => $r->first_agent_at !== null)
                ->map(fn ($r) => MetricEngine::firstReplyMinutes($r->reviewed_at, $r->received_at, $r->first_agent_at))
                ->values()->all();

            return [PricePerSqm::median($values), count($values)];
        }

        $count = match ($key) {
            'inquiries.reached_agent' => $reached->count(),
            'inquiries.answered' => $reached->filter(fn ($r) => $r->last_side_at !== null)->count(),
            'inquiries.hanging.pending_review' => $rows->where('bucket', 'pending_review')->count(),
            'inquiries.hanging.no_reply' => $rows->where('bucket', 'no_reply')->count(),
            'inquiries.hanging.client_waiting' => $rows->where('bucket', 'client_waiting')->count(),
            'inquiries.hanging.total' => $rows->whereIn('bucket', MetricEngine::HANGING)->count(),
            'inquiries.stalled' => $rows->where('bucket', 'stalled')->count(),
        };

        return [$count, null];
    }

    public static function changePct(int|float|null $value, int|float|null $previous): ?float
    {
        if ($value === null || $previous === null || (float) $previous === 0.0) {
            return null;
        }

        return round(($value - $previous) / abs($previous) * 100, 1);
    }

    // ── Row sets ────────────────────────────────────────────────────────────

    /** Listings added in the range — one row per listing. */
    public function listingsAdded(): Collection
    {
        return $this->memo['rows:listings'] ??= $this->listingRows('listings.added', 'listings.id', ['listings.created_at as at']);
    }

    /** Deals in the range — one row per listing, with its status and deal date. */
    public function deals(): Collection
    {
        return $this->memo['rows:deals'] ??= $this->listingRows('deals.total', 'listings.id', [
            'properties.status as status', 'properties.status_change_date as at',
        ]);
    }

    /** Inquiries received in the range — one row per chat. */
    public function inquiries(): Collection
    {
        return $this->memo['rows:inquiries'] ??= $this->listingRows('inquiries.received', 'chats.id', ['chats.created_at as at']);
    }

    /** Listings live right now — one row per listing, with its status and price. */
    public function liveListings(): Collection
    {
        return $this->memo['rows:live'] ??= $this->listingRows('listings.live', 'listings.id', ['properties.status as status', 'listings.price']);
    }

    /** The range's inquiry lifecycle — one row per chat (see ReportQueries::lifecycle()). */
    public function lifecycle(): Collection
    {
        return $this->memo['rows:lifecycle'] ??= DB::query()
            ->fromSub($this->q->lifecycle($this->range, $this->now, $this->filters), 't')
            ->get(['t.chat_id', 't.listing_id', 't.agent_id', 't.office', 't.bucket', 't.reached_agent', 't.received_at', 't.reviewed_at', 't.last_side_at', 't.first_agent_at']);
    }

    /** Every lead of the range from all four sources — one row per lead (see LeadQueries). */
    public function leadRows(): Collection
    {
        return $this->memo['rows:leads'] ??= $this->leads->applyFilters(
            DB::query()->fromSub($this->leads->all($this->range, $this->now), 'ld'),
            array_intersect_key($this->filters, array_flip(MetricRegistry::FAMILY_FILTERS['lead'])),
        )->get(['ld.lead_key', 'ld.source', 'ld.agent_id', 'ld.office', 'ld.answered', 'ld.waiting']);
    }

    /** The listings of a ₱/sqm sample, each with its ₱/sqm — the median's drill lists exactly these. */
    public function priced(string $key): Collection
    {
        return $this->memo['rows:'.$key] ??= $this->pricedRows($key);
    }

    private function pricedRows(string $key): Collection
    {
        [$base] = $this->engine->source($key, $this->range, $this->now, $this->filters);

        return (clone $base)->get([
            'listings.id as id', 'listings.price', 'pt.id as type_id', 'pt.name as type', 'pa.floor_area', 'pa.lot_area',
            'projects.id as project_id', DB::raw($this->q->dimExpr('city').' as city_id'),
        ])
            ->unique('id')
            ->map(fn ($r) => (object) [...(array) $r, 'ppsqm' => PricePerSqm::perSqm((float) $r->price, $r->type, $r->floor_area, $r->lot_area)])
            ->filter(fn ($r) => $r->ppsqm !== null)
            ->values();
    }

    private function listingRows(string $key, string $id, array $extra): Collection
    {
        [$base] = $this->engine->source($key, $this->range, $this->now, $this->filters);

        return (clone $base)->get([
            "{$id} as id",
            DB::raw($this->q->dimExpr('city').' as city_id'),
            DB::raw($this->q->dimExpr('province').' as province_id'),
            'owner.region as office',
            'pt.id as type_id',
            'projects.id as project_id',
            'categories.name as category',
            'listings.id as listing_id',
            'listings.agent_id',
            ...$extra,
        ])->unique('id')->values();
    }

    // ── People ──────────────────────────────────────────────────────────────

    /**
     * @return array<int, string|null> agent id => office region, for an agent-family metric.
     *                                 Two sets are differences of sets already fetched, not a query
     *                                 of their own — the same people the drill's SQL lists.
     */
    public function agents(string $key, ?ReportRange $range = null): array
    {
        $range ??= $this->range;
        $when = MetricRegistry::get($key)['as_of'] === 'now' && ! in_array($key, self::RANGE_BOUND_NOW, true) ? 'now' : $range->key();

        return $this->memo['agents:'.$key.':'.$when] ??= match ($key) {
            // Active the period before, not in this one.
            'agents.gone_quiet' => array_diff_key($this->agents('agents.active', $range->previous()), $this->agents('agents.active', $range)),
            // Dormant, minus the dormant who never used FH.
            'agents.dormant_used' => array_diff_key($this->agents('agents.dormant_30d'), $this->agents('agents.never_used')),
            default => $this->engine->agentRegions($key, $range, $this->now, $this->filtersFor($key)),
        };
    }

    /**
     * Team ids behind a team metric — MetricEngine::teamIds()'s sets, over the
     * memoized agent sets. A team count can't narrow to an office (its drill
     * takes no office filter), so under a focus it stays FH-wide.
     */
    public function teamIds(string $key, ?ReportRange $range = null): array
    {
        $range ??= $this->range;
        $roster = $this->teamsOf(array_keys($this->fhWideAgents('agents.headcount', $range)));
        $active = fn () => $this->teamsOf(array_keys($this->fhWideAgents('agents.active', $range)));

        $ids = match ($key) {
            'teams.total' => $roster,
            'teams.active' => $active(),
            'teams.inactive' => array_values(array_diff($roster, $active())),
            'teams.no_leader' => array_values(array_filter($roster, fn ($team) => $this->teams->leaders($team) === [])),
        };
        sort($ids);

        return $ids;
    }

    /** Agent ids whose user is an agent — not admin / editor / secretary — the rankings' population. */
    public function agentRoleIds(): array
    {
        return $this->memo['agent-role-ids'] ??= array_fill_keys(
            array_map('intval', $this->people->agents()->pluck('agents.id')->all()),
            true,
        );
    }

    /** An agent set ignoring the context's filters (the same memo as agents() when there are none). */
    private function fhWideAgents(string $key, ReportRange $range): array
    {
        if ($this->filtersFor($key) === []) {
            return $this->agents($key, $range);
        }
        $when = MetricRegistry::get($key)['as_of'] === 'now' ? 'now' : $range->key();

        return $this->memo['agents-fh:'.$key.':'.$when] ??= $this->engine->agentRegions($key, $range, $this->now);
    }

    private function teamsOf(array $agentIds): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($id) => $this->teams->teamOf((int) $id), $agentIds))));
    }

    // ── Folds — each the inverse of a ReportQueries::applyFilters() filter ──

    /** [field value => rows] ('' for a missing value). */
    public static function countBy(iterable $rows, string $field): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row->{$field} ?? '');
            $out[$key] = ($out[$key] ?? 0) + 1;
        }

        return $out;
    }

    /** Raw city counts → towns (group ids); 0 = no city. Inverse of `city_id`. */
    public function byCity(array $raw): array
    {
        $out = [];
        foreach ($raw as $city => $n) {
            $group = $city === '' ? 0 : ($this->geo->cityGroup((int) $city) ?? 0);
            $out[$group] = ($out[$group] ?? 0) + $n;
        }

        return $out;
    }

    /** Raw province counts → canonical provinces; 0 = no province. Inverse of `province_id`. */
    public function byProvince(array $raw): array
    {
        $out = [];
        foreach ($raw as $province => $n) {
            $canonical = $province === '' ? 0 : ($this->geo->canonicalProvince((int) $province) ?? 0);
            $out[$canonical] = ($out[$canonical] ?? 0) + $n;
        }

        return $out;
    }

    /** Raw province counts → PSA regions; `unknown` = no province, or one mapped to no region. Inverse of `psa_region`. */
    public function byRegion(array $raw): array
    {
        $out = [];
        foreach ($raw as $province => $n) {
            $region = ($province === '' ? null : $this->geo->regionOfProvince((int) $province)) ?? Geography::UNKNOWN_REGION;
            $out[$region] = ($out[$region] ?? 0) + $n;
        }

        return $out;
    }

    /** Raw office-region counts → valid regions or `unassigned`. Inverse of `office_region`. */
    public static function byOffice(array $raw): array
    {
        $out = [];
        foreach ($raw as $region => $n) {
            $key = self::officeOf((string) $region);
            $out[$key] = ($out[$key] ?? 0) + $n;
        }

        return $out;
    }

    /** An agent's office region as the `office_region` filter sees it (MySQL compares case-insensitively). */
    public static function officeOf(?string $region): string
    {
        $region = strtolower(rtrim((string) $region));

        return in_array($region, OfficeRegionMap::REGIONS, true) ? $region : 'unassigned';
    }

    /** Agent-level counts → credited teams; 0 = no team. Rows without an agent count nowhere — as with `team_id`. */
    public function byTeam(array $agentCounts): array
    {
        $out = [];
        foreach ($agentCounts as $agent => $n) {
            if ($agent === '') {
                continue;
            }
            $team = $this->teams->teamOf((int) $agent) ?? 0;
            $out[$team] = ($out[$team] ?? 0) + $n;
        }

        return $out;
    }

    /** Keys by count, highest first; ties by key — so a ranking never reshuffles between requests. */
    public static function ranked(array $counts, ?int $limit = null): array
    {
        $keys = array_keys($counts);
        usort($keys, fn ($a, $b) => [$counts[$b], (string) $a] <=> [$counts[$a], (string) $b]);

        return $limit === null ? $keys : array_slice($keys, 0, $limit);
    }

    // ── Names ───────────────────────────────────────────────────────────────

    /** @return array<int, string> property type id => name */
    public function typeNames(): array
    {
        return $this->memo['type-names'] ??= DB::table('property_types')->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name])->all();
    }

    /** @return array<int, string> project id => name, for the given ids */
    public function projectNames(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        return $ids === [] ? [] : DB::table('projects')->whereIn('id', $ids)->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(int) $id => (string) ($name ?: "Project #{$id}")])->all();
    }

    public function geo(): Geography
    {
        return $this->geo;
    }

    public function teams(): TeamDirectory
    {
        return $this->teams;
    }
}
