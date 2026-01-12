<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Facebook\WebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Facebook Webhook
// URL: domain.com/api/fb-webhook
Route::get('/fb-webhook', [WebhookController::class, 'verify']);
Route::post('/fb-webhook', [WebhookController::class, 'handle']);
