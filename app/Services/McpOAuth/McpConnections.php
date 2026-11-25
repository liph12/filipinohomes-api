<?php

namespace App\Services\McpOAuth;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\ExpoPushService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ending a connection, keeping a person to a few, and telling them when one is
 * made. Ending is the only thing that stops a connection other than its end
 * date, and it is final: Claude would have to sign in again.
 */
final class McpConnections
{
    /** The reasons a connection is ended, for the record. */
    public const USER = 'user';

    public const ADMIN = 'admin';

    public const CLAUDE = 'claude';

    public const REPLACED = 'replaced';

    public const ROLE_CHANGED = 'role_changed';

    public const REFRESH_REUSE = 'refresh_reuse';

    /** The person's account no longer has a role on the server (switched off, say): what they agreed to is gone. */
    public const NO_ACCESS = 'no_access';

    /** The person signed out everywhere (or had their password reset): nothing opened by that login outlives it. */
    public const LOGGED_OUT = 'logged_out';

    public static function end(McpConnection $connection, ?User $by, string $reason): void
    {
        if ($connection->revoked_at !== null) {
            return;
        }

        $connection->forceFill(['revoked_at' => now(), 'revoked_by' => $by?->id, 'revoked_reason' => $reason])->save();
        Log::info('fhmcp.connection.ended', ['connection' => $connection->id, 'user' => $connection->user_id, 'reason' => $reason, 'by' => $by?->id]);
    }

    /** Ends every connection the person still has. Never the reason what called it fails (the sign-out itself has already happened). */
    public static function endAll(User $user, string $reason): void
    {
        try {
            McpConnection::query()->where('user_id', $user->id)->active()->get()->each(fn (McpConnection $connection) => self::end($connection, null, $reason));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Keeps a person to the allowed number of connections for an AI by ending the ones used least recently.
     * Claude signs in again from time to time without ever telling us the old one is gone, so
     * without this they would pile up. The one just made is never the one that goes, and one AI's
     * connections never push another's out: Claude Code's and Claude's count together, ChatGPT's apart.
     */
    public static function retireBeyondCap(User $user, McpConnection $keep): void
    {
        $limit = max(1, (int) config('fhmcp.max_connections', 5));
        $provider = OAuthClients::providerOf($keep->client_id);

        $active = McpConnection::query()
            ->where('user_id', $user->id)
            ->when($provider !== null, fn ($query) => $query->whereIn('client_id', OAuthClients::clientsOf($provider)))
            ->active()
            ->orderByRaw('coalesce(last_used_at, created_at) desc')
            ->orderByDesc('id')
            ->get();

        foreach ($active->slice($limit) as $old) {
            if ($old->id !== $keep->id) {
                self::end($old, null, self::REPLACED);
            }
        }
    }

    /** A bell notification (and a push, if they have the app) that an AI (Claude, ChatGPT) was connected to their account. */
    public static function tellConnected(User $user, McpConnection $connection): void
    {
        $ai = OAuthClients::providerName((string) OAuthClients::providerOf($connection->client_id));

        self::tell($user, "{$ai} connected", "{$ai} was connected to your Filipino Homes account.", ['type' => 'mcp_connection', 'connection_id' => $connection->id]);
    }

    /**
     * Whether they were told. A notification that could not be made is never the reason a sign-in fails, but
     * whoever sent it for a reason that matters (an address change) has to know who was not reached.
     *
     * @param  array<string, mixed>  $data
     */
    public static function tell(User $user, string $title, string $body, array $data): bool
    {
        try {
            app(ExpoPushService::class)->notify($user, 'mcp_connection', $title, $body, $data);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
