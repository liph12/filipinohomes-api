<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LoginLog;
use App\Models\User;
use App\Models\DeviceToken;
use App\Support\DeviceLabel;
use App\Support\TokenIssuer;
use App\Http\Resources\UserResourceCollection;
use App\Http\Resources\UserResource;
use App\Services\User\LoginUserService;
use App\Services\AuditAuthService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use App\Mail\LoginOtpMailer;
use App\Mail\InquiryMailer;
use App\Mail\ContactUsMailer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Services\LeuterioreRealty\LrApiService;
use App\Models\Agent;
use Illuminate\Support\Facades\DB;
use App\Models\UserInfo;
use App\Models\Inquiry;
use App\Services\LeuterioreRealty\TeamSyncService;

class UserController extends Controller
{
    public function sendInquiry(Request $request)
    {
        $deviceId = $request->input('device_id');

        if (!$deviceId) {
            return response()->json([
                'message' => 'Device ID is required.'
            ], 400);
        }

        $cacheKey = "inquiry_attempts:{$deviceId}";
        $banKey   = "inquiry_ban:{$deviceId}";

        if (Cache::has($banKey)) {
            return response()->json([
                'message' => 'Too many requests. Try again after 5 minutes.'
            ], 429);
        }

        $attempts = Cache::get($cacheKey, 0);

        if ($attempts >= 5) {
            Cache::put($banKey, true, now()->addMinutes(5));

            return response()->json([
                'message' => 'You are temporarily blocked for 5 minutes.'
            ], 429);
        }

        Cache::put($cacheKey, $attempts + 1, now()->addMinute());

        $userInfo = $request->input('user_info');

        // Trim name/email before validation so leading/trailing whitespace
        // doesn't sneak past the validators or land in the database.
        $request->merge([
            'name'  => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name'),
            'email' => is_string($request->input('email')) ? trim($request->input('email')) : $request->input('email'),
        ]);

        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            // Strict email validation: rfc syntax + dns lookup (rejects
            // addresses like asd@d.c whose domain has no MX/A records) +
            // strict RFC 5321 mode. The `spoof` homoglyph check is
            // intentionally omitted — it requires PHP's Intl extension,
            // which isn't installed on the production server.
            'email'   => ['required', 'email:rfc,dns,strict', 'max:255'],
            'message' => 'required|string|max:5000',
            // Identifies which page submitted the form ('home_get_in_touch',
            // 'contact_page'). Optional — older clients still post without it,
            // and the blade falls back to a generic label.
            'source'  => 'nullable|string|max:64',
        ]);

        // Notify every admin — role_id=1. Matches MessageNotificationMailer's
        // admin lookup so the inquiry inbox stays in sync with admin team
        // membership instead of pointing at a single hardcoded mailbox.
        $adminEmails = User::where('role_id', 1)
            ->whereNotNull('email')
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($adminEmails)) {
            // Defensive — should never happen in prod, but a bad migration
            // could leave the admin role empty. Log and bail so we don't
            // silently swallow the inquiry.
            Log::warning('sendInquiry: no admin recipients found (role_id=1)');
        } else {
            // To: the shared public inbox (info@filipinohomes.com) so admins
            // don't see each other's emails exposed in the recipient header.
            // Actual delivery to every admin happens via BCC.
            // SMTP failures must not 500 the inquiry — Inquiry row below
            // is the real persistence; email is a notification.
            try {
                Mail::to(env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'))
                    ->bcc($adminEmails)
                    ->send(new InquiryMailer(
                        clientName:    $validated['name'],
                        clientEmail:   $validated['email'],
                        clientMessage: $validated['message'],
                        source:        $validated['source'] ?? null,
                    ));
            } catch (\Throwable $e) {
                Log::warning('Get In Touch inquiry email failed', [
                    'error' => $e->getMessage(),
                ]);
                app(\App\Services\AuditMailService::class)->recordFailure(
                    $e,
                    InquiryMailer::class,
                    $adminEmails,
                    'New inquiry from ' . ($validated['name'] ?? 'visitor'),
                );
            }
        }

        Inquiry::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'message' => $validated['message'],
            // Persist the source label so /admin/inquiries can filter by
            // which public form the submission came from
            // (home_get_in_touch / maintenance_page). Null for older
            // clients that don't post a source.
            'source' => $validated['source'] ?? null,
            'device' => $userInfo['device'],
            'country' => $userInfo['country'],
            'state' => $userInfo['state'],
            'city' => $userInfo['city']
        ]);

        return response()->json([
            'message' => 'Inquiry sent successfully!'
        ]);
    }

    /**
     * Handle the dedicated Contact Us form. Unlike sendInquiry (the generic
     * /inquiry endpoint), this accepts the richer fields the public Contact
     * Us page collects — phone, inquiry type, subject — and routes to the
     * dedicated emails.contact-us blade so admins see them rendered nicely.
     *
     * Shares the same per-device rate limit as sendInquiry to stop someone
     * spamming both endpoints from one machine.
     */
    public function sendContactUs(Request $request)
    {
        $deviceId = $request->input('device_id');

        if (!$deviceId) {
            return response()->json([
                'message' => 'Device ID is required.'
            ], 400);
        }

        $cacheKey = "inquiry_attempts:{$deviceId}";
        $banKey   = "inquiry_ban:{$deviceId}";

        if (Cache::has($banKey)) {
            return response()->json([
                'message' => 'Too many requests. Try again after 5 minutes.'
            ], 429);
        }

        $attempts = Cache::get($cacheKey, 0);

        if ($attempts >= 5) {
            Cache::put($banKey, true, now()->addMinutes(5));
            return response()->json([
                'message' => 'You are temporarily blocked for 5 minutes.'
            ], 429);
        }

        Cache::put($cacheKey, $attempts + 1, now()->addMinute());

        $userInfo = $request->input('user_info');

        $request->merge([
            'name'  => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name'),
            'email' => is_string($request->input('email')) ? trim($request->input('email')) : $request->input('email'),
        ]);

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            // See sendInquiry: rfc+dns+strict rejects junk like asd@d.c.
            // `spoof` is omitted because it needs the Intl extension.
            'email'       => ['required', 'email:rfc,dns,strict', 'max:255'],
            'message'     => 'required|string|max:5000',
            'phone'       => 'nullable|string|max:64',
            'inquiryType' => 'nullable|string|max:128',
            'subject'     => 'nullable|string|max:255',
        ]);

        // Same admin fan-out as sendInquiry — every admin (role_id=1) gets
        // the message so contact-form submissions don't sit in a single
        // mailbox.
        $adminEmails = User::where('role_id', 1)
            ->whereNotNull('email')
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($adminEmails)) {
            Log::warning('sendContactUs: no admin recipients found (role_id=1)');
        } else {
            // BCC pattern (mirrors sendInquiry above) — admin emails stay
            // hidden behind info@filipinohomes.com. Same SMTP-resilience
            // wrap so the persistence call below still runs even if the
            // mail send fails.
            try {
                Mail::to(env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'))
                    ->bcc($adminEmails)
                    ->send(new ContactUsMailer(
                        clientName:    $validated['name'],
                        clientEmail:   $validated['email'],
                        clientMessage: $validated['message'],
                        clientPhone:   $validated['phone']       ?? null,
                        inquiryType:   $validated['inquiryType'] ?? null,
                        clientSubject: $validated['subject']     ?? null,
                    ));
            } catch (\Throwable $e) {
                Log::warning('Contact Us email failed', [
                    'error' => $e->getMessage(),
                ]);
                app(\App\Services\AuditMailService::class)->recordFailure(
                    $e,
                    ContactUsMailer::class,
                    $adminEmails,
                    'Contact Us submission from ' . ($validated['name'] ?? 'visitor'),
                );
            }
        }

        // Persist via the same Inquiry table so submissions stay in one place.
        // Subject + type + phone are appended to message body for the model.
        $composedMessage = $validated['message'];
        $metaLines = array_filter([
            !empty($validated['inquiryType']) ? "Inquiry Type: {$validated['inquiryType']}" : null,
            !empty($validated['subject'])     ? "Subject: {$validated['subject']}"         : null,
            !empty($validated['phone'])       ? "Phone: {$validated['phone']}"             : null,
        ]);
        if (!empty($metaLines)) {
            $composedMessage = implode("\n", $metaLines) . "\n\n" . $composedMessage;
        }

        Inquiry::create([
            'name'    => $validated['name'],
            'email'   => $validated['email'],
            'message' => $composedMessage,
            // Hardcoded — this endpoint is exclusively the Contact Us
            // page; sendInquiry handles the home Get-In-Touch and
            // maintenance forms via their own `source` values.
            'source'  => 'contact_page',
            'device'  => $userInfo['device']  ?? null,
            'country' => $userInfo['country'] ?? null,
            'state'   => $userInfo['state']   ?? null,
            'city'    => $userInfo['city']    ?? null,
        ]);

        return response()->json([
            'message' => 'Message sent successfully!'
        ]);
    }

    public function registerWithOtp(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email'
        ]);

        $email = $validated['email'];
        $user = User::where('email', $email)->first();

        if ($user && $user->verification === 'verified') {
            return response()->json([
                'message' => 'Email is already registered.'
            ], 409);
        }

        if (!$user) {
            $user = User::create([
                'name' => Str::before($email, '@'),
                'email' => $email,
                'password' => Str::random(32),
                'role_id' => 3,
                'verification' => 'pending',
            ]);
        }

        $otp = Str::upper(Str::random(6));
        $user->verification = $otp;
        $user->save();

        try {
            Mail::to($email)->send(new LoginOtpMailer($email, $otp, $user->name));
        } catch (\Throwable $e) {
            Log::warning('OTP email failed', ['email' => $email, 'error' => $e->getMessage()]);
            app(\App\Services\AuditMailService::class)->recordFailure(
                $e,
                LoginOtpMailer::class,
                [$email],
                'OTP login code',
            );
        }

        return response()->json([
            'message' => 'Register OTP successfully sent!'
        ]);
    }

    public function registerRequestVerifyOtp(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string'
        ]);

        $verified = User::where([
            ['email', $validated['email']],
            ['verification', $validated['otp']]
        ])->first();

        if (!$verified) {
            return response()->json([
                'message' => 'Invalid one time pin.'
            ], 403);
        }

        // One token per login → each device can be logged out independently.
        // The token name labels the session in the user's "active devices" list.
        $token = TokenIssuer::fromRequest($verified, $request);

        $verified->verification = 'verified';
        $verified->email_verified_at = now();
        $verified->save();

        return response()->json([
            'user' => $verified,
            'token' => $token
        ]);
    }

    public function dashboardUsersByDate(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'date_start'  => 'nullable|date',
            'date_end'    => 'nullable|date|after_or_equal:date_start',
            'granularity' => 'nullable|in:day,month,year',
            'role'        => 'nullable|in:all,agent,client',
        ]);

        // role_id per RoleSeeder: admin=1, agent=2, client=3, editor=4.
        if ($request->user()->role_id !== 1) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $start = $validated['date_start'] ?? now()->startOfYear()->toDateString();
        $end   = $validated['date_end']   ?? now()->toDateString();
        $gran  = $validated['granularity'] ?? 'day';
        $role  = $validated['role'] ?? 'all';

        $roleIds = $role === 'agent' ? [2] : ($role === 'client' ? [3] : [2, 3]);

        $bucketExpr = match ($gran) {
            'year'  => "DATE_FORMAT(created_at, '%Y-01-01')",
            'month' => "DATE_FORMAT(created_at, '%Y-%m-01')",
            default => "DATE(created_at)",
        };

        $rows = User::query()
            ->selectRaw("$bucketExpr as bucket_start, SUM(role_id = 2) as agents, SUM(role_id = 3) as clients")
            ->whereIn('role_id', $roleIds)
            ->whereBetween('created_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->groupBy('bucket_start')
            ->orderBy('bucket_start')
            ->get();

        $totals = ['agent' => 0, 'client' => 0, 'total' => 0];
        $data = $rows->map(function ($row) use ($gran, &$totals) {
            $agent  = (int) $row->agents;
            $client = (int) $row->clients;
            $totals['agent']  += $agent;
            $totals['client'] += $client;
            $totals['total']  += $agent + $client;

            return [
                'bucket_start' => $row->bucket_start,
                'bucket_label' => match ($gran) {
                    'year'  => substr($row->bucket_start, 0, 4),
                    'month' => date('M Y', strtotime($row->bucket_start)),
                    default => date('M j, Y', strtotime($row->bucket_start)),
                },
                'counts' => ['agent' => $agent, 'client' => $client, 'total' => $agent + $client],
            ];
        });

        return response()->json([
            'data'   => $data,
            'totals' => $totals,
            'meta'   => ['granularity' => $gran, 'from' => $start, 'to' => $end, 'role' => $role],
        ]);
    }

    public function index(Request $request)
    {
        $query = User::query()
            ->latest()
            ->where('created_at', '>=', now()->subDays(10));

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->query('per_page', 10);

        return new UserResourceCollection(
            $query->paginate($perPage)
        );
    }

    public function userSettings(Request $request)
    {
        $query = User::query()->latest();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->whereHas('role', fn ($q) => $q->where('name', $role));
        }

        $perPage = (int) $request->query('per_page', 10);

        return new UserResourceCollection(
            $query->paginate($perPage)
        );
    }

    public function show($id)
    {
        return new UserResource(
            User::find($id)
        );
    }

    public function profile()
    {
        return new UserResource(
            User::find(Auth::user()->id)
        );
    }

    public function update($id, Request $request)
    {
        $user = User::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            // 'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            // 'password' => 'sometimes|string|min:6',
            'avatar'            => 'nullable|string|url',
            'mobile_no'         => 'nullable|string|max:20',
            'role_id' => 'sometimes|exists:roles,id',
        ]);
        $user->update($validated);
        return new UserResource($user);
    }

    public function destroy($id)
    {
        User::findOrFail($id)->delete();
    }

    public function login(Request $request, LoginUserService $loginUserService)
    {
        $credentials = $request->validate([
            'email'     => 'required|email',
            'password'  => 'required|string'
        ]);

        try {
            $result = $loginUserService->execute(
                $credentials,
                $request->ip(),
                $request->userAgent(),
                $request->input('device_name'),
            );
            // Surface in /admin/activity-logs alongside the
            // existing LoginLog write. New 'auth' category.
            if (isset($result['user']) && $result['user'] instanceof User) {
                app(AuditAuthService::class)->recordLogin(
                    $result['user'],
                    'password',
                    $request,
                );
            }
            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], $e->getCode() ?: 401);
        }
    }

    public function authWithOtp(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            // First-touch acquisition id from the web's localStorage (fh_vid).
            'visitor_id' => 'nullable|string|max:64',
        ]);

        $email = $validated['email'];
        $user = User::where('email', $email)->first();

        if (!$user) {
            $lrService = new LrApiService();
            $lrData = $lrService->fetchAgentByEmail($email);

            if (!$lrData) {
                $user = User::create([
                    'name' => Str::before($email, '@'),
                    'email' => $email,
                    'password' => Str::random(32),
                    'role_id' => 3,
                    'verification' => 'pending',
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
                $nameParts = $lrService->parseName($lrData['name'] ?? $email);

                $user = DB::transaction(function () use ($email, $nameParts, $lrData, $fhRoleId) {
                    $user = User::create([
                        'name' => $lrData['name'] ?? Str::before($email, '@'),
                        'email' => $email,
                        'password' => Str::random(32),
                        'role_id' => $fhRoleId,
                        'verification' => 'pending',
                    ]);

                    // They signed in with their LR email, so link it on the new
                    // agent — unless it's already linked to another agent
                    // (lr_email is unique), in which case leave it null.
                    $lrEmailAvailable = !Agent::where('lr_email', $email)->exists();

                    Agent::create([
                        'user_id' => $user->id,
                        'first_name' => $nameParts['first_name'],
                        'middle_name' => $nameParts['middle_name'],
                        'last_name' => $nameParts['last_name'],
                        'mobile_no' => $lrData['mobile_no'] ?? null,
                        'lr_email' => $lrEmailAvailable ? $email : null,
                    ]);

                    return $user;
                });
            }
        }

        $otp = Str::upper(Str::random(6));
        $user->verification = $otp;
        $user->save();

        try {
            Mail::to($email)->send(new LoginOtpMailer($email, $otp, $user->name));
        } catch (\Throwable $e) {
            Log::warning('OTP email failed', ['email' => $email, 'error' => $e->getMessage()]);
            app(\App\Services\AuditMailService::class)->recordFailure(
                $e,
                LoginOtpMailer::class,
                [$email],
                'OTP login code',
            );
        }

        return response()->json([
            'message' => 'Login OTP successfully sent!',
        ]);
    }

    public function authRequestVerifyOtp(Request $request)
    {
        $userInfo = $request->input('user_info');
        $verified = User::where([['email', $request->email], ['verification', $request->otp]])->first();

        if (!$verified) {
            return response()->json([
                'message' => 'Invalid one time pin.'
            ], 403);
        }

        $userInfo['user_id'] = $verified->id;

        UserInfo::updateOrCreate(
            ['user_id' => $verified->id], // condition
            $userInfo
        );

        // One token per login → each device can be logged out independently.
        // The token name labels the session in the user's "active devices" list.
        $token = TokenIssuer::fromRequest($verified, $request);

        $verified->verification = "verified";
        $verified->save();

        // Sync team assignment from LR API if not yet in team_agents
        (new TeamSyncService())->syncForUser($verified);

        // Backfill blank LR fields (lr_email, birthdate, gender) from LR.
        // The v2 detail call is slow (~11s), so defer it to run AFTER the
        // login response is sent — login stays instant; the fields fill a
        // moment later. Only does anything when a field is still blank.
        defer(fn () => app(\App\Services\LeuterioreRealty\LrAgentBackfillService::class)
            ->backfill($verified->loadMissing('agent')));

        LoginLog::create([
            'user_id'      => $verified->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
            'logged_in_at' => now(),
        ]);

        app(AuditAuthService::class)->recordLogin($verified, 'otp', $request);

        return response()->json([
            'user' => $verified,
            'token' => $token
        ]);
    }

    public function devLogin(Request $request)
    {
        $userInfo = $request->input('user_info');
        if (app()->environment() !== 'local') {
            abort(404);
        }

        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $email = $validated['email'];
        $user = User::where('email', $email)->first();

        if (!$user) {
            $user = User::create([
                'name' => Str::before($email, '@'),
                'email' => $email,
                'password' => Str::random(32),
                'role_id' => 2,
                'verification' => 'verified',
            ]);
        }

        $userInfo['user_id'] = $user->id;
        
        UserInfo::updateOrCreate(
            ['user_id' => $user->id],
            $userInfo
        );

        // One token per login → each device can be logged out independently.
        $token = TokenIssuer::fromRequest($user, $request);

        // Sync team assignment from LR API if not yet in team_agents
        (new TeamSyncService())->syncForUser($user);

        // Backfill blank LR fields (lr_email, birthdate, gender) after response.
        defer(fn () => app(\App\Services\LeuterioreRealty\LrAgentBackfillService::class)
            ->backfill($user->loadMissing('agent')));

        LoginLog::create([
            'user_id'      => $user->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
            'logged_in_at' => now(),
        ]);

        app(AuditAuthService::class)->recordLogin($user, 'dev', $request);

        return response()->json([
            'message' => 'Dev login successful.',
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        // Stop pushing to this device once it signs out. The client sends the
        // Expo token it registered; remove only that row so the user's other
        // devices keep receiving notifications.
        if ($expoToken = $request->input('expo_token')) {
            DeviceToken::where('user_id', $user->id)
                ->where('expo_token', $expoToken)
                ->delete();
        }

        // Revoke only this device's token (per-device logout).
        $user->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ], 200);
    }

    public function logoutAll(Request $request)
    {
        $user = $request->user();

        // Revoke every session and stop pushing to every device.
        $user->deviceTokens()->delete();
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Logged out of all devices',
        ], 200);
    }

    public function authenticate()
    {
        $user = Auth::user();
        $_user = User::find($user->id);
        $_user->active_at = now();
        $_user->last_online_at = now();
        $_user->save();

        return response()->json(new UserResource($user));
    }

    public function sessionPing(Request $request)
    {
        // Bump `last_online_at` only. The frontend calls this every
        // 60s while the tab is visible, so any side effect here
        // multiplies by every logged-in user. LoginLog rows belong
        // to actual `authenticate()` calls — not heartbeats.
        User::where('id', $request->user()->id)
            ->update(['last_online_at' => now()]);

        return response()->json(['ok' => true]);
    }
    
    public function getClients()
    {
        $users = User::whereHas('userInfo')->with('userInfo')->client()->get();

        return response()->json($users);
    }

    public function searchUsers(Request $request)
    {
        $search = $request->query('q', '');
        if (strlen($search) < 2) {
            return response()->json([]);
        }

        $currentUserId = Auth::id();

        $users = User::where('id', '!=', $currentUserId)
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->select('id', 'name', 'email', 'avatar', 'role_id', 'active_at')
            ->with('role:id,name')
            ->limit(10)
            ->get();

        return response()->json($users);
    }
}
