<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel's auth middleware redirects guests to a route named `login`,
        // which this app does not have -- the panel names its own. Without this
        // an unauthenticated request to a guarded route 500s instead of
        // redirecting to the sign-in page.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

        // Slack posts server to server and cannot carry a session token. The
        // route authenticates by verifying Slack's request signature instead.
        $middleware->validateCsrfTokens(except: ['slack/commands/*']);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
