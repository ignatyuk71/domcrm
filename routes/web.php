<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\NovaPoshtaController;
use App\Http\Controllers\OrderSourceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Публічні маршрути
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

/*
|--------------------------------------------------------------------------
| Маршрути з авторизацією (auth)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {

    // --- ЗАМОВЛЕННЯ (Orders) ---
    Route::controller(OrderController::class)->group(function () {
        Route::get('/orders', 'index')->name('orders.index');
        Route::get('/orders/list', 'list')->name('orders.list');
        
        Route::get('/orders/create', function () {
            return view('orders.create');
        })->name('orders.create');

        Route::get('/orders/{order}', 'show')->name('orders.show');
        Route::get('/orders/{order}/edit', function (\App\Models\Order $order) {
            return view('orders.edit', ['orderId' => $order->id]);
        })->name('orders.edit');

        Route::post('/orders', 'store')->name('orders.store');
        Route::put('/orders/{order}', 'update')->name('orders.update');
        Route::delete('/orders/{order}', 'destroy')->name('orders.destroy');
        
        // Додаткові дії з замовленням
        Route::patch('/orders/{order}/tags', 'updateTags')->name('orders.tags');
        Route::patch('/orders/{order}/status', 'updateStatus')->name('orders.updateStatus');
        
        // Нова Пошта: Генерація, Анулювання та Друк
        Route::post('/orders/{order}/generate-ttn', 'generateTTN')->name('orders.generateTTN');
        Route::post('/orders/{order}/cancel-ttn', 'cancelTTN')->name('orders.cancelTTN');
        Route::get('/orders/{order}/print-ttn', 'printTTN')->name('orders.printTTN');
        Route::post('/orders/{order}/track-delivery', 'trackDelivery')->name('orders.trackDelivery');
    });

    // --- НОВА ПОШТА (Nova Poshta довідники) ---
    Route::controller(NovaPoshtaController::class)->prefix('nova-poshta')->name('novaPoshta.')->group(function () {
        Route::get('/cities', 'cities')->name('cities');
        Route::get('/warehouses', 'warehouses')->name('warehouses');
        Route::get('/streets', 'streets')->name('streets');
        
        // Debug маршрут для перевірки даних відправника
        Route::get('/debug-sender', fn(\App\Services\NovaPoshtaService $np) => $np->getSenderData())->name('debug');
    });

    // --- ТОВАРИ (Products) ---
    Route::controller(ProductController::class)->prefix('products')->name('products.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{product}/edit', 'edit')->name('edit');
        Route::put('/{product}', 'update')->name('update');
    });

    // --- ДОВІДНИКИ ---
    Route::get('/statuses', [StatusController::class, 'index'])->name('statuses.index');
    Route::get('/order-sources', [OrderSourceController::class, 'index'])->name('orderSources.index');
    Route::get('/tags', [TagController::class, 'index'])->name('tags.index');

    // --- ПРОФІЛЬ КОРИСТУВАЧА ---
    Route::controller(ProfileController::class)->prefix('profile')->name('profile.')->group(function () {
        Route::get('/', 'edit')->name('edit');
        Route::patch('/', 'update')->name('update');
        Route::delete('/', 'destroy')->name('destroy');
    });
});

require __DIR__.'/auth.php';
