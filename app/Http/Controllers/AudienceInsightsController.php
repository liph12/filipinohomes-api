<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for the admin "Audience Insights" page. Currently exposes the
 * audience-size counts (total / new / returning clients + distinct visitors)
 * for a date range. Admin-only (route gated by RoleMiddleware:admin); the
 * date-range param mirrors the other dashboard endpoints.
 */
class AudienceInsightsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_start' => 'nullable|date',
            'date_end'   => 'nullable|date|after_or_equal:date_start',
        ]);

        // No date_start → all-time (from the earliest record). The frontend
        // ships a Jun-10-2026 default, so this only kicks in when the user
        // clears the filter.
        $start   = $validated['date_start'] ?? $this->earliestDate();
        $end     = $validated['date_end']   ?? now()->toDateString();
        $startDt = $start . ' 00:00:00';
        $endDt   = $end . ' 23:59:59';

        return response()->json([
            'size'         => $this->size($startDt, $endDt),
            'acquisition'  => $this->acquisition($startDt, $endDt),
            'trend'        => $this->trend($startDt, $endDt, $start, $end),
            'source_trend' => $this->sourceTrend($startDt, $endDt, $start, $end),
            'meta'         => ['from' => $start, 'to' => $end],
        ]);
    }

    /**
     * Per-day anonymous-visitor counts split by acquisition channel — powers the
     * "Source Breakdown" multi-line chart. Same recent-≤400-day window as trend.
     * Returns aligned date labels + one zero-filled series per channel (ordered
     * by total, biggest first).
     */
    private function sourceTrend(string $startDt, string $endDt, string $start, string $end): array
    {
        $rows = DB::table('visits')
            ->whereBetween('created_at', [$startDt, $endDt])
            ->whereNull('user_id')
            ->groupBy(DB::raw('DATE(created_at)'), 'channel')
            ->select(DB::raw('DATE(created_at) as d'), 'channel', DB::raw('COUNT(DISTINCT visitor_id) as c'))
            ->get();

        // Pivot: byChannel[channel][date] = count, plus per-channel totals.
        $byChannel = [];
        $totals = [];
        foreach ($rows as $r) {
            $ch = (string) $r->channel;
            $byChannel[$ch][$r->d] = (int) $r->c;
            $totals[$ch] = ($totals[$ch] ?? 0) + (int) $r->c;
        }
        arsort($totals);

        // Date axis — most recent ≤400 days (mirrors trend()).
        $last   = Carbon::parse($end);
        $cursor = Carbon::parse($start);
        $floor  = (clone $last)->subDays(399);
        if ($cursor->lt($floor)) {
            $cursor = $floor;
        }
        $dates = [];
        while ($cursor->lte($last)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        $channels = [];
        foreach (array_keys($totals) as $ch) {
            $channels[] = [
                'channel' => $ch,
                'data'    => array_map(fn ($d) => $byChannel[$ch][$d] ?? 0, $dates),
            ];
        }

        return ['dates' => $dates, 'channels' => $channels];
    }

    /**
     * Per-day series so the dashboard can chart whether the audience is rising
     * or falling: unique visitors per day (DISTINCT visitor_id), new clients
     * per day (registrations), and returning clients per day (logged in that
     * day but registered before the range start). Zero-filled across the range
     * for a continuous line; capped at 400 points to keep wide ranges sane.
     */
    private function trend(string $startDt, string $endDt, string $start, string $end): array
    {
        // Anonymous visitors only (not logged-in users).
        $visitorsByDay = DB::table('visits')
            ->whereBetween('created_at', [$startDt, $endDt])
            ->whereNull('user_id')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->select(DB::raw('DATE(created_at) as d'), DB::raw('COUNT(DISTINCT visitor_id) as c'))
            ->pluck('c', 'd');

        $clientsByDay = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client')
            ->whereBetween('users.created_at', [$startDt, $endDt])
            ->groupBy(DB::raw('DATE(users.created_at)'))
            ->select(DB::raw('DATE(users.created_at) as d'), DB::raw('COUNT(*) as c'))
            ->pluck('c', 'd');

        // Returning = client logged in that day, registered before the range.
        $returningByDay = DB::table('login_logs')
            ->join('users', 'users.id', '=', 'login_logs.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client')
            ->whereBetween('login_logs.logged_in_at', [$startDt, $endDt])
            ->where('users.created_at', '<', $startDt)
            ->groupBy(DB::raw('DATE(login_logs.logged_in_at)'))
            ->select(DB::raw('DATE(login_logs.logged_in_at) as d'), DB::raw('COUNT(DISTINCT login_logs.user_id) as c'))
            ->pluck('c', 'd');

        // Show at most 400 days. For wide ranges (e.g. all-time) keep the most
        // recent 400 days rather than the oldest, so the line stays relevant.
        $last   = Carbon::parse($end);
        $cursor = Carbon::parse($start);
        $floor  = (clone $last)->subDays(399);
        if ($cursor->lt($floor)) {
            $cursor = $floor;
        }

        $series = [];
        while ($cursor->lte($last)) {
            $key = $cursor->toDateString();
            $series[] = [
                'date'      => $key,
                'visitors'  => (int) ($visitorsByDay[$key] ?? 0),
                'clients'   => (int) ($clientsByDay[$key] ?? 0),
                'returning' => (int) ($returningByDay[$key] ?? 0),
            ];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * Geography breakdown on its own endpoint so the Audience Insights map card
     * can filter by an independent date range. Admin-only (route-gated).
     */
    public function geographyShow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_start' => 'nullable|date',
            'date_end'   => 'nullable|date|after_or_equal:date_start',
        ]);

        $start = $validated['date_start'] ?? $this->earliestDate();
        $end   = $validated['date_end']   ?? now()->toDateString();

        return response()->json([
            'geography' => $this->geography($start . ' 00:00:00', $end . ' 23:59:59'),
            'trend'     => $this->geoTrend($start . ' 00:00:00', $end . ' 23:59:59', $start, $end),
            'meta'      => ['from' => $start, 'to' => $end],
        ]);
    }

    /**
     * Per-day geography series for the Top-10 line charts. For each dimension
     * (country / region / city) returns the top-10 locations by total and their
     * daily counts: client registrations (by users.created_at) + anonymous
     * visits (by visit date), matching the geography totals. Recent ≤400 days.
     */
    private function geoTrend(string $startDt, string $endDt, string $start, string $end): array
    {
        $last   = Carbon::parse($end);
        $cursor = Carbon::parse($start);
        $floor  = (clone $last)->subDays(399);
        if ($cursor->lt($floor)) {
            $cursor = $floor;
        }
        $dates = [];
        while ($cursor->lte($last)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        $dim = function (string $userCol, string $visitCol) use ($startDt, $endDt, $dates) {
            $byName = [];
            $totals = [];
            $add = function ($rows) use (&$byName, &$totals) {
                foreach ($rows as $r) {
                    $name = trim((string) $r->name);
                    if ($name === '' || strcasecmp($name, 'unknown') === 0) {
                        continue;
                    }
                    $byName[$name][$r->d] = ($byName[$name][$r->d] ?? 0) + (int) $r->c;
                    $totals[$name] = ($totals[$name] ?? 0) + (int) $r->c;
                }
            };

            $add(DB::table('user_info')
                ->join('users', 'users.id', '=', 'user_info.user_id')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('roles.name', 'client')
                ->whereBetween('users.created_at', [$startDt, $endDt])
                ->whereNotNull('user_info.' . $userCol)
                ->where('user_info.' . $userCol, '!=', '')
                ->groupBy(DB::raw('DATE(users.created_at)'), 'user_info.' . $userCol)
                ->select(DB::raw('DATE(users.created_at) as d'), 'user_info.' . $userCol . ' as name', DB::raw('COUNT(DISTINCT user_info.user_id) as c'))
                ->get());

            $add(DB::table('visits')
                ->whereBetween('created_at', [$startDt, $endDt])
                ->whereNull('user_id')
                ->whereNotNull($visitCol)
                ->where($visitCol, '!=', '')
                ->groupBy(DB::raw('DATE(created_at)'), $visitCol)
                ->select(DB::raw('DATE(created_at) as d'), $visitCol . ' as name', DB::raw('COUNT(DISTINCT visitor_id) as c'))
                ->get());

            arsort($totals);
            $top = array_slice(array_keys($totals), 0, 10);

            return array_map(fn ($name) => [
                'name' => $name,
                'data' => array_map(fn ($d) => $byName[$name][$d] ?? 0, $dates),
            ], $top);
        };

        return [
            'dates'   => $dates,
            'country' => $dim('country', 'country'),
            'region'  => $dim('state', 'region'),
            'city'    => $dim('city', 'city'),
        ];
    }

    /**
     * Where the audience is located — country / state / city, geo resolved by
     * ipinfo.io. Combines two non-overlapping sources so the count is unique
     * per person/device with no double counting, both scoped to the date range:
     *   - clients  : their stored user_info location, by registration date.
     *   - visitors : anonymous visits only (user_id IS NULL), DISTINCT device,
     *                by visit date. Logged-in clients' visits are skipped here
     *                because they're already counted via user_info.
     * Top 10 per level.
     */
    private function geography(string $startDt, string $endDt): array
    {
        // Unique clients by stored location (+ their country, for the flag),
        // scoped to registrations in range.
        $clientGeo = function (string $column) use ($startDt, $endDt) {
            return DB::table('user_info')
                ->join('users', 'users.id', '=', 'user_info.user_id')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('roles.name', 'client')
                ->whereBetween('users.created_at', [$startDt, $endDt])
                ->whereNotNull('user_info.' . $column)
                ->where('user_info.' . $column, '!=', '')
                ->groupBy('user_info.' . $column, 'user_info.country')
                ->select('user_info.' . $column . ' as name', 'user_info.country as country', DB::raw('COUNT(DISTINCT user_info.user_id) as c'))
                ->get();
        };

        // Unique anonymous visitor devices in range (+ their country).
        $visitorGeo = function (string $column) use ($startDt, $endDt) {
            return DB::table('visits')
                ->whereBetween('created_at', [$startDt, $endDt])
                ->whereNull('user_id')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->groupBy($column, 'country')
                ->select($column . ' as name', 'country as country', DB::raw('COUNT(DISTINCT visitor_id) as c'))
                ->get();
        };

        // Merge both sources (same ipinfo vocabulary): sum per name, pick the
        // dominant country for the flag, take the top 10.
        $merge = function ($clients, $visitors): array {
            $totals = [];
            $countryVotes = [];
            foreach ([$clients, $visitors] as $rows) {
                foreach ($rows as $r) {
                    $name = trim((string) $r->name);
                    if ($name === '' || strcasecmp($name, 'unknown') === 0) {
                        continue;
                    }
                    $count = (int) $r->c;
                    $totals[$name] = ($totals[$name] ?? 0) + $count;
                    $ct = trim((string) ($r->country ?? ''));
                    if ($ct !== '' && strcasecmp($ct, 'unknown') !== 0) {
                        $countryVotes[$name][$ct] = ($countryVotes[$name][$ct] ?? 0) + $count;
                    }
                }
            }
            arsort($totals);
            $totals = array_slice($totals, 0, 10, true);

            return array_map(function ($name, $count) use ($countryVotes) {
                $country = null;
                if (!empty($countryVotes[$name])) {
                    arsort($countryVotes[$name]);
                    $country = array_key_first($countryVotes[$name]);
                }
                return ['name' => $name, 'value' => $count, 'country' => $country];
            }, array_keys($totals), array_values($totals));
        };

        return [
            'countries' => $merge($clientGeo('country'), $visitorGeo('country')),
            'states'    => $merge($clientGeo('state'),   $visitorGeo('region')),
            'cities'    => $merge($clientGeo('city'),    $visitorGeo('city')),
        ];
    }

    /**
     * Acquisition channels within the range, each split into:
     *   - visitors  : unique devices from that channel (DISTINCT visitor_id)
     *   - new       : clients who visited via that channel AND registered in
     *                 the range (DISTINCT logged-in user_id, created in range)
     *   - returning : clients who visited via that channel but registered
     *                 before the range start
     * New/returning rely on the visit carrying a logged-in user_id, so they
     * only count clients who browsed while signed in.
     */
    private function acquisition(string $startDt, string $endDt): array
    {
        // Unique anonymous devices per channel (not logged-in users).
        $visitors = DB::table('visits')
            ->whereBetween('created_at', [$startDt, $endDt])
            ->whereNull('user_id')
            ->groupBy('channel')
            ->select('channel', DB::raw('COUNT(DISTINCT visitor_id) as c'))
            ->pluck('c', 'channel');

        // Logged-in client visits per channel, split by registration date.
        $clientByChannel = function (bool $registeredInRange) use ($startDt, $endDt) {
            return DB::table('visits')
                ->join('users', 'users.id', '=', 'visits.user_id')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('roles.name', 'client')
                ->whereBetween('visits.created_at', [$startDt, $endDt])
                ->when(
                    $registeredInRange,
                    fn ($q) => $q->whereBetween('users.created_at', [$startDt, $endDt]),
                    fn ($q) => $q->where('users.created_at', '<', $startDt),
                )
                ->groupBy('visits.channel')
                ->select('visits.channel', DB::raw('COUNT(DISTINCT visits.user_id) as c'))
                ->pluck('c', 'visits.channel');
        };

        $newByChannel       = $clientByChannel(true);
        $returningByChannel = $clientByChannel(false);

        $channels = collect($visitors)
            ->map(fn ($count, $channel) => [
                'channel'   => $channel,
                'value'     => (int) $count,
                'new'       => (int) ($newByChannel[$channel] ?? 0),
                'returning' => (int) ($returningByChannel[$channel] ?? 0),
            ])
            ->sortByDesc('value')
            ->values()
            ->all();

        return ['channels' => $channels];
    }

    /**
     * Earliest activity date across users + visits — the "all-time" start used
     * when no date_start is supplied. Falls back to today if there's no data.
     */
    private function earliestDate(): string
    {
        $dates = array_filter([
            DB::table('users')->min('created_at'),
            DB::table('visits')->min('created_at'),
        ]);

        return empty($dates)
            ? now()->toDateString()
            : Carbon::parse(min($dates))->toDateString();
    }

    /** Clients are scoped via roles.name = 'client'. */
    private function clientsBase()
    {
        return DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client');
    }

    private function size(string $startDt, string $endDt): array
    {
        $totalClients = (clone $this->clientsBase())->count();

        $newClients = (clone $this->clientsBase())
            ->whereBetween('users.created_at', [$startDt, $endDt])
            ->count();

        // Returning = client logged in during the range AND registered before it.
        $returning = DB::table('login_logs')
            ->join('users', 'users.id', '=', 'login_logs.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client')
            ->whereBetween('login_logs.logged_in_at', [$startDt, $endDt])
            ->where('users.created_at', '<', $startDt)
            ->distinct('login_logs.user_id')
            ->count('login_logs.user_id');

        // Visitors = anonymous only (not a logged-in user — excludes agents,
        // admins, and signed-in clients, who are counted via the client metrics).
        $visitors = DB::table('visits')
            ->whereBetween('created_at', [$startDt, $endDt])
            ->whereNull('user_id')
            ->distinct('visitor_id')
            ->count('visitor_id');

        return [
            'total_clients'     => $totalClients,
            'new_clients'       => $newClients,
            'returning_clients' => $returning,
            'visitors'          => $visitors,
        ];
    }
}
