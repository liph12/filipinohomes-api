<?php

namespace App\Services\McpOAuth;

/**
 * The only apps that may ask a person to sign in, and where each may send them
 * back. There are three: the Claude apps (claude.ai, the desktop and mobile
 * apps), which use one fixed return address; Claude Code, a program on someone's
 * own computer, which listens on a port of its own (RFC 8252); and ChatGPT, whose
 * custom MCP servers use one of two return addresses on chatgpt.com.
 *
 * Each app belongs to an AI ("provider"): Claude and Claude Code are Claude's,
 * ChatGPT is ChatGPT's. The AI is what the portal shows and what an admin
 * switches on or off (OAuthSwitch).
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

    public const CHATGPT = 'chatgpt';

    /** What kind of app a client is: one of a company's servers (hosted), or a program on the person's own computer. */
    public const KIND_HOSTED = 'hosted';

    public const KIND_LOOPBACK = 'loopback';

    public const PROVIDER_CLAUDE = 'claude';

    public const PROVIDER_CHATGPT = 'chatgpt';

    /** @var array<string, array{name: string, provider: string, kind: string}> */
    private const APPS = [
        self::HOSTED => ['name' => 'Claude', 'provider' => self::PROVIDER_CLAUDE, 'kind' => self::KIND_HOSTED],
        self::LOOPBACK => ['name' => 'Claude Code', 'provider' => self::PROVIDER_CLAUDE, 'kind' => self::KIND_LOOPBACK],
        self::CHATGPT => ['name' => 'ChatGPT', 'provider' => self::PROVIDER_CHATGPT, 'kind' => self::KIND_HOSTED],
    ];

    /** The AIs, and the app a person signs in with when they connect each (its return address is the one to look for). */
    private const PROVIDERS = [
        self::PROVIDER_CLAUDE => ['name' => 'Claude', 'client' => self::HOSTED],
        self::PROVIDER_CHATGPT => ['name' => 'ChatGPT', 'client' => self::CHATGPT],
    ];

    /** The return addresses of the Claude apps (claude.com is where Anthropic says it may move). */
    private const HOSTED_REDIRECTS = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ];

    /**
     * ChatGPT's: the one it uses for a server that identifies its issuer (RFC 9207), and the one of its own that it
     * makes for each connection otherwise. OpenAI does not say what an id looks like, so any characters a URL path
     * may hold unescaped; https, chatgpt.com and nothing else (\z: not even a newline after it).
     */
    private const CHATGPT_REDIRECT = 'https://chatgpt.com/connector_platform_oauth_redirect';

    private const CHATGPT_CALLBACK = '#^https://chatgpt\.com/connector/oauth/[A-Za-z0-9._~-]{1,200}\z#';

    /** Claude Code's: http on this computer, any port, /callback and nothing else (\z: not even a newline after it). */
    private const LOOPBACK_REDIRECT = '#^http://(localhost|127\.0\.0\.1|\[::1\])(:\d{1,5})?/callback\z#';

    public static function exists(string $clientId): bool
    {
        return isset(self::APPS[$clientId]);
    }

    /** The client a return address belongs to, or null when it belongs to none. */
    public static function forRedirect(string $uri): ?string
    {
        if (in_array($uri, self::HOSTED_REDIRECTS, true)) {
            return self::HOSTED;
        }

        if ($uri === self::CHATGPT_REDIRECT || preg_match(self::CHATGPT_CALLBACK, $uri) === 1) {
            return self::CHATGPT;
        }

        return preg_match(self::LOOPBACK_REDIRECT, $uri) === 1 ? self::LOOPBACK : null;
    }

    public static function allowsRedirect(string $clientId, string $uri): bool
    {
        return self::exists($clientId) && self::forRedirect($uri) === $clientId;
    }

    public static function name(string $clientId): string
    {
        return self::APPS[$clientId]['name'] ?? 'An AI app';
    }

    /** hosted or loopback */
    public static function kind(string $clientId): string
    {
        return self::APPS[$clientId]['kind'] ?? self::KIND_HOSTED;
    }

    /** The AI an app signs in for (claude, chatgpt), or null for an app that is not one of ours. */
    public static function providerOf(string $clientId): ?string
    {
        return self::APPS[$clientId]['provider'] ?? null;
    }

    /** The AI's own name, for a sentence: "Claude" for Claude and for Claude Code. */
    public static function providerName(string $provider): string
    {
        return self::PROVIDERS[$provider]['name'] ?? 'AI';
    }

    /**
     * The AIs, in the order they are shown.
     *
     * @return array<string, string> id => name
     */
    public static function providers(): array
    {
        return array_map(fn (array $provider): string => $provider['name'], self::PROVIDERS);
    }

    /** The app a person signs in with when they connect this AI (the one with the return address the AI uses). */
    public static function clientOf(string $provider): ?string
    {
        return self::PROVIDERS[$provider]['client'] ?? null;
    }

    /**
     * Every app that signs in for this AI.
     *
     * @return list<string>
     */
    public static function clientsOf(string $provider): array
    {
        return array_keys(array_filter(self::APPS, fn (array $app): bool => $app['provider'] === $provider));
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
        $provider = (string) self::providerOf($clientId);

        // An AI an admin has not opened is told so, in words the person adding it can read.
        if (OAuthSwitch::providerMode($provider) === OAuthSwitch::OFF) {
            throw new OAuthError('invalid_client_metadata', 'Connecting '.self::providerName($provider).' to Filipino Homes is not open yet.');
        }

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
