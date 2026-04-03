<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AllowAdPreviewIframe
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->query('_ad_preview') !== '1') {
            return $response;
        }

        $token = $request->query('_preview_token');

        if (!$token || !Cache::has("ad_preview_token:{$token}")) {
            return $response;
        }

        $dashboardUrl = config('app.dashboard_url', '*');

        $response->headers->remove('X-Frame-Options');
        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors 'self' {$dashboardUrl}"
        );

        return $response;
    }
}
