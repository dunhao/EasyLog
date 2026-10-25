<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(\EasyLog\Http\Middleware\ResolveTenantContext::class);
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
            \EasyLog\Http\Middleware\RateLimitByAppId::class,
        ]);
        $middleware->alias([
            'ability' => \EasyLog\Http\Middleware\CheckAbility::class,
            'audit'   => \EasyLog\Http\Middleware\AuditMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
