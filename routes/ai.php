<?php

use App\Mcp\Servers\FilipinoHomesServer;
use Laravel\Mcp\Facades\Mcp;

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
*/

Mcp::web('/mcp/filipinohomes', FilipinoHomesServer::class)->middleware('mcp.key');
