<?php

namespace App\Services\Dashboard;

use App\Services\Listing\AgentInsightsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The admin main dashboard's "Business pulse": what an owner wants from a glance — today against
 * yesterday and the 7-day average, the last 30 days day by day, this month against the same days last
 * month, lead handling (waiting for review, never answered, response speed), the agent force, the live
 * shelf, recent deals and this month's top agents. One call, all from our own tables.
 *
 * Days are Philippine days (UTC+8): timestamps are stored in UTC, so they are shifted before bucketing.
 * Definitions:
 *   - listing     a listing created (not deleted), standard categories;
 *   - inquiry     a chat a CLIENT opened — on a listing, or straight to an agent (profile / website);
 *   - new client  a user with the client role created that day;
 *   - returning   a client who logged in that day and registered before it (Audience Insights' rule);
 *   - deal        a property marked sold / rented / leased, dated by its status_change_date;
 *   - views       listing views (one per device per listing per day), from listing_view_days, which
 *                 only exists from 2026-10-22 — the all-time total is SUM(listings.clicks).
 */
class OwnerPulseService
{
    private const TZ = 'Asia/Manila';

    private const OFFSET = '+08:00';

    private const DEALS = ['sold', 'rented', 'leased'];

    private const CATEGORIES = ['For Sale', 'For Rent', 'Foreclosure'];

    /** Rows in the dashboard's two city lists (they show ~5 and scroll for the rest). */
    private const CITY_ROWS = 20;

    /** Rows in "Recent deals" (shows ~5, scrolls for the rest). */
    private const DEAL_ROWS = 20;

    public function __construct(private AgentInsightsService $agents) {}

    public function pulse(): array
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $from30 = $today->subDays(29);

        $series = $this->daily($from30, $today);
        $byDay = collect($series)->keyBy('date');
        $t = $byDay[$today->toDateString()] ?? null;
        $y = $byDay[$today->subDay()->toDateString()] ?? null;
        $last7 = collect($series)->filter(fn ($d) => $d['date'] < $today->toDateString())->take(-7);

        $metrics = ['listings', 'inquiries', 'clients', 'returning', 'deals', 'views'];
        $todayBlock = [];
        foreach ($metrics as $m) {
            $todayBlock[$m] = [
                'today' => $t[$m] ?? 0,
                'yesterday' => $y[$m] ?? 0,
                'avg7' => round($last7->avg($m) ?? 0, 1),
            ];
        }

        // Month to date vs the same number of days last month.
        $mtdStart = $today->startOfMonth();
        $days = $today->day;
        $prevStart = $mtdStart->subMonth();
        $prevEnd = $prevStart->addDays(min($days, $prevStart->daysInMonth) - 1);
        $mtd = $this->totals($mtdStart, $today);
        $prev = $this->totals($prevStart, $prevEnd);

        $split = $this->agents->summary([])['split'];

        return [
            'as_of' => CarbonImmutable::now(self::TZ)->toIso8601String(),
            'today' => $todayBlock,
            'series' => $series,
            'month' => ['current' => $mtd, 'previous' => $prev, 'days' => $days],
            'leads' => $this->leads($today),
            'agents' => [
                'active' => $split['agents'],
                'online_7d' => (int) DB::table('agents')->join('users', 'users.id', '=', 'agents.user_id')
                    ->whereNull('agents.deleted_at')->where('agents.status', 'active')
                    ->where('users.last_online_at', '>=', now()->subDays(7))->count(),
                'listed_30d' => (int) DB::table('listings')->join('agents', 'agents.id', '=', 'listings.agent_id')
                    ->whereNull('listings.deleted_at')->where('agents.status', 'active')
                    ->where('listings.created_at', '>=', $from30->utc())->distinct()->count('listings.agent_id'),
                'no_listings' => $split['no_listings'],
                'closed' => $split['closed'],
            ],
            'shelf' => $this->shelf(),
            'recent_deals' => $this->recentDeals(),
            'most_active' => $this->mostActive($from30, $today),
            'views' => [
                'total' => (int) DB::table('listings')->whereNull('deleted_at')->sum('clicks'),
                'tracked_since' => $this->viewDays()->min('date'),
                'top_cities' => $this->topViewedCities($from30),
            ],
            'visitor_origins' => $this->visitorOrigins($from30),
            'clients_total' => (int) DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->where('roles.name', 'client')->count(),
        ];
    }

    /** Per Philippine day between $from and $to (inclusive): listings, inquiries, new clients, deals. */
    private function daily(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $listings = $this->countByDay($this->listingQuery(), 'listings.created_at', $from, $to);
        $inquiries = $this->countByDay($this->inquiryQuery(), 'chats.created_at', $from, $to);
        $clients = $this->countByDay($this->clientQuery(), 'users.created_at', $from, $to);
        $local = "DATE(CONVERT_TZ(login_logs.logged_in_at, '+00:00', '".self::OFFSET."'))";
        $returning = $this->returningQuery()
            ->whereBetween('login_logs.logged_in_at', [$from->startOfDay()->utc(), $to->endOfDay()->utc()])
            ->whereRaw("DATE(CONVERT_TZ(users.created_at, '+00:00', '".self::OFFSET."')) < {$local}")
            ->selectRaw("{$local} as d, COUNT(DISTINCT login_logs.user_id) as n")->groupBy('d')->pluck('n', 'd');
        $deals = DB::table('properties')->whereNull('deleted_at')->whereIn('status', self::DEALS)
            ->whereBetween('status_change_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('DATE(status_change_date) as d, COUNT(*) as n')->groupBy('d')->pluck('n', 'd');
        $views = $this->viewDays()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('date as d, SUM(views) as n')->groupBy('date')->pluck('n', 'd');

        $out = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $k = $d->toDateString();
            $out[] = [
                'date' => $k,
                'listings' => (int) ($listings[$k] ?? 0),
                'inquiries' => (int) ($inquiries[$k] ?? 0),
                'clients' => (int) ($clients[$k] ?? 0),
                'returning' => (int) ($returning[$k] ?? 0),
                'deals' => (int) ($deals[$k] ?? 0),
                'views' => (int) ($views[$k] ?? 0),
            ];
        }

        return $out;
    }

    private function totals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sum = fn ($q, string $col) => (int) (clone $q)->whereBetween($col, [$from->startOfDay()->utc(), $to->endOfDay()->utc()])->count();

        return [
            'listings' => $sum($this->listingQuery(), 'listings.created_at'),
            'inquiries' => $sum($this->inquiryQuery(), 'chats.created_at'),
            // Client inquiries from the period that nobody but the client has written in yet.
            'unreplied' => $sum($this->inquiryQuery()
                ->join('conversations', 'conversations.chat_id', '=', 'chats.id')
                ->whereNull('conversations.deleted_at')
                ->whereNotExists(fn ($q) => $q->from('messages')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->whereColumn('messages.user_id', '!=', 'chats.user_id')), 'chats.created_at'),
            'clients' => $sum($this->clientQuery(), 'users.created_at'),
            'returning' => (int) $this->returningQuery()
                ->whereBetween('login_logs.logged_in_at', [$from->startOfDay()->utc(), $to->endOfDay()->utc()])
                ->where('users.created_at', '<', $from->startOfDay()->utc())
                ->distinct()->count('login_logs.user_id'),
            'deals' => (int) DB::table('properties')->whereNull('deleted_at')->whereIn('status', self::DEALS)
                ->whereBetween('status_change_date', [$from->toDateString(), $to->toDateString()])->count(),
            'views' => (int) $this->viewDays()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->sum('views'),
        ];
    }

    /**
     * Lead handling: conversations waiting for an admin to approve, client inquiries from the last
     * 30 days whose agent never replied, and the agents' typical first-response time.
     */
    private function leads(CarbonImmutable $today): array
    {
        $since = $today->subDays(29)->utc();
        $recent = DB::table('conversations')
            ->join('chats', 'chats.id', '=', 'conversations.chat_id')
            ->join('users as sender', 'sender.id', '=', 'chats.user_id')
            ->join('roles', 'roles.id', '=', 'sender.role_id')
            ->whereNull('conversations.deleted_at')->whereNull('chats.deleted_at')
            ->whereIn('chats.type', ['listing', 'agent'])
            ->where('roles.name', 'client')
            ->where('chats.created_at', '>=', $since);

        $total = (int) (clone $recent)->count();
        $unanswered = (int) (clone $recent)
            ->where('conversations.status', 'accepted')
            ->whereNotNull('conversations.agent_user_id')
            ->whereNotExists(fn ($q) => $q->from('messages')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->whereColumn('messages.user_id', 'conversations.agent_user_id'))
            ->count();

        $responses = DB::table('agents')->whereNull('deleted_at')->where('status', 'active')
            ->where('response_sample_size', '>', 0)->whereNotNull('median_first_response_seconds')
            ->orderBy('median_first_response_seconds')->pluck('median_first_response_seconds')->all();
        $median = $responses ? $responses[intdiv(count($responses), 2)] : null;

        return [
            'pending_review' => (int) DB::table('conversations')->whereNull('deleted_at')->where('status', 'pending')->count(),
            'inquiries_30d' => $total,
            'unanswered_30d' => $unanswered,
            'answered_pct' => $total > 0 ? round(($total - $unanswered) / $total * 100) : null,
            'median_first_response_seconds' => $median !== null ? (int) $median : null,
        ];
    }

    /** Live listings (public, active property) by category, and how many are new in the last 7 days. */
    private function shelf(): array
    {
        $rows = $this->listingQuery()
            ->where('listings.visibility', 'public')->where('properties.status', 'active')
            ->selectRaw('categories.name as category, COUNT(*) as n, SUM(CASE WHEN listings.created_at >= ? THEN 1 ELSE 0 END) as new7', [now()->subDays(7)])
            ->groupBy('categories.name')->get()->keyBy('category');

        return array_map(fn ($c) => ['category' => $c, 'live' => (int) ($rows[$c]->n ?? 0), 'new_7d' => (int) ($rows[$c]->new7 ?? 0)], self::CATEGORIES);
    }

    private function recentDeals(): array
    {
        return DB::table('properties')
            ->join('listings', 'listings.property_id', '=', 'properties.id')
            ->join('agents', 'agents.id', '=', 'listings.agent_id')
            ->leftJoin('categories', 'categories.id', '=', 'listings.category_id')
            ->whereNull('properties.deleted_at')->whereNull('listings.deleted_at')
            ->whereIn('properties.status', self::DEALS)->whereNotNull('properties.status_change_date')
            ->orderByDesc('properties.status_change_date')->orderByDesc('properties.id')
            ->limit(self::DEAL_ROWS)
            ->get(['properties.status', 'properties.status_change_date as date', 'listings.name', 'listings.slug', 'listings.price', 'categories.name as category', 'agents.first_name', 'agents.last_name'])
            ->map(fn ($r) => [
                'status' => $r->status,
                'date' => $r->date,
                'listing' => $r->name,
                'slug' => $r->slug,
                'price' => $r->price !== null ? (float) $r->price : null,
                'category' => $r->category,
                'agent' => trim((string) preg_replace('/\s+/', ' ', ($r->first_name ?? '').' '.($r->last_name ?? ''))),
            ])->all();
    }

    /**
     * The most active agents and teams over the last 30 days. Activity is what an agent DOES on the
     * platform: messages they send (replying to leads), listings they create, and any other recorded
     * action (audit log); logins add active days but no actions. Agents rank by active days, then
     * actions; teams by how many of their agents were active, then their actions.
     */
    private function mostActive(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $since = $from->startOfDay()->utc();
        $until = $to->endOfDay()->utc();
        $local = fn (string $col) => "DATE(CONVERT_TZ({$col}, '+00:00', '".self::OFFSET."'))";

        // Real agents only: staff (admin / editor / secretary) accounts that also have an agent row
        // would top the list on admin work alone.
        $agents = DB::table('agents')
            ->join('users', 'users.id', '=', 'agents.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereNull('agents.deleted_at')->where('agents.status', 'active')->where('roles.name', 'agent')
            ->get(['agents.id', 'agents.user_id', 'agents.first_name', 'agents.last_name'])->keyBy('id');
        $byUser = $agents->filter(fn ($a) => $a->user_id)->mapWithKeys(fn ($a) => [$a->user_id => $a->id]);

        $days = [];    // agent_id => [date => true]
        $actions = []; // agent_id => count
        $listed = [];  // agent_id => count
        $replies = []; // agent_id => count
        $add = function (iterable $rows, bool $byUserId, ?array &$counter) use ($byUser, &$days, &$actions) {
            foreach ($rows as $r) {
                $agentId = $byUserId ? ($byUser[$r->uid] ?? null) : (int) $r->uid;
                if ($agentId === null) {
                    continue;
                }
                $days[$agentId][$r->d] = true;
                if ($counter !== null) {
                    $counter[$agentId] = ($counter[$agentId] ?? 0) + (int) $r->n;
                    $actions[$agentId] = ($actions[$agentId] ?? 0) + (int) $r->n;
                }
            }
        };
        $none = null;
        $other = [];

        $add(DB::table('login_logs')->whereBetween('logged_in_at', [$since, $until])
            ->selectRaw($local('logged_in_at').' as d, user_id as uid, COUNT(*) as n')->groupBy('d', 'uid')->get(), true, $none);
        $add(DB::table('messages')->whereBetween('created_at', [$since, $until])
            ->selectRaw($local('created_at').' as d, user_id as uid, COUNT(*) as n')->groupBy('d', 'uid')->get(), true, $replies);
        $add(DB::table('listings')->whereNull('deleted_at')->whereBetween('created_at', [$since, $until])
            ->selectRaw($local('created_at').' as d, agent_id as uid, COUNT(*) as n')->groupBy('d', 'uid')->get(), false, $listed);
        // Every other recorded action; listing/message rows already counted above are left out.
        $add(DB::table('audits')->whereNotNull('user_id')->whereBetween('created_at', [$since, $until])
            ->where('event', '!=', 'logged_in')
            ->where(fn ($q) => $q->where('event', '!=', 'created')->orWhereNotIn('auditable_type', ['App\\Models\\Listing', 'App\\Models\\Message']))
            ->selectRaw($local('created_at').' as d, user_id as uid, COUNT(*) as n')->groupBy('d', 'uid')->get(), true, $other);

        $teams = DB::table('team_agents')->join('teams', 'teams.id', '=', 'team_agents.team_id')
            ->where('teams.status', 'active')->where('team_agents.status', 'active')
            ->get(['team_agents.agent_id', 'teams.id as team_id', 'teams.name']);
        $teamOf = $teams->groupBy('agent_id')->map(fn ($g) => $g->first()->name);

        $agentRows = [];
        foreach ($days as $agentId => $d) {
            $a = $agents[$agentId] ?? null;
            if (! $a) {
                continue; // resigned / deactivated / deleted
            }
            $agentRows[] = [
                'id' => (int) $agentId,
                'name' => trim((string) preg_replace('/\s+/', ' ', ($a->first_name ?? '').' '.($a->last_name ?? ''))),
                'team' => $teamOf[$agentId] ?? null,
                'active_days' => count($d),
                'actions' => (int) ($actions[$agentId] ?? 0),
                'replies' => (int) ($replies[$agentId] ?? 0),
                'listings' => (int) ($listed[$agentId] ?? 0),
            ];
        }
        usort($agentRows, fn ($x, $y) => [$y['active_days'], $y['actions']] <=> [$x['active_days'], $x['actions']]);

        $teamRows = [];
        foreach ($teams->groupBy('team_id') as $teamId => $members) {
            $ids = $members->pluck('agent_id')->filter(fn ($id) => isset($agents[$id]))->unique();
            if ($ids->isEmpty()) {
                continue;
            }
            $active = $ids->filter(fn ($id) => isset($days[$id]));
            $teamRows[] = [
                'id' => (int) $teamId,
                'name' => $members->first()->name,
                'members' => $ids->count(),
                'active_members' => $active->count(),
                'actions' => (int) $active->sum(fn ($id) => $actions[$id] ?? 0),
                'listings' => (int) $active->sum(fn ($id) => $listed[$id] ?? 0),
            ];
        }
        usort($teamRows, fn ($x, $y) => [$y['active_members'], $y['actions']] <=> [$x['active_members'], $x['actions']]);

        return [
            'agents' => array_slice($agentRows, 0, 10),
            'teams' => array_slice(array_values(array_filter($teamRows, fn ($t) => $t['active_members'] > 0)), 0, 10),
        ];
    }

    private function listingQuery()
    {
        return DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->whereNull('listings.deleted_at')->whereNull('properties.deleted_at')
            ->where('properties.status', '!=', 'deleted')
            ->whereIn('categories.name', self::CATEGORIES);
    }

    private function inquiryQuery()
    {
        return DB::table('chats')
            ->join('users as sender', 'sender.id', '=', 'chats.user_id')
            ->join('roles', 'roles.id', '=', 'sender.role_id')
            ->whereNull('chats.deleted_at')
            ->whereIn('chats.type', ['listing', 'agent'])
            ->where('roles.name', 'client');
    }

    /**
     * Cities whose listings are viewed most — all time (listings.clicks) and over the last 30 days
     * (listing_view_days, empty until it has history). A listing's city: its project's, else its
     * barangay's, the same resolution Listing Insights uses.
     */
    private function topViewedCities(CarbonImmutable $from): array
    {
        $city = 'COALESCE(projects.city_id, property_cities.id)';
        $base = fn () => $this->listingQuery()
            ->leftJoin('projects', fn ($j) => $j->on('projects.id', '=', 'properties.project_id')->whereNull('projects.deleted_at'))
            ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
            ->leftJoin('cities as property_cities', 'property_cities.id', '=', 'barangays.city_id')
            ->join('cities', 'cities.id', '=', DB::raw($city))
            ->leftJoin('provinces', 'provinces.id', '=', 'cities.province_id');

        $allTime = $base()
            ->groupBy('cities.id', 'cities.name', 'provinces.name')
            ->orderByDesc('views')->limit(self::CITY_ROWS)
            ->get([DB::raw('cities.id as id'), DB::raw('cities.name as city'), DB::raw('provinces.name as province'), DB::raw('SUM(listings.clicks) as views'), DB::raw('COUNT(*) as listings')]);

        $recent = Schema::hasTable('listing_view_days')
            ? $base()->join('listing_view_days as v', 'v.listing_id', '=', 'listings.id')
                ->where('v.date', '>=', $from->toDateString())
                ->groupBy('cities.id', 'cities.name', 'provinces.name')
                ->orderByDesc('views')->limit(self::CITY_ROWS)
                ->get([DB::raw('cities.id as id'), DB::raw('cities.name as city'), DB::raw('provinces.name as province'), DB::raw('SUM(v.views) as views'), DB::raw('COUNT(DISTINCT listings.id) as listings')])
            : collect();

        $shape = fn ($rows) => $rows->map(fn ($r) => ['id' => (int) $r->id, 'city' => $r->city, 'province' => $r->province, 'views' => (int) $r->views, 'listings' => (int) $r->listings])->all();

        return ['all_time' => $shape($allTime), 'last_30d' => $shape($recent)];
    }

    /**
     * Where the site's visitors come from (last 30 days): distinct visitors by city, from the visits
     * table — anonymous visitors and clients, agents and staff left out (Audience Insights' rule).
     * Locations come from IP lookup, so some visits have none; `known_pct` says how many do.
     */
    private function visitorOrigins(CarbonImmutable $from): array
    {
        $base = fn () => DB::table('visits')
            ->leftJoin('users', 'users.id', '=', 'visits.user_id')
            ->leftJoin('roles', 'roles.id', '=', 'users.role_id')
            ->where('visits.created_at', '>=', $from->startOfDay()->utc())
            ->where(fn ($q) => $q->whereNull('visits.user_id')->orWhere('roles.name', 'client'));

        $total = (int) $base()->distinct()->count('visits.visitor_id');
        $known = (int) $base()->whereNotNull('visits.city')->where('visits.city', '!=', '')->distinct()->count('visits.visitor_id');
        $cities = $base()->whereNotNull('visits.city')->where('visits.city', '!=', '')
            ->groupBy('visits.city', 'visits.region', 'visits.country')
            ->orderByDesc('visitors')->limit(self::CITY_ROWS)
            ->get(['visits.city', 'visits.region', 'visits.country', DB::raw('COUNT(DISTINCT visits.visitor_id) as visitors')])
            ->map(fn ($r) => ['city' => $r->city, 'region' => $r->region, 'country' => $r->country, 'visitors' => (int) $r->visitors])->all();

        return ['visitors' => $total, 'known_pct' => $total > 0 ? round($known / $total * 100) : null, 'cities' => $cities];
    }

    /** listing_view_days, or an always-empty stand-in until its migration has run. */
    private function viewDays()
    {
        return Schema::hasTable('listing_view_days')
            ? DB::table('listing_view_days')
            : DB::table('listings')->selectRaw('NULL as date, 0 as views')->whereRaw('1 = 0');
    }

    private function returningQuery()
    {
        return DB::table('login_logs')
            ->join('users', 'users.id', '=', 'login_logs.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client');
    }

    private function clientQuery()
    {
        return DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->where('roles.name', 'client');
    }

    /** @return Collection<string, int> date (PH) → count */
    private function countByDay($query, string $col, CarbonImmutable $from, CarbonImmutable $to)
    {
        $local = "DATE(CONVERT_TZ({$col}, '+00:00', '".self::OFFSET."'))";

        return $query->whereBetween($col, [$from->startOfDay()->utc(), $to->endOfDay()->utc()])
            ->selectRaw("{$local} as d, COUNT(*) as n")->groupBy('d')->pluck('n', 'd');
    }
}
