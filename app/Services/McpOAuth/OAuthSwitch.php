<?php

namespace App\Services\McpOAuth;

use App\Mcp\Support\McpRole;
use App\Mcp\Support\McpUrl;
use App\Models\Setting;

/**
 * Whether people may sign an AI in, and which of them. Kept in the database
 * (System Settings > AI access) so it is a switch, not a deploy:
 *
 *  - off:    no sign-in route answers, and a 401 says nothing about signing in.
 *  - admins: only admins may authorize or use a connection. The documents and
 *            refreshes still answer, so a pause does not strand the connectors
 *            an AI already holds (turning it off would).
 *  - on:     everyone who has a role on the MCP server.
 *
 * That switch (fhmcp.oauth) is the MASTER, and Claude's own: Claude is let in
 * as far as it lets people. ChatGPT has a switch of its own (fhmcp.oauth.chatgpt,
 * off until an admin opens it) that can only NARROW the master: a person can use
 * ChatGPT only where the master and ChatGPT's switch both allow them. Nothing
 * else about sign-in knows which AI a person signs in for.
 *
 * It is also off on any address an AI could not use: not https (plain http
 * is accepted for localhost, for testing).
 */
final class OAuthSwitch
{
    public const KEY = 'fhmcp.oauth';

    /** ChatGPT's own switch. */
    public const CHATGPT_KEY = 'fhmcp.oauth.chatgpt';

    public const OFF = 'off';

    public const ADMINS = 'admins';

    public const ON = 'on';

    public const MODES = [self::OFF, self::ADMINS, self::ON];

    /** The master switch. */
    public static function mode(): string
    {
        $mode = (string) Setting::get(self::KEY, config('fhmcp.oauth_mode', self::OFF));

        return in_array($mode, self::MODES, true) ? $mode : self::OFF;
    }

    public static function set(string $mode): void
    {
        Setting::set(self::KEY, in_array($mode, self::MODES, true) ? $mode : self::OFF);
    }

    /**
     * One AI's own switch: Claude's is the master itself; ChatGPT's is its own and starts off. An AI that is not
     * one of ours is off.
     */
    public static function providerMode(string $provider): string
    {
        if ($provider === OAuthClients::PROVIDER_CLAUDE) {
            return self::mode();
        }

        if ($provider !== OAuthClients::PROVIDER_CHATGPT) {
            return self::OFF;
        }

        $mode = (string) Setting::get(self::CHATGPT_KEY, config('fhmcp.chatgpt_oauth_mode', self::OFF));

        return in_array($mode, self::MODES, true) ? $mode : self::OFF;
    }

    /** Sets an AI's own switch (Claude's is the master). */
    public static function setProvider(string $provider, string $mode): void
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : self::OFF;

        if ($provider === OAuthClients::PROVIDER_CLAUDE) {
            self::set($mode);
        } elseif ($provider === OAuthClients::PROVIDER_CHATGPT) {
            Setting::set(self::CHATGPT_KEY, $mode);
        }
    }

    /** Whether signing in is answering at all. */
    public static function enabled(): bool
    {
        return self::mode() !== self::OFF && self::addressIsUsable();
    }

    /** Whether this API's address is one an AI can sign in to: https, or localhost for testing. */
    public static function addressIsUsable(): bool
    {
        $parts = parse_url(McpUrl::root());
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true));
    }

    /**
     * Whether this role may authorize, and use a connection, right now. Given an app (a client id) it is also that
     * app's AI that has to allow them; an app that is not one of ours is allowed nobody. Given none, it is the
     * master alone, as it always was.
     */
    public static function allows(McpRole $role, ?string $client = null): bool
    {
        if (! self::modeAllows(self::mode(), $role)) {
            return false;
        }

        if ($client === null) {
            return true;
        }

        $provider = OAuthClients::providerOf($client);

        return $provider !== null && self::modeAllows(self::providerMode($provider), $role);
    }

    /**
     * Whether authorization responses name their issuer (RFC 9207: the `iss` parameter, and the document that says
     * so). ChatGPT needs it to use its one stable return address. It is only done while ChatGPT is open, so that
     * everything Claude is sent stays as it was until then, and only on an address that is fixed: an issuer that
     * followed the request's own host could differ from the one the document names.
     */
    public static function issuerIdentified(): bool
    {
        return trim((string) config('fhmcp.public_url', '')) !== '' && self::providerMode(OAuthClients::PROVIDER_CHATGPT) !== self::OFF;
    }

    private static function modeAllows(string $mode, McpRole $role): bool
    {
        return match ($mode) {
            self::ON => true,
            self::ADMINS => $role === McpRole::Admin,
            default => false,
        };
    }
}
