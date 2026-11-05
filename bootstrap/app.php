<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Webhooks live outside the "web" group: no sessions, cookies or CSRF.
        then: function () {
            Route::prefix('webhooks')->name('webhooks.')->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Fly.io's proxy: trust X-Forwarded-* so URLs, HTTPS and client IPs are right.
        $middleware->trustProxies(at: '*');

        // Admin password check before route-model binding, so a stranger learns nothing
        // (not even whether a sync job id exists) without logging in.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\HorizonBasicAuth::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Error tracking. Active once `composer require sentry/sentry-laravel` is installed and
        // SENTRY_LARAVEL_DSN is set; without the package this line is skipped.
        if (class_exists(\Sentry\Laravel\Integration::class)) {
            \Sentry\Laravel\Integration::handles($exceptions);
        }

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'webhooks/*') || $request->expectsJson(),
        );
    })->create();
