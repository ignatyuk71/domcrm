<?php

namespace App\Services\Integration;

use App\Models\Customer;
use App\Models\ExternalOrderRaw;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderPayment;
use App\Models\OrderSource;
use App\Models\Status;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Створює замовлення CRM із канонічних даних зовнішнього сайту.
 * Логіка створення дзеркалить ручний OrderController@store, але не змінює його.
 */
class ExternalOrderImporter
{
    public function __construct(protected ProductMatcher $matcher)
    {
    }

    /**
     * Ідемпотентно: повторний імпорт того ж external_order_id повертає наявне замовлення.
     */
    public function import(OrderSource $source, array $canonical, ?ExternalOrderRaw $raw = null): Order
    {
        $externalOrderId = isset($canonical['external_order_id']) ? trim((string) $canonical['external_order_id']) : '';

        if ($externalOrderId !== '') {
            $existing = Order::query()
                ->where('source_id', $source->id)
                ->where('external_id', $externalOrderId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($source, $canonical, $externalOrderId) {
            $customer = $this->resolveCustomer((array) ($canonical['customer'] ?? []));

            $statusId = Status::query()
                ->where('code', 'new')
                ->where('type', 'order')
                ->value('id');

            $currency = (string) ($canonical['currency'] ?? 'UAH');

            $order = Order::create([
                'order_number' => 'TMP-' . now()->format('YmdHis') . '-' . random_int(100, 999),
                'source' => $source->code,
                'source_id' => $source->id,
                'external_id' => $externalOrderId !== '' ? $externalOrderId : null,
                'status' => 'new',
                'status_id' => $statusId,
                'payment_status' => 'unpaid',
                'customer_id' => $customer?->id,
                'currency' => $currency,
                'comment_internal' => $canonical['note'] ?? null,
                'needs_review' => false,
                'search_blob' => $this->buildSearchBlob($customer),
            ]);

            // Номер замовлення = id (як у ручному створенні).
            $order->update(['order_number' => (string) $order->id]);

            // Позиції + мапінг товарів.
            $needsReview = false;
            foreach ((array) ($canonical['items'] ?? []) as $item) {
                $item = (array) $item;
                $match = $this->matcher->match($source, $item);
                if (!$match['matched']) {
                    $needsReview = true;
                }

                $price = (float) ($item['price'] ?? 0);
                $qty = (int) ($item['qty'] ?? 1);
                $qty = $qty > 0 ? $qty : 1;

                $order->items()->create([
                    'product_id' => $match['product_id'],
                    'product_variant_id' => $match['product_variant_id'],
                    'external_id' => $this->nullableString($item['external_id'] ?? null),
                    'product_title' => $item['name'] ?? null,
                    'sku' => $this->nullableString($item['sku'] ?? null),
                    'size' => $this->nullableString($item['size'] ?? null),
                    'color' => $this->nullableString($item['color'] ?? null),
                    'price' => $price,
                    'qty' => $qty,
                    'total' => $price * $qty,
                ]);
            }

            if ($needsReview) {
                $order->update(['needs_review' => true]);
            }

            // Оплата.
            $payment = (array) ($canonical['payment'] ?? []);
            OrderPayment::create([
                'order_id' => $order->id,
                'method' => $payment['method'] ?? 'cod',
                'prepay_amount' => $payment['prepay_amount'] ?? null,
                'currency' => $payment['currency'] ?? $currency,
            ]);

            // Доставка — текстом; рефи Нової Пошти резолвимо пізніше (окремий етап).
            $delivery = (array) ($canonical['delivery'] ?? []);
            $deliveryType = $this->mapDeliveryType($delivery['type'] ?? null);
            $serviceType = match ($deliveryType) {
                'courier' => OrderDelivery::SERVICE_DOORS,
                'postomat' => OrderDelivery::SERVICE_POSTOMAT,
                default => OrderDelivery::SERVICE_WAREHOUSE,
            };

            OrderDelivery::create([
                'order_id' => $order->id,
                'carrier' => 'nova_poshta',
                'delivery_type' => $deliveryType,
                'service_type' => $serviceType,
                'delivery_payer' => 'recipient',
                'city_name' => $delivery['city_name'] ?? null,
                'city_ref' => $delivery['city_ref'] ?? null,
                'settlement_ref' => $delivery['settlement_ref'] ?? null,
                'warehouse_name' => $delivery['warehouse_name'] ?? null,
                'warehouse_ref' => $delivery['warehouse_ref'] ?? null,
                'street_name' => $delivery['street_name'] ?? null,
                'building' => $delivery['building'] ?? null,
                'apartment' => $delivery['apartment'] ?? null,
                'recipient_name' => $delivery['recipient_name'] ?? ($customer?->full_name ?: null),
                'recipient_phone' => $delivery['recipient_phone'] ?? $customer?->phone,
            ]);

            return $order;
        });
    }

    protected function resolveCustomer(array $data): ?Customer
    {
        $phone = isset($data['phone']) ? trim((string) $data['phone']) : '';
        $normalized = PhoneNormalizer::normalize($phone !== '' ? $phone : null);

        if ($normalized) {
            $customer = Customer::query()->where('phone_normalized', $normalized)->first();
            if ($customer) {
                return $customer;
            }

            return Customer::create([
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'phone' => $phone !== '' ? $phone : null,
                'phone_normalized' => $normalized,
                'email' => $data['email'] ?? null,
            ]);
        }

        // Без телефону — створюємо клієнта лише якщо є хоч якісь дані.
        $hasData = array_filter([$data['first_name'] ?? null, $data['last_name'] ?? null, $data['email'] ?? null]);
        if ($hasData) {
            return Customer::create([
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'phone' => null,
                'phone_normalized' => null,
                'email' => $data['email'] ?? null,
            ]);
        }

        return null;
    }

    protected function buildSearchBlob(?Customer $customer): ?string
    {
        if (!$customer) {
            return null;
        }

        return trim(implode(' ', array_filter([
            $customer->full_name,
            $customer->phone,
            $customer->email,
        ]))) ?: null;
    }

    protected function mapDeliveryType(?string $type): string
    {
        $type = mb_strtolower((string) $type);

        if (str_contains($type, 'courier') || str_contains($type, 'door') || str_contains($type, 'кур')) {
            return 'courier';
        }
        if (str_contains($type, 'postomat') || str_contains($type, 'поштомат') || str_contains($type, 'постамат')) {
            return 'postomat';
        }

        return 'warehouse';
    }

    protected function nullableString($value): ?string
    {
        $value = $value === null ? '' : (string) $value;

        return $value !== '' ? $value : null;
    }
}
