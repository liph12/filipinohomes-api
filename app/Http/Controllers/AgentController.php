<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Http\Resources\AgentResourceCollection;
use App\Http\Resources\AgentResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function index()
    {
        $agents = Agent::with('user')->get();
        return new AgentResourceCollection($agents);
    }

    // GET /agents/{id}
    public function show($id)
    {
        $profile = Agent::with('user')->findOrFail($id);
        return new AgentResource($profile);
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

        $userId = Auth::user()->id;
        $validated['user_id']= $userId;

        $profile = Agent::updateOrCreate(['user_id' => $userId], $validated);

        return new AgentResource($profile);
    }
}
