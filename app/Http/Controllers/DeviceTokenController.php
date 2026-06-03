<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /**
     * Register (or refresh) the Expo push token for the calling device.
     * Upsert keyed on expo_token: the same device re-registering updates its
     * row, and a device that switched accounts gets reassigned to the new
     * user. One row per device → every device a user signs into gets pushes.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'expo_token' => 'required|string|max:255',
            'platform'   => 'nullable|string|max:16',
        ]);

        $token = DeviceToken::updateOrCreate(
            ['expo_token' => $validated['expo_token']],
            [
                'user_id'      => $request->user()->id,
                'platform'     => $validated['platform'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return response()->json(['data' => $token], 201);
    }
}
