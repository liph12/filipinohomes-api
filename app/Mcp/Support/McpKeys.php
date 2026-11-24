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
     * @param  int|null  $days  how many days it works (null: the default in config/fhmcp.php)
     * @param  User|null  $by  the admin making it from the dashboard (null: the artisan command)
     * @param  bool  $permanent  no end date: it works until it is revoked, or its person loses access
     * @return array{key: string, record: McpKey}
     *
     * @throws InvalidArgumentException when this person may not hold a key, already holds as many as are allowed, or the key is both permanent and for a number of days
     */
    public static function issue(User $user, string $name, ?int $days = null, ?User $by = null, bool $permanent = false): array
    {
        $role = McpRole::of($user);

        if ($role === null) {
            throw new InvalidArgumentException('Only an admin, a team leader or an active agent can have an MCP key.');
        }

        $max = (int) config('fhmcp.max_keys', 3);

        if (McpKey::query()->where('user_id', $user->id)->active()->count() >= $max) {
            throw new InvalidArgumentException("This person already has {$max} keys in use. Revoke one first.");
        }

        if ($permanent && $days !== null) {
            throw new InvalidArgumentException('A key is either permanent or lasts a number of days, not both.');
        }

        $expiresAt = null;

        if (! $permanent) {
            $days ??= (int) config('fhmcp.default_days', 30);
            $longest = (int) config('fhmcp.max_days', 90);

            if ($days < 1 || $days > $longest) {
                throw new InvalidArgumentException("A key lasts between 1 and {$longest} days.");
            }

            $expiresAt = now()->addDays($days);
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
            'expires_at' => $expiresAt,
            // Only from the dashboard, so the artisan commands keep working in the moment between
            // deploying this and running the migration that adds the column.
        ] + ($by === null ? [] : ['created_by' => $by->id]));

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

    public static function revoke(McpKey $key, ?User $by = null): void
    {
        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => now()] + ($by === null ? [] : ['revoked_by' => $by->id]))->save();
        }
    }
}
