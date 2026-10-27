<?php

use App\Modules\Gpt\Http\DraftController;
use App\Modules\Gpt\Http\PrivateResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'role:owner', PrivateResponse::class])
    ->prefix('settings/gpt')->name('settings.gpt.')->group(function () {
        Route::get('/', [DraftController::class, 'page'])->name('index');
        Route::get('/data', [DraftController::class, 'data'])->name('data');
        Route::put('/settings', [DraftController::class, 'saveSettings'])->name('settings');
        Route::get('/dialogues/{dialogue}', [DraftController::class, 'dialogue'])->whereNumber('dialogue')->name('dialogue');
        Route::post('/drafts', [DraftController::class, 'create'])->middleware('throttle:6,1,gpt-drafts')->name('create');
        Route::get('/drafts/{run}', [DraftController::class, 'show'])->whereNumber('run')->name('show');
    });
