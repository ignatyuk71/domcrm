<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        \App\Console\Commands\SyncDeliveryStatuses::class,
        \App\Console\Commands\FiscalizeDeliveredOrders::class,
        \App\Console\Commands\ReleaseStalePackingOrders::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserRole::class,
            'verify.external.source' => \App\Http\Middleware\VerifyExternalSource::class,
        ]);
        // CSRF діє на всі POST (зокрема генерацію ТТН — це гроші). Фронт шле токен:
        // generate-ttn(-courier) — через X-CSRF-TOKEN (fetch), cancel/print — XSRF-кукі (axios).
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
