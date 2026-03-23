<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;

class MaintenanceController extends Controller
{
    public function status()
    {
        return response()->json([
            'maintenance' => Setting::get('maintenance_mode') === 'true'
        ]);
    }

    public function toggle(Request $request)
    {
        if (Auth::user()->role->name !== 'admin') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate(['maintenance' => 'required|boolean']);
        Setting::set('maintenance_mode', $request->boolean('maintenance') ? 'true' : 'false');

        return response()->json([
            'maintenance' => $request->boolean('maintenance'),
            'message' => 'Maintenance mode updated.'
        ]);
    }
}
