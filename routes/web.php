<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\NovaPoshtaController;
use App\Http\Controllers\OrderSourceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/list', [OrderController::class, 'list'])->name('orders.list');

    Route::get('/orders/create', function () {
        return view('orders.create');
    })->name('orders.create');

    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders/{order}/edit', function (\App\Models\Order $order) {
        return view('orders.edit', ['orderId' => $order->id]);
    })->name('orders.edit');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::put('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');
    Route::patch('/orders/{order}/tags', [OrderController::class, 'updateTags'])->name('orders.tags');
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.updateStatus');

    // Nova Poshta lookups
    Route::get('/nova-poshta/cities', [NovaPoshtaController::class, 'cities'])->name('novaPoshta.cities');
    Route::get('/nova-poshta/warehouses', [NovaPoshtaController::class, 'warehouses'])->name('novaPoshta.warehouses');
    Route::get('/nova-poshta/streets', [NovaPoshtaController::class, 'streets'])->name('novaPoshta.streets');

    Route::get('/statuses', [StatusController::class, 'index'])->name('statuses.index');
    Route::get('/order-sources', [OrderSourceController::class, 'index'])->name('orderSources.index');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::get('/tags', [TagController::class, 'index'])->name('tags.index');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
