<?php

use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpenseReceiptController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:owner'])->group(function () {
    Route::get('/expenses', fn () => response()->view('expenses.index')->header('Cache-Control', 'no-store, private'))->name('expenses.index');
    Route::prefix('api/expenses')->group(function () {
        Route::get('meta', [ExpenseController::class, 'meta']);
        Route::get('export', [ExpenseController::class, 'export']);
        Route::post('categories', [ExpenseController::class, 'category']);
        Route::post('accounts', [ExpenseController::class, 'account']);
        Route::post('groups', [ExpenseController::class, 'group']);
        Route::get('receipts/{receipt}', [ExpenseReceiptController::class, 'show'])->whereNumber('receipt');
        Route::delete('receipts/{receipt}', [ExpenseReceiptController::class, 'destroy'])->whereNumber('receipt');
        Route::get('/', [ExpenseController::class, 'index']);
        Route::post('/', [ExpenseController::class, 'store']);
        Route::get('{expense}', [ExpenseController::class, 'show'])->whereNumber('expense');
        Route::put('{expense}', [ExpenseController::class, 'update'])->whereNumber('expense');
        Route::delete('{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense');
        Route::post('{expense}/payments', [ExpenseController::class, 'storePayment'])->whereNumber('expense');
        Route::put('{expense}/payments/{payment}', [ExpenseController::class, 'updatePayment'])->whereNumber(['expense', 'payment']);
        Route::delete('{expense}/payments/{payment}', [ExpenseController::class, 'destroyPayment'])->whereNumber(['expense', 'payment']);
        Route::post('{expense}/payments/{payment}/receipts', [ExpenseReceiptController::class, 'store'])->whereNumber(['expense', 'payment']);
    });
});
