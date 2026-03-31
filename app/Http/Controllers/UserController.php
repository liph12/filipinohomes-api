<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Http\Resources\UserResourceCollection;
use App\Http\Resources\UserResource;
use App\Services\User\LoginUserService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Mail\LoginOtpMailer;
use App\Mail\InquiryMailer;
use Illuminate\Support\Facades\Mail;
use App\Services\LeuterioreRealty\LrApiService;
use App\Models\Agent;
use Illuminate\Support\Facades\DB;
use App\Models\UserInfo;

class UserController extends Controller
{
    public function sendInquiry(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email',
            'message' => 'required|string|max:5000',
        ]);
            
        Mail::to('marklawrince730@gmail.com')->send(new InquiryMailer(
            $validated['name'],
            $validated['email'],
            $validated['message'],
            [
                ['email' => env('MAIL_CC')],
            ]
        ));

        return response()->json([
            'message' => 'Inquiry sent successfully!'
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

        Mail::to($email)->send(new LoginOtpMailer($email, $otp, $user->name));

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

        if (!$verified->remember_token) {
            $token = $verified->createToken('API Token')->plainTextToken;
            $verified->remember_token = $token;
        } else {
            $token = $verified->remember_token;
        }

        $verified->verification = 'verified';
        $verified->email_verified_at = now();
        $verified->save();

        return response()->json([
            'user' => $verified,
            'token' => $token
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
            $result = $loginUserService->execute($credentials);
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
        }

        $otp = Str::upper(Str::random(6));
        $user->verification = $otp;
        $user->save();

        Mail::to($email)->send(new LoginOtpMailer($email, $otp, $user->name));

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

        if (!$verified->remember_token) {
            $token = $verified->createToken('API Token')->plainTextToken;
            $verified->remember_token = $token;
        } else {
            $token = $verified->remember_token;
        }

        $verified->verification = "verified";
        $verified->save();

        return response()->json([
            'user' => $verified,
            'token' => $token
        ]);
    }

    public function devLogin(Request $request)
    {
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

        if (!$user->remember_token) {
            $token = $user->createToken('API Token')->plainTextToken;
            $user->remember_token = $token;
            $user->save();
        } else {
            $token = $user->remember_token;
        }

        return response()->json([
            'message' => 'Dev login successful.',
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        $user->currentAccessToken()->delete();

        $user->forceFill([
            'remember_token' => null,
        ])->save();

        return response()->json([
            'message' => 'Logged out successfully',
        ], 200);
    }

    public function authenticate()
    {
        $user = Auth::user();
        return response()->json(new UserResource($user));
    }
}
