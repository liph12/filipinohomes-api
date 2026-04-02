<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->wantsJson()
        );

        $exceptions->render(function (QueryException $e, Request $request) {
            if ($e->getCode() !== '40001') {
                return null;
            }

            logger()->warning('Deadlock detected on cache write, suppressing.', [
                'sql'      => $e->getSql(),
                'bindings' => $e->getBindings(),
                'url'      => $request->fullUrl(),
                'method'   => $request->method(),
            ]);

            // Return null so Laravel continues with the normal response
            // The cache write is skipped — acceptable for rate limiter/timer keys
            return null;
        });
    })->create();