<?php

namespace App\Http\Controllers;

use App\Http\Resources\TeamResource;
use App\Http\Resources\TeamResourceCollection;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $agentStatsSql = [
            DB::raw("(SELECT COUNT(*) FROM login_logs ll
                      JOIN agents a ON a.id = team_agents.agent_id
                      WHERE ll.user_id = a.user_id) as agent_login_count"),
            DB::raw("(SELECT COUNT(*) FROM listings l
                      WHERE l.agent_id = team_agents.agent_id) as agent_listings_count"),
            DB::raw("(SELECT COUNT(*) FROM conversations c
                      JOIN agents a ON a.id = team_agents.agent_id
                      WHERE c.agent_user_id = a.user_id) as agent_inquiries_count"),
        ];

        $query = Team::query()
            ->select([
                'teams.*',
                DB::raw("(SELECT COUNT(*) FROM login_logs ll
                          INNER JOIN team_agents ta ON ta.team_id = teams.id
                          INNER JOIN agents a ON a.id = ta.agent_id
                          WHERE ll.user_id = a.user_id) as login_count"),
                DB::raw("(SELECT COUNT(*) FROM listings l
                          INNER JOIN team_agents ta ON ta.team_id = teams.id
                          WHERE l.agent_id = ta.agent_id) as listings_count"),
                DB::raw("(SELECT COUNT(*) FROM listings l
                          INNER JOIN team_agents ta ON ta.team_id = teams.id
                          INNER JOIN properties p ON p.id = l.property_id
                          WHERE l.agent_id = ta.agent_id
                            AND p.status IN ('sold','rented','leased')) as transactions_count"),
                DB::raw("(SELECT COUNT(*) FROM conversations c
                          INNER JOIN team_agents ta ON ta.team_id = teams.id
                          INNER JOIN agents a ON a.id = ta.agent_id
                          WHERE c.agent_user_id = a.user_id) as inquiries_count"),
            ])
            ->with([
                'leader.agent',
                'teamAgents' => function ($q) use ($agentStatsSql) {
                    $q->select(array_merge(['team_agents.*'], $agentStatsSql))
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
