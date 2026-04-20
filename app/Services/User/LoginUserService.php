<?php

namespace App\Services\User;

use App\Models\User;
use App\Models\LoginLog;
use Illuminate\Support\Facades\Hash;

class LoginUserService
{
    /**
     * @throws \Exception
     */
    public function execute(array $credentials, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $user = User::where('email', $credentials['email'])->first();

        if (!$user) {
            throw new \Exception('Email not found', 404);
        }

        if (!Hash::check($credentials['password'], $user->password)) {
            throw new \Exception('Incorrect password', 401);
        }

        if (!$user->remember_token) {
            $token = $user->createToken('API Token')->plainTextToken;
            $user->remember_token = $token;
            $user->save();
        } else {
            $token = $user->remember_token;
        }

        LoginLog::create([
            'user_id'     => $user->id,
            'ip_address'  => $ipAddress,
            'user_agent'  => $userAgent,
            'logged_in_at' => now(),
        ]);

        return [
            'user'  => $user,
            'token' => $token,
        ];
    }
}
