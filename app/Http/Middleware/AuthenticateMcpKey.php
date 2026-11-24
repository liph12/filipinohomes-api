<?php

namespace App\Http\Middleware;

use App\Mcp\Support\McpKeys;
use App\Mcp\Support\McpRole;
use App\Mcp\Support\McpUrl;
use App\Services\McpOAuth\McpConnections;
use App\Services\McpOAuth\OAuthSwitch;
use App\Services\McpOAuth\OAuthTokens;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /mcp/filipinohomes (Claude reading FH's numbers), sent as
 * `Authorization: Bearer <key>`. See docs/mcp.md.
 *
 * A key belongs to one person and opens this endpoint only (it is not a
 * Sanctum token; the rest of the API does not know it). Every request looks
 * the key up again and judges the person's role again, so a revoked key, an
 * expired one, a demoted admin or an agent whose account was switched off
 * stops working at once.
 *
 *   401  no key, an unknown one, an expired one, a revoked one (all alike)
 *   403  a real key whose person may no longer use the MCP server
 *   429  too many wrong keys from one address, or too many requests on one key
 *
 * The reason for a refusal goes to the log, never into the answer. Wrong keys
 * are counted per address, so a script guessing keys is cut off early. Only a
 * guess counts (something shaped like a key that is not one): a request with
 * no key at all is how a client asks what it needs, and Claude's users all
 * arrive from the same few addresses, so neither may count against the rest.
 * The key is read before the count is looked at, and a good key is never
 * turned away because of what someone else at its address sent.
 *
 * What a 401 says in WWW-Authenticate is this gate's to say: the package's own
 * header middleware is taken off the route (routes/ai.php). While signing in is
 * on, it points Claude at where to sign a person in; otherwise it says nothing
 * of that.
 *
 * A person's Claude, signed in (an `fhoat_` access token, not a key), comes
 * through here too (throughConnection): the connection and the person's role are
 * judged on every request, as a key's are, and a request with an access token
 * that has run out or a connection that has ended is a plain 401, never counted
 * against anyone, because that is how Claude learns to refresh or sign in again.
 *
 * With config('fhmcp.open_handshake') on, a request that carries no key at all
 * may still start the conversation (initialize, ping, notifications, list the
 * tools): claude.ai probes a connector that way when it is added. It cannot
 * run a tool.
 */
class AuthenticateMcpKey
{
    /** What a client with no key may ask. Nothing here reads FH's data. */
    private const HANDSHAKE = ['initialize', 'ping', 'tools/list'];

    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();
        $token = (string) $request->bearerToken();

        if (str_starts_with($token, OAuthTokens::ACCESS_PREFIX)) {
            return $this->throughConnection($request, $next, $token);
        }

        if ($token === '' && $this->mayHandshake($request)) {
            $open = 'fhmcp-open:'.$ip;

            if (RateLimiter::tooManyAttempts($open, (int) config('fhmcp.ip_rate_limit', 30))) {
                return $this->refuse(429, 'Too many requests', RateLimiter::availableIn($open));
            }

            RateLimiter::hit($open, 60);

            return $next($request);
        }

        $key = McpKeys::find($token);

        if ($key === null || ! $key->isActive()) {
            // A guess is something shaped like a key. Those are counted per address, and past the
            // count that address's guesses are cut off; nothing else is.
            if (str_starts_with($token, McpKeys::PREFIX)) {
                $failures = 'fhmcp-key-failures:'.$ip;

                if (RateLimiter::tooManyAttempts($failures, (int) config('fhmcp.ip_failures', 20))) {
                    return $this->refuse(429, 'Too many failed attempts', RateLimiter::availableIn($failures));
                }

                RateLimiter::hit($failures, 60);
            }

            $this->note($request, match (true) {
                $token === '' => 'no_key',
                $key === null => 'unknown_key',
                $key->revoked_at !== null => 'revoked_key',
                default => 'expired_key',
            });

            return $this->refuse(401, 'Unauthorized', hadToken: $token !== '');
        }

        $user = $key->user;

        if ($user === null || McpRole::of($user) === null) {
            $this->note($request, 'no_access', $key->id);

            return $this->refuse(403, 'This account cannot use the MCP server.');
        }

        $bucket = 'fhmcp-key:'.$key->id;

        if (RateLimiter::tooManyAttempts($bucket, (int) config('fhmcp.rate_limit', 60))) {
            return $this->refuse(429, 'Too many requests on this key', RateLimiter::availableIn($bucket));
        }

        RateLimiter::hit($bucket, 60);

        // The tools ask Auth who is calling, and the key is what they report in the audit.
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('mcp.key', $key);

        // Often enough to be useful in a list of keys, rarely enough not to be a write on every call.
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }

    /** A person's Claude, signed in: the connection, its person and their role are judged as a key's are. */
    private function throughConnection(Request $request, Closure $next, string $token): Response
    {
        $pair = OAuthSwitch::enabled() ? app(OAuthTokens::class)->findAccess($token) : null;
        $connection = $pair?->connection;

        // Not a token we hold, one that has run out, a connection that has ended, or one made for an address this
        // API no longer answers to. Claude answers a 401 by refreshing, or by signing the person in again.
        if ($pair === null || $connection === null || $pair->access_expires_at->isPast() || ! $connection->isActive() || ! $connection->addressIsAccepted()) {
            $this->note($request, $pair === null ? 'unknown_token' : 'ended_token', null, $connection?->id);

            return $this->refuse(401, 'Unauthorized', hadToken: true);
        }

        $user = $connection->user;
        $role = $user === null ? null : McpRole::of($user);

        if ($role === null) {
            $this->note($request, 'no_access', null, $connection->id);

            return $this->refuse(403, 'This account cannot use the MCP server.');
        }

        // What they are now is not what they agreed to when they connected: Claude has to ask them again.
        if ($role->value !== $connection->role) {
            McpConnections::end($connection, null, McpConnections::ROLE_CHANGED);

            return $this->refuse(401, 'Unauthorized', hadToken: true);
        }

        if (! OAuthSwitch::allows($role)) {
            $this->note($request, 'paused', null, $connection->id);

            return $this->refuse(403, 'Connecting Claude is not open to your account yet.');
        }

        $bucket = 'fhmcp-conn:'.$connection->id;

        if (RateLimiter::tooManyAttempts($bucket, (int) config('fhmcp.rate_limit', 60))) {
            return $this->refuse(429, 'Too many requests on this connection', RateLimiter::availableIn($bucket));
        }

        RateLimiter::hit($bucket, 60);

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('mcp.connection', $connection);

        // The first time this pair does anything is what tells a retried refresh from a stolen token.
        if ($pair->used_at === null) {
            $pair->forceFill(['used_at' => now()])->saveQuietly();
        }

        if ($connection->last_used_at === null || $connection->last_used_at->lt(now()->subMinute())) {
            $connection->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }

    /** Whether this key-less request is one a client with no key may make. */
    private function mayHandshake(Request $request): bool
    {
        // While signing in is on, the 401 is what starts it: a handshake without a key would let Claude in without one.
        if (! config('fhmcp.open_handshake') || OAuthSwitch::enabled()) {
            return false;
        }

        $body = json_decode($request->getContent(), true);
        $method = is_array($body) ? ($body['method'] ?? null) : null;

        return is_string($method) && (in_array($method, self::HANDSHAKE, true) || str_starts_with($method, 'notifications/'));
    }

    private function refuse(int $status, string $message, ?int $retryAfter = null, bool $hadToken = false): Response
    {
        // What a client is told to do next. A request with no credential at all gets no error code
        // (RFC 6750); one that carried a credential this gate did not accept gets invalid_token.
        $headers = $status === 401 ? ['WWW-Authenticate' => $this->challenge($hadToken)] : [];

        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32001, 'message' => $message],
        ], $status, $headers);
    }

    /**
     * What a 401 tells the client to do. While signing in is on it points at the document that says where to
     * sign a person in (the shape Claude's own documentation shows); otherwise it only says a key is needed.
     */
    private function challenge(bool $hadToken): string
    {
        if (OAuthSwitch::enabled()) {
            return 'Bearer error="invalid_token", error_description="'.($hadToken ? 'The access token is not valid' : 'Authentication required').'", resource_metadata="'.McpUrl::resourceMetadata().'", scope="fh:read"';
        }

        return $hadToken ? 'Bearer realm="mcp", error="invalid_token"' : 'Bearer realm="mcp"';
    }

    private function note(Request $request, string $reason, ?int $key = null, ?int $connection = null): void
    {
        // A request with no key, or a token that has run out, is ordinary (a client finding out what the server wants,
        // or about to refresh), not a warning.
        Log::log(in_array($reason, ['no_key', 'unknown_token', 'ended_token'], true) ? 'debug' : 'warning', 'fhmcp.rejected', [
            'reason' => $reason,
            'key' => $key,
            'connection' => $connection,
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);
    }
}
