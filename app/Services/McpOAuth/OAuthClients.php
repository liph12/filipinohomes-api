<?php

namespace App\Services\McpOAuth;

/**
 * The only apps that may ask a person to sign in, and where each may send them
 * back. There are two, both Claude: the Claude apps (claude.ai, the desktop and
 * mobile apps), which use one fixed return address, and Claude Code, a program
 * on someone's own computer, which listens on a port of its own (RFC 8252).
 *
 * Registering is stateless: nothing is stored, a fixed client id comes back,
 * and what is shown to a person is our own name for it, never what the caller
 * said about itself. Anything that wants a return address outside this list is
 * refused at registration and again at sign-in.
 */
final class OAuthClients
{
    public const HOSTED = 'claude';

    public const LOOPBACK = 'claude-code';

    /** What kind of app a client is: one of Anthropic's (hosted), or a program on the person's own computer. */
    public const KIND_HOSTED = 'hosted';

    public const KIND_LOOPBACK = 'loopback';

    /** The return addresses of the Claude apps (claude.com is where Anthropic says it may move). */
    private const HOSTED_REDIRECTS = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ];

    /** Claude Code's: http on this computer, any port, /callback and nothing else (\z: not even a newline after it). */
    private const LOOPBACK_REDIRECT = '#^http://(localhost|127\.0\.0\.1|\[::1\])(:\d{1,5})?/callback\z#';

    public static function exists(string $clientId): bool
    {
        return in_array($clientId, [self::HOSTED, self::LOOPBACK], true);
    }

    /** The client a return address belongs to, or null when it belongs to none. */
    public static function forRedirect(string $uri): ?string
    {
        if (in_array($uri, self::HOSTED_REDIRECTS, true)) {
            return self::HOSTED;
        }

        return preg_match(self::LOOPBACK_REDIRECT, $uri) === 1 ? self::LOOPBACK : null;
    }

    public static function allowsRedirect(string $clientId, string $uri): bool
    {
        return self::exists($clientId) && self::forRedirect($uri) === $clientId;
    }

    public static function name(string $clientId): string
    {
        return $clientId === self::LOOPBACK ? 'Claude Code' : 'Claude';
    }

    /** hosted or loopback */
    public static function kind(string $clientId): string
    {
        return $clientId === self::LOOPBACK ? self::KIND_LOOPBACK : self::KIND_HOSTED;
    }

    /**
     * Dynamic client registration (RFC 7591), without keeping anything: the answer is a fixed client id,
     * and every return address asked for has to be one of the above, all of one client.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws OAuthError
     */
    public static function register(array $body): array
    {
        $uris = $body['redirect_uris'] ?? null;

        if (! is_array($uris) || $uris === [] || count($uris) > 10 || array_filter($uris, 'is_string') !== $uris) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris must be a short list of addresses.');
        }

        $clients = [];

        foreach ($uris as $uri) {
            $client = self::forRedirect($uri);

            if ($client === null) {
                throw new OAuthError('invalid_redirect_uri', 'That return address is not allowed.');
            }

            $clients[$client] = true;
        }

        if (count($clients) !== 1) {
            throw new OAuthError('invalid_redirect_uri', 'Return addresses of different apps cannot be registered together.');
        }

        $clientId = (string) array_key_first($clients);

        return [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'client_name' => self::name($clientId),
            'redirect_uris' => array_values($uris),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            // Whatever was asked for, these are public clients: they prove themselves with PKCE.
            'token_endpoint_auth_method' => 'none',
        ];
    }
}
