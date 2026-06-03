<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

class TokenIssuer
{
    /**
     * Mint a per-device Sanctum token labeled from the request and stamped
     * with the originating IP (so the session can be shown with an
     * approximate location in the "active devices" list).
     */
    public static function fromRequest(User $user, Request $request): string
    {
        return self::issue($user, DeviceLabel::fromRequest($request), $request->ip());
    }

    public static function issue(User $user, string $label, ?string $ipAddress = null): string
    {
        $new = $user->createToken($label);

        if ($ipAddress) {
            $new->accessToken->forceFill(['ip_address' => $ipAddress])->save();
        }

        return $new->plainTextToken;
    }
}
