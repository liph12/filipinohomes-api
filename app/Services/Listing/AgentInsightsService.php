<?php

namespace App\Services\Listing;

use Illuminate\Support\Facades\DB;

/**
 * Admin Listing Insights → "Agent Insights": every agent with what they have done on the platform —
 * how many listings they created, how many are live, how many closed (sold / rented / leased), when they
 * last listed and when they were last online — and the headline split for the tile: agents with no
 * listings, agents who listed but have not closed anything, and agents who closed at least one.
 *
 * Listings count the way the rest of Listing Insights does: not soft-deleted, property not deleted
 * (soft or status), standard categories. A deal is a listing whose property status is sold / rented /
 * leased. Optional date range narrows the LISTINGS (created in the period), never the agent list, so
 * "no listings" then means "nothing created in that period".
 */
class AgentInsightsService
{
    public const STATUSES = ['active', 'resigned', 'deactivated'];

    private const CATEGORIES = ['For Sale', 'For Rent', 'Foreclosure'];

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function agents(array $params, ?array $agentIds = null): array
    {
        $status = in_array($params['status'] ?? null, self::STATUSES, true) ? $params['status'] : (($params['status'] ?? null) === 'all' ? null : 'active');

        $rows = $this->agentQuery($params, $agentIds)
            ->when($status !== null, fn ($q) => $q->where('agents.status', $status))
            ->select(
                'agents.id', 'agents.first_name', 'agents.last_name', 'agents.status', 'agents.avatar',
                'users.email', 'users.last_online_at',
                DB::raw('COALESCE(l.listings, 0) as listings'),
                DB::raw('COALESCE(l.live, 0) as live'),
                DB::raw('COALESCE(l.sold, 0) as sold'),
                DB::raw('COALESCE(l.rented, 0) as rented'),
                DB::raw('COALESCE(l.leased, 0) as leased'),
                DB::raw('COALESCE(l.month, 0) as this_month'),
                'l.last_listing_at',
                DB::raw('tm.team as team'),
                DB::raw('COALESCE(li.n, 0) as listing_inquiries'),
                DB::raw('COALESCE(di.n, 0) as direct_inquiries'),
                DB::raw('COALESCE(di.profile, 0) as direct_profile'),
                DB::raw('COALESCE(di.website, 0) as direct_website'),
                DB::raw('COALESCE(li.replied, 0) as listing_replied'),
                DB::raw('COALESCE(di.profile_replied, 0) as direct_profile_replied'),
                DB::raw('COALESCE(di.website_replied, 0) as direct_website_replied'),
            )
            ->orderByDesc('listings')
            ->orderBy('agents.first_name')
            ->get();

        $data = $rows->map(function ($r) {
            $closed = (int) $r->sold + (int) $r->rented + (int) $r->leased;

            return [
                'id' => (int) $r->id,
                'name' => trim((string) preg_replace('/\s+/', ' ', ($r->first_name ?? '').' '.($r->last_name ?? ''))) ?: ($r->email ?? 'Agent #'.$r->id),
                'email' => $r->email,
                'avatar' => self::avatarUrl($r->avatar),
                'status' => $r->status,
                'team' => $r->team,
                'listings' => (int) $r->listings,
                'live' => (int) $r->live,
                'sold' => (int) $r->sold,
                'rented' => (int) $r->rented,
                'leased' => (int) $r->leased,
                'closed' => $closed,
                'this_month' => (int) $r->this_month,
                'last_listing_at' => $r->last_listing_at,
                'listing_inquiries' => (int) $r->listing_inquiries,
                'direct_inquiries' => (int) $r->direct_inquiries,
                'direct_profile' => (int) $r->direct_profile,
                'direct_website' => (int) $r->direct_website,
                'listing_replied' => (int) $r->listing_replied,
                'direct_profile_replied' => (int) $r->direct_profile_replied,
                'direct_website_replied' => (int) $r->direct_website_replied,
                'last_online_at' => $r->last_online_at,
                'group' => (int) $r->listings === 0 ? 'none' : ($closed === 0 ? 'no_deal' : 'closed'),
            ];
        })->all();

        return ['data' => $data, 'meta' => ['status' => $status ?? 'all'] + $this->split($data)];
    }

    /**
     * The Agent Insights page's bento tiles, in one call: the active agents' split (no listings / listed,
     * no deal / closed), agents by status, how many listings active agents hold (buckets), when they were
     * last online (buckets), and the top ten listers and closers.
     */
    public function summary(array $params, ?array $agentIds = null): array
    {
        $byStatus = $this->agentQuery($params, $agentIds)
            ->selectRaw('agents.status as status, COUNT(*) as n')
            ->groupBy('agents.status')
            ->pluck('n', 'status');
        $active = $this->agents(['status' => 'active'] + $params, $agentIds);
        $rows = $active['data'];

        $buckets = ['0' => 0, '1–5' => 0, '6–20' => 0, '21–50' => 0, '50+' => 0];
        foreach ($rows as $a) {
            $n = $a['listings'];
            $buckets[$n === 0 ? '0' : ($n <= 5 ? '1–5' : ($n <= 20 ? '6–20' : ($n <= 50 ? '21–50' : '50+')))]++;
        }

        $now = time();
        $online = ['7 days' => 0, '30 days' => 0, '90 days' => 0, 'Longer' => 0, 'Never' => 0];
        foreach ($rows as $a) {
            $t = $a['last_online_at'] ? strtotime($a['last_online_at'].' UTC') : false;
            $days = $t ? ($now - $t) / 86400 : null;
            $online[$days === null ? 'Never' : ($days <= 7 ? '7 days' : ($days <= 30 ? '30 days' : ($days <= 90 ? '90 days' : 'Longer')))]++;
        }

        $top = function (string $key) use ($rows): array {
            $list = array_values(array_filter($rows, fn ($a) => $a[$key] > 0));
            usort($list, fn ($x, $y) => $y[$key] <=> $x[$key] ?: strcmp($x['name'], $y['name']));

            return array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name'], 'team' => $a['team'], 'avatar' => $a['avatar'], 'value' => $a[$key]], array_slice($list, 0, 10));
        };

        return [
            'split' => $active['meta'],
            'by_status' => array_combine(self::STATUSES, array_map(fn ($st) => (int) ($byStatus[$st] ?? 0), self::STATUSES)),
            'listing_buckets' => $buckets,
            'online_buckets' => $online,
            'top_listers' => $top('listings'),
            'top_closers' => $top('closed'),
        ];
    }

    /**
     * Active agents over time: per week (last 26) or month (last 12), how many distinct agents
     *   - were active at all: logged in, did anything recorded in the audit log, or created a listing;
     *   - logged in (login_logs + the auth audit's logged_in rows);
     *   - created at least one listing.
     * Every agent account counts (any status today), since an agent who has since resigned was still
     * active back then. Logins alone undercount — app tokens never expire, so a busy agent may not log
     * in for months — which is why "active" also takes actions and listings.
     *
     * @return array{data: list<array{period: string, active: int, logged_in: int, listed: int}>, meta: array<string, mixed>}
     */
    public function activityTrend(array $params, ?array $agentIds = null): array
    {
        $monthly = ($params['granularity'] ?? 'week') === 'month';
        $count = $monthly ? 12 : 26;
        $start = $monthly
            ? now()->startOfMonth()->subMonths($count - 1)
            : now()->startOfWeek()->subWeeks($count - 1);
        $startStr = $start->toDateTimeString();

        $agents = DB::table('agents')->whereNull('deleted_at')
            ->when($agentIds !== null, fn ($q) => $q->whereIn('id', $agentIds));
        $userToAgent = (clone $agents)->whereNotNull('user_id')->pluck('id', 'user_id');

        $bucket = fn (string $col) => $monthly
            ? "DATE_FORMAT({$col}, '%Y-%m-01')"
            : "DATE(DATE_SUB({$col}, INTERVAL WEEKDAY({$col}) DAY))";

        // [period => [agent_id => true]] per source
        $logins = [];
        $actions = [];
        $listed = [];
        $collect = function (iterable $rows, array &$into, bool $byUser) use ($userToAgent) {
            foreach ($rows as $r) {
                $agentId = $byUser ? ($userToAgent[$r->uid] ?? null) : $r->uid;
                if ($agentId !== null) {
                    $into[$r->period][$agentId] = true;
                }
            }
        };

        $collect(DB::table('login_logs')->where('logged_in_at', '>=', $startStr)
            ->selectRaw($bucket('logged_in_at').' as period, user_id as uid')->distinct()->get(), $logins, true);
        $collect(DB::table('audits')->where('created_at', '>=', $startStr)->where('event', 'logged_in')->whereNotNull('user_id')
            ->selectRaw($bucket('created_at').' as period, user_id as uid')->distinct()->get(), $logins, true);
        $collect(DB::table('audits')->where('created_at', '>=', $startStr)->whereNotNull('user_id')
            ->selectRaw($bucket('created_at').' as period, user_id as uid')->distinct()->get(), $actions, true);
        $collect(DB::table('listings')->where('created_at', '>=', $startStr)
            ->when($agentIds !== null, fn ($q) => $q->whereIn('agent_id', $agentIds))
            ->selectRaw($bucket('created_at').' as period, agent_id as uid')->distinct()->get(), $listed, false);

        $data = [];
        for ($i = 0; $i < $count; $i++) {
            $p = ($monthly ? $start->copy()->addMonths($i) : $start->copy()->addWeeks($i))->toDateString();
            $all = ($logins[$p] ?? []) + ($actions[$p] ?? []) + ($listed[$p] ?? []);
            $data[] = ['period' => $p, 'active' => count($all), 'logged_in' => count($logins[$p] ?? []), 'listed' => count($listed[$p] ?? [])];
        }

        // Trend: the last COMPLETE period against the one before (the current one is still filling).
        $last = $data[$count - 2]['active'] ?? 0;
        $prev = $data[$count - 3]['active'] ?? 0;

        return ['data' => $data, 'meta' => [
            'granularity' => $monthly ? 'month' : 'week',
            'last_complete' => $last,
            'previous' => $prev,
            'change_pct' => $prev > 0 ? round(($last - $prev) / $prev * 100) : null,
            // Logins are only recorded from this date; before it the "active" line is listings only.
            'logins_tracked_since' => DB::table('login_logs')->min('logged_in_at'),
        ]];
    }

    /** agents.avatar holds a URL, a JSON-encoded URL, or a JSON list of URLs (older imports); the first URL. */
    private static function avatarUrl(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        $url = is_array($decoded) ? ($decoded[0] ?? null) : (is_string($decoded) ? $decoded : $raw);

        return is_string($url) && str_starts_with($url, 'http') ? $url : null;
    }

    /** @param list<array<string, mixed>> $data */
    private function split(array $data): array
    {
        $count = fn (string $g) => count(array_filter($data, fn ($a) => $a['group'] === $g));

        return [
            'agents' => count($data),
            'with_listings' => count($data) - $count('none'),
            'no_listings' => $count('none'),
            'listed_no_deal' => $count('no_deal'),
            'closed' => $count('closed'),
            'listed_this_month' => count(array_filter($data, fn ($a) => $a['this_month'] > 0)),
            'listings' => array_sum(array_column($data, 'listings')),
            'deals' => array_sum(array_column($data, 'closed')),
            // Inquiries: on their listings, and straight to them (profile / website "Message me").
            'listed_with_inquiry' => count(array_filter($data, fn ($a) => $a['listings'] > 0 && $a['listing_inquiries'] > 0)),
            'listed_no_inquiry' => count(array_filter($data, fn ($a) => $a['listings'] > 0 && $a['listing_inquiries'] === 0)),
            'with_direct' => count(array_filter($data, fn ($a) => $a['direct_inquiries'] > 0)),
            'direct_only' => count(array_filter($data, fn ($a) => $a['direct_inquiries'] > 0 && $a['listing_inquiries'] === 0)),
            // Agents per channel (an agent messaged on both counts in both).
            'agents_profile' => count(array_filter($data, fn ($a) => $a['direct_profile'] > 0)),
            'agents_website' => count(array_filter($data, fn ($a) => $a['direct_website'] > 0)),
            'agents_unrecorded' => count(array_filter($data, fn ($a) => $a['direct_inquiries'] > $a['direct_profile'] + $a['direct_website'])),
            'listing_inquiries' => array_sum(array_column($data, 'listing_inquiries')),
            'direct_inquiries' => array_sum(array_column($data, 'direct_inquiries')),
            'direct_profile' => array_sum(array_column($data, 'direct_profile')),
            'direct_website' => array_sum(array_column($data, 'direct_website')),
        ];
    }

    /** Agents (not deleted, team lead's agents when scoped) with their listing aggregates and a team name. */
    private function agentQuery(array $params, ?array $agentIds)
    {
        $monthStart = date('Y-m-01').' 00:00:00';
        $listings = DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            ->where('properties.status', '!=', 'deleted')
            ->whereIn('categories.name', self::CATEGORIES)
            ->when(! empty($params['date_start']), fn ($q) => $q->where('listings.created_at', '>=', $params['date_start'].' 00:00:00'))
            ->when(! empty($params['date_end']), fn ($q) => $q->where('listings.created_at', '<=', $params['date_end'].' 23:59:59'))
            ->groupBy('listings.agent_id')
            ->selectRaw("
                listings.agent_id,
                COUNT(*) as listings,
                SUM(CASE WHEN listings.visibility = 'public' AND properties.status = 'active' THEN 1 ELSE 0 END) as live,
                SUM(CASE WHEN properties.status = 'sold' THEN 1 ELSE 0 END) as sold,
                SUM(CASE WHEN properties.status = 'rented' THEN 1 ELSE 0 END) as rented,
                SUM(CASE WHEN properties.status = 'leased' THEN 1 ELSE 0 END) as leased,
                SUM(CASE WHEN listings.created_at >= ? THEN 1 ELSE 0 END) as month,
                MAX(listings.created_at) as last_listing_at
            ", [$monthStart]);

        $teams = DB::table('team_agents')
            ->join('teams', 'teams.id', '=', 'team_agents.team_id')
            ->groupBy('team_agents.agent_id')
            ->selectRaw('team_agents.agent_id, MAX(teams.name) as team');

        // An inquiry counts as RESPONDED when the agent has written at least one message in any of its
        // conversations — removed messages (deleted / unsent) don't count. The same rule Chat Statistics
        // uses for "agent replied"; {user} is the agent's users.id column for that subquery.
        $replied = fn (string $user) => "EXISTS (
            SELECT 1 FROM messages m
            JOIN conversations cv ON cv.id = m.conversation_id
            WHERE cv.chat_id = chats.id
              AND m.user_id = {$user}
              AND m.status NOT IN ('deleted', 'unsent')
        )";

        // Inquiries on the agent's listings (chats type=listing, as Inquiry Analytics counts them).
        $listingInquiries = DB::table('chats')
            ->join('listings', 'listings.id', '=', 'chats.type_id')
            ->join('agents as owner', 'owner.id', '=', 'listings.agent_id')
            ->whereNull('chats.deleted_at')
            ->whereNull('listings.deleted_at')
            ->where('chats.type', 'listing')
            ->groupBy('listings.agent_id')
            ->selectRaw('listings.agent_id, COUNT(DISTINCT chats.id) as n,
                SUM(CASE WHEN '.$replied('owner.user_id').' THEN 1 ELSE 0 END) as replied');

        // Inquiries straight to the agent — "Message me" on their profile or website (chats type=agent,
        // type_id = the agent's user id), from clients only, so agent-to-agent and staff DMs stay out.
        // chats.source is only stored from 2026-10-20; older rows count in n but in neither split.
        $directInquiries = DB::table('chats')
            ->join('users as sender', 'sender.id', '=', 'chats.user_id')
            ->join('roles as sender_role', 'sender_role.id', '=', 'sender.role_id')
            ->join('agents as target', 'target.user_id', '=', 'chats.type_id')
            ->whereNull('chats.deleted_at')
            ->where('chats.type', 'agent')
            ->where('sender_role.name', 'client')
            ->groupBy('target.id')
            ->selectRaw("target.id as agent_id, COUNT(DISTINCT chats.id) as n,
                SUM(CASE WHEN chats.source = 'agent_profile' THEN 1 ELSE 0 END) as profile,
                SUM(CASE WHEN chats.source = 'agent_page' THEN 1 ELSE 0 END) as website,
                SUM(CASE WHEN chats.source = 'agent_profile' AND ".$replied('target.user_id')." THEN 1 ELSE 0 END) as profile_replied,
                SUM(CASE WHEN chats.source = 'agent_page' AND ".$replied('target.user_id')." THEN 1 ELSE 0 END) as website_replied");

        return DB::table('agents')
            ->leftJoin('users', 'users.id', '=', 'agents.user_id')
            ->leftJoinSub($listings, 'l', 'l.agent_id', '=', 'agents.id')
            ->leftJoinSub($teams, 'tm', 'tm.agent_id', '=', 'agents.id')
            ->leftJoinSub($listingInquiries, 'li', 'li.agent_id', '=', 'agents.id')
            ->leftJoinSub($directInquiries, 'di', 'di.agent_id', '=', 'agents.id')
            ->whereNull('agents.deleted_at')
            ->when($agentIds !== null, fn ($q) => $q->whereIn('agents.id', $agentIds));
    }
}
