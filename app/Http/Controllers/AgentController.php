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
            'first_name'        => 'required|string|max:255',
            'middle_name'       => 'nullable|string|max:255',
            'last_name'         => 'required|string|max:255',
            'mobile_no'         => 'required|string|max:20',
            'whats_app_no'      => 'nullable|string|max:20',
            'address'           => 'nullable|string|max:500',
            'socials'           => 'nullable|array',
            'socials.facebook'  => 'nullable|string|max:255',
            'socials.instagram' => 'nullable|string|max:255',
            'socials.twitter'   => 'nullable|string|max:255',
            'socials.linkedin'  => 'nullable|string|max:255',
            'bio'               => 'nullable|string',
            'avatar'            => 'nullable|string|url',
            'geo_location'      => 'nullable|array:lat,lng',
            'user_id'           => 'nullable|exists:users,id', // ← admin can pass a target user
        ]);

        $user      = Auth::user();
        $role      = $user->role?->name;
        $targetId  = $user->id; // default to self

        if ($role === 'admin') {
            // Admin can pass user_id to update any agent, or defaults to themselves
            if (!empty($validated['user_id'])) {
                $targetId = $validated['user_id'];

                // Make sure target user is actually an agent (or admin)
                $targetUser = \App\Models\User::with('role')->findOrFail($targetId);
                $targetRole = $targetUser->role?->name;

                if (!in_array($targetRole, ['agent', 'admin'])) {
                    abort(403, 'Target user must be an agent or admin.');
                }
            }
        } elseif ($role === 'agent') {
            // Agent can only update their own profile
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

        return new AgentResource($agent);
    }
}
