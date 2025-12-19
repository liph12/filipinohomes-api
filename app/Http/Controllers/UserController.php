<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\User;
use App\Http\Resources\UserResourceCollection;
use App\Http\Resources\UserResource;
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
}
