<?php

use App\Http\Controllers\Api\OrderIntakeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// --- Прийом замовлень із зовнішніх сайтів ---
Route::prefix('v1')->middleware('verify.external.source')->group(function () {
    Route::post('/orders/intake', [OrderIntakeController::class, 'store'])->name('api.orders.intake');
});
