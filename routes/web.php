<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\NovaPoshtaController;
use App\Http\Controllers\OrderSourceController;
use App\Http\Controllers\AiGalleryController;
use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChatStatusController;
use App\Http\Controllers\ColorController;
use App\Http\Controllers\FiscalController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\PackingController;
use App\Http\Controllers\SavedFileController;
use App\Http\Controllers\NovaPoshtaSettingsController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\IntegrationSettingsController;
use App\Http\Controllers\IntegrationMappingController;
use App\Http\Controllers\MetaConnectionController;
use App\Http\Controllers\InboxController;
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

Route::view('/privacy-policy', 'privacy')->name('privacy');
Route::view('/terms-of-use', 'terms-of-use')->name('terms-of-use');


Route::middleware(['auth', 'verified', 'role:owner,operator,packer'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/api/dashboard/data', [DashboardController::class, 'data'])->name('dashboard.data');
});

/*
|--------------------------------------------------------------------------
| Маршрути з авторизацією (auth)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {

    // --- ЗАМОВЛЕННЯ (Orders) ---
    Route::middleware('role:owner,operator')->controller(OrderController::class)->group(function () {
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
        Route::patch('/orders/{order}/comment', 'updateComment')->name('orders.updateComment');
        
        // Нова Пошта: Генерація, Анулювання та Друк
        Route::post('/orders/{order}/generate-ttn', 'generateTTN')->name('orders.generateTTN');
        Route::post('/orders/{order}/generate-ttn-courier', 'generateTTNCourier')->name('orders.generateTTNCourier');
        Route::post('/orders/{order}/cancel-ttn', 'cancelTTN')->name('orders.cancelTTN');
        Route::get('/orders/{order}/print-ttn', 'printTTN')->name('orders.printTTN');
        Route::post('/orders/{order}/track-delivery', 'trackDelivery')->name('orders.trackDelivery');
    });

    // --- КЛІЄНТИ (Customers) ---
    Route::middleware('role:owner,operator')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::put('/api/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    });

    // --- ЧАТ / ІНБОКС (Messenger + Instagram) ---
    Route::middleware('role:owner,operator')->group(function () {
        Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::get('/orders-board', [InboxController::class, 'board'])->name('inbox.board');
        Route::get('/api/inbox/board', [InboxController::class, 'boardData'])->name('inbox.boardData');
        Route::get('/api/inbox/conversations', [InboxController::class, 'conversations'])->name('inbox.conversations');
        Route::get('/api/inbox/conversations/{conversation}/messages', [InboxController::class, 'messages'])->name('inbox.messages');
        Route::get('/api/inbox/conversations/{conversation}/preview', [InboxController::class, 'preview'])->name('inbox.preview');
        Route::post('/api/inbox/conversations/{conversation}/send', [InboxController::class, 'send'])->name('inbox.send');
        Route::post('/api/inbox/sync', [InboxController::class, 'sync'])->name('inbox.sync');
        Route::post('/api/inbox/conversations/{conversation}/send-attachment', [InboxController::class, 'sendAttachment'])->name('inbox.sendAttachment');
        Route::post('/api/inbox/conversations/{conversation}/send-gallery', [InboxController::class, 'sendGallery'])->name('inbox.sendGallery');
        Route::get('/inbox/page-avatar/{connection}', [InboxController::class, 'pageAvatar'])->name('inbox.pageAvatar');
        Route::get('/api/chat-statuses', [ChatStatusController::class, 'index'])->name('chatStatuses.list');
        Route::post('/api/inbox/conversations/{conversation}/status', [InboxController::class, 'setStatus'])->name('inbox.setStatus');
        Route::post('/api/inbox/conversations/{conversation}/ai', [InboxController::class, 'setAi'])->name('inbox.setAi');
        Route::post('/api/inbox/conversations/{conversation}/ai-reset', [InboxController::class, 'resetAiContext'])->name('inbox.aiReset');
        Route::post('/api/inbox/conversations/{conversation}/ai-summary', [InboxController::class, 'aiSummary'])->name('inbox.aiSummary');
        Route::post('/api/inbox/conversations/{conversation}/ai-order-handled', [InboxController::class, 'markAiOrderHandled'])->name('inbox.aiOrderHandled');
        Route::get('/api/inbox/comments', [InboxController::class, 'comments'])->name('inbox.comments');
        Route::post('/api/inbox/comments/{comment}/dm', [InboxController::class, 'commentDm'])->name('inbox.commentDm');
        Route::get('/api/inbox/comments/{comment}/conversation', [InboxController::class, 'commentConversation'])->name('inbox.commentConversation');
        Route::delete('/api/inbox/comments/{comment}', [InboxController::class, 'destroyComment'])->name('inbox.commentDestroy');
        Route::post('/api/inbox/conversations/{conversation}/refresh', [InboxController::class, 'refresh'])->name('inbox.refresh');
        Route::get('/api/inbox/conversations/{conversation}/panel', [InboxController::class, 'panel'])->name('inbox.panel');
        Route::post('/api/inbox/conversations/{conversation}/attach-customer', [InboxController::class, 'attachCustomer'])->name('inbox.attachCustomer');
        Route::post('/api/inbox/conversations/{conversation}/attach-order', [InboxController::class, 'attachOrder'])->name('inbox.attachOrder');
        Route::post('/api/inbox/conversations/{conversation}/clear', [InboxController::class, 'clear'])->name('inbox.clear');
        Route::delete('/api/inbox/conversations/{conversation}', [InboxController::class, 'destroy'])->name('inbox.destroy');
    });

    Route::middleware('role:owner,operator')->group(function () {
        Route::get('/api/saved-files', [SavedFileController::class, 'index'])->name('savedFiles.index');
        Route::post('/api/saved-files', [SavedFileController::class, 'store'])->name('savedFiles.store');
        Route::delete('/api/saved-files/{id}', [SavedFileController::class, 'destroy'])->name('savedFiles.destroy');

        // --- ГАЛЕРЕЯ ---
        Route::view('/gallery', 'gallery.index')->name('gallery.index');

        // --- НОВА ПОШТА (Nova Poshta довідники) ---
        Route::controller(NovaPoshtaController::class)->prefix('nova-poshta')->name('novaPoshta.')->group(function () {
            Route::get('/cities', 'cities')->name('cities');
            Route::get('/warehouses', 'warehouses')->name('warehouses');
            Route::get('/streets', 'streets')->name('streets');

            // Debug маршрут для перевірки даних відправника
            Route::get('/debug-sender', fn(\App\Services\NovaPoshtaService $np) => $np->getSenderData())->name('debug');
        });
    });

    // --- ФІНАНСИ / КАСА (Checkbox) ---
    Route::middleware('role:owner')->group(function () {
        Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
        Route::get('/api/finance/checkbox', [FinanceController::class, 'data'])->name('finance.checkbox.data');
        Route::post('/finance/checkbox', [FinanceController::class, 'save'])->name('finance.checkbox.save');
        Route::post('/finance/checkbox/test', [FinanceController::class, 'test'])->name('finance.checkbox.test');
        Route::post('/finance/checkbox/shift/open', [FinanceController::class, 'openShift'])->name('finance.checkbox.openShift');
        Route::post('/finance/checkbox/shift/close', [FinanceController::class, 'closeShift'])->name('finance.checkbox.closeShift');
        Route::post('/finance/checkbox/queue/process', [FinanceController::class, 'processQueue'])->name('finance.checkbox.processQueue');
    });

    // Debug: перевірка відповідності StreetRef до SettlementRef для конкретного замовлення
    Route::middleware('role:owner')->get('/debug-np-address/{order}', function (Order $order, \App\Services\NovaPoshtaService $np) {
        if (!config('app.debug') && !app()->environment('local')) {
            abort(404);
        }

        $order->load('delivery');
        $delivery = $order->delivery;

        if (!$delivery) {
            return response()->json([
                'error' => 'Delivery not found for order',
                'order_id' => $order->id,
            ], 404);
        }

        $streetRef = $delivery->street_ref;
        $streetName = $delivery->street_name;

        $result = [
            'order_id' => $order->id,
            'order_number' => $order->order_number ?? null,
            'delivery' => [
                'city_ref' => $delivery->city_ref,
                'street_ref' => $streetRef,
                'street_name' => $streetName,
                'building' => $delivery->building,
                'apartment' => $delivery->apartment,
            ],
        ];

        if (!$delivery->city_ref || !$streetName) {
            $result['error'] = 'Missing city_ref or street_name';
            return response()->json($result, 422);
        }

        $options = $np->searchStreets((string) $delivery->city_ref, $streetName, 50);
        $match = false;
        foreach ($options as $option) {
            if (($option['ref'] ?? null) === $streetRef) {
                $match = true;
                break;
            }
        }

        $result['search'] = [
            'query' => $streetName,
            'count' => count($options),
            'match' => $match,
            'options' => $options,
        ];

        return response()->json($result);
    })->name('novaPoshta.debugAddress');

    // --- ТОВАРИ (Products) ---
    Route::middleware('role:owner,operator')->controller(ProductController::class)->prefix('products')->name('products.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/categories', 'categories')->name('categories');
        Route::get('/colors', 'colors')->name('colors');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::patch('/variants/{variant}', 'updateVariant')->name('variants.update');
        Route::patch('/{product}/inline', 'updateInline')->name('inline.update');
        Route::get('/{product}/edit', 'edit')->name('edit');
        Route::put('/{product}', 'update')->name('update');
        Route::delete('/{product}', 'destroy')->name('destroy');
    });

    // --- ІНТЕГРАЦІЇ: МАПІНГ ТОВАРІВ (власник + оператор) ---
    Route::middleware('role:owner,operator')->prefix('integrations')->name('integrations.')->group(function () {
        Route::get('/review', [IntegrationMappingController::class, 'review'])->name('review');
        Route::post('/review/map', [IntegrationMappingController::class, 'map'])->name('review.map');
    });

    // --- ДОВІДНИКИ ---
    Route::middleware('role:owner,operator')->group(function () {
        Route::get('/statuses', [StatusController::class, 'index'])->name('statuses.index');
        Route::get('/order-sources', [OrderSourceController::class, 'index'])->name('orderSources.index');
        Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
    });

    Route::middleware('role:owner')->group(function () {
        Route::post('/statuses', [StatusController::class, 'store'])->name('statuses.store');
        Route::put('/statuses/{status}', [StatusController::class, 'update'])->name('statuses.update');
        Route::delete('/statuses/{status}', [StatusController::class, 'destroy'])->name('statuses.destroy');
        Route::post('/tags', [TagController::class, 'store'])->name('tags.store');
        Route::put('/tags/{tag}', [TagController::class, 'update'])->name('tags.update');
        Route::delete('/tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
    });

    // --- НАЛАШТУВАННЯ: ДОВІДНИКИ ---
    Route::middleware('role:owner')->prefix('settings')->name('settings.')->group(function () {
        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::post('/team', [TeamController::class, 'store'])->name('team.store');
        Route::patch('/team/{user}', [TeamController::class, 'update'])->name('team.update');
        Route::delete('/team/{user}', [TeamController::class, 'destroy'])->name('team.destroy');

        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        Route::get('/colors', [ColorController::class, 'index'])->name('colors.index');
        Route::post('/colors', [ColorController::class, 'store'])->name('colors.store');
        Route::put('/colors/{color}', [ColorController::class, 'update'])->name('colors.update');
        Route::delete('/colors/{color}', [ColorController::class, 'destroy'])->name('colors.destroy');

        Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
        Route::get('/statuses', [StatusController::class, 'index'])->name('statuses.index');

        // --- AI-АГЕНТ ---
        Route::get('/ai', [AiSettingsController::class, 'page'])->name('ai.index');
        Route::get('/ai/data', [AiSettingsController::class, 'data'])->name('ai.data');
        Route::post('/ai/save', [AiSettingsController::class, 'save'])->name('ai.save');
        Route::post('/ai/test', [AiSettingsController::class, 'test'])->name('ai.test');

        // --- ГАЛЕРЕЯ ШІ (фото для агента) ---
        Route::get('/ai-gallery', [AiGalleryController::class, 'page'])->name('aiGallery.index');
        Route::get('/ai-gallery/data', [AiGalleryController::class, 'data'])->name('aiGallery.data');
        Route::get('/ai-gallery/product-search', [AiGalleryController::class, 'productSearch'])->name('aiGallery.productSearch');
        Route::post('/ai-gallery/groups', [AiGalleryController::class, 'storeGroup'])->name('aiGallery.storeGroup');
        Route::patch('/ai-gallery/groups/{group}', [AiGalleryController::class, 'updateGroup'])->name('aiGallery.updateGroup');
        Route::delete('/ai-gallery/groups/{group}', [AiGalleryController::class, 'destroyGroup'])->name('aiGallery.destroyGroup');
        Route::post('/ai-gallery/groups/{group}/products', [AiGalleryController::class, 'attachProduct'])->name('aiGallery.attachProduct');
        Route::delete('/ai-gallery/groups/{group}/products/{product}', [AiGalleryController::class, 'detachProduct'])->name('aiGallery.detachProduct');
        Route::post('/ai-gallery/groups/{group}/photos', [AiGalleryController::class, 'uploadPhotos'])->name('aiGallery.uploadPhotos');
        Route::patch('/ai-gallery/photos/{photo}', [AiGalleryController::class, 'updatePhoto'])->name('aiGallery.updatePhoto');
        Route::delete('/ai-gallery/photos/{photo}', [AiGalleryController::class, 'destroyPhoto'])->name('aiGallery.destroyPhoto');

        // --- СТАТУСИ ЧАТУ ---
        Route::get('/chat-statuses', [ChatStatusController::class, 'page'])->name('chatStatuses.index');
        Route::post('/chat-statuses', [ChatStatusController::class, 'store'])->name('chatStatuses.store');
        Route::put('/chat-statuses/{chatStatus}', [ChatStatusController::class, 'update'])->name('chatStatuses.update');
        Route::delete('/chat-statuses/{chatStatus}', [ChatStatusController::class, 'destroy'])->name('chatStatuses.destroy');
        Route::get('/nova-poshta', [NovaPoshtaSettingsController::class, 'index'])->name('novaPoshta.index');
        Route::post('/nova-poshta', [NovaPoshtaSettingsController::class, 'save'])->name('novaPoshta.save');
        Route::post('/nova-poshta/fetch-refs', [NovaPoshtaSettingsController::class, 'fetchRefs'])->name('novaPoshta.fetchRefs');

        // --- ІНТЕГРАЦІЇ ІЗ САЙТАМИ ---
        Route::get('/integrations', [IntegrationSettingsController::class, 'index'])->name('integrations.index');
        Route::post('/integrations', [IntegrationSettingsController::class, 'store'])->name('integrations.store');
        Route::patch('/integrations/{source}', [IntegrationSettingsController::class, 'update'])->name('integrations.update');
        Route::post('/integrations/{source}/regenerate', [IntegrationSettingsController::class, 'regenerate'])->name('integrations.regenerate');
        Route::delete('/integrations/{source}', [IntegrationSettingsController::class, 'destroy'])->name('integrations.destroy');

        // --- META: підключення Facebook / Instagram ---
        Route::get('/meta', [MetaConnectionController::class, 'index'])->name('meta.index');
        Route::get('/meta/connect', [MetaConnectionController::class, 'connect'])->name('meta.connect');
        Route::get('/meta/callback', [MetaConnectionController::class, 'callback'])->name('meta.callback');
        Route::post('/meta/{connection}/test', [MetaConnectionController::class, 'test'])->name('meta.test');
        Route::delete('/meta/{connection}', [MetaConnectionController::class, 'disconnect'])->name('meta.disconnect');
    });

    // --- ФІСКАЛІЗАЦІЯ (Checkbox) ---
    Route::middleware('role:owner,operator')->controller(FiscalController::class)->prefix('api')->name('fiscal.')->group(function () {
        Route::post('/orders/{order}/fiscalize', 'fiscalize')->name('fiscalize');
        Route::post('/orders/{order}/refund', 'refund')->name('refund');
        Route::get('/orders/{order}/fiscal-status', 'status')->name('status');
    });

    // --- ПАКУВАННЯ ---
    Route::middleware('role:owner,operator,packer')->prefix('packing')->name('packing.')->controller(PackingController::class)->group(function () {
        Route::get('/list', 'index')->name('list');
        Route::get('/{order}', 'show')->name('show');
        Route::post('/{order}/start', 'start')->name('start');
        Route::post('/{order}/finish', 'finish')->name('finish');
        Route::post('/{order}/pause', 'pause')->name('pause');
        Route::post('/{order}/problem', 'problem')->name('problem');
        Route::post('/{order}/release', 'release')->name('release');
    });

    Route::middleware('role:owner,operator,packer')->group(function () {
        Route::get('/api/packing/list', [PackingController::class, 'list'])->name('packing.api.list');
        Route::get('/api/packing/history', [PackingController::class, 'history'])->name('packing.api.history');
    });

    // --- ШАБЛОНИ ПОВІДОМЛЕНЬ ---
    Route::middleware('role:owner,operator')->group(function () {
        Route::resource('templates', MessageTemplateController::class)->except(['show']);
        Route::get('/api/templates-list', [MessageTemplateController::class, 'apiList'])->name('templates.list');
    });

    // --- ПРОФІЛЬ КОРИСТУВАЧА ---
    Route::controller(ProfileController::class)->prefix('profile')->name('profile.')->group(function () {
        Route::get('/', 'edit')->name('edit');
        Route::patch('/', 'update')->name('update');
        Route::delete('/', 'destroy')->name('destroy');
    });

    // --- ОБСЛУГОВУВАННЯ СИСТЕМИ ---
    Route::middleware('role:owner')->get('/clear-everything', function () {
        Artisan::call('route:clear');
        Artisan::call('config:clear');
        Artisan::call('cache:clear');
        return response('ok');
    });


    Route::middleware('role:owner')->get('/check-logs', function () {
        $path = storage_path('logs/laravel.log');
        if (!file_exists($path)) return "Файл логів ще не створено.";
        
        $lines = file($path);
        $lastLines = array_slice($lines, -20);
        
        echo "<h3>Останні записи в логах:</h3><pre>";
        foreach ($lastLines as $line) {
            echo htmlspecialchars($line);
        }
        echo "</pre>";
    });



});

require __DIR__.'/auth.php';
