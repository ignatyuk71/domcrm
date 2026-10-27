<?php

use App\Modules\Gpt\Http\DraftController;
use App\Modules\Gpt\Http\PrivateResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'role:owner', PrivateResponse::class])
    ->prefix('settings/gpt')->name('settings.gpt.')->group(function () {
        Route::get('/', [DraftController::class, 'page'])->name('index');
        Route::get('/data', [DraftController::class, 'data'])->name('data');
        Route::post('/attachments', [DraftController::class, 'upload'])->middleware('throttle:12,1,gpt-uploads')->name('upload');
        Route::get('/attachments/{file}', [DraftController::class, 'attachment'])->where('file', '[a-f0-9-]{36}\.(jpg|png|webp)')->name('attachment');
        Route::put('/settings', [DraftController::class, 'saveSettings'])->name('settings');
        Route::get('/dialogues/{dialogue}', [DraftController::class, 'dialogue'])->whereNumber('dialogue')->name('dialogue');
        Route::post('/drafts', [DraftController::class, 'create'])->middleware('throttle:6,1,gpt-drafts')->name('create');
        Route::get('/drafts/{run}', [DraftController::class, 'show'])->whereNumber('run')->name('show');
    });
