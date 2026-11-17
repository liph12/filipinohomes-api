<?php

namespace App\Http\Middleware;

use App\Support\FhOneKeys;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /api/fh-one: FH One's own key, sent as `Authorization: Bearer`.
 *
 * Like VerifyServiceToken there is no route that mints it, so anyone can't
 * obtain one; unlike it, keys carry a name and an expiry (see FhOneKeys), and
 * the rate limit that follows (`throttle:fhone`) is per key and can't be
 * lifted by any other token.
 *
 * The answer to a wrong, missing or expired key is the same 401, with the
 * reason only in the log. Failed attempts are counted per IP so a script
 * guessing keys is cut off long before it matters.
 *
 * Callers may also send X-FH-One-User and X-FH-One-Role (who FH One is asking
 * for) and X-Request-Id; they go into the log line, nothing else.
 */
class VerifyFhOneKey
{
    private const FAILURES_PER_MINUTE = 20;

    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $requestId = $this->requestId($request);
        $failures = 'fhone-key-failures:'.$request->ip();

        if (RateLimiter::tooManyAttempts($failures, self::FAILURES_PER_MINUTE)) {
            return response()->json(['error' => 'Too many failed attempts'], 429, [
                'Retry-After' => (string) RateLimiter::availableIn($failures),
                'X-Request-Id' => $requestId,
            ]);
        }

        // config(), never env(): env() is null outside config files once the
        // config is cached (see VerifyServiceToken).
        $key = FhOneKeys::match((string) $request->bearerToken());

        if ($key === null || $key['expired']) {
            RateLimiter::hit($failures, 60);

            Log::warning('fhone.rejected', [
                'reason' => $key === null ? 'unknown_or_missing_key' : 'expired_key',
                'key' => $key['name'] ?? null,
                'ip' => $request->ip(),
                'path' => $request->path(),
                'request_id' => $requestId,
            ]);

            return response()->json(['error' => 'Unauthorized'], 401, [
                'WWW-Authenticate' => 'Bearer',
                'X-Request-Id' => $requestId,
            ]);
        }

        // The limiter that runs next is keyed by this.
        $request->attributes->set('fhone.key', $key['name']);
        $request->attributes->set('fhone.key_expires', $key['expires']);
        $request->attributes->set('fhone.request_id', $requestId);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        Log::info('fhone.request', [
            'key' => $key['name'],
            'user' => $this->token($request->header('X-FH-One-User'), '/^\d{1,12}$/'),
            'role' => $this->token($request->header('X-FH-One-Role'), '/^[a-z_]{1,20}$/'),
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'ms' => (int) round((hrtime(true) - $started) / 1e6),
        ]);

        return $response;
    }

    /** The caller's request id when it is a plain token, else a new one. */
    private function requestId(Request $request): string
    {
        return $this->token($request->header('X-Request-Id'), '/^[A-Za-z0-9._-]{1,64}$/') ?? (string) Str::uuid();
    }

    /** A header value when it looks like the expected token (nothing odd ends up in the log), else null. */
    private function token(mixed $value, string $pattern): ?string
    {
        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
    }
}
