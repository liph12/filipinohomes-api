<?php

namespace App\Services\Reports\Executive;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a metric key to the query behind it — the ONE place that does.
 *
 * The summary's value, a breakdown's cell and the drill's list all call
 * source() and then count, group or page the same builder, which is why the
 * report can promise that clicking a number lists exactly that many records.
 * The drill-parity test checks that promise for every key.
 */
final class MetricEngine
{
    /** Lifecycle buckets that count as "hanging". */
    public const HANGING = ['pending_review', 'no_reply', 'client_waiting'];

    public function __construct(
        private readonly ReportQueries $q,
        private readonly PeopleQueries $people,
        private readonly TeamDirectory $teams,
        private readonly LeadQueries $leads,
    ) {}

    /**
     * The builder behind a metric with its filters applied, and the column
     * that identifies one counted record.
     *
     * @return array{0: Builder, 1: string}
     */
    public function source(string $key, ReportRange $range, Carbon $now, array $filters = []): array
    {
        $def = MetricRegistry::get($key);

        if ($def['family'] === 'lifecycle') {
            return [$this->lifecycleSource($key, $range, $now, $filters), 't.chat_id'];
        }

        if ($def['family'] === 'lead') {
            return [$this->leads->source($key, $range, $now, $filters), 'ld.lead_key'];
        }

        if ($def['family'] === 'user') {
            return [$this->people->applyUserFilters($this->userBase($key, $range), $filters), 'u.id'];
        }

        if ($def['family'] === 'agent') {
            return [$this->people->applyAgentFilters($this->agentBase($key, $range, $now, $filters), $filters), 'agents.id'];
        }

        if ($def['family'] === 'team') {
            throw new \LogicException("Team metric '{$key}' is folded in PHP — use teamIds().");
        }

        [$base, $id] = match ($key) {
            'listings.added' => [$this->q->listingsAdded($range), 'listings.id'],
            'listings.added_by_staff' => [$this->q->listingsAdded($range)
                ->where(fn ($w) => $w->whereNull('owner_role.name')->orWhere('owner_role.name', '<>', 'agent')), 'listings.id'],
            'listings.live' => [$this->q->liveListings(), 'listings.id'],
            'listings.ppsqm_sale' => [PricePerSqm::pricedRows($this->q->listingsAdded($range)->where('categories.name', 'For Sale')), 'listings.id'],
            'listings.ppsqm_rent' => [PricePerSqm::pricedRows($this->q->listingsAdded($range)->where('categories.name', 'For Rent')), 'listings.id'],
            'deals.total' => [$this->q->deals($range), 'listings.id'],
            'deals.sold' => [$this->q->deals($range, 'sold'), 'listings.id'],
            'deals.rented' => [$this->q->deals($range, 'rented'), 'listings.id'],
            'deals.leased' => [$this->q->deals($range, 'leased'), 'listings.id'],
            'inquiries.received' => [$this->q->inquiries($range), 'chats.id'],
            'inquiries.unique_clients' => [$this->q->inquiries($range), 'chats.user_id'],
            'inquiries.pending_now' => [$this->q->pendingInquiries(), 'chats.id'],
            'claims.sold_unmarked' => [$this->q->claims($range, 'strong'), 'listings.id'],
            'claims.reserved_unmarked' => [$this->q->claims($range, 'medium'), 'listings.id'],
            'claims.inquiries_after' => [$this->q->inquiriesAfterClaims($range), 'chats.id'],
        };

        return [$this->q->applyFilters($base, $filters), $id];
    }

    /** A count metric's value, or a median metric's value. */
    public function value(string $key, ReportRange $range, Carbon $now, array $filters = []): int|float|null
    {
        $def = MetricRegistry::get($key);

        if ($def['family'] === 'team') {
            return count($this->teamIds($key, $range, $now, $filters));
        }
        if ($def['kind'] === 'median') {
            return $this->median($key, $range, $now, $filters)['value'];
        }

        [$base, $id] = $this->source($key, $range, $now, $filters);

        return (int) (clone $base)->distinct()->count($id);
    }

    /**
     * A median metric's value and how many records it was taken over — the
     * drill of a median lists exactly that sample.
     *
     * @return array{value: float|null, sample: int}
     */
    public function median(string $key, ReportRange $range, Carbon $now, array $filters = []): array
    {
        $values = match ($key) {
            'listings.ppsqm_sale', 'listings.ppsqm_rent' => $this->ppsqmValues($key, $range, $now, $filters),
            'inquiries.first_reply_minutes' => $this->firstReplyValues($range, $now, $filters),
            default => throw new \InvalidArgumentException("'{$key}' is not a median metric."),
        };

        return ['value' => PricePerSqm::median($values), 'sample' => count($values)];
    }

    /**
     * [raw dimension value => count] for a listing-family metric — the cells
     * of a breakdown. Filters narrow it first (e.g. deals of one type by city).
     */
    public function group(string $key, string $dim, ReportRange $range, Carbon $now, array $filters = []): array
    {
        [$base, $id] = $this->source($key, $range, $now, $filters);

        if (MetricRegistry::get($key)['family'] === 'lifecycle') {
            $column = match ($dim) {
                'agent' => 't.agent_id',
                'listing' => 't.listing_id',
                default => throw new \InvalidArgumentException("Lifecycle metrics group by agent or listing, not '{$dim}'."),
            };

            return (clone $base)
                ->selectRaw("{$column} as d, COUNT(DISTINCT t.chat_id) as n")
                ->groupBy(DB::raw($column))
                ->get()
                ->mapWithKeys(fn ($row) => [(string) ($row->d ?? '') => (int) $row->n])
                ->all();
        }

        return $this->q->groupCount($base, $dim, $id);
    }

    /**
     * Agent ids behind an agent-family metric — folded into teams and office
     * regions in PHP (a few thousand ids at most).
     *
     * @return array<int, string|null> agent id => office region
     */
    public function agentRegions(string $key, ReportRange $range, Carbon $now, array $filters = []): array
    {
        [$base] = $this->source($key, $range, $now, $filters);

        return (clone $base)->distinct()->pluck('agents.region', 'agents.id')
            ->mapWithKeys(fn ($region, $id) => [(int) $id => $region])->all();
    }

    /**
     * Team ids behind a team metric. Teams are folded from agents (see
     * TeamDirectory), so these are sets over agent ids, not SQL.
     *
     * @return int[]
     */
    public function teamIds(string $key, ReportRange $range, Carbon $now, array $filters = []): array
    {
        $teamsOf = fn (array $agentIds) => array_values(array_unique(array_filter(array_map(
            fn ($id) => $this->teams->teamOf((int) $id),
            $agentIds,
        ))));

        $roster = $teamsOf($this->people->headcount()->pluck('agents.id')->all());
        $ids = match ($key) {
            'teams.total' => $roster,
            'teams.active' => $teamsOf($this->people->activeAgents($range)->pluck('agents.id')->all()),
            'teams.inactive' => array_values(array_diff(
                $roster,
                $teamsOf($this->people->activeAgents($range)->pluck('agents.id')->all()),
            )),
            'teams.no_leader' => array_values(array_filter($roster, fn ($team) => $this->teams->leaders($team) === [])),
        };

        if (($filters['team_id'] ?? '') !== '') {
            $ids = array_values(array_intersect($ids, [(int) $filters['team_id']]));
        }

        sort($ids);

        return $ids;
    }

    // ── Branches ────────────────────────────────────────────────────────────

    /** The lifecycle subquery narrowed to one metric's rows (aliased `t`). */
    private function lifecycleSource(string $key, ReportRange $range, Carbon $now, array $filters): Builder
    {
        $life = DB::query()->fromSub($this->q->lifecycle($range, $now, $filters), 't');

        return match ($key) {
            'inquiries.reached_agent' => $life->where('t.reached_agent', 1),
            'inquiries.answered' => $life->where('t.reached_agent', 1)->whereNotNull('t.last_side_at'),
            'inquiries.first_reply_minutes' => $life->where('t.reached_agent', 1)->whereNotNull('t.first_agent_at'),
            'inquiries.hanging.pending_review' => $life->where('t.bucket', 'pending_review'),
            'inquiries.hanging.no_reply' => $life->where('t.bucket', 'no_reply'),
            'inquiries.hanging.client_waiting' => $life->where('t.bucket', 'client_waiting'),
            'inquiries.hanging.total' => $life->whereIn('t.bucket', self::HANGING),
            'inquiries.stalled' => $life->where('t.bucket', 'stalled'),
        };
    }

    private function userBase(string $key, ReportRange $range): Builder
    {
        return match ($key) {
            'usage.total_users' => $this->people->realUsers(),
            'usage.active_users' => $this->people->activeUsers($range),
            'usage.new_clients' => $this->people->newClients($range),
            'usage.unfinished_signups' => $this->people->unfinishedSignups($range),
            'usage.returning_clients' => $this->people->returningClients($range),
            'usage.active_clients' => $this->people->activeUsers($range, 'client'),
        };
    }

    private function agentBase(string $key, ReportRange $range, Carbon $now, array $filters): Builder
    {
        return match ($key) {
            'agents.headcount' => $this->people->headcount(),
            'agents.new' => $this->people->newAgents($range),
            'agents.posting' => $this->people->postingAgents($range, $this->q->listingsAdded($range)),
            'agents.logged_in' => $this->people->loggedInAgents($range),
            'agents.active' => $this->people->activeAgents($range),
            'agents.idle' => $this->people->idleAgents($range),
            'agents.dormant_30d' => $this->people->dormantAgents($now),
            'agents.never_used' => $this->people->neverUsedAgents($now),
            'agents.dormant_used' => $this->people->dormantUsedAgents($now),
            'agents.gone_quiet' => $this->people->goneQuietAgents($range),
            'agents.with_leads' => $this->people->agentsWithLeads($this->leads->perAgent($range, $now), isset($filters['leads']) ? (string) $filters['leads'] : null),
            'agents.unanswered' => $this->people->agentsWithWaitingLeads($this->leads->waitingAgents($range, $now)),
        };
    }

    /** ₱/sqm of every row in a priced sample — non-null by construction (area ≥ 10). */
    private function ppsqmValues(string $key, ReportRange $range, Carbon $now, array $filters): array
    {
        [$base] = $this->source($key, $range, $now, $filters);

        $values = [];
        foreach ((clone $base)->get(['listings.price', 'pt.name as type', 'pa.floor_area', 'pa.lot_area']) as $row) {
            $value = PricePerSqm::perSqm((float) $row->price, $row->type, $row->floor_area, $row->lot_area);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** Minutes from reaching the agent to the agent's first message, per answered inquiry. */
    private function firstReplyValues(ReportRange $range, Carbon $now, array $filters): array
    {
        [$base] = $this->source('inquiries.first_reply_minutes', $range, $now, $filters);

        $values = [];
        foreach ((clone $base)->get(['t.received_at', 't.reviewed_at', 't.first_agent_at']) as $row) {
            $values[] = self::firstReplyMinutes($row->reviewed_at, $row->received_at, $row->first_agent_at);
        }

        return $values;
    }

    /** Never negative: an agent who wrote before the approval landed answered at once. */
    public static function firstReplyMinutes(?string $reviewedAt, ?string $receivedAt, string $firstAgentAt): float
    {
        $start = Carbon::parse($reviewedAt ?? $receivedAt, 'UTC');
        $first = Carbon::parse($firstAgentAt, 'UTC');

        return max(0.0, round(($first->getTimestamp() - $start->getTimestamp()) / 60, 1));
    }
}
