<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API memakai cookie session Laravel (SPA/HTML statis same-origin).
        // CATATAN: /app/*.html adalah file statis yang di-serve web server
        // (nginx/php built-in) TANPA melewati Laravel — jadi XSRF-TOKEN +
        // session cookie diterbitkan oleh endpoint API pertama yang dipanggil
        // frontend (GET /api/me), bukan oleh halaman HTML.
        $middleware->api(prepend: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
        $middleware->alias([
            'pj' => \App\Http\Middleware\EnsureUserIsPj::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);
        // Trust reverse proxy (TLS terminate di proxy) agar URL ter-generate https
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
