<?php

namespace App\Services\McpOAuth;

use App\Mcp\Support\McpRole;
use App\Mcp\Support\McpUrl;
use App\Models\Setting;

/**
 * Whether people may sign Claude in, and which of them. Kept in the database
 * (System Settings > Claude access) so it is a switch, not a deploy:
 *
 *  - off:    no sign-in route answers, and a 401 says nothing about signing in.
 *  - admins: only admins may authorize or use a connection. The documents and
 *            refreshes still answer, so a pause does not strand the connectors
 *            Claude already holds (turning it off would).
 *  - on:     everyone who has a role on the MCP server.
 *
 * It is also off on any address Claude could not use: not https (plain http
 * is accepted for localhost, for testing).
 */
final class OAuthSwitch
{
    public const KEY = 'fhmcp.oauth';

    public const OFF = 'off';

    public const ADMINS = 'admins';

    public const ON = 'on';

    public const MODES = [self::OFF, self::ADMINS, self::ON];

    public static function mode(): string
    {
        $mode = (string) Setting::get(self::KEY, config('fhmcp.oauth_mode', self::OFF));

        return in_array($mode, self::MODES, true) ? $mode : self::OFF;
    }

    public static function set(string $mode): void
    {
        Setting::set(self::KEY, in_array($mode, self::MODES, true) ? $mode : self::OFF);
    }

    /** Whether signing in is answering at all. */
    public static function enabled(): bool
    {
        return self::mode() !== self::OFF && self::addressIsUsable();
    }

    /** Whether this API's address is one Claude can sign in to: https, or localhost for testing. */
    public static function addressIsUsable(): bool
    {
        $parts = parse_url(McpUrl::root());
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true));
    }

    /** Whether this role may authorize, and use a connection, right now. */
    public static function allows(McpRole $role): bool
    {
        return match (self::mode()) {
            self::ON => true,
            self::ADMINS => $role === McpRole::Admin,
            default => false,
        };
    }
}
