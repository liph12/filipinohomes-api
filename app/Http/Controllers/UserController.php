<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Http\Resources\UserResourceCollection;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Hash;
use App\Services\User\LoginUserService;
class UserController extends Controller
{

    public function index()
    {
        $users = User::get();

        return new UserResourceCollection($users);
    }

    public function show($id)
    {
        $user = User::find($id);

        return new UserResource($user);
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
        // Find the user or fail with 404
        $user = User::findOrFail($id);

        $user->delete();
        return response()->json([
            'message' => 'User deleted successfully',
            'id' => $id
        ], 200);
    }

    public function login(Request $request, LoginUserService $loginUserService)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string'
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

    // public function login(Request $request)
    // {
    //     // Validate email and password
    //     $credentials = $request->validate([
    //         'email' => 'required|email',
    //         'password' => 'required|string'
    //     ]);

    //     // Find user by email
    //     $user = User::where('email', $credentials['email'])->first();

    //     if (!$user) {
    //         // Email does not exist
    //         return response()->json([
    //             'message' => 'Email not found'
    //         ], 404);
    //     }

    //     if (!Hash::check($credentials['password'], $user->password)) {
    //         // Password is incorrect
    //         return response()->json([
    //             'message' => 'Incorrect password'
    //         ], 401);
    //     }

    //     if ($user->remember_token == null) {
    //         $token = $user->createToken('API Token')->plainTextToken;
    //         $user->remember_token = $token;
    //         $user->save();
    //     } else {
    //         $token = $user->remember_token;
    //     }

    //     return response()->json([
    //         'user' => $user,
    //         'token' => $token
    //     ], 200);
    // }
}
