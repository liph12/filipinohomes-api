<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Blocks inactive/resigned agents from acting on the API while still letting
 * them log in and see WHY they're blocked. The allowlist keeps the
 * account-blocked modal working (profile reads power AuthGuard + the modal),
 * lets the agent log out, and lets an impersonating admin exit the session.
 * Everything else returns 403.
 *
 * Runs after auth:sanctum. Admins are exempt so a stray status on an admin's
 * own agent row can never lock the admins out.
 */
class EnsureAgentActive
{
    private const BLOCKED_STATUSES = ['inactive', 'resigned'];

    /** [method, path] pairs a blocked agent may still call. */
    private const ALLOWED = [
        ['GET',  'api/agent/profile'],
        ['GET',  'api/user/profile'],
        ['GET',  'api/authenticate'],
        ['POST', 'api/logout'],
        ['POST', 'api/logout-all'],
        ['POST', 'api/admin/impersonate/stop'],
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (
            $user
            && $user->role?->name !== 'admin'
            && in_array($user->agent?->status, self::BLOCKED_STATUSES, true)
            && ! $this->isAllowed($request)
        ) {
            $status = $user->agent->status;

            return response()->json([
                'message' => "Your account has been marked as {$status}. Please contact the administrator.",
                'account_status' => $status,
            ], 403);
        }

        return $next($request);
    }

    private function isAllowed(Request $request): bool
    {
        foreach (self::ALLOWED as [$method, $path]) {
            if ($request->isMethod($method) && $request->is($path)) {
                return true;
            }
        }

        return false;
    }
}
