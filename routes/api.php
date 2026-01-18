<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Facebook\WebhookController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\ChatApiController;

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
