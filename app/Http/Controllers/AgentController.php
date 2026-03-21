<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Http\Resources\AgentResourceCollection;
use App\Http\Resources\AgentResource;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function index()
    {
        return new AgentResourceCollection(
            Agent::with('user')->withCount('listings')->orderByDesc('listings_count')->paginate(10)
        );
    }

    public function show($id)
    {
        $agent = Agent::with('user')
            ->withCount('listings')
            ->findOrFail($id);
 
        $agent->setRelation('listings', $agent->listings()->paginate(10));
 
        return new AgentResource($agent);
    }

    public function profile()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $agent = Agent::where('user_id', $user->id)
            ->with('user.role')
            ->first();

        if (!$agent) {
            return new UserResource($user->load('role'));
        }

        return new AgentResource($agent);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name'    => 'required|string|max:255',
            'middle_name'   => 'nullable|string|max:255',
            'last_name'     => 'required|string|max:255',
            'mobile_no'     => 'required|string|max:20',
            'whats_app_no'  => 'nullable|string|max:20',
            'address'       => 'nullable|string|max:500',
            'socials'       => 'nullable|array',
            'bio'           => 'nullable|string',
            'avatar'        => 'nullable|string',
            'geo_location'  => 'nullable|string',
        ]);

        $user = Auth::user();
        $role = $user->role?->name ?? '';
        $userId = $user->id;

        // Only allow agent role to create profile
        if ($role !== 'agent') {
            abort(403, 'Only agents can create agent profile.');
        }

        // Check if agent profile already exists
        if (Agent::where('user_id', $userId)->exists()) {
            abort(409, 'Agent profile already exists.');
        }

        $validated['user_id'] = $userId;
        $agent = Agent::create($validated);
        return new AgentResource($agent);
    }
}
