<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PackingSession;
use App\Services\PackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PackingController extends Controller
{
    /**
     * Головна сторінка (SPA вхід).
     */
    public function index()
    {
        return view('packing.app');
    }

    /**
     * Сторінка пакування конкретного замовлення.
     */
    public function show(Order $order)
    {
        if ($order->packing_status !== 'packed') {
            PackingSession::firstOrCreate(
                ['order_id' => $order->id, 'finished_at' => null],
                ['packer_id' => Auth::id(), 'started_at' => now()]
            );
        }

        // Завантажуємо дані для фронтенду (товари, фото, доставка)
        $order->refresh()->load(['items.product.color', 'items.variant', 'delivery', 'customer']);

        return view('packing.app', ['order' => $order]);
    }

    /**
     * API: Отримати список замовлень для черги.
     */
    public function list(PackingService $packing): JsonResponse
    {
        // Беремо статуси черги через сервіс (code -> id, з fallback).
        $queueStatusIds = $packing->queueStatusIds();

        $orders = Order::query()
            ->when($queueStatusIds, fn ($q) => $q->whereIn('status_id', $queueStatusIds), fn ($q) => $q->whereRaw('1 = 0'))
            ->where(function ($q) {
                $q->whereNull('packing_status')
                    ->orWhereIn('packing_status', ['pending', 'processing', 'skipped']);
            })
            ->orderBy('is_priority', 'desc') // Спочатку пріоритетні
            ->orderBy('created_at', 'asc')   // Потім старіші
            ->with([
                'items.product.color',
                'items.variant',
                'delivery',
                'customer',
                'packer:id,name',
                'activePackingSession:id,order_id,started_at',
            ])
            ->get();

        return response()->json($orders);
    }

    /**
     * API: Отримати історію запакованих за сьогодні.
     */
    public function history(PackingService $packing): JsonResponse
    {
        // Статуси, які вже поїхали (щоб не показувати їх у списку)
        $shippedStatusIds = $packing->shippedStatusIds();

        // Підзапит для отримання часу завершення пакування
        $subQuery = PackingSession::query()
            ->select('order_id', DB::raw('MAX(finished_at) as packed_at'))
            ->where('packer_id', Auth::id())
            ->whereNotNull('finished_at')
            ->whereDate('finished_at', now()) // Тільки за сьогодні
            ->groupBy('order_id');

        $history = Order::query()
            ->where('packing_status', 'packed') // Тільки запаковані
            ->when($shippedStatusIds, function ($q) use ($shippedStatusIds) {
                // Прибираємо ті, що вже відправлені (статуси 5, 6, 11 тощо)
                $q->whereNotIn('status_id', $shippedStatusIds);
            })
            ->joinSub($subQuery, 'packing_sessions', function ($join) {
                $join->on('orders.id', '=', 'packing_sessions.order_id');
            })
            ->with([
                'items.product.color',
                'items.variant',
                'delivery',
                'customer',
                'packer:id,name',
                'activePackingSession:id,order_id,started_at',
            ])
            ->orderByDesc('packing_sessions.packed_at')
            ->get([
                'orders.*',
                'packing_sessions.packed_at',
            ]);

        return response()->json($history);
    }

    /**
     * API: Почати пакування (натискання кнопки "Пакувати").
     */
    public function start(Order $order): JsonResponse
    {
        $userId = Auth::id();

        PackingSession::firstOrCreate(
            ['order_id' => $order->id, 'finished_at' => null],
            ['packer_id' => $userId, 'started_at' => now()]
        );

        return response()->json(['success' => true, 'message' => 'Пакування розпочато']);
    }

    /**
     * API: Завершити пакування (Кнопка "Запаковано").
     */
    public function finish(Order $order, PackingService $packing): JsonResponse
    {
        $userId = Auth::id();

        return DB::transaction(function () use ($order, $userId, $packing) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if (!$locked) return response()->json(['error' => 'Замовлення не знайдено'], 404);

            $packing->closeSession($locked, $userId, 'finished');

            // Оновлюємо статус на "Запаковано" (визначається через PackingService)
            $packedStatusId = $packing->packedStatusId();
            $updates = [
                'packer_id' => $userId,
                'packing_status' => 'packed',
                'status_id' => $packedStatusId,
            ];
            $packedStatusCode = $packing->statusCodeById($packedStatusId);
            if ($packedStatusCode) {
                $updates['status'] = $packedStatusCode;
            }

            $locked->update($updates);

            return response()->json(['success' => true]);
        });
    }

    /**
     * API: Поставити на паузу (повернути в чергу).
     */
    public function pause(Order $order, PackingService $packing): JsonResponse
    {
        $userId = Auth::id();
        $queueStatusId = $packing->queueStatusId();
        $queueStatusCode = $packing->statusCodeById($queueStatusId);

        return DB::transaction(function () use ($order, $userId, $queueStatusId, $queueStatusCode, $packing) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (!$locked) return response()->json(['error' => 'Замовлення не знайдено'], 404);

            $packing->closeSession($locked, $userId, 'paused');

            $updates = [
                'packer_id' => null,
                'packing_status' => 'pending',
                'status_id' => $queueStatusId, // Повертаємо статус "Упакування"
            ];
            if ($queueStatusCode) {
                $updates['status'] = $queueStatusCode;
            }

            $locked->update($updates);

            return response()->json(['success' => true]);
        });
    }

    /**
     * API: Проблема (Нема товару / Брак).
     */
    public function problem(Order $order, PackingService $packing): JsonResponse
    {
        $userId = Auth::id();
        // ID статусу "Проблема" (наприклад, 2 - В обробці)
        $problemStatusId = $packing->problemStatusId();

        if (!$problemStatusId) {
            return response()->json(['error' => 'Не налаштовано статус проблеми.'], 422);
        }

        $problemStatusCode = $packing->statusCodeById($problemStatusId);

        return DB::transaction(function () use ($order, $userId, $problemStatusId, $problemStatusCode, $packing) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (!$locked) return response()->json(['error' => 'Замовлення не знайдено'], 404);

            $packing->closeSession($locked, $userId, 'problem');

            // Скидаємо пакувальника і переводимо замовлення у відкладений стан.
            // Воно лишається доступним у списку для ручного запуску, але не має
            // автоматично підхоплюватися як наступне замовлення.
            $updates = [
                'packer_id' => null,
                'packing_status' => 'skipped',
                'status_id' => $problemStatusId,
            ];
            if ($problemStatusCode) {
                $updates['status'] = $problemStatusCode;
            }

            $locked->update($updates);

            return response()->json(['success' => true, 'message' => 'Замовлення позначено як проблемне.']);
        });
    }

    /**
     * API: Примусово зняти пакувальника та повернути замовлення у чергу.
     */
    public function release(Order $order, PackingService $packing): JsonResponse
    {
        $userId = Auth::id();
        if (!$packing->canRelease($order, $userId)) {
            return response()->json(['error' => 'Немає доступу'], 403);
        }

        $released = $packing->releaseOrder($order, $userId, 'manual_release');
        if (!$released) {
            return response()->json(['error' => 'Замовлення не в роботі'], 409);
        }

        return response()->json(['success' => true]);
    }
}
