<?php

namespace App\Support;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Single source of truth for the admin "login as agent" (impersonation)
 * mechanism. An impersonation session is just a normal Sanctum token minted for
 * the agent's user, but with a distinctive token NAME so presence-writing
 * endpoints can recognise it and keep the agent looking offline to the public.
 */
final class Impersonation
{
    /** Token-name prefix that marks a session as admin impersonation. */
    public const TOKEN_PREFIX = 'admin-impersonation:';

    /** Build the token name for an impersonation session, encoding the admin id. */
    public static function tokenName(int $adminId): string
    {
        return self::TOKEN_PREFIX . $adminId;
    }

    /**
     * Whether the given authenticated user's CURRENT request token is an
     * impersonation token. Safe for any auth driver — only personal access
     * tokens carry a name, so anything else is treated as non-impersonation.
     */
    public static function isImpersonated(?User $user): bool
    {
        $token = $user?->currentAccessToken();
        if (!$token instanceof PersonalAccessToken) {
            return false;
        }

        return is_string($token->name) && str_starts_with($token->name, self::TOKEN_PREFIX);
    }

    /** Extract the impersonating admin's id from an impersonation token name. */
    public static function adminIdFromTokenName(?string $tokenName): ?int
    {
        if (!is_string($tokenName) || !str_starts_with($tokenName, self::TOKEN_PREFIX)) {
            return null;
        }

        $id = (int) substr($tokenName, strlen(self::TOKEN_PREFIX));

        return $id > 0 ? $id : null;
    }
}
