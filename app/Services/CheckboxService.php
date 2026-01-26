<?php

namespace App\Services;

use App\Models\FiscalLog;
use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Models\CheckboxSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckboxService
{
    private string $apiUrl;
    private ?string $licenseKey;
    private ?string $login;
    private ?string $password;
    
    // Константи для ясності
    private const CACHE_TTL = 3000;
    private const QUANTITY_MULTIPLIER = 1000;
    private const DEFAULT_TAX_CODE = 8;
    private const TOKEN_CACHE_KEY = 'checkbox.cashier_token';

    public function __construct()
    {
        $settings = CheckboxSetting::resolveCheckboxConnection();

        $this->apiUrl = rtrim($settings['api_url'] ?? 'https://api.checkbox.in.ua/api/v1', '/');
        $this->licenseKey = $settings['license_key'] ?? null;
        $this->login = $settings['login'] ?? null;
        $this->password = $settings['password'] ?? null;
    }

    /**
     * Авторизація касира з кешуванням.
     */
    public function auth(bool $forceRefresh = false): ?string
    {
        if (!$forceRefresh) {
            $cached = Cache::get(self::TOKEN_CACHE_KEY);
            if ($cached) {
                return $cached;
            }
        }

        $response = $this->client()->post($this->url('cashier/signin'), [
            'login' => $this->login,
            'password' => $this->password,
            'license_key' => $this->licenseKey,
        ]);

        if (!$response->successful()) {
            Log::error('Checkbox Auth Failed', ['body' => $response->body()]);
            return null;
        }

        $token = $response->json('access_token');
        if ($token) {
            Cache::put(self::TOKEN_CACHE_KEY, $token, self::CACHE_TTL);
        }

        return $token;
    }

    /**
     * Перевірка зміни (відкриття при потребі).
     */
    public function ensureShift(?string $token = null): bool
    {
        $token = $token ?? $this->auth();
        if (!$token) return false;

        $current = $this->getCurrentShift($token);
        
        // Якщо зміна вже відкрито - чудово
        if ($current && strtoupper($current['status'] ?? '') === 'OPENED') {
            return true;
        }

        // Пробуємо відкрити
        $response = $this->client($token)->post($this->url('shifts'));

        if ($response->successful() || ($response->status() === 400 && str_contains($response->body(), 'Касир вже працює'))) {
            return true;
        }

        Log::error('Checkbox Shift Error', ['body' => $response->body()]);
        return false;
    }

    public function openShift(): bool
    {
        return $this->ensureShift();
    }

    public function closeShift(): bool
    {
        $token = $this->auth();
        if (!$token) {
            Log::error('Checkbox Shift Close Failed: auth error');
            return false;
        }

        $response = $this->client($token)->post($this->url('shifts/close'));
        if ($response->successful()) {
            return true;
        }

        if ($response->status() === 400 && str_contains($response->body(), 'Зміна вже закрита')) {
            return true;
        }

        Log::error('Checkbox Shift Close Error', ['body' => $response->body()]);
        return false;
    }

    public function testConnection(): array
    {
        $token = $this->auth();
        if (!$token) {
            return ['ok' => false, 'message' => 'Auth failed'];
        }

        $shift = $this->getCurrentShift($token);
        $status = strtoupper($shift['status'] ?? '');

        return [
            'ok' => true,
            'message' => $status ? "Shift: {$status}" : 'Shift status unknown',
            'shift' => $shift,
        ];
    }

    public function getCurrentShift(?string $token = null): ?array
    {
        $token = $token ?? $this->auth();
        if (!$token) return null;

        $response = $this->client($token)->get($this->url('cashier/shift'));
        if ($response->successful()) {
            return $response->json();
        }

        if (in_array($response->status(), [400, 404], true)) {
            return ['status' => 'CLOSED'];
        }

        Log::error('Checkbox Shift Status Error', ['body' => $response->body()]);
        return null;
    }

    /**
     * Основний метод створення чека.
     * ОНОВЛЕНО: Додано аргумент $customGoods для передачі списку товарів ззовні.
     */
    public function createReceipt(Order $order, string $type = FiscalReceipt::TYPE_SELL, ?int $amountCents = null, array $customGoods = []): ?array
    {
        $token = $this->auth();
        
        if (!$token || !$this->ensureShift($token)) {
            Log::error("Checkbox: Auth or Shift failed for Order #{$order->id}");
            return null;
        }

        // Будуємо тіло запиту (payload), передаючи $customGoods
        $payload = $this->buildReceiptPayload($order, $type, $amountCents, $customGoods);
        $endpoint = ($type === FiscalReceipt::TYPE_RETURN) ? 'receipts/return' : 'receipts/sell';

        $start = microtime(true);
        $response = $this->client($token)->post($this->url($endpoint), $payload);
        $durationMs = (int) ((microtime(true) - $start) * 1000);

        $body = $response->json();
        $isSuccess = $response->successful();

        if (!$isSuccess) {
            Log::error("Checkbox API Error Order #{$order->id}", [
                'status' => $response->status(),
                'payload' => $payload, 
                'response' => $body
            ]);
        }

        // Логування в БД
        $this->logFiscal(
            stage: 'receipt',
            method: "POST {$endpoint}",
            request: $payload,
            response: $body,
            code: $response->status(),
            order: $order,
            status: $isSuccess ? 'success' : 'error',
            uuid: $body['id'] ?? null,
            fcode: $body['fiscal_code'] ?? null,
            duration: $durationMs
        );

        return $isSuccess ? $body : null;
    }

    /**
     * Формування масиву даних для чека.
     * ОНОВЛЕНО: Логіка пріоритетів формування товарів.
     */
    protected function buildReceiptPayload(Order $order, string $type, ?int $customAmountCents = null, array $customGoods = []): array
    {
        // Завантажуємо дані, якщо їх немає (безпечно)
        $order->loadMissing(['customer', 'payment', 'fiscalReceipts', 'items']);

        $goods = [];
        $isReturn = ($type === FiscalReceipt::TYPE_RETURN);
        $defaultTax = [config('fiscal.default_tax_code', self::DEFAULT_TAX_CODE)];

        // 1. Формування товарів (Пріоритети)
        if (!empty($customGoods)) {
            // ВАРІАНТ А: Готовий список товарів (переданий з Job)
            // Тут ми просто перетворюємо структуру під формат Checkbox
            foreach ($customGoods as $good) {
                $goods[] = [
                    'good' => [
                        'code' => (string) $good['code'],
                        'name' => (string) $good['name'],
                        'price' => (int) $good['price'], // Ціна вже в копійках
                        'tax' => $defaultTax, 
                    ],
                    'quantity' => (int) $good['qty'], // Вже помножено на 1000 у Job
                    'is_return' => $isReturn,
                ];
            }
        } elseif ($customAmountCents !== null) {
            // ВАРІАНТ Б: Один рядок із кастомною сумою (стара логіка "Передоплата")
            $goods[] = $this->buildCustomAmountItem($order, $customAmountCents, $isReturn);
        } else {
            // ВАРІАНТ В: Повний чек усіх товарів замовлення
            foreach ($order->items as $item) {
                $goods[] = $this->buildProductItem($item, $isReturn);
            }
        }

        // Рахуємо загальну суму для блоку оплати
        $totalSum = 0;
        foreach ($goods as $g) {
            // Checkbox: price * quantity / 1000
            $itemPrice = $g['good']['price'];
            $itemQty = $g['quantity'];
            $totalSum += round(($itemPrice * $itemQty) / self::QUANTITY_MULTIPLIER);
        }

        // 2. Визначення типу оплати
        $paymentDetails = $this->determinePaymentDetails($order);

        return [
            'goods' => $goods,
            'payments' => [[
                'type' => $paymentDetails['type'],
                'value' => (int) $totalSum,
                'label' => $paymentDetails['label'],
            ]],
            'delivery' => [
                'email' => $order->customer?->email ?? 'no-email@example.com',
                'phone' => $order->customer?->phone ?? null,
            ],
        ];
    }

    /**
     * Побудова рядка товару для звичайного продажу.
     */
    private function buildProductItem($item, bool $isReturn): array
    {
        $price = (int) round(($item->price ?? 0) * 100);
        $qty = ($item->qty ?? 1);

        return [
            'good' => [
                'code' => (string) ($item->sku ?? $item->id),
                'name' => (string) ($item->product_title ?? "Товар"),
                'price' => $price,
                'tax' => $this->resolveTaxCode($item),
            ],
            'quantity' => (int) round($qty * self::QUANTITY_MULTIPLIER),
            'sum' => (int) round($price * $qty),
            'is_return' => $isReturn,
        ];
    }

    /**
     * Побудова рядка для кастомної суми (передоплата).
     */
    private function buildCustomAmountItem(Order $order, int $amount, bool $isReturn): array
    {
        $hasHistory = $this->hasSuccessfulReceipts($order);
        
        $itemName = $hasHistory 
            ? "Післяплата за замовлення #{$order->id}" 
            : "Передоплата за замовлення #{$order->id}";

        return [
            'good' => [
                'code' => 'prepay-' . $order->id,
                'name' => $itemName,
                'price' => $amount,
                'tax' => [config('fiscal.default_tax_code', self::DEFAULT_TAX_CODE)],
            ],
            'quantity' => 1 * self::QUANTITY_MULTIPLIER,
            'is_return' => $isReturn,
        ];
    }

    /**
     * Визначення типу оплати та лейбла.
     */
    private function determinePaymentDetails(Order $order): array
    {
        $method = $order->payment?->method ?? $order->payment_method ?? 'card';
        $hasHistory = $this->hasSuccessfulReceipts($order);

        $mappedType = config("fiscal.payment_mapping.{$method}", 'CASHLESS');
        $label = config("fiscal.payment_labels.{$method}", 'Оплата карткою');

        if ($method === 'cod' || ($method === 'prepay' && $hasHistory)) {
            $label = 'Післяплата через інтегратор NovaPay';
            $mappedType = 'CASHLESS';
        } elseif ($method === 'prepay' && !$hasHistory) {
            $label = 'Передоплата (картка)';
            $mappedType = 'CASHLESS';
        }

        return [
            'type' => $mappedType,
            'label' => $label,
        ];
    }

    private function hasSuccessfulReceipts(Order $order): bool
    {
        return $order->fiscalReceipts && $order->fiscalReceipts
            ->where('status', FiscalReceipt::STATUS_SUCCESS)
            ->where('type', FiscalReceipt::TYPE_SELL)
            ->isNotEmpty();
    }

    protected function resolveTaxCode($item): array
    {
        $taxKey = $item?->tax_code ?? $item?->product?->tax_code ?? null;
        $mapping = config('fiscal.tax_mapping', []);
        
        if ($taxKey && isset($mapping[$taxKey])) {
            return [$mapping[$taxKey]];
        }

        return [config('fiscal.default_tax_code', self::DEFAULT_TAX_CODE)];
    }

    protected function client(?string $token = null): PendingRequest
    {
        $request = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-License-Key' => $this->licenseKey,
                'X-Client-Name' => config('fiscal.client.name', 'CRM'),
                'X-Client-Version' => config('fiscal.client.version', '1.0'),
            ]);

        if ($token) {
            $request->withToken($token);
        }

        return $request;
    }

    private function url(string $path): string
    {
        return $this->apiUrl . '/' . ltrim($path, '/');
    }

    public function createZReport(): ?array
    {
        $token = $this->auth();
        if (!$token) return null;

        $response = $this->client($token)->post($this->url('reports/z_reports'));
        return $response->successful() ? $response->json() : null;
    }

    private function logFiscal(string $stage, string $method, $request, $response, int $code, ?Order $order, string $status, ?string $uuid = null, ?string $fcode = null, ?int $duration = null): void
    {
        FiscalLog::create([
            'order_id' => $order?->id,
            'receipt_uuid' => $uuid,
            'fiscal_code' => $fcode,
            'status' => $status,
            'stage' => $stage,
            'method' => $method,
            'request_body' => $request,
            'response_body' => $response,
            'response_code' => $code,
            'duration_ms' => $duration,
        ]);
    }
}
