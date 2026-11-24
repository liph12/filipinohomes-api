<?php

namespace App\Services\McpOAuth;

/**
 * PKCE (RFC 7636), the S256 method only: the proof that whoever trades a code
 * for tokens is the one who started the sign-in.
 */
final class Pkce
{
    /** A challenge is the unpadded base64url of a SHA-256: always 43 characters. */
    public static function isChallenge(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $value) === 1;
    }

    /** A verifier is 43 to 128 characters of the unreserved set. */
    public static function isVerifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $value) === 1;
    }

    public static function matches(string $verifier, string $challenge): bool
    {
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $computed);
    }
}
