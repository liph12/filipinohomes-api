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

    public static function end(McpConnection $connection, ?User $by, string $reason): void
    {
        if ($connection->revoked_at !== null) {
            return;
        }

        $connection->forceFill(['revoked_at' => now(), 'revoked_by' => $by?->id, 'revoked_reason' => $reason])->save();
        Log::info('fhmcp.connection.ended', ['connection' => $connection->id, 'user' => $connection->user_id, 'reason' => $reason, 'by' => $by?->id]);
    }

    /**
     * Keeps a person to the allowed number of connections by ending the ones used least recently.
     * Claude signs in again from time to time without ever telling us the old one is gone, so
     * without this they would pile up. The one just made is never the one that goes.
     */
    public static function retireBeyondCap(User $user, McpConnection $keep): void
    {
        $limit = max(1, (int) config('fhmcp.max_connections', 5));

        $active = McpConnection::query()
            ->where('user_id', $user->id)
            ->active()
            ->orderByRaw('coalesce(last_used_at, created_at) desc')
            ->get();

        foreach ($active->slice($limit) as $old) {
            if ($old->id !== $keep->id) {
                self::end($old, null, self::REPLACED);
            }
        }
    }

    /** A bell notification (and a push, if they have the app) that Claude was connected to their account. */
    public static function tellConnected(User $user, McpConnection $connection): void
    {
        self::tell($user, 'Claude connected', 'Claude was connected to your Filipino Homes account.', ['type' => 'mcp_connection', 'connection_id' => $connection->id]);
    }

    /** @param  array<string, mixed>  $data */
    public static function tell(User $user, string $title, string $body, array $data): void
    {
        try {
            app(ExpoPushService::class)->notify($user, 'mcp_connection', $title, $body, $data);
        } catch (Throwable $e) {
            // Never the reason a sign-in fails.
            report($e);
        }
    }
}
