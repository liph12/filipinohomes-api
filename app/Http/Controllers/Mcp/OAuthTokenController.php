<?php

namespace App\Http\Controllers\Mcp;

use App\Services\McpOAuth\OAuthError;
use App\Services\McpOAuth\OAuthTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * POST /oauth/token and /oauth/revoke: Claude's servers trade the one-time code
 * for tokens, the refresh token for the next pair, and say when they are done.
 * Form bodies only, as OAuth says; nothing the request carries is read from the
 * query string. Whatever goes wrong inside is answered as OAuth's server_error,
 * with nothing of the inside in it.
 */
class OAuthTokenController extends OAuthController
{
    public function token(Request $request, OAuthTokens $tokens): JsonResponse
    {
        if ($response = $this->off() ?? $this->tooMany('fhmcp-oauth-token:'.$request->ip(), 1200)) {
            return $response;
        }

        if ($request->isJson()) {
            return $this->fail(new OAuthError('invalid_request', 'Send the request as application/x-www-form-urlencoded.'));
        }

        try {
            return response()->json($tokens->grant($request->request->all()), 200, self::NO_STORE);
        } catch (OAuthError $e) {
            return $this->fail($e);
        } catch (Throwable $e) {
            report($e);

            return $this->fail(new OAuthError('server_error', 'Something went wrong on our side. Try again.', 500));
        }
    }

    /** RFC 7009: it always answers 200, so it cannot be used to find out what is a token. */
    public function revoke(Request $request, OAuthTokens $tokens): JsonResponse
    {
        if ($response = $this->off() ?? $this->tooMany('fhmcp-oauth-revoke:'.$request->ip(), 600)) {
            return $response;
        }

        try {
            $tokens->revoke($request->request->all());
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json((object) [], 200, self::NO_STORE);
    }
}
