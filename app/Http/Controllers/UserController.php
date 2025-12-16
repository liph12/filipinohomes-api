<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Http\Resources\UserResourceCollection;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    
    /*
    
    GET, POST, PUT

    GET - index()

    GET - show($id)

    POST - store()

    PUT - update($id)

    */

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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email', // email must be unique
            'password' => 'required|string|min:6',
        ]);
        // store user implementation
        $user = User::create($request->only([
            'name',
            'email',
            'password',
            'role_id'
        ]));

    return new UserResource($user);
    }

    public function update($id, Request $request)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,'.$user->id,
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

    public function login(Request $request)
    {
        // Validate email and password
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        // Find user by email
        $user = User::where('email', $credentials['email'])->first();

        if (!$user) {
            // Email does not exist
            return response()->json([
                'message' => 'Email not found'
            ], 404);
        }

        if (!Hash::check($credentials['password'], $user->password)) {
            // Password is incorrect
            return response()->json([
                'message' => 'Incorrect password'
            ], 401);
        }

        // Create a personal access token
        $token = $user->createToken('API Token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token
        ], 200);
    }
}
