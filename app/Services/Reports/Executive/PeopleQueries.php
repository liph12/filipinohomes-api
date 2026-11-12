<?php

namespace App\Services\Reports\Executive;

use App\Models\User;
use App\Support\OfficeRegionMap;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The people side of the Executive Report — users, clients and agents —
 * as query bases the summary counts and the drills page through.
 *
 * "Real" users. A `users` row is created the moment someone asks for a login
 * code, before they verify it, so a raw count includes every abandoned
 * sign-up (and `users.verification` can't tell them apart — it holds codes
 * and was mass-reset in October); the Leuterio Realty sync also creates rows
 * for agents who never opened FH. A user counts as real once they have left
 * any trace of using it: shown up as online (`last_online_at`), signed in
 * (`login_logs`, kept since April), held an API token (deleted on logout),
 * sent a message, opened an inquiry, saved a favourite or owned a listing.
 * Audited edits are left out of the all-time check: scanning every audit row
 * is far too slow on production, and anyone who edits is online, so they
 * already count through `last_online_at`.
 *
 * How the sets are queried — this is what keeps the page inside the
 * production host's ~20-second request limit. Every "user is in / not in this
 * set of ids" check is a JOIN against ONE materialized derived table (the
 * UNION of the sources, deduplicated once), never `u.id IN (…)` OR'd with
 * other `IN (…)`s or run against a UNION: MySQL can't turn those into joins
 * and re-checks them for every user row, which the first production build
 * did ten times over and took past the limit. "Not in" is a LEFT JOIN with
 * no match — NULL-safe by construction, unlike NOT IN.
 *
 * Agents. Only agent rows whose user's role is `agent`: admins, editors and
 * secretaries also carry agent rows (to post listings) and would otherwise
 * crowd the rankings — the same exclusion as the Business Pulse's
 * most-active list. Headcount is the agents whose status is `active`.
 *
 * "Active in the range" counts RECORDED actions only — a sign-in, a message,
 * an inquiry, a favourite, a listing posted, an audited edit. Presence
 * (`last_online_at`) is a single latest value with no history, so it would
 * quietly rewrite past ranges; it drives "dormant" instead, measured now.
 */
final class PeopleQueries
{
    /** Audit events that are a person's own edit (not a login or a system note). */
    private const EDIT_EVENTS = ['created', 'updated', 'deleted', 'restored'];

    public function __construct(private readonly TeamDirectory $teams) {}

    // ── Users ───────────────────────────────────────────────────────────────

    /** All users joined to their role (`u`, `r`). */
    public function users(): Builder
    {
        return DB::table('users as u')->join('roles as r', 'r.id', '=', 'u.role_id');
    }

    /** Users who have really used FH (see the class note). */
    public function realUsers(): Builder
    {
        return $this->users()->joinSub($this->realIds(), 'real', 'real.user_id', '=', 'u.id');
    }

    /** Clients who signed up in the range and became real users. */
    public function newClients(ReportRange $range): Builder
    {
        return $this->clientsCreated($range)->joinSub($this->realIds(), 'real', 'real.user_id', '=', 'u.id');
    }

    /** Clients whose account was created in the range but never used — abandoned sign-ups (new clients' complement). */
    public function unfinishedSignups(ReportRange $range): Builder
    {
        return $this->clientsCreated($range)
            ->leftJoinSub($this->realIds(), 'real', 'real.user_id', '=', 'u.id')
            ->whereNull('real.user_id');
    }

    /** Clients who signed in during the range and joined before it. */
    public function returningClients(ReportRange $range): Builder
    {
        return $this->users()
            ->where('r.name', 'client')
            ->where('u.created_at', '<', $range->fromUtc())
            ->joinSub($this->loginsIn($range), 'lg', 'lg.user_id', '=', 'u.id');
    }

    /** Users of any role (or one role) with a recorded action in the range. */
    public function activeUsers(ReportRange $range, ?string $role = null): Builder
    {
        return $this->users()
            ->when($role !== null, fn ($q) => $q->where('r.name', $role))
            ->joinSub($this->activityIn($range), 'act', 'act.user_id', '=', 'u.id');
    }

    // ── Agents ──────────────────────────────────────────────────────────────

    /** Agent rows of agent-role users, not deleted (`agents`, `u`, `r`). */
    public function agents(): Builder
    {
        return DB::table('agents')
            ->join('users as u', 'u.id', '=', 'agents.user_id')
            ->join('roles as r', function ($join) {
                $join->on('r.id', '=', 'u.role_id')->where('r.name', 'agent');
            })
            ->whereNull('agents.deleted_at');
    }

    /** Agents whose status is active — the headcount. */
    public function headcount(): Builder
    {
        return $this->agents()->where('agents.status', 'active');
    }

    /** Agent rows created in the range (includes accounts the Leuterio Realty sync created). */
    public function newAgents(ReportRange $range): Builder
    {
        return $this->agents()
            ->where('agents.created_at', '>=', $range->fromUtc())
            ->where('agents.created_at', '<', $range->toUtc());
    }

    /** Agents who posted at least one listing in the range (any agent status). */
    public function postingAgents(ReportRange $range, Builder $listingsAdded): Builder
    {
        return $this->agents()
            ->joinSub((clone $listingsAdded)->select('listings.agent_id')->distinct(), 'posted', 'posted.agent_id', '=', 'agents.id');
    }

    /** Agents who signed in during the range (any agent status). */
    public function loggedInAgents(ReportRange $range): Builder
    {
        return $this->agents()->joinSub($this->loginsIn($range), 'lg', 'lg.user_id', '=', 'agents.user_id');
    }

    /** Headcount agents with a recorded action in the range. */
    public function activeAgents(ReportRange $range): Builder
    {
        return $this->headcount()->joinSub($this->activityIn($range), 'act', 'act.user_id', '=', 'agents.user_id');
    }

    /** Headcount agents with NO recorded action in the range — the active ones' complement. */
    public function idleAgents(ReportRange $range): Builder
    {
        return $this->headcount()
            ->leftJoinSub($this->activityIn($range), 'act', 'act.user_id', '=', 'agents.user_id')
            ->whereNull('act.user_id');
    }

    /**
     * Headcount agents not seen for 30 days, measured NOW — the rule of
     * User::scopeDormantSince(): last heartbeat before the cut-off, or never
     * online and signed up before it.
     */
    public function dormantAgents(Carbon $now): Builder
    {
        $cutoff = $now->copy()->subDays(30)->utc()->format('Y-m-d H:i:s');

        return $this->headcount()->where(function ($q) use ($cutoff) {
            $q->where('u.last_online_at', '<', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('u.last_online_at')
                    ->where(fn ($q) => $q->whereNull('u.created_at')->orWhere('u.created_at', '<', $cutoff)));
        });
    }

    /** Dormant agents with no trace of ever using FH — to onboard, not to remind (one all-time scan). */
    public function neverUsedAgents(Carbon $now): Builder
    {
        return $this->dormantAgents($now)
            ->leftJoinSub($this->realIds(), 'real', 'real.user_id', '=', 'agents.user_id')
            ->whereNull('real.user_id');
    }

    /** Dormant agents who did use FH before — the ones to win back (never-used's complement). */
    public function dormantUsedAgents(Carbon $now): Builder
    {
        return $this->dormantAgents($now)->joinSub($this->realIds(), 'real', 'real.user_id', '=', 'agents.user_id');
    }

    /** Headcount agents active in the period before the range and not in the range — they stopped. */
    public function goneQuietAgents(ReportRange $range): Builder
    {
        return $this->headcount()
            ->joinSub($this->activityIn($range->previous()), 'was', 'was.user_id', '=', 'agents.user_id')
            ->leftJoinSub($this->activityIn($range), 'act', 'act.user_id', '=', 'agents.user_id')
            ->whereNull('act.user_id');
    }

    /**
     * Agents with leads in the range — $perAgent is LeadQueries::perAgent()
     * (agent_id, n); $bucket narrows to a lead-count bucket (`1`, `2-3`, …).
     */
    public function agentsWithLeads(Builder $perAgent, ?string $bucket = null): Builder
    {
        $query = $this->agents()->joinSub($perAgent, 'wl', 'wl.agent_id', '=', 'agents.id');
        if ($bucket !== null && isset(LeadQueries::BUCKETS[$bucket])) {
            [$min, $max] = LeadQueries::BUCKETS[$bucket];
            $query->where('wl.n', '>=', $min);
            if ($max !== null) {
                $query->where('wl.n', '<=', $max);
            }
        }

        return $query;
    }

    /** Agents holding a lead nobody answered — $agentIds is LeadQueries::waitingAgents(). */
    public function agentsWithWaitingLeads(Builder $agentIds): Builder
    {
        return $this->agents()->joinSub($agentIds, 'wt', 'wt.agent_id', '=', 'agents.id');
    }

    /** Agent filters: team (credited team; 0 = no team), office region, one agent. */
    public function applyAgentFilters(Builder $query, array $filters): Builder
    {
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            switch ($key) {
                case 'team_id':
                    (int) $value === 0
                        ? $query->whereNotIn('agents.id', $this->teams->allMemberIds() ?: [0])
                        : $query->whereIn('agents.id', $this->teams->members((int) $value) ?: [0]);
                    break;
                case 'office_region':
                    if ($value === 'unassigned') {
                        $query->where(function ($q) {
                            $q->whereNull('agents.region')
                                ->orWhere('agents.region', '')
                                ->orWhereNotIn('agents.region', OfficeRegionMap::REGIONS);
                        });
                    } else {
                        $query->where('agents.region', (string) $value);
                    }
                    break;
                case 'agent_id':
                    $query->where('agents.id', (int) $value);
                    break;
            }
        }

        return $query;
    }

    /** User filters: a role. */
    public function applyUserFilters(Builder $query, array $filters): Builder
    {
        if (($filters['role'] ?? '') !== '') {
            $query->where('r.name', (string) $filters['role']);
        }

        return $query;
    }

    // ── Building blocks — each one derived table of distinct user ids ──────

    private function clientsCreated(ReportRange $range): Builder
    {
        return $this->users()
            ->where('r.name', 'client')
            ->where('u.created_at', '>=', $range->fromUtc())
            ->where('u.created_at', '<', $range->toUtc());
    }

    /** Every user with a trace of using FH (see the class note); UNION = deduplicated once. */
    private function realIds(): Builder
    {
        return DB::table('users')->whereNotNull('last_online_at')->select('id as user_id')
            ->union(DB::table('login_logs')->whereNotNull('user_id')->select('user_id'))
            ->union(DB::table('personal_access_tokens')->where('tokenable_type', User::class)->select('tokenable_id'))
            ->union(DB::table('messages')->whereNotNull('user_id')->select('user_id'))
            ->union(DB::table('chats')->whereNotNull('user_id')->select('user_id'))
            ->union(DB::table('favorites')->whereNotNull('user_id')->select('user_id'))
            ->union(DB::table('listings')->join('agents as ta', 'ta.id', '=', 'listings.agent_id')->whereNotNull('ta.user_id')->select('ta.user_id'));
    }

    /** User ids with a login in the range (uses the (logged_in_at, user_id) index). */
    private function loginsIn(ReportRange $range): Builder
    {
        return DB::table('login_logs')
            ->where('logged_in_at', '>=', $range->fromUtc())
            ->where('logged_in_at', '<', $range->toUtc())
            ->select('user_id')
            ->distinct();
    }

    /**
     * User ids with any recorded action in the range: a sign-in, a message, an
     * inquiry opened, a favourite, a listing posted (credited to the owner's
     * user), or an audited edit they made. One UNION, joined as a derived table.
     */
    public function activityIn(ReportRange $range): Builder
    {
        $from = $range->fromUtc();
        $to = $range->toUtc();

        return DB::table('login_logs')->where('logged_in_at', '>=', $from)->where('logged_in_at', '<', $to)->select('user_id')
            ->union(DB::table('messages')->whereNotNull('user_id')->where('created_at', '>=', $from)->where('created_at', '<', $to)->select('user_id'))
            ->union(DB::table('chats')->whereNotNull('user_id')->where('created_at', '>=', $from)->where('created_at', '<', $to)->select('user_id'))
            ->union(DB::table('favorites')->whereNotNull('user_id')->where('created_at', '>=', $from)->where('created_at', '<', $to)->select('user_id'))
            ->union(DB::table('listings')
                ->join('agents as la', 'la.id', '=', 'listings.agent_id')
                ->whereNotNull('la.user_id')
                ->where('listings.created_at', '>=', $from)->where('listings.created_at', '<', $to)
                ->select('la.user_id'))
            ->union(DB::table('audits')
                ->whereNotNull('user_id')
                ->whereIn('event', self::EDIT_EVENTS)
                ->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->select('user_id'));
    }
}
