<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PackingSession;
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
        // Якщо замовлення в роботі у іншого - перенаправляємо назад
        if ($order->packing_status === 'processing' && $order->packer_id !== Auth::id()) {
            return redirect()->route('packing.list')->with('error', 'Це замовлення вже зайняте.');
        }

        $userId = Auth::id();

        // Логіка блокування замовлення (Locking)
        if ($order->packing_status !== 'packed' && ($order->packing_status !== 'processing' || !$order->packer_id)) {
            DB::transaction(function () use ($order, $userId) {
                // Блокуємо рядок в БД, щоб уникнути гонки даних
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();
                if (!$locked) {
                    return;
                }
                // Подвійна перевірка
                if ($locked->packing_status === 'processing' && $locked->packer_id && $locked->packer_id !== $userId) {
                    return;
                }

                // Призначаємо пакувальника
                $locked->update([
                    'packer_id' => $userId,
                    'packing_status' => 'processing',
                ]);

                // Створюємо сесію, якщо немає
                if (!$locked->activePackingSession()->exists()) {
                    PackingSession::create([
                        'order_id' => $locked->id,
                        'packer_id' => $userId,
                        'started_at' => now(),
                    ]);
                }
            });
        }

        // Завантажуємо дані для фронтенду (товари, фото, доставка)
        $order->refresh()->load(['items.product', 'delivery', 'customer']);

        return view('packing.app', ['order' => $order]);
    }

    /**
     * API: Отримати список замовлень для черги.
     */
    public function list(): JsonResponse
    {
        // Беремо ID статусів з конфігу (наприклад, 4 - Упакування)
        $queueStatusIds = config('packing.status_ids.queue', [4]);

        $orders = Order::whereIn('status_id', $queueStatusIds)
            ->orderBy('is_priority', 'desc') // Спочатку пріоритетні
            ->orderBy('created_at', 'asc')   // Потім старіші
            ->with(['items.product', 'delivery'])
            ->get();

        return response()->json($orders);
    }

    /**
     * API: Отримати історію запакованих за сьогодні.
     */
    public function history(): JsonResponse
    {
        // Статуси, які вже поїхали (щоб не показувати їх у списку)
        $shippedStatusIds = config('packing.status_ids.shipped', []);
        if (!is_array($shippedStatusIds)) {
            $shippedStatusIds = [$shippedStatusIds];
        }

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
            ->with(['items.product', 'delivery'])
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

        return DB::transaction(function () use ($order, $userId) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if (!$locked) return response()->json(['error' => 'Замовлення не знайдено'], 404);

            if ($locked->packing_status === 'processing' && $locked->packer_id !== $userId) {
                return response()->json(['error' => 'Замовлення вже пакує інший працівник'], 423);
            }

            $locked->update([
                'packer_id' => $userId,
                'packing_status' => 'processing',
            ]);

            PackingSession::firstOrCreate(
                ['order_id' => $locked->id, 'finished_at' => null],
                ['packer_id' => $userId, 'started_at' => now()]
            );

            return response()->json(['success' => true, 'message' => 'Пакування розпочато']);
        });
    }

    /**
     * API: Завершити пакування (Кнопка "Запаковано").
     */
    public function finish(Order $order): JsonResponse
    {
        $userId = Auth::id();

        return DB::transaction(function () use ($order, $userId) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if (!$locked) return response()->json(['error' => 'Замовлення не знайдено'], 404);
            if ($locked->packer_id !== $userId) return response()->json(['error' => 'Немає доступу'], 403);

            // Закриваємо сесію
            $session = $locked->activePackingSession()->first();
            if ($session) {
                $now = now();
                $duration = $session->started_at ? $now->diffInSeconds($session->started_at) : null;
                $session->update([
                    'finished_at' => $now,
                    'duration_seconds' => $duration,
                ]);
            }

            // Оновлюємо статус на "Запаковано" (ID 12)
            $packedStatusId = config('packing.status_ids.packed', 12);
            
            $locked->update([
                'packing_status' => 'packed',
                'status_id' => $packedStatusId,
            ]);

            return response()->json(['success' => true]);
        });
    }

    /**
     * API: Поставити на паузу (повернути в чергу).
     */
    public function pause(Order $order): JsonResponse
    {
        $userId = Auth::id();
        $queueStatusIds = config('packing.status_ids.queue', []);
        $queueStatusId = is_array($queueStatusIds) ? ($queueStatusIds[0] ?? 4) : $queueStatusIds;

        return DB::transaction(function () use ($order, $userId, $queueStatusId) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (!$locked) return response()->json(['error' => 'Замовлення не знайдено'], 404);

            // Видаляємо поточну сесію, бо робота не зроблена
            $locked->activePackingSession()->delete();

            $locked->update([
                'packer_id' => null,
                'packing_status' => 'pending',
                'status_id' => $queueStatusId, // Повертаємо статус "Упакування"
            ]);

            return response()->json(['success' => true]);
        });
    }

    /**
     * API: Проблема (Нема товару / Брак).
     */
    public function problem(Order $order): JsonResponse
    {
        $userId = Auth::id();
        // ID статусу "Проблема" (наприклад, 2 - В обробці)
        $problemStatusId = config('packing.status_ids.problem');

        if (!$problemStatusId) {
            return response()->json(['error' => 'Не налаштовано статус проблеми.'], 422);
        }

        return DB::transaction(function () use ($order, $userId, $problemStatusId) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (!$locked) return response()->json(['error' => 'Замовлення не знайдено'], 404);

            // Закриваємо сесію (час витрачено)
            $session = $locked->activePackingSession()->first();
            if ($session) {
                $now = now();
                $duration = $session->started_at ? $now->diffInSeconds($session->started_at) : null;
                $session->update(['finished_at' => $now, 'duration_seconds' => $duration]);
            }

            // Скидаємо пакувальника і ставимо проблемний статус
            $locked->update([
                'packer_id' => null,
                'packing_status' => 'pending',
                'status_id' => $problemStatusId,
            ]);

            return response()->json(['success' => true, 'message' => 'Замовлення позначено як проблемне.']);
        });
    }
}