<?php

use App\Http\Controllers\Mcp\OAuthAuthorizeController;
use App\Http\Controllers\Mcp\OAuthMetadataController;
use App\Http\Controllers\Mcp\OAuthRegisterController;
use App\Http\Controllers\Mcp\OAuthTokenController;
use App\Mcp\Servers\FilipinoHomesServer;
use Illuminate\Support\Facades\Route;
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

/*
|--------------------------------------------------------------------------
| Signing Claude in
|--------------------------------------------------------------------------
|
| A person connects their own Claude to their FH account instead of copying a
| key (docs/mcp.md, "Sign-in"). These are the routes Claude's servers and the
| person's browser use. They sit at the root of the site, where the standard
| expects them; while the switch (System Settings > Claude access) is off every
| one of them answers 404, and the 401 of the MCP route says nothing about it.
|
*/

Route::get('/.well-known/oauth-protected-resource/{path?}', [OAuthMetadataController::class, 'resource'])->where('path', '.*');
Route::get('/.well-known/oauth-authorization-server/{path?}', [OAuthMetadataController::class, 'server'])->where('path', '.*');

Route::post('/oauth/register', OAuthRegisterController::class);
Route::get('/oauth/authorize', OAuthAuthorizeController::class);
Route::post('/oauth/token', [OAuthTokenController::class, 'token']);
Route::post('/oauth/revoke', [OAuthTokenController::class, 'revoke']);

// Where Claude looks when it could not use the documents once: the same routes, at the root.
Route::post('/register', OAuthRegisterController::class);
Route::get('/authorize', OAuthAuthorizeController::class);
Route::post('/token', [OAuthTokenController::class, 'token']);
