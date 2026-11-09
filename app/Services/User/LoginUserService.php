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

        // Signing in counts as activity, so it refreshes the heartbeat. The
        // 45-day dormancy rule is applied by agents:deactivate-dormant, NOT
        // here — evaluating it at login only ever caught agents on their way
        // back, and silently undid admin reactivations.
        $user->recordLoginActivity();

        // One token per login so each device/session can be revoked
        // independently (per-device logout). Do NOT reuse a cached token.
        // The token name labels the session in the user's "active devices" list.
        $label = $deviceName ?: DeviceLabel::fromUserAgent($userAgent);
        $token = TokenIssuer::issue($user, $label, $ipAddress);

        LoginLog::record($user->id, $ipAddress, $userAgent);

        return [
            // The web routes a fresh login by user.role.name (/admin, /agent, …).
            'user'  => $user->loadMissing('role'),
            'token' => $token,
        ];
    }
}
