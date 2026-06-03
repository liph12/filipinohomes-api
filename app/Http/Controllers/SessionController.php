<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use App\Support\GeoLocation;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    /**
     * Active login sessions for the authenticated user — one row per
     * personal_access_token. Powers the "Manage devices" screen so the user
     * can see every device/browser signed into their account and sign out
     * any of them.
     */
    public function index(Request $request)
    {
        $currentId = $request->user()->currentAccessToken()->id;

        $sessions = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($token) => [
                'id'           => $token->id,
                'name'         => $token->name,
                'ip_address'   => $token->ip_address,
                'location'     => GeoLocation::resolve($token->ip_address),
                'last_used_at' => $token->last_used_at,
                'created_at'   => $token->created_at,
                'current'      => $token->id === $currentId,
            ]);

        return response()->json(['data' => $sessions]);
    }

    /**
     * Revoke a specific session and silence the device it belongs to.
     * Scoped to the caller's own tokens so a user can't revoke someone else's.
     */
    public function destroy(Request $request, $id)
    {
        $token = $request->user()->tokens()->find($id);

        if (!$token) {
            return response()->json(['message' => 'Session not found'], 404);
        }

        // Stop pushing to the device this session registered.
        DeviceToken::where('personal_access_token_id', $token->id)->delete();

        $token->delete();

        return response()->json(['message' => 'Session revoked']);
    }
}
