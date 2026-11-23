<?php

namespace App\Http\Middleware;

use App\Mcp\Support\McpKeys;
use App\Mcp\Support\McpRole;
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
 * are counted per address, so a script guessing keys is cut off early.
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
        $failures = 'fhmcp-key-failures:'.$ip;

        if (RateLimiter::tooManyAttempts($failures, (int) config('fhmcp.ip_failures', 20))) {
            return $this->refuse(429, 'Too many failed attempts', RateLimiter::availableIn($failures));
        }

        $token = (string) $request->bearerToken();

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
            RateLimiter::hit($failures, 60);
            $this->note($request, match (true) {
                $token === '' => 'no_key',
                $key === null => 'unknown_key',
                $key->revoked_at !== null => 'revoked_key',
                default => 'expired_key',
            });

            return $this->refuse(401, 'Unauthorized');
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

    /** Whether this key-less request is one a client with no key may make. */
    private function mayHandshake(Request $request): bool
    {
        if (! config('fhmcp.open_handshake')) {
            return false;
        }

        $body = json_decode($request->getContent(), true);
        $method = is_array($body) ? ($body['method'] ?? null) : null;

        return is_string($method) && (in_array($method, self::HANDSHAKE, true) || str_starts_with($method, 'notifications/'));
    }

    private function refuse(int $status, string $message, ?int $retryAfter = null): Response
    {
        $headers = $status === 401 ? ['WWW-Authenticate' => 'Bearer'] : [];

        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32001, 'message' => $message],
        ], $status, $headers);
    }

    private function note(Request $request, string $reason, ?int $key = null): void
    {
        Log::warning('fhmcp.rejected', [
            'reason' => $reason,
            'key' => $key,
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);
    }
}
