<?php

namespace App\Services\User;

use App\Models\User;
use App\Models\LoginLog;
use App\Support\DeviceLabel;
use App\Support\TokenIssuer;
use Illuminate\Support\Facades\Hash;

class LoginUserService
{
    /**
     * @throws \Exception
     */
    public function execute(array $credentials, ?string $ipAddress = null, ?string $userAgent = null, ?string $deviceName = null): array
    {
        // Accept either the user's own email or their agent's lr_email
        // (Leuterio Realty email) as the login identifier. Both are unique.
        $login = trim((string) ($credentials['email'] ?? ''));
        $user = User::where('email', $login)
            ->orWhereHas('agent', fn ($q) => $q->where('lr_email', $login))
            ->first();

        if (!$user) {
            throw new \Exception('Email not found', 404);
        }

        if (!Hash::check($credentials['password'], $user->password)) {
            throw new \Exception('Incorrect password', 401);
        }

        // One token per login so each device/session can be revoked
        // independently (per-device logout). Do NOT reuse a cached token.
        // The token name labels the session in the user's "active devices" list.
        $label = $deviceName ?: DeviceLabel::fromUserAgent($userAgent);
        $token = TokenIssuer::issue($user, $label, $ipAddress);

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
