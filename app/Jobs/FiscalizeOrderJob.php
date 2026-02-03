<?php

namespace App\Jobs;

use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Models\Status;
use App\Services\CheckboxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FiscalizeOrderJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Order $order;
    public string $type;
    public ?int $amountCents;

    // Час життя блокування (60 секунд)
    public int $uniqueFor = 60;

    public function __construct(Order $order, string $type = FiscalReceipt::TYPE_SELL, ?int $amountCents = null)
    {
        $this->order = $order;
        $this->type = $type;
        $this->amountCents = $amountCents;
    }

    public function uniqueId(): string
    {
        return "fiscal_order_{$this->order->id}_{$this->type}";
    }

    public function handle(CheckboxService $checkbox): void
    {
        $lock = Cache::lock("fiscal:order:{$this->order->id}:{$this->type}", 60);
        if (!$lock->get()) {
            Log::warning("Fiscal Job Skipped: lock busy for Order #{$this->order->id}");
            return;
        }

        try {
            // 1. Вантажимо товари
            $this->order->loadMissing(['items.product.color', 'items.variant', 'fiscalReceipts']);

            // 2. Рахуємо суму вручну з items (щоб уникнути помилок, якщо в order->total 0)
            $calculatedTotal = $this->order->items->sum('total');
            
            // Fallback: якщо сума items 0, пробуємо взяти з order->total
            if ($calculatedTotal <= 0) {
                $calculatedTotal = $this->order->total ?? 0;
            }

            $totalOrderCents = (int) round($calculatedTotal * 100);
            $targetAmount = $this->amountCents ?? $totalOrderCents;

            // 3. Формуємо товари (передаємо розраховану загальну суму)
            $goods = $this->prepareGoods($this->order, $targetAmount / 100, $calculatedTotal);
            $effectiveAmount = $this->calculateGoodsTotalCents($goods);

            if ($effectiveAmount <= 0) {
                Log::warning("Fiscal Job Skipped: empty goods sum for Order #{$this->order->id}");
                return;
            }

            if (!$this->shouldFiscalize($totalOrderCents, $effectiveAmount)) {
                return;
            }

            $receipt = $this->createPendingReceipt($effectiveAmount);

            // Логуємо для контролю
            Log::info("Fiscalizing Order #{$this->order->id}", [
                'target_amount_cents' => $targetAmount,
                'effective_amount_cents' => $effectiveAmount,
                'calculated_total' => $calculatedTotal,
                'goods_count' => count($goods)
            ]);

            // 4. Відправляємо в сервіс
            $response = $checkbox->createReceipt($this->order, $this->type, $effectiveAmount, $goods);

            if (!$response) {
                throw new \Exception('Empty response from Checkbox Service');
            }

            $this->completeReceipt($receipt, $response);
            $this->updateOrderStatusIfNeeded($totalOrderCents);

        } catch (\Throwable $e) {
            if (isset($receipt)) {
                $this->failReceipt($receipt, $e->getMessage());
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Формує список товарів для чека з правильною кількістю та ціною за одиницю.
     */
    private function prepareGoods(Order $order, float $targetAmountFloat, float $totalOrderAmount): array
    {
        // Якщо сума 0 або товарів немає — заглушка
        if ($totalOrderAmount <= 0 || $order->items->isEmpty()) {
            return [[
                'code' => 'order-' . $order->id,
                'name' => 'Замовлення #' . ($order->order_number ?? $order->id),
                'price' => (int) round($targetAmountFloat * 100),
                'qty' => 1000,
            ]];
        }

        // Коефіцієнт: яку частину ми платимо (наприклад 1.0 або 0.5)
        $coefficient = $targetAmountFloat / $totalOrderAmount;

        $goods = [];
        $currentSum = 0;
        $items = $order->items;
        $lastIndex = count($items) - 1;

        foreach ($items as $index => $item) {
            $itemTotalOriginal = (float) $item->total; 
            
            // 1. Рахуємо нову ЗАГАЛЬНУ вартість цього рядка (ціна * кількість)
            $newItemLineTotal = round($itemTotalOriginal * $coefficient, 2);

            // 2. Коригуємо копійки на останньому товарі
            if ($index === $lastIndex) {
                $newItemLineTotal = round($targetAmountFloat - $currentSum, 2);
            }

            if ($newItemLineTotal <= 0) {
                continue;
            }

            $currentSum += $newItemLineTotal;

            // 3. Беремо кількість (qty)
            $qty = (float) ($item->qty ?? 1);
            if ($qty <= 0) $qty = 1;

            // 4. Рахуємо ціну за ОДИНИЦЮ = Загальна сума рядка / Кількість
            $unitPrice = $newItemLineTotal / $qty;

            // 5. Формуємо назву: заголовок - колір - розмір - sku
            $product = $item->product;
            $variant = $item->variant;
            $baseTitle = $product?->title ?? 'Товар';
            $size = trim((string) ($variant?->size ?? ''));
            $color = trim((string) ($product?->color?->name ?? ''));
            $sku = trim((string) ($variant?->sku ?? $product?->sku ?? ''));

            if ($size !== '') {
                $sizeSuffix = " ({$size})";
                if (str_ends_with($baseTitle, $sizeSuffix)) {
                    $baseTitle = substr($baseTitle, 0, -strlen($sizeSuffix));
                }
            }

            $parts = [trim($baseTitle)];
            if ($color !== '') $parts[] = $color;
            if ($size !== '') $parts[] = $size;
            if ($sku !== '') $parts[] = $sku;
            $name = implode(' - ', array_filter($parts, static fn ($part) => $part !== ''));

            $goods[] = [
                'code' => $sku !== '' ? $sku : ('item-' . $item->id),
                'name' => $name,
                'price' => (int) round($unitPrice * 100), // Ціна за ОДИНИЦЮ в копійках
                'qty' => (int) ($qty * 1000),             // Кількість * 1000
            ];
        }

        return $goods;
    }

    private function calculateGoodsTotalCents(array $goods): int
    {
        $total = 0;
        foreach ($goods as $good) {
            $price = (int) ($good['price'] ?? 0);
            $qty = (int) ($good['qty'] ?? 0);
            $total += (int) round(($price * $qty) / 1000);
        }

        return $total;
    }

    // --- Стандартні методи ---

    private function shouldFiscalize(int $totalOrder, int $targetAmount): bool
    {
        $alreadyPaid = $this->getAlreadyPaidAmount();

        $hasPending = $this->order->fiscalReceipts()
            ->where('type', $this->type)
            ->whereIn('status', [FiscalReceipt::STATUS_PENDING, FiscalReceipt::STATUS_PROCESSING])
            ->exists();

        if ($hasPending) return false;

        if ($this->type === FiscalReceipt::TYPE_RETURN) {
            if ($alreadyPaid <= 0) {
                Log::info("Fiscal Job Skipped: Order #{$this->order->id} has no paid receipts.");
                return false;
            }

            if ($targetAmount <= 0) {
                Log::error("Fiscal Job: Return amount is zero or negative.");
                return false;
            }

            if ($targetAmount > ($alreadyPaid + 10)) {
                Log::error("Fiscal Job: Return amount exceeds paid sum.");
                return false;
            }

            return true;
        }

        if ($alreadyPaid >= $totalOrder && $totalOrder > 0) {
            Log::info("Fiscal Job Skipped: Order #{$this->order->id} fully paid.");
            return false;
        }

        if (($alreadyPaid + $targetAmount) > ($totalOrder + 10)) {
            Log::error("Fiscal Job: Amount mismatch.");
            return false;
        }

        return true;
    }

    private function createPendingReceipt(int $amount): FiscalReceipt
    {
        $hash = md5($this->order->id . $this->type . $amount . microtime());
        return $this->order->fiscalReceipts()->create([
            'type' => $this->type,
            'status' => FiscalReceipt::STATUS_PROCESSING,
            'total_amount' => $amount,
            'payload_hash' => $hash, 
        ]);
    }

    private function failReceipt(FiscalReceipt $receipt, string $message): void
    {
        $receipt->update([
            'status' => FiscalReceipt::STATUS_ERROR,
            'error_message' => substr($message, 0, 1000),
            'retry_count' => ($receipt->retry_count ?? 0) + 1,
        ]);
    }

    private function completeReceipt(FiscalReceipt $receipt, array $response): void
    {
        $uuid = $response['id'] ?? null;
        $statusStr = strtoupper($response['status'] ?? '');
        $isSuccess = in_array($statusStr, ['DONE', 'SUCCESS', 'CREATED']);

        $receipt->update([
            'uuid' => $uuid,
            'status' => $isSuccess ? FiscalReceipt::STATUS_SUCCESS : FiscalReceipt::STATUS_ERROR,
            'fiscal_code' => $response['fiscal_code'] ?? null,
            'check_link' => $response['visual_url'] ?? ($uuid ? "https://check.checkbox.ua/status/{$uuid}" : null),
            'meta' => $response,
            'error_message' => $isSuccess ? null : ($response['message'] ?? 'Error'),
        ]);
    }

    private function updateOrderStatusIfNeeded(int $totalOrder): void
    {
        if ($this->getAlreadyPaidAmount() >= $totalOrder) {
            $updates = [];
            $fiscalizedCode = 'delivered_paid';
            $fiscalizedId = Status::query()
                ->where('type', 'order')
                ->where('code', $fiscalizedCode)
                ->value('id') ?? config('fiscal.status_ids.fiscalized');

            if ($fiscalizedId && $this->order->status_id !== (int) $fiscalizedId) {
                $updates['status_id'] = (int) $fiscalizedId;
            }
            if ($this->order->status !== $fiscalizedCode) {
                $updates['status'] = $fiscalizedCode;
            }
            if ($this->order->payment_status !== 'paid') {
                $updates['payment_status'] = 'paid';
            }
            if (!empty($updates)) {
                $this->order->update($updates);
            }
        }
    }

    private function getAlreadyPaidAmount(): int
    {
        return (int) $this->order->fiscalReceipts()
            ->where('status', FiscalReceipt::STATUS_SUCCESS)
            ->where('type', FiscalReceipt::TYPE_SELL)
            ->sum('total_amount');
    }
}
