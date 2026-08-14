<?php

namespace App\Http\Controllers;

use App\Http\Resources\AgentResource;
use App\Http\Resources\AgentResourceCollection;
use App\Http\Resources\ExternalAgentResource;
use App\Http\Resources\UserResource;
use App\Jobs\PingIndexNow;
use App\Mail\AgentCertificateMailer;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\LoginLog;
use App\Models\TeamAgent;
use App\Models\User;
use App\Services\AuditMailService;
use App\Services\IndexNowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class AgentController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->query('per_page', 12), 50));

        // Optional date-range filter for the "listings created in range" subquery.
        // Used by the admin Top Agents table. When absent, listings_in_range_count
        // falls back to the all-time count so existing callers keep working.
        $dateFrom = $request->query('date_from'); // 'YYYY-MM-DD' or null
        $dateTo = $request->query('date_to');
        $teamId = $request->query('team_id');

        // Mirror Laravel's SoftDeletes scope so this matches the other
        // listings counts and /all-listings.
        $rangeSql = '(SELECT COUNT(*) FROM listings WHERE listings.agent_id = agents.id AND listings.deleted_at IS NULL';
        $rangeBindings = [];
        if ($dateFrom) {
            $rangeSql .= ' AND listings.created_at >= ?';
            $rangeBindings[] = $dateFrom.' 00:00:00';
        }
        if ($dateTo) {
            $rangeSql .= ' AND listings.created_at <= ?';
            $rangeBindings[] = $dateTo.' 23:59:59';
        }
        $rangeSql .= ') as listings_in_range_count';

        $query = Agent::query()
            ->select([
                'agents.*',
                // flat COUNT subqueries — no nested EXISTS, JOIN for property status.
                // Every subquery must filter listings.deleted_at IS NULL to match
                // Laravel's SoftDeletes scope, otherwise these counts include
                // trashed rows and diverge from /all-listings (the View Listings
                // grid). Same applies for the property-joined counts below.
                DB::raw('(SELECT COUNT(*) FROM listings WHERE listings.agent_id = agents.id AND listings.deleted_at IS NULL) as listings_count'),
                DB::raw("(SELECT COUNT(*) FROM listings WHERE listings.agent_id = agents.id AND listings.deleted_at IS NULL AND listings.visibility = 'public') as public_listings_count"),
                DB::raw("(SELECT COUNT(*) FROM listings WHERE listings.agent_id = agents.id AND listings.deleted_at IS NULL AND listings.visibility = 'private') as private_listings_count"),
                DB::raw("(SELECT COUNT(*) FROM listings INNER JOIN properties ON properties.id = listings.property_id WHERE listings.agent_id = agents.id AND listings.deleted_at IS NULL AND properties.status = 'sold') as sold_count"),
                DB::raw("(SELECT COUNT(*) FROM listings INNER JOIN properties ON properties.id = listings.property_id WHERE listings.agent_id = agents.id AND listings.deleted_at IS NULL AND properties.status = 'rented') as rented_count"),
                DB::raw("(SELECT COUNT(*) FROM listings INNER JOIN properties ON properties.id = listings.property_id WHERE listings.agent_id = agents.id AND listings.deleted_at IS NULL AND properties.status = 'leased') as leased_count"),
                DB::raw("(SELECT COUNT(*) FROM conversations WHERE conversations.agent_user_id = agents.user_id AND conversations.status = 'accepted') as ongoing_inquiries_count"),
                DB::raw("(SELECT COUNT(*) FROM conversations WHERE conversations.agent_user_id = agents.user_id AND conversations.status = 'closed') as closed_inquiries_count"),
                DB::raw($rangeSql),
                // response_* columns are already included by `agents.*` above;
                // listing them explicitly here duplicates them, which surfaces
                // as a "Duplicate column" SQL error when paginate() wraps the
                // query for its COUNT subquery (the wrap happens whenever a
                // HAVING clause is added — e.g. ?min_listings=1).
            ])
            ->addBinding($rangeBindings, 'select')
            ->with([
                // withCount/withMax feed AgentResource's last_login_at + login_count
                // without an N+1 query per agent.
                'user' => fn ($q) => $q->withCount('loginLogs')->withMax('loginLogs', 'logged_in_at'),
                'pageBuilder:id,agent_id,slug',
                'teamMembers.team',
            ])
            ->whereHas('user.role', function ($q) use ($request) {
                // Opt-in: show ONLY admins that have an agent profile.
                $q->where('name', $request->boolean('include_admins') ? 'admin' : 'agent');
            });

        // Public visibility gate: the public agents directory opts in with
        // `public=1` (see lib/agents.ts + hooks/useAgents.ts) and must surface
        // ONLY active agents — for search-engine crawlers AND for any signed-in
        // viewer (an admin browsing the public page still gets the public view).
        // Admin/analytics callers (Agents Management, Top Agents, Leaderboard,
        // chat stats) omit the flag and keep every status. Mirrors the
        // site-wide agent-status gate.
        if ($request->boolean('public')) {
            $query->where('agents.status', 'active');
        }

        // Secretary (FH role 5): in the management DASHBOARD the agents list is
        // region-scoped to their office region. This same endpoint also powers
        // the PUBLIC agents directory, which must show everyone — even when a
        // secretary is logged in (their Bearer token rides every request). So
        // the dashboard opts in with `managed=1`; without it we never scope.
        //
        // The route is public (verify.guest.token, no auth:sanctum), so
        // $request->user() uses the session (web) guard and is null for a
        // token-authenticated secretary — resolve the Bearer user via the
        // sanctum guard. Guests stay null, and admins are never scoped.
        if ($request->boolean('managed')) {
            $requester = $request->user() ?? Auth::guard('sanctum')->user();
            if ($requester && $requester->isSecretary()) {
                $region = $requester->secretaryRegion();
                if ($region === null) {
                    abort(403);
                }
                $query->where('agents.region', $region);
            }
        }

        if ($teamId) {
            $query->whereHas('teamMembers', function ($q) use ($teamId) {
                $q->where('team_id', $teamId);
            });
        }

        // Public directory hides agents with no listings — opt-in so admin
        // tables (Top Agents, Agents Management) still see everyone.
        if (($min = (int) $request->query('min_listings', 0)) > 0) {
            $query->having('listings_count', '>=', $min);
        }

        // Online-only filter — driven by the public /agents directory's
        // "● N agents online now" pill becoming a toggleable chip.
        // 5-minute freshness window mirrors the threshold used across
        // the frontend (utils/presence.ts) and the rest of this
        // controller. Accepts "1", "true", and "yes" so the param is
        // friendly to query strings hand-typed into a URL.
        if (in_array(strtolower((string) $request->query('online', '')), ['1', 'true', 'yes'], true)) {
            $threshold = now()->subMinutes(5);
            $query->whereHas('user', function ($uq) use ($threshold) {
                $uq->where('last_online_at', '>=', $threshold);
            });
        }

        // Admin activity filters (Agents Management "Last online" dropdown):
        // active_within=N keeps agents seen in the last N days;
        // inactive_over=N keeps agents NOT seen for N+ days (or never online).
        if (($within = (int) $request->query('active_within', 0)) > 0) {
            $threshold = now()->subDays($within);
            $query->whereHas('user', function ($uq) use ($threshold) {
                $uq->where('last_online_at', '>=', $threshold);
            });
        }
        if (($over = (int) $request->query('inactive_over', 0)) > 0) {
            $threshold = now()->subDays($over);
            $query->where(function ($q) use ($threshold) {
                $q->whereDoesntHave('user')
                    ->orWhereHas('user', function ($uq) use ($threshold) {
                        $uq->where(function ($w) use ($threshold) {
                            $w->whereNull('last_online_at')
                                ->orWhere('last_online_at', '<', $threshold);
                        });
                    });
            });
        }

        // Listings ceiling — max_listings=0 gives the "no listings yet" view.
        if ($request->filled('max_listings')) {
            $query->having('listings_count', '<=', (int) $request->query('max_listings'));
        }

        // Posted-in-period filter — pairs with date_from/date_to (which drive
        // listings_in_range_count): min_in_range=1 keeps agents who POSTED in
        // the window; max_in_range=0 keeps those who did NOT (the boss's
        // "who isn't inputting listings" view).
        if (($minRange = (int) $request->query('min_in_range', 0)) > 0) {
            $query->having('listings_in_range_count', '>=', $minRange);
        }
        if ($request->filled('max_in_range')) {
            $query->having('listings_in_range_count', '<=', (int) $request->query('max_in_range'));
        }

        // Agents with no team membership at all — the "who has no team" view.
        if ($request->boolean('no_team')) {
            $query->whereDoesntHave('teamMembers');
        }

        if ($search = $request->query('search')) {
            $term = '%'.$search.'%';

            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'LIKE', $term)
                    ->orWhere('last_name', 'LIKE', $term)
                    ->orWhere('address', 'LIKE', $term)
                    ->orWhere('mobile_no', 'LIKE', $term)
                    ->orWhereHas('user', function ($uq) use ($term) {
                        $uq->where('email', 'LIKE', $term)
                            ->orWhere('name', 'LIKE', $term);
                    });
            });
        }

        // Per-team breakdown of the CURRENT filtered set (Agents Management
        // shows it while the no-listings / no-posts filters are active):
        // which teams have the most matching agents, plus how many matching
        // agents belong to no team at all. fromSub keeps the HAVING-based
        // filters intact (they reference select aliases).
        $teamBreakdown = null;
        if ($request->boolean('with_team_breakdown') && $request->query('export') !== 'csv') {
            $ids = DB::query()
                ->fromSub((clone $query)->reorder(), 'filtered')
                ->pluck('id');
            $memberships = TeamAgent::query()
                ->whereIn('agent_id', $ids)
                ->with('team:id,name')
                ->get();
            $teams = $memberships
                ->filter(fn ($m) => $m->team)
                ->groupBy('team_id')
                ->map(fn ($g) => [
                    'id' => $g->first()->team->id,
                    'name' => $g->first()->team->name,
                    'count' => $g->pluck('agent_id')->unique()->count(),
                ])
                ->sortByDesc('count')
                ->values();
            $teamBreakdown = [
                'teams' => $teams,
                'no_team' => $ids->count() - $memberships->pluck('agent_id')->unique()->count(),
                'total' => $ids->count(),
            ];
        }

        $sortBy = $request->query('sort_by');
        $sortDir = strtolower($request->query('sort_dir', 'desc'));
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        $allowed = ['full_name', 'email', 'member_since', 'listings_count', 'listings_in_range', 'transactions_count', 'inquiries_count', 'response_speed', 'highest_rated', 'last_online', 'status', 'login_count'];

        if (in_array($sortBy, $allowed)) {
            match ($sortBy) {
                'full_name' => $query->orderByRaw("CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) $sortDir"),
                'email' => $query->orderByRaw("(SELECT email FROM users WHERE users.id = agents.user_id) $sortDir"),
                'member_since' => $query->orderBy('member_since', $sortDir),
                'listings_count' => $query->orderBy('listings_count', $sortDir),
                'listings_in_range' => $query->orderBy('listings_in_range_count', $sortDir),
                'transactions_count' => $query->orderByRaw("(sold_count + rented_count + leased_count) $sortDir"),
                'inquiries_count' => $query->orderByRaw("(ongoing_inquiries_count + closed_inquiries_count) $sortDir"),
                'last_online' => $query->orderByRaw("(SELECT last_online_at FROM users WHERE users.id = agents.user_id) $sortDir"),
                'status' => $query->orderBy('agents.status', $sortDir),
                'login_count' => $query->orderByRaw("(SELECT COUNT(*) FROM login_logs WHERE login_logs.user_id = agents.user_id) $sortDir"),
                'response_speed' => $query
                    ->orderByRaw('CASE WHEN response_sample_size >= 3 AND median_first_response_seconds IS NOT NULL AND (unanswered_response_pct IS NULL OR unanswered_response_pct < 50) THEN 0 ELSE 1 END ASC')
                    ->orderByRaw('CASE WHEN response_sample_size >= 3 AND median_first_response_seconds IS NOT NULL AND (unanswered_response_pct IS NULL OR unanswered_response_pct < 50) THEN median_first_response_seconds ELSE NULL END ASC')
                    ->orderByDesc('listings_count'),
                // Highest rated: agents with <3 reviews or no rating sink to
                // the bottom so a freshly-rated 5.0 doesn't outrank a
                // battle-tested 4.6. Within the rated tier, sort by
                // avg_rating desc then total_reviews desc as tiebreaker.
                'highest_rated' => $query
                    ->orderByRaw('CASE WHEN total_reviews >= 3 AND avg_rating IS NOT NULL THEN 0 ELSE 1 END ASC')
                    ->orderByDesc('avg_rating')
                    ->orderByDesc('total_reviews')
                    ->orderByDesc('listings_count'),
            };
        } else {
            // Default browse: online agents bubble to the top so
            // visitors can preferentially reach someone responsive.
            // Bucket 0 = currently online (last_online_at within 5
            // min), bucket 1 = everyone else. Within each bucket we
            // keep the existing listings_count desc tiebreaker so
            // top producers stay surfaced first.
            $query
                ->orderByRaw(
                    '(CASE WHEN (SELECT last_online_at FROM users WHERE users.id = agents.user_id) >= ? THEN 0 ELSE 1 END) ASC',
                    [now()->subMinutes(5)]
                )
                ->orderByDesc('listings_count');
        }

        // CSV export (Agents Management "Export Excel") — the SAME query and
        // filters as the table, but the full result set streamed as UTF-8 CSV
        // (BOM included so Excel renders names correctly). Auth-gated: the
        // route itself is public for the directory, so exporting contact data
        // requires a logged-in non-client user.
        if ($request->query('export') === 'csv') {
            $exporter = $request->user() ?? Auth::guard('sanctum')->user();
            abort_unless($exporter && $exporter->role?->name !== 'client', 403);

            $rows = $query
                ->addSelect(DB::raw('(SELECT COUNT(*) FROM login_logs WHERE login_logs.user_id = agents.user_id) as export_login_count'))
                ->with('user')
                ->get();
            $filename = 'agents-report-'.now()->format('Y-m-d').'.csv';

            return response()->streamDownload(function () use ($rows, $dateFrom, $dateTo) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                $rangeLabel = $dateFrom
                    ? 'Posted '.$dateFrom.' to '.($dateTo ?: now()->toDateString())
                    : 'Posted (all time)';
                fputcsv($out, [
                    'Agent', 'Email', 'Mobile', 'Status', 'Team', 'Team Role', 'Last Online', 'Total Logins',
                    'Total Listings', 'Public', 'Private', $rangeLabel,
                    'Sold', 'Rented', 'Leased', 'Ongoing Inquiries', 'Closed Inquiries', 'Member Since',
                ]);
                foreach ($rows as $a) {
                    $tm = $a->teamMembers->first();
                    fputcsv($out, [
                        trim(($a->first_name ?? '').' '.($a->last_name ?? '')),
                        $a->user?->email,
                        $a->mobile_no,
                        $a->status,
                        $tm?->team?->name ?? '',
                        $tm ? ($tm->is_leader ? 'Team Leader' : 'Member') : '',
                        optional($a->user?->last_online_at)->toDateTimeString(),
                        (int) ($a->export_login_count ?? 0),
                        (int) ($a->listings_count ?? 0),
                        (int) ($a->public_listings_count ?? 0),
                        (int) ($a->private_listings_count ?? 0),
                        (int) ($a->listings_in_range_count ?? 0),
                        (int) ($a->sold_count ?? 0),
                        (int) ($a->rented_count ?? 0),
                        (int) ($a->leased_count ?? 0),
                        (int) ($a->ongoing_inquiries_count ?? 0),
                        (int) ($a->closed_inquiries_count ?? 0),
                        $a->member_since,
                    ]);
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $collection = new AgentResourceCollection(
            $query->paginate($perPage)
        );
        if ($teamBreakdown !== null) {
            $collection->additional(['team_breakdown' => $teamBreakdown]);
        }

        return $collection;
    }

    public function admins()
    {
        $adminIds = User::query()->admin()->pluck('id');

        return response()->json($adminIds);
    }

    // Lightweight presence endpoint used by the public /agents
    // directory header ("● N agents online now") and any surface
    // that wants to hydrate online state for many agents in a
    // single call instead of reading per-row last_online_at.
    //
    // Returns the user_ids (not agent ids) of agents whose
    // last_online_at is within the 5-minute online threshold AND
    // who would actually appear in the public /agents directory
    // (i.e. have at least one non-deleted listing). Without the
    // listings filter the pill could advertise more agents than
    // `?online=1` can surface, leaving visitors staring at an
    // empty grid when they click the pill to filter.
    public function onlineAgentIds()
    {
        $threshold = now()->subMinutes(5);

        $ids = User::query()
            ->whereHas('role', fn ($q) => $q->where('name', 'agent'))
            // Public "N agents online" pill — only count active agents, matching
            // the public agents-directory gate (excludes inactive/resigned/
            // deactivated and soft-deleted agents).
            ->whereHas('agent', fn ($q) => $q->where('status', 'active'))
            ->where('last_online_at', '>=', $threshold)
            ->whereHas('agent.listings', function ($lq) {
                $lq->whereNull('listings.deleted_at');
            })
            ->pluck('id');

        return response()->json(['ids' => $ids]);
    }

    /**
     * For an authenticated secretary, block access to an agent outside their
     * office region (403). No-op for guests / clients / agents / admin so the
     * public directory and admin behavior are unchanged.
     */
    private function guardSecretaryRegion(Request $request, Agent $agent): void
    {
        $requester = $request->user();
        if ($requester && $requester->isSecretary()) {
            $region = $requester->secretaryRegion();
            if ($region === null || $agent->region !== $region) {
                abort(403);
            }
        }
    }

    public function statistics(Request $request, $id)
    {
        $agent = Agent::with('user')->withCount('listings')->findOrFail($id);
        $this->guardSecretaryRegion($request, $agent);
        $user = $agent->user;

        $activeListings = $agent->listings()
            ->whereHas('property', fn ($q) => $q->where('status', 'active'))
            ->count();

        $soldCount = $agent->listings()
            ->whereHas('property', fn ($q) => $q->where('status', 'sold'))
            ->count();

        $rentedCount = $agent->listings()
            ->whereHas('property', fn ($q) => $q->where('status', 'rented'))
            ->count();

        $leasedCount = $agent->listings()
            ->whereHas('property', fn ($q) => $q->where('status', 'leased'))
            ->count();

        $agentUserId = $agent->user_id;

        // Inquiries are tracked in conversations.agent_user_id (a users.id),
        // not the legacy listing_inquiries table — match what the agent
        // sees on /agent/listing-inquiries.
        $totalInquiries = $agentUserId
            ? Conversation::where('agent_user_id', $agentUserId)->count()
            : 0;
        $pendingInquiries = $agentUserId
            ? Conversation::where('agent_user_id', $agentUserId)
                ->where('status', 'pending')
                ->count()
            : 0;

        // Sold chart — last 12 months grouped by month
        $twelveMonthsAgo = Carbon::now()->subMonths(11)->startOfMonth();

        $buildMonthlyChart = function (string $status) use ($agent, $twelveMonthsAgo): array {
            $byMonth = DB::table('listings')
                ->join('properties', 'listings.property_id', '=', 'properties.id')
                ->where('listings.agent_id', $agent->id)
                ->where('properties.status', $status)
                ->whereBetween('properties.status_change_date', [
                    $twelveMonthsAgo,
                    Carbon::now()->endOfMonth(),
                ])
                ->select(
                    DB::raw("DATE_FORMAT(properties.status_change_date, '%Y-%m') as month"),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('month')
                ->orderBy('month')
                ->pluck('count', 'month');

            $chart = [];
            for ($i = 0; $i < 12; $i++) {
                $key = Carbon::now()->subMonths(11 - $i)->format('Y-m');
                $chart[] = ['month' => $key, 'count' => (int) ($byMonth[$key] ?? 0)];
            }

            return $chart;
        };

        $soldChart = $buildMonthlyChart('sold');
        $rentedChart = $buildMonthlyChart('rented');
        $leasedChart = $buildMonthlyChart('leased');

        // Recent inquiries — pulled from conversations.agent_user_id, joining
        // chat → listing (the inquired-on listing) and chat → user (the client).
        $recentInquiries = $agentUserId
            ? Conversation::query()
                ->where('agent_user_id', $agentUserId)
                ->with([
                    'chat:id,user_id,type,type_id',
                    'chat.user:id,name,email',
                    'chat.listing:id,name,slug',
                ])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn ($conv) => [
                    'id' => $conv->id,
                    'listing_name' => $conv->chat?->listing?->name ?? 'N/A',
                    'listing_slug' => $conv->chat?->listing?->slug ?? '',
                    'client_name' => $conv->chat?->user?->name ?? 'N/A',
                    'client_email' => $conv->chat?->user?->email ?? '',
                    'status' => $conv->status,
                    'created_at' => $conv->created_at?->toDateTimeString(),
                ])
            : collect();

        // Login history + stats
        $loginHistory = $user
            ? LoginLog::where('user_id', $user->id)
                ->orderByDesc('logged_in_at')
                ->limit(20)
                ->get()
                ->map(fn ($log) => [
                    'id' => $log->id,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'logged_in_at' => $log->logged_in_at?->toDateTimeString(),
                ])
            : [];

        $loginCount = $user ? LoginLog::where('user_id', $user->id)->count() : 0;

        $loginByMonth = $user
            ? LoginLog::where('user_id', $user->id)
                ->where('logged_in_at', '>=', $twelveMonthsAgo)
                ->select(
                    DB::raw("DATE_FORMAT(logged_in_at, '%Y-%m') as month"),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('month')
                ->pluck('count', 'month')
            : collect();

        $loginChart = [];
        for ($i = 0; $i < 12; $i++) {
            $key = Carbon::now()->subMonths(11 - $i)->format('Y-m');
            $loginChart[] = ['month' => $key, 'count' => (int) ($loginByMonth[$key] ?? 0)];
        }

        $fullName = collect([$agent->first_name, $agent->middle_name, $agent->last_name])
            ->filter()->join(' ') ?: $user?->name ?: 'Guest User';

        return response()->json([
            'agent' => [
                'id' => $agent->id,
                'full_name' => $fullName,
                'avatar' => $agent->avatar ?? $user?->avatar,
                'address' => $agent->address,
                'member_since' => $agent->member_since,
                'email' => $user?->email,
                'mobile_no' => $agent->mobile_no ?? $user?->mobile_no,
            ],
            'kpi' => [
                'total_listings' => $agent->listings_count,
                'active_listings' => $activeListings,
                'sold_count' => $soldCount,
                'rented_count' => $rentedCount,
                'leased_count' => $leasedCount,
                'total_inquiries' => $totalInquiries,
                'pending_inquiries' => $pendingInquiries,
            ],
            'sold_chart' => $soldChart,
            'rented_chart' => $rentedChart,
            'leased_chart' => $leasedChart,
            'recent_inquiries' => $recentInquiries,
            'login_history' => $loginHistory,
            'login_count' => $loginCount,
            'login_chart' => $loginChart,
            'response_metrics' => [
                'median_first_response_seconds' => $agent->median_first_response_seconds,
                'within_1h_response_pct' => $agent->within_1h_response_pct !== null
                    ? (float) $agent->within_1h_response_pct
                    : null,
                'unanswered_response_pct' => $agent->unanswered_response_pct !== null
                    ? (float) $agent->unanswered_response_pct
                    : null,
                'sample_size' => $agent->response_sample_size,
                'window_days' => $agent->response_metrics_window_days,
                'updated_at' => $agent->response_metrics_updated_at?->toDateTimeString(),
            ],
        ]);
    }

    /**
     * Paginated detail rows for the admin Teams "view details" drill-down.
     *
     * Query params:
     *   type      = logins | listings | transactions | inquiries (required)
     *   date_from = YYYY-MM-DD (optional, inclusive)
     *   date_to   = YYYY-MM-DD (optional, inclusive)
     *   page      = 1-based page index (default 1)
     *   per_page  = clamped to [1, 50] (default 10)
     */
    public function activity(Request $request, $id)
    {
        $agent = Agent::with('user')->findOrFail($id);
        $this->guardSecretaryRegion($request, $agent);
        $userId = $agent->user_id;

        $type = $request->query('type');
        if (! in_array($type, ['logins', 'listings', 'transactions', 'inquiries'], true)) {
            return response()->json(['message' => 'Invalid type.'], 422);
        }

        $perPage = max(1, min((int) $request->query('per_page', 10), 50));
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $from = $dateFrom ? Carbon::parse($dateFrom)->startOfDay() : null;
        $to = $dateTo ? Carbon::parse($dateTo)->endOfDay() : null;

        if ($type === 'logins') {
            if (! $userId) {
                return response()->json(['data' => [], 'meta' => ['total' => 0]]);
            }
            $q = LoginLog::where('user_id', $userId)->orderByDesc('logged_in_at');
            if ($from) {
                $q->where('logged_in_at', '>=', $from);
            }
            if ($to) {
                $q->where('logged_in_at', '<=', $to);
            }
            $page = $q->paginate($perPage);

            return response()->json([
                'data' => $page->getCollection()->map(fn ($l) => [
                    'id' => $l->id,
                    'logged_in_at' => $l->logged_in_at?->toDateTimeString(),
                    'ip_address' => $l->ip_address,
                    'user_agent' => $l->user_agent,
                ]),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                ],
            ]);
        }

        if ($type === 'listings') {
            $q = $agent->listings()
                ->with(['property:id,address,status,status_change_date', 'category:id,name'])
                ->orderByDesc('created_at');
            if ($from) {
                $q->where('listings.created_at', '>=', $from);
            }
            if ($to) {
                $q->where('listings.created_at', '<=', $to);
            }
            $page = $q->paginate($perPage);

            return response()->json([
                'data' => $page->getCollection()->map(fn ($l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'code' => $l->code,
                    'slug' => $l->slug,
                    'visibility' => $l->visibility,
                    'created_at' => $l->created_at?->toDateTimeString(),
                    'category' => $l->category?->name,
                    'address' => $l->property?->address,
                    'status' => $l->property?->status,
                ]),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                ],
            ]);
        }

        if ($type === 'transactions') {
            $q = $agent->listings()
                ->whereHas('property', function ($pq) use ($from, $to) {
                    $pq->whereIn('status', ['sold', 'rented', 'leased']);
                    if ($from) {
                        $pq->where('status_change_date', '>=', $from);
                    }
                    if ($to) {
                        $pq->where('status_change_date', '<=', $to);
                    }
                })
                ->with(['property:id,address,status,status_change_date', 'category:id,name']);
            $page = $q->paginate($perPage);

            return response()->json([
                'data' => $page->getCollection()->map(fn ($l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'code' => $l->code,
                    'slug' => $l->slug,
                    'category' => $l->category?->name,
                    'address' => $l->property?->address,
                    'status' => $l->property?->status,
                    'status_change_date' => $l->property?->status_change_date?->toDateTimeString(),
                    'created_at' => $l->created_at?->toDateTimeString(),
                ]),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                ],
            ]);
        }

        // inquiries
        if (! $userId) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }
        $q = Conversation::query()
            ->where('agent_user_id', $userId)
            ->with([
                'chat:id,user_id,type,type_id',
                'chat.user:id,name,email,avatar',
                'chat.listing:id,name,slug',
            ])
            ->orderByDesc('created_at');
        if ($from) {
            $q->where('conversations.created_at', '>=', $from);
        }
        if ($to) {
            $q->where('conversations.created_at', '<=', $to);
        }
        $page = $q->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($conv) => [
                'id' => $conv->id,
                'chat_id' => $conv->chat?->id,
                'status' => $conv->status,
                'created_at' => $conv->created_at?->toDateTimeString(),
                'client_name' => $conv->chat?->user?->name,
                'client_email' => $conv->chat?->user?->email,
                'client_avatar' => $conv->chat?->user?->avatar,
                'listing_name' => $conv->chat?->listing?->name,
                'listing_slug' => $conv->chat?->listing?->slug,
                'chat_type' => $conv->chat?->type,
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $agent = Agent::with('user')
            // select() REPLACES the column list, so it must come BEFORE
            // withCount() — the other way round wipes the listings_count
            // subselect and the payload reports 0 active listings.
            ->select('agents.*')
            ->withCount(['listings' => fn ($q) => $q->where('visibility', 'public')])
            // Same flat COUNT subqueries as index() — without them the single
            // agent payload reports sold/inquiry counts as 0 (AgentResource's
            // `?? 0`), which the public agent-website stats read as real data.
            ->addSelect([
                DB::raw("(SELECT COUNT(*) FROM listings INNER JOIN properties ON properties.id = listings.property_id WHERE listings.agent_id = agents.id AND listings.deleted_at IS NULL AND properties.status = 'sold') as sold_count"),
                DB::raw("(SELECT COUNT(*) FROM conversations WHERE conversations.agent_user_id = agents.user_id AND conversations.status = 'accepted') as ongoing_inquiries_count"),
                DB::raw("(SELECT COUNT(*) FROM conversations WHERE conversations.agent_user_id = agents.user_id AND conversations.status = 'closed') as closed_inquiries_count"),
                DB::raw('(SELECT COUNT(*) FROM conversations WHERE conversations.agent_user_id = agents.user_id) as total_inquiries_count'),
            ])
            ->findOrFail($id);
        $this->guardSecretaryRegion($request, $agent);

        // Public visibility gate: hide a non-active agent's listings from
        // non-privileged viewers. This endpoint powers BOTH the public agent
        // profile (already noindexed on the frontend for inactive agents) and
        // the client "share a listing" picker, so we keep the 200 response and
        // just empty the listings rather than 404 — matching the site-wide
        // agent-status gate. Admins and region secretaries (vetted just above
        // by guardSecretaryRegion) keep full visibility.
        $viewer = $request->user();
        $hideListings = $agent->status !== 'active'
            && ! ($viewer && ($viewer->role?->name === 'admin' || $viewer->isSecretary()));
        if ($hideListings) {
            $agent->listings_count = 0;
        }

        $listingsQuery = $agent->listings()
            ->where('visibility', 'public')
            ->when($hideListings, fn ($q) => $q->whereRaw('1 = 0'))
            ->with([
                'property.propertyAttribute.subtype.type',
                'property.furnishing',
                'property.barangay.city.province',
                'category',
            ])
            ->orderByDesc('created_at');

        if ($search = trim((string) $request->query('search', ''))) {
            $term = "%{$search}%";

            $listingsQuery->where(function ($q) use ($term) {
                $q->where('name', 'LIKE', $term)
                    ->orWhere('code', 'LIKE', $term)
                    ->orWhereHas('property', function ($propertyQuery) use ($term) {
                        $propertyQuery
                            ->orWhere('address', 'LIKE', $term);
                    });
            });
        }

        // Optional listing filters — mirrors the params the chat "Share a
        // listing" picker sends from /my-listings, so the client/inquirer can
        // filter the agent's public listings the same way the agent can.
        if (($category = trim((string) $request->query('category', ''))) !== '' && strtolower($category) !== 'all') {
            $listingsQuery->whereHas('category', fn ($q) => $q->where('name', $category));
        }

        if ($subtypes = $request->query('subtypes')) {
            $ids = array_filter(array_map('intval', explode(',', (string) $subtypes)));
            if (! empty($ids)) {
                $listingsQuery->whereHas(
                    'property.propertyAttribute.subtype',
                    fn ($q) => $q->whereIn('id', $ids),
                );
            }
        }

        if (is_numeric($request->query('price_min'))) {
            $listingsQuery->where('listings.price', '>=', (float) $request->query('price_min'));
        }
        if (is_numeric($request->query('price_max'))) {
            $listingsQuery->where('listings.price', '<=', (float) $request->query('price_max'));
        }

        if (($beds = (int) $request->query('beds', 0)) > 0) {
            $listingsQuery->whereHas(
                'property.propertyAttribute',
                fn ($q) => $q->where('bedroom_count', '>=', $beds),
            );
        }
        if (($baths = (int) $request->query('baths', 0)) > 0) {
            $listingsQuery->whereHas(
                'property.propertyAttribute',
                fn ($q) => $q->where('bathroom_count', '>=', $baths),
            );
        }

        $agent->setRelation(
            'listings',
            $listingsQuery
                ->paginate(12)
                ->appends($request->only([
                    'search', 'category', 'subtypes',
                    'price_min', 'price_max', 'beds', 'baths',
                ]))
        );

        return new AgentResource($agent);
    }

    public function showByEmail(Request $request, string $email)
    {
        $email = trim(urldecode($email));
        if ($email === '') {
            return response()->json(['message' => 'The email path segment is required.'], 422);
        }

        $agent = Agent::with('user')
            ->withCount(['listings' => fn ($q) => $q->where('visibility', 'public')])
            ->whereHas('user', fn ($q) => $q->whereRaw('LOWER(email) = ?', [strtolower($email)]))
            ->first();

        if (! $agent) {
            return response()->json(['message' => 'Agent not found.'], 404);
        }

        $listingsQuery = $agent->listings()
            ->where('visibility', 'public')
            // Only active listings whose ATS is approved (filters on the related
            // properties table — dot notation in where() does NOT do this).
            ->whereHas('property', fn ($q) => $q
                ->where('status', 'active')
                ->where('ats_status', 'approve'))
            ->with([
                'property.propertyAttribute.subtype.type',
                'property.furnishing',
                'property.barangay.city.province',
                'category',
            ])
            ->orderByDesc('created_at');

        // Read ?page= explicitly and pass it to paginate() so page 2+ always
        // returns the correct slice (no reliance on the global request resolver).
        $page = max(1, (int) $request->query('page', 1));
        $agent->setRelation(
            'listings',
            $listingsQuery->paginate(10, ['*'], 'page', $page)->appends(['page' => $page])
        );

        return new ExternalAgentResource($agent);
    }

    public function profile()
    {
        /** @var User $user */
        $user = Auth::user();
        $agent = Agent::where('user_id', $user->id)
            ->with('user.role')
            ->first();

        if (! $agent) {
            return new UserResource($user->load('role'));
        }

        return new AgentResource($agent);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'mobile_no' => 'required|string|max:20',
            'whats_app_no' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'socials' => 'nullable|array',
            'socials.facebook' => 'nullable|string|max:255',
            'socials.instagram' => 'nullable|string|max:255',
            'socials.twitter' => 'nullable|string|max:255',
            'socials.linkedin' => 'nullable|string|max:255',
            'socials.youtube' => 'nullable|string|max:255',
            'socials.tiktok' => 'nullable|string|max:255',
            'bio' => 'nullable|string',
            'avatar' => 'nullable|string|url',
            'geo_location' => 'nullable|array:lat,lng',
            'birthdate' => 'nullable|date',
            'gender' => 'nullable|string|in:male,female',
            'user_id' => 'nullable|exists:users,id', // ← admin can pass a target user
        ]);

        $user = Auth::user();
        $role = $user->role?->name;
        $targetId = $user->id; // default to self

        if ($role === 'admin') {
            // Admin can pass user_id to update any agent, or defaults to themselves
            if (! empty($validated['user_id'])) {
                $targetId = $validated['user_id'];

                // Make sure target user is actually an agent (or admin)
                $targetUser = User::with('role')->findOrFail($targetId);
                $targetRole = $targetUser->role?->name;

                if (! in_array($targetRole, ['agent', 'admin'])) {
                    abort(403, 'Target user must be an agent or admin.');
                }
            }
        } elseif ($role === 'agent' || $role === 'secretary') {
            // Agents — and secretaries (who are agents scoped to one region) —
            // can only update their OWN profile. Editing one's own account is a
            // self-service action, separate from the secretary's read-only rule
            // on other agents' region data; the admin user_id passthrough above
            // is never reached for them.
            $targetId = $user->id;
        } else {
            abort(403, 'Only agents or admins can create or update an agent profile.');
        }

        // Remove user_id from validated so it doesn't get saved into agent fields
        unset($validated['user_id']);

        $agent = Agent::updateOrCreate(
            ['user_id' => $targetId],
            $validated
        );

        if (array_key_exists('avatar', $validated) && $agent->user) {
            $avatarUrl = is_array($agent->avatar) ? ($agent->avatar[0] ?? null) : $agent->avatar;
            $agent->user->update(['avatar' => $avatarUrl]);
        }

        return new AgentResource($agent);
    }

    public function updateStatus(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role?->name !== 'admin') {
            abort(403);
        }

        $agent = Agent::withTrashed()->findOrFail($id);
        $validated = $request->validate([
            'status' => 'required|in:active,inactive,resigned,deactivated',
        ]);
        $old = $agent->status;

        if ($validated['status'] === 'deactivated') {
            $dormantDays = User::DORMANT_DAYS;
            $threshold = Carbon::now()->subDays($dormantDays);

            $hasUser = User::where('id', $agent->user_id)->exists();
            $isDormant = User::where('id', $agent->user_id)->dormantSince($threshold)->exists();

            if ($hasUser && ! $isDormant) {
                return response()->json([
                    'message' => "This agent has been active within the last {$dormantDays} days, so they can't be marked as deactivated. Use \"Inactive\" instead.",
                ], 422);
            }
        }

        // First-class audit: a readable summary + a distinct source so an admin
        // status change stands out in the activity log and is separable from a
        // generic profile edit or the dormancy auto-deactivation. The owen-it
        // 'updated' event still records the old→new status diff in
        // old_values/new_values; this only adds the human-readable label. No
        // audit row is written when the status is unchanged (nothing dirty).
        $agent->auditDescription = "Status: {$old} → {$validated['status']}";
        $agent->auditSource = 'admin_status_dropdown';
        $agent->update(['status' => $validated['status']]);

        // Nudge search engines to recrawl this agent's listing URLs so the
        // now-hidden (blocked) or newly-restored (active) pages leave/return to
        // the index faster than the sitemap revalidate window. Fires only on a
        // real change. Uses raw visibility (NOT publiclyListed(), which would now
        // exclude a blocked agent's listings entirely).
        if ($old !== $validated['status'] && config('services.indexnow.enabled')) {
            $svc = app(IndexNowService::class);
            $urls = $agent->listings()
                ->where('visibility', 'public')
                ->pluck('slug')
                ->filter(fn ($s) => is_string($s) && $s !== '' && ! str_starts_with($s, 'tmp-'))
                ->map(fn ($s) => $svc->listingUrl($s))
                ->values()
                ->all();
            foreach (array_chunk($urls, 10000) as $chunk) {
                PingIndexNow::dispatch($chunk)->afterCommit();
            }
        }

        return response()->json(['data' => new AgentResource($agent)]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role?->name !== 'admin') {
            abort(403);
        }

        $agent = Agent::findOrFail($id);
        $agent->delete();

        return response()->json(['message' => 'Agent removed.']);
    }

    public function restore(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role?->name !== 'admin') {
            abort(403);
        }

        $agent = Agent::onlyTrashed()->findOrFail($id);
        $agent->restore();

        return response()->json(['data' => new AgentResource($agent)]);
    }

    public function deletedAgents(Request $request)
    {
        $user = $request->user();
        // Admin sees every deleted agent; a secretary (FH role 5) sees only the
        // deleted agents in their office region — a read-only view mirroring the
        // active Agents list. This route is auth:sanctum + dashboard-only (no
        // public counterpart), so no `managed` opt-in is needed here.
        $isSecretary = $user->isSecretary();
        if ($user->role?->name !== 'admin' && ! $isSecretary) {
            abort(403);
        }

        $perPage = max(1, min((int) $request->query('per_page', 12), 24));

        $query = Agent::onlyTrashed()
            ->select([
                'agents.*',
                DB::raw('(SELECT COUNT(*) FROM listings WHERE listings.agent_id = agents.id) as listings_count'),
            ])
            ->with(['user' => fn ($q) => $q->withCount('loginLogs')->withMax('loginLogs', 'logged_in_at')])
            ->whereHas('user.role', function ($q) use ($request) {
                $q->where('name', $request->boolean('include_admins') ? 'admin' : 'agent');
            });

        // Secretary: scope to their office region (fail-closed if unassigned).
        if ($isSecretary) {
            $region = $user->secretaryRegion();
            if ($region === null) {
                abort(403);
            }
            $query->where('agents.region', $region);
        }

        if ($search = $request->query('search')) {
            $term = '%'.$search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'LIKE', $term)
                    ->orWhere('last_name', 'LIKE', $term)
                    ->orWhere('mobile_no', 'LIKE', $term)
                    ->orWhereHas('user', function ($uq) use ($term) {
                        $uq->where('email', 'LIKE', $term)->orWhere('name', 'LIKE', $term);
                    });
            });
        }

        $sortBy = $request->query('sort_by');
        $sortDir = strtolower($request->query('sort_dir', 'asc'));
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'asc';
        }

        match ($sortBy) {
            'full_name' => $query->orderByRaw("CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) $sortDir"),
            'deleted_at' => $query->orderBy('deleted_at', $sortDir),
            'listings_count' => $query->orderBy('listings_count', $sortDir),
            default => $query->orderByDesc('deleted_at'),
        };

        return new AgentResourceCollection(
            $query->paginate($perPage)
        );
    }

    /**
     * Email a Top-10 leaderboard certificate to an agent. The admin dashboard
     * renders the certificate as a PNG client-side and uploads it here; we
     * forward it to the agent's stored email address as an attachment.
     *
     * Mirrors the audit verification mail flow (ListingController@updateVerification):
     * admin-gated, sends synchronously, records failures via AuditMailService,
     * and echoes back { email_sent } so the UI only claims delivery on success.
     */
    public function sendCertificate(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role?->name !== 'admin') {
            abort(403);
        }

        $validated = $request->validate([
            'certificate' => 'required|file|mimes:png|max:8192', // ≤ 8 MB PNG
            'month' => 'required|string|max:20',
            'year' => 'required|integer|min:2000|max:2100',
            'agent_name' => 'nullable|string|max:255',
        ]);

        $agent = Agent::with('user')->findOrFail($id);
        $agentUser = $agent->user;

        // Prefer the name the dashboard displayed; fall back to the stored user.
        $agentName = trim((string) ($validated['agent_name'] ?? ''))
            ?: (trim((string) optional($agentUser)->name) ?: 'Agent');

        $emailSent = false;

        if (! $agentUser || ! $agentUser->email) {
            Log::warning('Agent certificate email skipped — no agent user/email', [
                'agent_id' => $agent->id,
                'has_user' => (bool) $agentUser,
            ]);

            // 200 (not an error) — the request was valid, there was just no
            // address to send to. The UI reads email_sent=false and says so.
            return response()->json(['email_sent' => false]);
        }

        $file = $request->file('certificate');
        // Read the bytes now — the temp upload is gone once the request ends,
        // and we send synchronously so there's no queue serialization concern.
        $rawData = file_get_contents($file->getRealPath());
        $baseName = pathinfo(
            $file->getClientOriginalName() ?: "certificate_{$validated['month']}_{$validated['year']}.png",
            PATHINFO_FILENAME,
        );

        // Normalize the certificate so mail clients reliably render an inline
        // preview thumbnail on the attachment. A large/heavy PNG straight off
        // the canvas tends to show as a bare download link with no preview
        // (which is what agents were seeing). Re-encoding to a moderate-width
        // JPEG produces a clean, lightweight image Gmail can thumbnail — and
        // also shrinks the SMTP payload. Non-fatal: fall back to the original
        // PNG bytes if GD/Intervention can't process it.
        $certificateData = $rawData;
        $filename = $baseName.'.png';
        $certificateMime = 'image/png';
        try {
            $manager = new ImageManager(new Driver);
            $image = $manager->read($rawData)->scaleDown(width: 1600);
            $certificateData = (string) $image->toJpeg(90);
            $filename = $baseName.'.jpg';
            $certificateMime = 'image/jpeg';
        } catch (\Throwable $e) {
            Log::warning('Agent certificate image normalize failed — attaching original PNG', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            // Send synchronously — production has no queue worker wired up (see
            // MessageNotificationMailer's note), so a queued mailable would just
            // sit in the `jobs` table and never send. Lift the execution ceiling
            // for this one blocking send so pushing the attachment over SMTP
            // can't trip PHP's default 30s max_execution_time; tiny HTML mails
            // (audit) never hit this.
            @set_time_limit(120);

            Mail::to($agentUser->email)->send(new AgentCertificateMailer(
                agentName: $agentName,
                awardMonth: $validated['month'],
                awardYear: (int) $validated['year'],
                certificateData: $certificateData,
                certificateFilename: $filename,
                certificateMime: $certificateMime,
            ));
            $emailSent = true;

            Log::info('Agent certificate email sent', [
                'agent_id' => $agent->id,
                'to' => $agentUser->email,
                'month' => $validated['month'],
                'year' => $validated['year'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Agent certificate email failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
            app(AuditMailService::class)->recordFailure(
                $e,
                'AgentCertificateMailer',
                [$agentUser->email],
                "Top Agent certificate — {$validated['month']} {$validated['year']}",
                [
                    'auditable_type' => Agent::class,
                    'auditable_id' => $agent->id,
                ],
            );
        }

        return response()->json(['email_sent' => $emailSent]);
    }
}
