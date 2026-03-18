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
use Illuminate\Support\Facades\Mail;
use App\Services\LeuterioreRealty\LrApiService;
use App\Models\Agent;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
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

    public function index()
    {
        return new UserResourceCollection(
            User::get()
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
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:6',
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
                return response()->json([
                    'message' => 'This email is not registered with Leuterio Realty. Please contact your administrator.',
                ], 404);
            }

            if (!$lrService->hasRequiredFireCertificates($lrData)) {
                return response()->json([
                    'message' => 'You need to complete at least 3 FIRE training certificates before you can sign in. Please complete your FIRE training first.',
                ], 403);
            }

            $nameParts = $lrService->parseName($lrData['name'] ?? $email);

            $user = DB::transaction(function () use ($email, $nameParts, $lrData) {
                $user = User::create([
                    'name' => $lrData['name'] ?? Str::before($email, '@'),
                    'email' => $email,
                    'password' => Str::random(32),
                    'role_id' => 2,
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
        $verified = User::where([['email', $request->email], ['verification', $request->otp]])->first();

        if(!$verified)
        {
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
}
