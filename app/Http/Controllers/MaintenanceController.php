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

        $request->validate([
            'maintenance'        => 'sometimes|boolean',
            'maintenance_mobile' => 'sometimes|boolean',
            'message'            => 'nullable|string|max:1000',
            'force_update'       => 'sometimes|boolean',
            'min_app_version'    => 'nullable|string|max:32',
            'update_message'     => 'nullable|string|max:1000',
        ]);

        // Web maintenance, mobile maintenance and the forced-update gate are all
        // independent flags. Persist each only when the caller sends it, so the
        // existing web toggle (which posts just `maintenance`) keeps controlling
        // the website unchanged.
        if ($request->has('maintenance')) {
            Setting::set('maintenance_mode', $request->boolean('maintenance') ? 'true' : 'false');
        }
        if ($request->has('maintenance_mobile')) {
            Setting::set('maintenance_mode_mobile', $request->boolean('maintenance_mobile') ? 'true' : 'false');
        }
        if ($request->has('message')) {
            Setting::set('maintenance_message', (string) $request->input('message', ''));
        }
        if ($request->has('force_update')) {
            Setting::set('force_update_mobile', $request->boolean('force_update') ? 'true' : 'false');
        }
        if ($request->has('min_app_version')) {
            Setting::set('min_app_version', (string) $request->input('min_app_version', ''));
        }
        if ($request->has('update_message')) {
            Setting::set('update_message', (string) $request->input('update_message', ''));
        }
        return response()->json([
            'maintenance'        => Setting::get('maintenance_mode') === 'true',
            'maintenance_mobile' => Setting::get('maintenance_mode_mobile') === 'true',
            'force_update'       => Setting::get('force_update_mobile') === 'true',
            'message'            => 'Maintenance mode updated.',
        ]);
    }
}
