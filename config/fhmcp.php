<?php

/*
|--------------------------------------------------------------------------
| FH's MCP server (Claude reading FH's numbers): /mcp/filipinohomes
|--------------------------------------------------------------------------
|
| See docs/mcp.md. Read-only by construction: the tools only read, and
| nothing here can be turned up to make them write. These are the dials
| that are safe to turn.
|
*/

return [

    // The address of this API as Claude reaches it, like https://api.example.com (no path, no
    // trailing slash). It is what the dashboard shows and what a new key's connect command uses.
    // Left empty, the address each request came to is used, which reads http:// behind a proxy
    // that ends TLS for us: set it on production.
    'public_url' => env('FH_MCP_PUBLIC_URL'),

    // Sign-in for Claude (people connect their own Claude with their FH login instead of copying a key).
    // The switch itself is in the database, set from System Settings > Claude access (off, admins or on);
    // this is what it is until someone sets it. Off means no sign-in route answers at all.
    'oauth_mode' => env('FH_MCP_OAUTH', 'off'),

    // The page on the website where a person confirms the account (the API sends the browser there).
    'consent_url' => env('FH_MCP_CONSENT_URL', 'https://filipinohomes.com/connect/claude'),

    // Addresses this API used to be reached at, comma separated, like https://old.example.com. A connection
    // made at one keeps working while it is listed, and its person is asked to reconnect.
    'former_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('FH_MCP_FORMER_URLS', ''))))),

    // How long a connection's access token lasts (every request re-checks the connection, the person's role
    // and the switch, so this is not how long access lasts), how long an unused connection survives (the
    // refresh token slides forward each time it is used), and how many connections a person may hold.
    'access_token_hours' => (int) env('FH_MCP_ACCESS_HOURS', 8),
    'refresh_days' => (int) env('FH_MCP_REFRESH_DAYS', 60),
    'max_connections' => 5,

    // The longest a connection lasts from the day it was made, however often it is used: after that the
    // person connects again. (The refresh token slides forward each time it is used, so without this one
    // that is used often would never end.)
    'max_connection_days' => (int) env('FH_MCP_MAX_CONNECTION_DAYS', 180),

    // Let a client that has no key yet (claude.ai probes a connector this way when it is
    // added) do the handshake and list the tools. Calling a tool still needs a key. Off by
    // default: a key-less request is a plain 401.
    'open_handshake' => (bool) env('FH_MCP_OPEN_HANDSHAKE', false),

    // Requests a minute for one key (every call a client makes counts, listing the tools too).
    'rate_limit' => (int) env('FH_MCP_RATE_LIMIT', 60),

    // Wrong keys a minute from one address before it is cut off for the rest of the minute.
    'ip_failures' => (int) env('FH_MCP_IP_FAILURES', 20),

    // Requests a minute from one address that carries no key (only matters with open_handshake).
    'ip_rate_limit' => (int) env('FH_MCP_IP_RATE_LIMIT', 30),

    // Keys one person may hold at once, how long a new key lasts unless asked otherwise, and the longest it may.
    // (An admin can also make a key permanent from the dashboard: that one has no end date, and the
    // longest does not apply to it. It still counts toward max_keys until it is revoked.)
    'max_keys' => 3,
    'default_days' => 30,
    'max_days' => 90,

    // The most a tool hands back at once (bytes of JSON), and the most records on a page.
    // Over it, the tool says to narrow the question instead of sending a wall of text.
    'max_result_bytes' => 60000,
    'max_per_page' => 25,

];
