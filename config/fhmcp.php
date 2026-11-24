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
