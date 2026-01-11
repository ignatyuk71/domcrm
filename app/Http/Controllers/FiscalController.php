<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\FiscalizeOrderJob;
use App\Models\FiscalReceipt;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class FiscalController extends Controller
{
    /**
     * Запуск фіскалізації (Продаж або Передоплата).
     */
    public function fiscalize(Order $order, Request $request): JsonResponse
    {
        $type = $request->input('type', FiscalReceipt::TYPE_SELL);
        $customAmountCents = null;

        // --- ЛОГІКА 1: ПЕРЕДОПЛАТА ---
        if ($type === 'prepay') {
            $payment = $order->payment;
            // Беремо суму передоплати з платежу
            $prepayVal = $payment->prepay_amount ?? $payment->prepayment ?? 0;
            $customAmountCents = (int) round((float) $prepayVal * 100);

            if ($customAmountCents <= 0) {
                return response()->json(['message' => 'Сума передоплати не вказана або дорівнює нулю'], 422);
            }

            // Для Checkbox це технічно все одно "SELL", просто на іншу суму
            $type = FiscalReceipt::TYPE_SELL;
        } 
        // --- ЛОГІКА 2: ПОВНА ОПЛАТА АБО ЗАЛИШОК ---
        elseif ($type === FiscalReceipt::TYPE_SELL) {
            $alreadyPaidCents = $this->getAlreadyPaidCents($order);
            $totalOrderCents = (int) round($order->items->sum('total') * 100);

            // Якщо вже сплачено все (або більше) -> просто повертаємо статус
            if ($alreadyPaidCents >= $totalOrderCents) {
                return $this->status($order);
            }

            // Якщо була часткова оплата раніше -> фіскалізуємо різницю (залишок)
            if ($alreadyPaidCents > 0) {
                $customAmountCents = $totalOrderCents - $alreadyPaidCents;
            }
            // Якщо $alreadyPaidCents === 0, то $customAmountCents лишається null (що означає "повна сума")
        }

        Log::info('Fiscalize request', [
            'order_id' => $order->id,
            'req_type' => $request->input('type'), // оригінальний тип запиту
            'final_type' => $type,
            'amount_cents' => $customAmountCents,
        ]);

        return $this->runFiscalJob($order, $type, $customAmountCents);
    }

    /**
     * Запуск фіскалізації повернення.
     */
    public function refund(Order $order): JsonResponse
    {
        return $this->runFiscalJob($order, FiscalReceipt::TYPE_RETURN);
    }

    /**
     * Отримати актуальний статус фіскалізації для замовлення.
     */
    public function status(Order $order): JsonResponse
    {
        // 1. Збираємо всі успішні чеки (для підрахунку суми та історії)
        $successReceipts = $order->fiscalReceipts()
            ->where('status', FiscalReceipt::STATUS_SUCCESS)
            ->orderBy('created_at')
            ->get();

        // 2. Беремо найостанніший запис (будь-якого статусу), щоб показати поточний стан UI
        /** @var FiscalReceipt|null $latest */
        $latest = $order->fiscalReceipts()->latest()->first();

        if (!$latest) {
            return response()->json(['status' => 'absent']);
        }

        // 3. Рахуємо математику
        $successSum = (int) $successReceipts->sum('total_amount');
        $totalOrderCents = (int) round($order->items->sum('total') * 100);

        // 4. Визначаємо "Ефективний статус" для UI
        // Якщо сума чеків покриває замовлення - то це SUCCESS, навіть якщо останній чек був помилковим (хоча це рідкість)
        $effectiveStatus = ($totalOrderCents > 0 && $successSum >= $totalOrderCents && $latest->type === FiscalReceipt::TYPE_SELL)
            ? FiscalReceipt::STATUS_SUCCESS
            : $latest->status;

        return response()->json([
            'status' => $effectiveStatus,
            'type' => $latest->type,
            'fiscal_code' => $latest->fiscal_code,
            'check_link' => $latest->check_link,
            'total_amount' => $latest->total_amount, // сума останньої спроби
            '
            already_paid_cents' => $successSum, // сума всіх успішних
            'total_order_cents' => $totalOrderCents,
            'receipt_id' => $latest->id,
            'uuid' => $latest->uuid,
            'error_message' => $latest->error_message,
            // Передаємо історію успішних чеків для списку на фронтенді
            'all_success_receipts' => $successReceipts->map(fn ($r) => [
                'id' => $r->id,
                'amount' => $r->total_amount / 100,
                'fiscal_code' => $r->fiscal_code,
                'link' => $r->check_link,
                'created_at' => $r->created_at,
            ]),
        ]);
    }

    /**
     * Універсальний метод запуску Job'а з обробкою помилок.
     */
    private function runFiscalJob(Order $order, string $type, ?int $amount = null): JsonResponse
    {
        try {
            // Використовуємо dispatchSync, щоб фронтенд одразу отримав результат або помилку.
            // Якщо треба background processing - замінити на dispatch()
            FiscalizeOrderJob::dispatchSync($order, $type, $amount);
        } catch (\Throwable $e) {
            Log::error('Fiscalize Job Failed (Sync)', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Помилка запуску фіскалізації: ' . $e->getMessage()
            ], 500);
        }

        // Одразу повертаємо оновлений статус
        return $this->status($order);
    }

    /**
     * Отримати суму вже успішно фіскалізованих коштів (в копійках).
     */
    private function getAlreadyPaidCents(Order $order): int
    {
        return (int) $order->fiscalReceipts()
            ->where('status', FiscalReceipt::STATUS_SUCCESS)
            ->where('type', FiscalReceipt::TYPE_SELL)
            ->sum('total_amount');
    }
}