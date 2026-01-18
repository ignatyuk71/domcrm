<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Facebook\WebhookController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\ChatApiController;
use App\Http\Controllers\SavedFileController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Meta Webhook
Route::get('/fb-webhook', [WebhookController::class, 'verify']);
Route::post('/fb-webhook', [WebhookController::class, 'handle']);

Route::get('/chat/templates', [MessageTemplateController::class, 'apiList']);
Route::get('/chat/unread-count', [ChatApiController::class, 'getUnreadCount']);
Route::get('/saved-files', [SavedFileController::class, 'index']);
Route::post('/saved-files', [SavedFileController::class, 'store']);
Route::delete('/saved-files/{id}', [SavedFileController::class, 'destroy']);
