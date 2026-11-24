<?php

use App\Mcp\Servers\FilipinoHomesServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

/*
|--------------------------------------------------------------------------
| MCP servers
|--------------------------------------------------------------------------
|
| Claude reading Filipino Homes' numbers (docs/mcp.md). Every request needs a
| key made with `php artisan fhmcp:make-key`; a key opens this endpoint and
| nothing else. The tools are read-only, and an admin and an agent are shown
| different ones.
|
| The package's own WWW-Authenticate middleware is taken off this route: it
| replaces whatever a 401 says with its own header, and what a refused client
| is told is the gate's to say (AuthenticateMcpKey).
|
*/

Mcp::web('/mcp/filipinohomes', FilipinoHomesServer::class)
    ->middleware('mcp.key')
    ->withoutMiddleware(AddWwwAuthenticateHeader::class);
