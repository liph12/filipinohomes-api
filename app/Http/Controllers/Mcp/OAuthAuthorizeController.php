<?php

namespace App\Http\Controllers\Mcp;

use App\Mcp\Support\McpUrl;
use App\Services\McpOAuth\OAuthAuthorizations;
use App\Services\McpOAuth\OAuthError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /oauth/authorize: Claude has sent the person's browser here. The request
 * is checked and kept, and the browser goes on to the website to ask the person
 * (the page in McpUrl::consent()), carrying only an opaque handle. Where the
 * return address cannot be trusted the person sees a page of ours and goes
 * nowhere; a mistake after that goes back to Claude as OAuth says.
 */
class OAuthAuthorizeController extends OAuthController
{
    public function __invoke(Request $request, OAuthAuthorizations $authorizations): Response|JsonResponse|RedirectResponse
    {
        if ($response = $this->off() ?? $this->tooMany('fhmcp-oauth-authorize:'.$request->ip(), 60)) {
            return $response;
        }

        $query = $this->rawQuery($request);

        try {
            $handle = $authorizations->start($query);
        } catch (OAuthError $e) {
            if ($e->redirectable) {
                return redirect()->away(OAuthAuthorizations::errorRedirect($query, $e))->withHeaders(self::NO_STORE);
            }

            return $this->page($e);
        }

        return redirect()->away(McpUrl::consent().'?request='.$handle)->withHeaders(self::NO_STORE + ['Referrer-Policy' => 'no-referrer']);
    }

    /** A page of ours, saying nothing of what was sent. */
    private function page(OAuthError $e): Response
    {
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Filipino Homes</title><body style="font-family:system-ui,sans-serif;max-width:28rem;margin:4rem auto;padding:0 1rem;color:#23395E">'
            .'<h1 style="font-size:1.25rem">This sign-in link is not valid</h1>'
            .'<p>Please go back to the app and start again.</p></body></html>';

        return response($html, $e->status, self::NO_STORE + [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'",
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
