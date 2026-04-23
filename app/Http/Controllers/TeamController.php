<?php

namespace App\Http\Controllers;

use App\Http\Resources\TeamResource;
use App\Http\Resources\TeamResourceCollection;
use App\Models\Team;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $query = Team::query()
            ->with(['leader.agent', 'teamAgents.agent']);

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
