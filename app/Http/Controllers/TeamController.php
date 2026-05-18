<?php

namespace App\Http\Controllers;

use App\Http\Resources\TeamResource;
use App\Http\Resources\TeamResourceCollection;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        // Optional date range applied to listings.created_at / conversations.created_at /
        // login_logs.logged_in_at. Transactions use properties.status_change_date so a
        // sale that closes inside the window still counts even if its listing is older.
        $dateFrom = $request->input('date_from'); // 'YYYY-MM-DD' or null
        $dateTo   = $request->input('date_to');

        // Build clause + bindings pair per relation so we can reuse them
        $listingsClause = '';   $listingsBindings   = [];
        $conversationsClause = ''; $conversationsBindings = [];
        $loginsClause = '';     $loginsBindings     = [];
        $txnsClause   = '';     $txnsBindings       = [];

        if ($dateFrom) {
            $listingsClause      .= ' AND l.created_at >= ?';        $listingsBindings[]      = $dateFrom . ' 00:00:00';
            $conversationsClause .= ' AND c.created_at >= ?';        $conversationsBindings[] = $dateFrom . ' 00:00:00';
            $loginsClause        .= ' AND ll.logged_in_at >= ?';     $loginsBindings[]        = $dateFrom . ' 00:00:00';
            $txnsClause          .= ' AND p.status_change_date >= ?'; $txnsBindings[]          = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $listingsClause      .= ' AND l.created_at <= ?';        $listingsBindings[]      = $dateTo . ' 23:59:59';
            $conversationsClause .= ' AND c.created_at <= ?';        $conversationsBindings[] = $dateTo . ' 23:59:59';
            $loginsClause        .= ' AND ll.logged_in_at <= ?';     $loginsBindings[]        = $dateTo . ' 23:59:59';
            $txnsClause          .= ' AND p.status_change_date <= ?'; $txnsBindings[]          = $dateTo . ' 23:59:59';
        }

        // Per-member subqueries
        $agentStatsSql = [
            DB::raw("(SELECT COUNT(*) FROM login_logs ll
                      JOIN agents a ON a.id = team_agents.agent_id
                      WHERE ll.user_id = a.user_id{$loginsClause}) as agent_login_count"),
            DB::raw("(SELECT COUNT(*) FROM listings l
                      WHERE l.agent_id = team_agents.agent_id{$listingsClause}) as agent_listings_count"),
            DB::raw("(SELECT COUNT(*) FROM listings l
                      INNER JOIN properties p ON p.id = l.property_id
                      WHERE l.agent_id = team_agents.agent_id
                        AND p.status IN ('sold','rented','leased'){$txnsClause}) as agent_transactions_count"),
            DB::raw("(SELECT COUNT(*) FROM conversations c
                      JOIN agents a ON a.id = team_agents.agent_id
                      WHERE c.agent_user_id = a.user_id{$conversationsClause}) as agent_inquiries_count"),
        ];
        $agentBindings = array_merge($loginsBindings, $listingsBindings, $txnsBindings, $conversationsBindings);

        // Per-team subqueries
        $query = Team::query()
            ->select([
                'teams.*',
                DB::raw("(SELECT COUNT(*) FROM login_logs ll
                          INNER JOIN team_agents ta ON ta.team_id = teams.id
                          INNER JOIN agents a ON a.id = ta.agent_id
                          WHERE ll.user_id = a.user_id{$loginsClause}) as login_count"),
                DB::raw("(SELECT COUNT(*) FROM listings l
                          INNER JOIN team_agents ta ON ta.team_id = teams.id
                          WHERE l.agent_id = ta.agent_id{$listingsClause}) as listings_count"),
                DB::raw("(SELECT COUNT(*) FROM listings l
                          INNER JOIN team_agents ta ON ta.team_id = teams.id
                          INNER JOIN properties p ON p.id = l.property_id
                          WHERE l.agent_id = ta.agent_id
                            AND p.status IN ('sold','rented','leased'){$txnsClause}) as transactions_count"),
                DB::raw("(SELECT COUNT(*) FROM conversations c
                          INNER JOIN team_agents ta ON ta.team_id = teams.id
                          INNER JOIN agents a ON a.id = ta.agent_id
                          WHERE c.agent_user_id = a.user_id{$conversationsClause}) as inquiries_count"),
            ])
            ->addBinding(array_merge($loginsBindings, $listingsBindings, $txnsBindings, $conversationsBindings), 'select')
            ->with([
                'leader.agent',
                'teamAgents' => function ($q) use ($agentStatsSql, $agentBindings) {
                    $q->select(array_merge(['team_agents.*'], $agentStatsSql))
                      ->addBinding($agentBindings, 'select')
                      ->with(['agent.user']);
                },
            ]);

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        return new TeamResourceCollection(
            $query->orderBy('name')->get()
        );
    }

    /**
     * Listings created per month, optionally scoped by date range and/or team.
     *
     * Query params:
     *   - date_from / date_to (YYYY-MM-DD)  — bounds the series. Defaults to last 12 months.
     *   - team_id (int)                     — when present, only counts listings by agents on that team
     *
     * Response:
     *   { "data": [ { "month": "2026-01", "label": "Jan 2026", "count": 42 }, ... ] }
     */
    public function monthlyListings(Request $request)
    {
        $teamId = $request->input('team_id');

        $now  = Carbon::now();
        $from = $request->input('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : $now->copy()->subMonths(11)->startOfMonth();
        $to = $request->input('date_to')
            ? Carbon::parse($request->input('date_to'))->endOfDay()
            : $now->copy()->endOfMonth();

        $base = DB::table('listings as l')
            ->select(
                DB::raw("DATE_FORMAT(l.created_at, '%Y-%m') as month"),
                DB::raw('COUNT(*) as count')
            )
            ->whereBetween('l.created_at', [$from, $to]);

        if ($teamId) {
            $base->join('team_agents as ta', 'ta.agent_id', '=', 'l.agent_id')
                 ->where('ta.team_id', $teamId);
        }

        $byMonth = $base->groupBy('month')->pluck('count', 'month');

        // Backfill every month between $from and $to so the chart has no gaps
        $cursor = $from->copy()->startOfMonth();
        $end    = $to->copy()->startOfMonth();
        $series = [];
        while ($cursor->lte($end)) {
            $key = $cursor->format('Y-m');
            $series[] = [
                'month' => $key,
                'label' => $cursor->format('M Y'),
                'count' => (int) ($byMonth[$key] ?? 0),
            ];
            $cursor->addMonth();
        }

        return response()->json(['data' => $series]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'nullable|in:active,inactive,resigned',
            'logo' => 'nullable|string|max:255',
        ]);

        $team = Team::create($validated);

        return new TeamResource(
            $team->load(['leader.agent', 'teamAgents.agent'])
        );
    }

    public function show($id)
    {
        $team = Team::with(['leader.agent', 'teamAgents.agent'])->findOrFail($id);

        return new TeamResource($team);
    }

    public function update(Request $request, $id)
    {
        $team = Team::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:active,inactive,resigned',
            'logo' => 'nullable|string|max:255',
        ]);

        $team->update($validated);

        return new TeamResource(
            $team->load(['leader.agent', 'teamAgents.agent'])
        );
    }

    public function destroy($id)
    {
        $team = Team::findOrFail($id);
        $team->delete();

        return response()->json([
            'message' => 'Team deleted successfully.',
            'id' => $team->id,
        ]);
    }
}
