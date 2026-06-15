<?php

namespace App\Services\Audience;

use Illuminate\Support\Facades\DB;

/**
 * Powers the front-end EngagementOverview card: the headline audience-size
 * counts (size()) and the per-day visitors / new / returning trend line
 * (trend()). Both scoped to the instance date range.
 */
class EngagementOverviewService extends AudienceInsightsService
{
    /**
     * Headline cards: total / new / returning clients + distinct visitors.
     */
    public function size(): array
    {
        $totalClients = (clone $this->clientsBase())->count();

        $newClients = (clone $this->clientsBase())
            ->whereBetween('users.created_at', [$this->startDt, $this->endDt])
            ->count();

        // Returning = client logged in during the range AND registered before it.
        $returning = DB::table('login_logs')
            ->join('users', 'users.id', '=', 'login_logs.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client')
            ->whereBetween('login_logs.logged_in_at', [$this->startDt, $this->endDt])
            ->where('users.created_at', '<', $this->startDt)
            ->distinct('login_logs.user_id')
            ->count('login_logs.user_id');

        // Visitors = unique devices across anonymous + client visits (agents/
        // admins excluded). The single source of truth used everywhere.
        $visitors = $this->audienceVisits()
            ->distinct('visits.visitor_id')
            ->count('visits.visitor_id');

        return [
            'total_clients'     => $totalClients,
            'new_clients'       => $newClients,
            'returning_clients' => $returning,
            'visitors'          => $visitors,
        ];
    }

    /**
     * Per-day series so the card can chart whether the audience is rising or
     * falling: unique visitors per day (DISTINCT visitor_id), new clients per
     * day (registrations), and returning clients per day (logged in that day
     * but registered before the range start). Zero-filled across the range for
     * a continuous line; capped at ~1500 points to keep wide ranges sane.
     */
    public function trend(): array
    {
        // Audience visitors per day (anonymous + clients, agents/admins excluded).
        $visitorsByDay = $this->audienceVisits()
            ->groupBy(DB::raw('DATE(visits.created_at)'))
            ->select(DB::raw('DATE(visits.created_at) as d'), DB::raw('COUNT(DISTINCT visits.visitor_id) as c'))
            ->pluck('c', 'd');

        $clientsByDay = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client')
            ->whereBetween('users.created_at', [$this->startDt, $this->endDt])
            ->groupBy(DB::raw('DATE(users.created_at)'))
            ->select(DB::raw('DATE(users.created_at) as d'), DB::raw('COUNT(*) as c'))
            ->pluck('c', 'd');

        // Returning = client logged in that day, registered before the range.
        $returningByDay = DB::table('login_logs')
            ->join('users', 'users.id', '=', 'login_logs.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client')
            ->whereBetween('login_logs.logged_in_at', [$this->startDt, $this->endDt])
            ->where('users.created_at', '<', $this->startDt)
            ->groupBy(DB::raw('DATE(login_logs.logged_in_at)'))
            ->select(DB::raw('DATE(login_logs.logged_in_at) as d'), DB::raw('COUNT(DISTINCT login_logs.user_id) as c'))
            ->pluck('c', 'd');

        // Date axis starts at the first day that actually has data.
        $allKeys = array_merge(
            array_keys($visitorsByDay->all()),
            array_keys($clientsByDay->all()),
            array_keys($returningByDay->all()),
        );
        $dates = $this->buildDates($allKeys ? min($allKeys) : null);

        return array_map(fn ($key) => [
            'date'      => $key,
            'visitors'  => (int) ($visitorsByDay[$key] ?? 0),
            'clients'   => (int) ($clientsByDay[$key] ?? 0),
            'returning' => (int) ($returningByDay[$key] ?? 0),
        ], $dates);
    }
}
