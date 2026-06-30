<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\LoginLog;
use App\Models\User;
use App\Services\Auth\GoogleTokenService;
use App\Services\LeuterioreRealty\LrApiService;
use App\Support\TokenIssuer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\UserInfo;
use App\Services\LeuterioreRealty\TeamSyncService;

class GoogleAuthController extends Controller
{
    public function authenticate(Request $request)
    {
        $request->validate([
            'access_token' => 'required|string',
        ]);

        $googleService = new GoogleTokenService();
        $googleUser = $googleService->verify($request->access_token);
        $userInfo = $request->input('user_info');

        if (!$googleUser) {
            return response()->json([
                'message' => 'Invalid Google token. Please try again.',
            ], 401);
        }

        $user = User::where('email', $googleUser['email'])
            ->orWhere('google_id', $googleUser['google_id'])
            ->first();

        if ($user) {
            if (!$user->google_id) {
                $user->google_id = $googleUser['google_id'];
            }
            if (!$user->avatar && $googleUser['avatar']) {
                $user->avatar = $googleUser['avatar'];
            }
            $user->save();

            $token = $this->issueToken($user, $request);

            // Sync team assignment from LR API if not yet in team_agents
            (new TeamSyncService())->syncForUser($user);

            $this->recordLogin($user, $request);

            return response()->json([
                'message' => 'Login successful.',
                'token' => $token,
                'user' => $user,
            ]);
        }

        $lrService = new LrApiService();
        $lrData = $lrService->fetchAgentByEmail($googleUser['email']);

        if (!$lrData) {
            $user = User::create([
                'name' => $googleUser['name'],
                'email' => $googleUser['email'],
                'google_id' => $googleUser['google_id'],
                'avatar' => $googleUser['avatar'],
                'password' => Str::random(32),
                'role_id' => 3,
                'verification' => 'verified',
                'visitor_id' => $request->input('visitor_id'),
            ]);
        } else {
            if (!$lrService->isAllowedRole($lrData)) {
                return response()->json([
                    'message' => 'Your role is not authorized to access this platform.',
                ], 403);
            }

            if ($lrService->requiresFireCheck($lrData) && !$lrService->hasRequiredFireCertificates($lrData)) {
                return response()->json([
                    'message' => 'You need to complete at least 3 FIRE training certificates before you can sign in. Please complete your FIRE training first.',
                ], 403);
            }

            $fhRoleId = $lrService->mapToFhRoleId($lrData);
            $nameParts = $lrService->parseName($lrData['name'] ?? $googleUser['name']);

            $user = DB::transaction(function () use ($googleUser, $lrData, $nameParts, $fhRoleId) {
                $user = User::create([
                    'name' => $lrData['name'] ?? $googleUser['name'],
                    'email' => $googleUser['email'],
                    'google_id' => $googleUser['google_id'],
                    'avatar' => $googleUser['avatar'],
                    'password' => Str::random(32),
                    'role_id' => $fhRoleId,
                    'verification' => 'verified',
                ]);

                Agent::create([
                    'user_id' => $user->id,
                    'first_name' => $nameParts['first_name'],
                    'middle_name' => $nameParts['middle_name'],
                    'last_name' => $nameParts['last_name'],
                    'mobile_no' => $lrData['mobile_no'] ?? null,
                    // LR `state` → FH office region (drives Secretary scoping).
                    'lr_state' => $lrData['state'] ?? null,
                    'region' => \App\Support\OfficeRegionMap::regionOf($lrData['state'] ?? null),
                ]);

                return $user;
            });
        }

        $userInfo['user_id'] = $user->id;
        
        UserInfo::updateOrCreate(
            ['user_id' => $user->id],
            $userInfo
        );

        $token = $this->issueToken($user, $request);

        // Sync team assignment from LR API if not yet in team_agents
        (new TeamSyncService())->syncForUser($user);

        $this->recordLogin($user, $request);

        return response()->json([
            'message' => 'Account created and login successful.',
            'token' => $token,
            'user' => $user,
        ]);
    }

    private function issueToken(User $user, Request $request): string
    {
        // One token per login → each device can be logged out independently.
        // The token name labels the session in the user's "active devices" list.
        return TokenIssuer::fromRequest($user, $request);
    }

    private function recordLogin(User $user, Request $request): void
    {
        LoginLog::create([
            'user_id'      => $user->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
            'logged_in_at' => now(),
        ]);

        // Surface in /admin/activity-logs alongside the LoginLog row.
        app(\App\Services\AuditAuthService::class)
            ->recordLogin($user, 'google', $request);

        // Backfill blank LR fields (lr_email, birthdate, gender) after response.
        defer(fn () => app(\App\Services\LeuterioreRealty\LrAgentBackfillService::class)
            ->backfill($user->loadMissing('agent')));
    }
}
