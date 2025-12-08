<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware): void {
        // Aliases
        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
        ]);

        // Stateful, session-backed web stack
        $middleware->web([
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Http\Middleware\HandleCors::class,

            // (Optional) enable temporarily for troubleshooting
            // \App\Http\Middleware\LogSetCookies::class,
            // \App\Http\Middleware\LogQueuedCookies::class,

            // MUST remain last in 'web' so it can strip any stray cookies
            \App\Http\Middleware\KeepOnlyKnownCookies::class,
        ]);

        // Stateless API stack (no session, no CSRF, no stateful Sanctum)
        $middleware->api([
            \Illuminate\Http\Middleware\HandleCors::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            // \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
        ]);

        // Catch-all: ensure every response (including Sanctum's /sanctum/csrf-cookie)
        // passes through our filter. This runs at the OUTERMOST layer.
        //$middleware->append(\App\Http\Middleware\KeepOnlyKnownCookies::class);
    })
    ->withRouting(
        web: base_path('routes/web.php'),
        commands: base_path('routes/console.php'),
        health: '/up',
    )
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
