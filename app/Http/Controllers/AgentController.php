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
        return new AgentResourceCollection(
            Agent::with('user')->get()
        );
    }

    public function profile()
    {
        return new AgentResource( 
            Agent::find(Auth::user()->id)
        );
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
        $validated['user_id'] = $userId;

        return new AgentResource(
            Agent::updateOrCreate(['user_id' => $userId], $validated)
        );
    }
}
