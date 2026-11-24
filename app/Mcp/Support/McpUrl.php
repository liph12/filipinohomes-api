<?php

namespace App\Mcp\Support;

use Laravel\Mcp\Schema\Icon;

/**
 * The MCP server's own address, in one place.
 *
 * Behind the proxy that ends TLS for us, url() reads http:// (only the
 * forwarded address is trusted, not the forwarded scheme), and the same app
 * answers on more than one host name. What a person is told to connect Claude
 * to has to be one fixed, https address, so it comes from
 * config('fhmcp.public_url') when that is set, and from the request only when
 * it is not (local work, the tests).
 */
final class McpUrl
{
    /** Where the server is mounted (routes/ai.php). */
    public const PATH = '/mcp/filipinohomes';

    /** The API's public root, without a trailing slash. */
    public static function root(): string
    {
        $configured = trim((string) config('fhmcp.public_url', ''));

        return rtrim($configured !== '' ? $configured : url('/'), '/');
    }

    /** The address Claude is given. */
    public static function server(): string
    {
        return self::root().self::PATH;
    }

    /** The page on the website where a person confirms the account. */
    public static function consent(): string
    {
        return rtrim((string) config('fhmcp.consent_url', 'https://filipinohomes.com/connect/claude'), '/');
    }

    /**
     * The addresses of the server that a connection may have been made for: the current one, and any former
     * address still listed (config fhmcp.former_urls). A listed one keeps working while people move over.
     *
     * @return list<string>
     */
    public static function acceptedServers(): array
    {
        $former = array_map(
            fn (string $root): string => rtrim($root, '/').self::PATH,
            array_values(array_filter((array) config('fhmcp.former_urls', []), 'is_string')),
        );

        return array_values(array_unique([self::server(), ...$former]));
    }

    /** Whether an address is one this server answers at: the current one, or a former one still listed. */
    public static function isAccepted(string $address): bool
    {
        foreach (self::acceptedServers() as $accepted) {
            if (self::same($address, $accepted)) {
                return true;
            }
        }

        return false;
    }

    /** The document that says where to sign in, for the pointer in a 401 and for the .well-known address. */
    public static function resourceMetadata(): string
    {
        return self::root().'/.well-known/oauth-protected-resource'.self::PATH;
    }

    /**
     * An address as it is compared: lowercase scheme and host, no default
     * port, no trailing slash, no query and no fragment. Null when it is not
     * an absolute http(s) address.
     */
    public static function canonical(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.strtolower($parts['host'])
            .($port === null || $port === $default ? '' : ':'.$port)
            .rtrim($parts['path'] ?? '', '/');
    }

    /** Whether two addresses are the same place. */
    public static function same(string $one, string $other): bool
    {
        $a = self::canonical($one);

        return $a !== null && $a === self::canonical($other);
    }

    /**
     * The FH mark at this address, as the server's icon and every tool's (a client may show either,
     * e.g. beside a tool call). The files are public/fh-mark-192.png and fh-mark-512.png.
     *
     * @return list<Icon>
     */
    public static function icons(): array
    {
        $root = self::root();
        // Bump when the files change, so Cloudflare and clients that kept the old image fetch the new one.
        $version = '?v=3d';

        return [
            Icon::from($root.'/fh-mark-192.png'.$version, 'image/png', ['192x192']),
            Icon::from($root.'/fh-mark-512.png'.$version, 'image/png', ['512x512']),
        ];
    }
}
