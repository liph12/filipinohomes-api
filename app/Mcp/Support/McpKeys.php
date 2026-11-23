<?php

namespace App\Mcp\Support;

use App\Models\McpKey;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Making, finding and switching off the keys of the MCP server.
 *
 * A key is a long random secret. Only its SHA-256 is stored, next to the
 * person it belongs to and the moment it stops working. A key this long can't
 * be guessed, so a plain hash is enough and the lookup is by that hash. Like
 * FH One's keys, a new one is shown once, when it is made, and never again.
 *
 * A key only ever opens /mcp/filipinohomes (AuthenticateMcpKey): it is not a
 * Sanctum token and the rest of the API does not know it.
 */
final class McpKeys
{
    /** Easy to spot in a log, a paste or a secret scanner. */
    public const PREFIX = 'fhmcp_';

    /**
     * A new key for this person, and the key itself, which is not kept anywhere.
     *
     * @return array{key: string, record: McpKey}
     *
     * @throws InvalidArgumentException when this person may not hold a key, or already holds as many as are allowed
     */
    public static function issue(User $user, string $name, ?int $days = null): array
    {
        $role = McpRole::of($user);

        if ($role === null) {
            throw new InvalidArgumentException('Only an admin or an active agent can have an MCP key.');
        }

        $max = (int) config('fhmcp.max_keys', 3);

        if (McpKey::query()->where('user_id', $user->id)->active()->count() >= $max) {
            throw new InvalidArgumentException("This person already has {$max} keys in use. Revoke one first.");
        }

        $days ??= (int) config('fhmcp.default_days', 30);
        $longest = (int) config('fhmcp.max_days', 90);

        if ($days < 1 || $days > $longest) {
            throw new InvalidArgumentException("A key lasts between 1 and {$longest} days.");
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Give the key a name of up to 80 characters, like "Claude Code on my laptop".');
        }

        $key = self::PREFIX.Str::random(48);

        $record = McpKey::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'prefix' => substr($key, 0, 12),
            'token_hash' => hash('sha256', $key),
            'expires_at' => now()->addDays($days),
        ]);

        return ['key' => $key, 'record' => $record];
    }

    /** The key this secret is, with its person, or null. It may be expired or revoked: the caller says which. */
    public static function find(string $secret): ?McpKey
    {
        if ($secret === '' || ! str_starts_with($secret, self::PREFIX)) {
            return null;
        }

        return McpKey::query()->with('user.role', 'user.agent')->where('token_hash', hash('sha256', $secret))->first();
    }

    public static function revoke(McpKey $key): void
    {
        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => now()])->save();
        }
    }
}
