<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\NovaPoshtaController;
use App\Http\Controllers\OrderSourceController;
use App\Http\Controllers\FiscalController;
use App\Http\Controllers\PackingController;
use App\Http\Controllers\ChatController;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan; // Додано для роботи з командами

/*
|--------------------------------------------------------------------------
| Публічні маршрути
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return view('welcome');
});


Route::get('/dashboard', function () {
    $today = Carbon::today();

    $newOrdersCount = Order::whereDate('created_at', $today)->count();
    $inWorkCount = Order::where('packing_status', 'processing')->count();
    $readyToShipCount = Order::where('packing_status', 'packed')->count();

    $revenueToday = OrderItem::query()
        ->join('orders', 'orders.id', '=', 'order_items.order_id')
        ->whereDate('orders.created_at', $today)
        ->sum('order_items.total');

    $recentOrders = Order::query()
        ->with(['customer', 'statusRef'])
        ->withSum('items', 'total')
        ->orderByDesc('created_at')
        ->limit(5)
        ->get();

    $startDate = $today->copy()->subDays(6)->startOfDay();
    $endDate = $today->copy()->endOfDay();
    $salesByDate = OrderItem::query()
        ->join('orders', 'orders.id', '=', 'order_items.order_id')
        ->whereBetween('orders.created_at', [$startDate, $endDate])
        ->selectRaw('DATE(orders.created_at) as date, SUM(order_items.total) as total')
        ->groupBy('date')
        ->pluck('total', 'date');

    $chartLabels = [];
    $chartValues = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = $today->copy()->subDays($i);
        $key = $date->toDateString();
        $chartLabels[] = $date->format('d.m');
        $chartValues[] = (float) ($salesByDate[$key] ?? 0);
    }

    $stats = [
        [
            'label' => 'Нові замовлення',
            'value' => (string) $newOrdersCount,
            'sub' => 'за сьогодні',
            'bg' => 'linear-gradient(135deg, #eff6ff, #ffffff)',
            'icon_bg' => '#dbeafe',
            'icon_color' => '#2563eb',
            'icon' => 'bi-cart-plus-fill',
        ],
        [
            'label' => 'В роботі',
            'value' => (string) $inWorkCount,
            'sub' => 'у пакуванні',
            'bg' => 'linear-gradient(135deg, #fffbeb, #ffffff)',
            'icon_bg' => '#fef3c7',
            'icon_color' => '#d97706',
            'icon' => 'bi-fire',
        ],
        [
            'label' => 'Готові до відправки',
            'value' => (string) $readyToShipCount,
            'sub' => 'пакування завершено',
            'bg' => 'linear-gradient(135deg, #f0fdf4, #ffffff)',
            'icon_bg' => '#dcfce7',
            'icon_color' => '#16a34a',
            'icon' => 'bi-box-seam-fill',
        ],
        [
            'label' => 'Дохід за день',
            'value' => number_format((float) $revenueToday, 0, '.', ' ') . ' ₴',
            'sub' => 'сьогодні',
            'bg' => 'linear-gradient(135deg, #f5f3ff, #ffffff)',
            'icon_bg' => '#ede9fe',
            'icon_color' => '#7c3aed',
            'icon' => 'bi-wallet-fill',
        ],
    ];

    return view('dashboard', [
        'stats' => $stats,
        'recentOrders' => $recentOrders,
        'chartLabels' => $chartLabels,
        'chartValues' => $chartValues,
    ]);
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

    // --- КЛІЄНТИ (Customers) ---
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

    // --- ЧАТ ---
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{id}', [ChatController::class, 'getMessages'])->name('chat.messages');

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

    // --- ФІСКАЛІЗАЦІЯ (Checkbox) ---
    Route::controller(FiscalController::class)->prefix('api')->name('fiscal.')->group(function () {
        Route::post('/orders/{order}/fiscalize', 'fiscalize')->name('fiscalize');
        Route::post('/orders/{order}/refund', 'refund')->name('refund');
        Route::get('/orders/{order}/fiscal-status', 'status')->name('status');
    });

    // --- ПАКУВАННЯ ---
    Route::prefix('packing')->name('packing.')->controller(PackingController::class)->group(function () {
        Route::get('/list', 'index')->name('list');
        Route::get('/{order}', 'show')->name('show');
        Route::post('/{order}/start', 'start')->name('start');
        Route::post('/{order}/finish', 'finish')->name('finish');
        Route::post('/{order}/pause', 'pause')->name('pause');
        Route::post('/{order}/problem', 'problem')->name('problem');
    });

    Route::get('/api/packing/list', [PackingController::class, 'list'])->name('packing.api.list');
    Route::get('/api/packing/history', [PackingController::class, 'history'])->name('packing.api.history');

    // --- ПРОФІЛЬ КОРИСТУВАЧА ---
    Route::controller(ProfileController::class)->prefix('profile')->name('profile.')->group(function () {
        Route::get('/', 'edit')->name('edit');
        Route::patch('/', 'update')->name('update');
        Route::delete('/', 'destroy')->name('destroy');
    });

    // --- ОБСЛУГОВУВАННЯ СИСТЕМИ ---
    Route::get('/clear-everything', function () {
        Artisan::call('route:clear');
        Artisan::call('config:clear');
        Artisan::call('cache:clear');
        return 'Кеш, маршрути та конфіги очищено! Тепер все чисто.';
    });


    Route::view('/privacy', 'privacy');


});

require __DIR__.'/auth.php';