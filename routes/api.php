<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Facebook\WebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Meta Webhook
Route::get('/fb-webhook', [WebhookController::class, 'verify']);
Route::post('/fb-webhook', [WebhookController::class, 'handle']);
