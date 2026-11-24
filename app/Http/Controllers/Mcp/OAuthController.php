<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\McpOAuth\OAuthError;
use App\Services\McpOAuth\OAuthSwitch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * What the sign-in routes at the root of the site have in common (routes/ai.php).
 * They are answered by Claude's own servers and by a browser, never by the
 * website's scripts, so there is no CORS and no cookie; errors are OAuth's JSON,
 * because at the root Laravel would answer a mistake with a page; and nothing
 * that carries a token or a code is ever cached.
 */
abstract class OAuthController extends Controller
{
    /** Headers on anything that may carry a secret. */
    protected const NO_STORE = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];

    /** What every sign-in route says while sign-in is off. */
    protected function off(): ?JsonResponse
    {
        return OAuthSwitch::enabled() ? null : response()->json(['error' => 'not_found', 'error_description' => 'Not found.'], 404, self::NO_STORE);
    }

    protected function fail(OAuthError $error): JsonResponse
    {
        return response()->json($error->body(), $error->status, self::NO_STORE);
    }

    /**
     * A flood cap on a name, a minute at a time. Not a defence against anyone in particular: Claude's servers
     * are few addresses for many people, and the limit counts per server, so it is set well above real use.
     */
    protected function tooMany(string $name, int $perMinute): ?JsonResponse
    {
        if (RateLimiter::tooManyAttempts($name, $perMinute)) {
            return response()->json(
                ['error' => 'temporarily_unavailable', 'error_description' => 'Too many requests. Try again in a moment.'],
                429,
                self::NO_STORE + ['Retry-After' => (string) RateLimiter::availableIn($name)],
            );
        }

        RateLimiter::hit($name, 60);

        return null;
    }

    /**
     * The query string as it was sent, undone by nobody: the global middleware trims and blanks what it finds,
     * and the state Claude sends has to come back exactly.
     *
     * @return array<string, mixed>
     */
    protected function rawQuery(Request $request): array
    {
        parse_str((string) $request->server->get('QUERY_STRING', ''), $query);

        return $query;
    }
}
