<?php

namespace App\Http\Controllers\Mcp;

use App\Mcp\Support\McpUrl;
use App\Services\McpOAuth\OAuthSwitch;
use Illuminate\Http\JsonResponse;

/**
 * The two small documents Claude reads to find out how to sign a person in
 * (RFC 9728 and RFC 8414). Both name this API by its one configured address,
 * whatever host the request arrived at, and neither says a thing while sign-in
 * is off.
 */
class OAuthMetadataController extends OAuthController
{
    /** GET /.well-known/oauth-protected-resource and /.well-known/oauth-protected-resource/mcp/filipinohomes */
    public function resource(?string $path = null): JsonResponse
    {
        if ($off = $this->off()) {
            return $off;
        }

        if (! in_array(trim((string) $path, '/'), ['', trim(McpUrl::PATH, '/')], true)) {
            return response()->json(['error' => 'not_found'], 404, self::NO_STORE);
        }

        return response()->json([
            'resource' => McpUrl::server(),
            'authorization_servers' => [McpUrl::root()],
            'scopes_supported' => ['fh:read'],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Filipino Homes',
        ], 200, ['Cache-Control' => 'public, max-age=60']);
    }

    /** GET /.well-known/oauth-authorization-server */
    public function server(?string $path = null): JsonResponse
    {
        if ($off = $this->off()) {
            return $off;
        }

        if (trim((string) $path, '/') !== '') {
            return response()->json(['error' => 'not_found'], 404, self::NO_STORE);
        }

        $root = McpUrl::root();

        return response()->json([
            'issuer' => $root,
            'authorization_endpoint' => $root.'/oauth/authorize',
            'token_endpoint' => $root.'/oauth/token',
            'registration_endpoint' => $root.'/oauth/register',
            'revocation_endpoint' => $root.'/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => ['fh:read', 'offline_access'],
            // RFC 9207, only while ChatGPT is open (it is what lets ChatGPT use its stable return address); every
            // authorization response then carries `iss`, the same string as `issuer` above.
            ...(OAuthSwitch::issuerIdentified() ? ['authorization_response_iss_parameter_supported' => true] : []),
        ], 200, ['Cache-Control' => 'public, max-age=60']);
    }
}
