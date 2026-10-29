<?php

use App\Modules\GptSandbox\Http\PrivateSandboxResponse;
use App\Modules\GptSandbox\Http\SandboxController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'role:owner', PrivateSandboxResponse::class])
    ->prefix('settings/gpt-sandbox')->name('gptSandbox.')->group(function () {
        Route::get('/', [SandboxController::class, 'index'])->name('index');
        Route::get('/state', [SandboxController::class, 'state'])->name('state');
        Route::get('/catalog/photos/{photo}', [SandboxController::class, 'catalogImage'])->whereNumber('photo')->name('catalogImage');
        Route::get('/turns/{turn}/images/{index}', [SandboxController::class, 'image'])->whereNumber('index')->name('image');

        Route::middleware('throttle:gpt-sandbox')->group(function () {
            Route::put('/settings', [SandboxController::class, 'save'])->name('save');
            Route::post('/connection', [SandboxController::class, 'connection'])->name('connection');
            Route::post('/chats', [SandboxController::class, 'newChat'])->name('newChat');
            Route::post('/chats/{chat}/messages', [SandboxController::class, 'send'])->name('send');
            Route::post('/chats/{chat}/turns/{turn}/retry', [SandboxController::class, 'retry'])->name('retry');
        });
    });
