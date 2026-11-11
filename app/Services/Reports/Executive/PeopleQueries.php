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
 * (`login_logs`, kept since April), held an API token (deleted on logout), or
 * ever done anything "active" counts — a message, an inquiry, a favourite, a
 * listing, an audited edit. That last part keeps Total ≥ Active by
 * construction. Uncorrelated IN subqueries — each an index semi-join, the
 * guard style of Listing.php — never a per-row subquery; every subquery drops
 * NULLs, because a NULL inside NOT IN quietly matches nothing.
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

    /** Users who have really used FH — signed in, been online, or held a token. */
    public function realUsers(): Builder
    {
        return $this->whereReal($this->users());
    }

    /** Clients who signed up in the range and became real users. */
    public function newClients(ReportRange $range): Builder
    {
        return $this->whereReal($this->clientsCreated($range));
    }

    /** Clients whose account was created in the range but never used — abandoned sign-ups (new clients' complement). */
    public function unfinishedSignups(ReportRange $range): Builder
    {
        $query = $this->clientsCreated($range)->whereNull('u.last_online_at');
        foreach ($this->traces() as $trace) {
            $query->whereNotIn('u.id', $trace);
        }

        return $query;
    }

    /** Clients who signed in during the range and joined before it. */
    public function returningClients(ReportRange $range): Builder
    {
        return $this->users()
            ->where('r.name', 'client')
            ->where('u.created_at', '<', $range->fromUtc())
            ->whereIn('u.id', $this->loginsIn($range));
    }

    /** Users of any role (or one role) with a recorded action in the range. */
    public function activeUsers(ReportRange $range, ?string $role = null): Builder
    {
        return $this->users()
            ->when($role !== null, fn ($q) => $q->where('r.name', $role))
            ->whereIn('u.id', $this->activityIn($range));
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
            ->whereIn('agents.id', (clone $listingsAdded)->select('listings.agent_id'));
    }

    /** Agents who signed in during the range (any agent status). */
    public function loggedInAgents(ReportRange $range): Builder
    {
        return $this->agents()->whereIn('agents.user_id', $this->loginsIn($range));
    }

    /** Headcount agents with a recorded action in the range. */
    public function activeAgents(ReportRange $range): Builder
    {
        return $this->headcount()->whereIn('agents.user_id', $this->activityIn($range));
    }

    /** Headcount agents with NO recorded action in the range. */
    public function idleAgents(ReportRange $range): Builder
    {
        return $this->headcount()->whereNotIn('agents.user_id', $this->activityIn($range));
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

    // ── Building blocks ─────────────────────────────────────────────────────

    private function clientsCreated(ReportRange $range): Builder
    {
        return $this->users()
            ->where('r.name', 'client')
            ->where('u.created_at', '>=', $range->fromUtc())
            ->where('u.created_at', '<', $range->toUtc());
    }

    private function whereReal(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNotNull('u.last_online_at');
            foreach ($this->traces() as $trace) {
                $q->orWhereIn('u.id', $trace);
            }
        });
    }

    /**
     * Every source of "this user has used FH" besides presence: sign-ins,
     * tokens, and all-time versions of the activity sources (activityIn()).
     *
     * @return Builder[] each selecting non-null user ids
     */
    private function traces(): array
    {
        return [
            DB::table('login_logs')->whereNotNull('user_id')->select('user_id'),
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->select('tokenable_id'),
            DB::table('messages')->whereNotNull('user_id')->select('user_id'),
            DB::table('chats')->whereNotNull('user_id')->select('user_id'),
            DB::table('favorites')->whereNotNull('user_id')->select('user_id'),
            DB::table('listings')->join('agents as ta', 'ta.id', '=', 'listings.agent_id')->whereNotNull('ta.user_id')->select('ta.user_id'),
            DB::table('audits')->whereNotNull('user_id')->whereIn('event', self::EDIT_EVENTS)->select('user_id'),
        ];
    }

    /** User ids with a login in the range (uses the (logged_in_at, user_id) index). */
    private function loginsIn(ReportRange $range): Builder
    {
        return DB::table('login_logs')
            ->where('logged_in_at', '>=', $range->fromUtc())
            ->where('logged_in_at', '<', $range->toUtc())
            ->select('user_id');
    }

    /**
     * User ids with any recorded action in the range: a sign-in, a message, an
     * inquiry opened, a favourite, a listing posted (credited to the owner's
     * user), or an audited edit they made. One UNION, used as an IN subquery.
     */
    private function activityIn(ReportRange $range): Builder
    {
        $from = $range->fromUtc();
        $to = $range->toUtc();

        // NOT NULL throughout: idleAgents() takes this as NOT IN, where one NULL would empty it.
        return $this->loginsIn($range)
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
