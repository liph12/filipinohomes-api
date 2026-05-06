<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\LoginLog;
use App\Models\User;
use App\Services\Auth\GoogleTokenService;
use App\Services\LeuterioreRealty\LrApiService;
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

            $token = $this->getOrCreateToken($user);

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
                ]);

                return $user;
            });
        }

        $userInfo['user_id'] = $user->id;
        
        UserInfo::updateOrCreate(
            ['user_id' => $user->id],
            $userInfo
        );

        $token = $this->getOrCreateToken($user);

        // Sync team assignment from LR API if not yet in team_agents
        (new TeamSyncService())->syncForUser($user);

        $this->recordLogin($user, $request);

        return response()->json([
            'message' => 'Account created and login successful.',
            'token' => $token,
            'user' => $user,
        ]);
    }

    private function getOrCreateToken(User $user): string
    {
        if ($user->remember_token) {
            return $user->remember_token;
        }

        $token = $user->createToken('API Token')->plainTextToken;
        $user->remember_token = $token;
        $user->save();

        return $token;
    }

    private function recordLogin(User $user, Request $request): void
    {
        LoginLog::create([
            'user_id'      => $user->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
            'logged_in_at' => now(),
        ]);
    }
}
