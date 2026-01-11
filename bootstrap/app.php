<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        \App\Console\Commands\SyncDeliveryStatuses::class,
        \App\Console\Commands\FiscalizeDeliveredOrders::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Вимикаємо перевірку CSRF для маршрутів генерації ТТН
        $middleware->validateCsrfTokens(except: [
            'orders/*/generate-ttn',
            'api/fb-webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
