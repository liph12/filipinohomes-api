<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class LoginUserService
{
    /**
     * @throws \Exception
     */
    public function execute(array $credentials): array
    {
        // Find user by email
        $user = User::where('email', $credentials['email'])->first();

        if (!$user) {
            throw new \Exception('Email not found', 404);
        }

        // Check password
        if (!Hash::check($credentials['password'], $user->password)) {
            throw new \Exception('Incorrect password', 401);
        }

        // Create token if not exists
        if (!$user->remember_token) {
            $token = $user->createToken('API Token')->plainTextToken;
            $user->remember_token = $token;
            $user->save();
        } else {
            $token = $user->remember_token;
        }

        return [
            'user'  => $user,
            'token' => $token
        ];
    }
}
