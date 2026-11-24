<?php

namespace App\Http\Controllers\Mcp;

use App\Services\McpOAuth\OAuthClients;
use App\Services\McpOAuth\OAuthError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /oauth/register: Claude introduces itself (RFC 7591). Nothing is kept:
 * the return addresses it names are checked against the short list, and a
 * fixed client id comes back (OAuthClients).
 */
class OAuthRegisterController extends OAuthController
{
    public function __invoke(Request $request): JsonResponse
    {
        if ($response = $this->off() ?? $this->tooMany('fhmcp-oauth-register:'.$request->ip(), 60)) {
            return $response;
        }

        $body = $request->isJson() ? $request->json()->all() : $request->request->all();

        try {
            return response()->json(OAuthClients::register($body), 201, self::NO_STORE);
        } catch (OAuthError $e) {
            return $this->fail($e);
        }
    }
}
