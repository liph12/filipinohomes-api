<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the Leuterio Realty website's WRITE calls (changing a listing's status), by a static shared
 * secret the LR server sends as X-LR-Token. Same pattern as VerifyServiceToken, with its own secret so
 * it can be rotated without touching natcon-api-v2. Not the /fh-agent token: anyone can mint that one
 * from a public route, which is fine for reads but not for changing data.
 */
class VerifyLrToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // config(), never env(): env() is null after `php artisan config:cache`.
        $secret = (string) config('app.lr_api_token', '');
        $given = (string) $request->header('X-LR-Token', '');

        if ($secret === '' || $given === '' || ! hash_equals($secret, $given)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
