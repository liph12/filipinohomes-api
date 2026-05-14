<?php

namespace App\Http\Controllers;

use App\Http\Resources\TeamAgentResource;
use App\Http\Resources\TeamAgentResourceCollection;
use App\Models\TeamAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamAgentController extends Controller
{
    public function index(Request $request)
    {
        $query = TeamAgent::query()
            ->select([
                'team_agents.*',
                DB::raw("(SELECT COUNT(*) FROM login_logs ll
                          JOIN agents a ON a.id = team_agents.agent_id
                          WHERE ll.user_id = a.user_id) as agent_login_count"),
                DB::raw("(SELECT COUNT(*) FROM listings l
                          WHERE l.agent_id = team_agents.agent_id) as agent_listings_count"),
                DB::raw("(SELECT COUNT(*) FROM conversations c
                          JOIN agents a ON a.id = team_agents.agent_id
                          WHERE c.agent_user_id = a.user_id) as agent_inquiries_count"),
            ])
            ->with(['agent.user', 'team']);

        if ($teamId = $request->input('team_id')) {
            $query->where('team_id', $teamId);
        }

        if ($agentId = $request->input('agent_id')) {
            $query->where('agent_id', $agentId);
        }

        if (($isLeader = $request->input('is_leader')) !== null && $isLeader !== '') {
            $query->where('is_leader', filter_var($isLeader, FILTER_VALIDATE_BOOLEAN));
        }

        return new TeamAgentResourceCollection(
            $query->orderBy('team_id')->orderBy('id')->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'team_id' => 'required|integer|exists:teams,id',
            'agent_id' => 'required|integer|exists:agents,id',
            'is_leader' => 'nullable|boolean',
            'status' => 'nullable|in:active,inactive',
        ]);

        $teamAgent = DB::transaction(function () use ($validated) {
            $teamAgent = TeamAgent::create($validated);

            if ($teamAgent->is_leader) {
                TeamAgent::where('team_id', $teamAgent->team_id)
                    ->where('id', '!=', $teamAgent->id)
                    ->update(['is_leader' => false]);
            }

            return $teamAgent;
        });

        return new TeamAgentResource(
            $teamAgent->load(['agent.user', 'team'])
        );
    }

    public function show($id)
    {
        $teamAgent = TeamAgent::with(['agent.user', 'team'])->findOrFail($id);

        return new TeamAgentResource($teamAgent);
    }

    public function update(Request $request, $id)
    {
        $teamAgent = TeamAgent::findOrFail($id);

        $validated = $request->validate([
            'team_id' => 'sometimes|integer|exists:teams,id',
            'agent_id' => 'sometimes|integer|exists:agents,id',
            'is_leader' => 'sometimes|boolean',
            'status' => 'sometimes|in:active,inactive',
        ]);

        DB::transaction(function () use ($teamAgent, $validated) {
            $teamAgent->update($validated);

            if ($teamAgent->is_leader) {
                TeamAgent::where('team_id', $teamAgent->team_id)
                    ->where('id', '!=', $teamAgent->id)
                    ->update(['is_leader' => false]);
            }
        });

        return new TeamAgentResource(
            $teamAgent->fresh()->load(['agent.user', 'team'])
        );
    }

    public function destroy($id)
    {
        $teamAgent = TeamAgent::findOrFail($id);
        $teamAgent->delete();

        return response()->json([
            'message' => 'Team member deleted successfully.',
            'id' => $teamAgent->id,
        ]);
    }
}
