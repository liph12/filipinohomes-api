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

class UserController extends Controller
{
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

    public function store(Request $request)
    {
        $data = $request->only(['name', 'email', 'password', 'role_id']);
        $exists = User::where('email', $data['email'])->first();
        if ($exists) {
            return response()->json([
                'message' => 'Email is already used.'
            ], 401);
        }
        $user = User::create($data);
        return new UserResource($user);
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
        $email = $request->email;
        $user = User::where('email', $email)->first();

        if(!$user)
        {
            return response()->json([
                'message' => 'Email address not found.'
            ], 403);
        }

        $otp = Str::upper(Str::random(6));
        $user->verification = $otp;
        $user->save();

        Mail::to($email)->send(new LoginOtpMailer($email, $otp, $user->name));

        return response()->json([
            'message' => 'Login OTP successfully sent!'
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
