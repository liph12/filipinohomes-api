<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\Agent;
use App\Models\User;
use App\Services\AuditAuthService;
use App\Support\Impersonation;
use Illuminate\Http\Request;

/**
 * Admin "login as agent" (impersonation).
 *
 * An admin can't use an agent's real credentials (OTP / Google are personal),
 * so with admin authority we mint a fresh Sanctum token for the agent's user
 * and hand it to the frontend, which swaps its session into it. The minted
 * token is NAME-marked (see App\Support\Impersonation) so the presence-writing
 * endpoints keep the agent offline to the public, and every start/stop is
 * recorded in Activity Logs for traceability.
 */
class ImpersonationController extends Controller
{
    /** Hours an impersonation token stays valid before it must be re-issued. */
    private const TOKEN_TTL_HOURS = 4;

    /**
     * Admin-only: issue an impersonation token for {agent}.
     * Returns the same { token, user } shape as the login endpoints.
     */
    public function start(Request $request, Agent $agent)
    {
        $admin = $request->user();
        if ($admin->role?->name !== 'admin') {
            abort(403, 'Only administrators can log in as an agent.');
        }

        $agentUser = $agent->user;
        if (!$agentUser) {
            abort(422, 'This agent has no linked user account.');
        }
        if ($agentUser->role?->name !== 'agent') {
            abort(422, 'Only agent accounts can be impersonated.');
        }

        // Mint a per-device Sanctum token for the agent, name-marked so
        // presence writes recognise it. Stamp the originating IP and a short
        // expiry as a safety net.
        $newToken = $agentUser->createToken(Impersonation::tokenName($admin->id));
        $newToken->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'expires_at' => now()->addHours(self::TOKEN_TTL_HOURS),
        ])->save();

        // Traceability — who impersonated whom (actor = the admin).
        app(AuditAuthService::class)->recordImpersonation($admin, $agentUser, $request);

        return response()->json([
            'token' => $newToken->plainTextToken,
            'user'  => new UserResource($agentUser->load('role', 'agent')),
        ]);
    }

    /**
     * Called by the impersonated session on "Return to admin": revoke the
     * impersonation token so it doesn't linger. No-op for a normal session.
     */
    public function stop(Request $request)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (Impersonation::isImpersonated($user)) {
            $adminId = Impersonation::adminIdFromTokenName($token->name);
            $admin = $adminId ? User::find($adminId) : null;
            if ($admin) {
                app(AuditAuthService::class)
                    ->recordReturnFromImpersonation($admin, $user, $request);
            }
            $token->delete();
        }

        return response()->json(['ok' => true]);
    }
}
