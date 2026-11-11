<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StripHtmlTags
{
    /**
     * Fields to exclude from stripping (e.g. rich-text editors, passwords).
     */
    protected array $except = [
        'password',
        'password_confirmation',
        // Admin Mailbox compose body: plain text that MailboxService escapes
        // (htmlspecialchars) before building the HTML, so stripping would only
        // eat legitimate text like "price < 5M".
        'mail_body',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $input = $request->all();
        array_walk_recursive($input, function (&$value, $key) {
            if (! in_array($key, $this->except) && is_string($value)) {
                $value = strip_tags($value);
            }
        });

        $request->merge($input);

        return $next($request);
    }
}  