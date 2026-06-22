<?php

namespace App\Services\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\OrderSource;
use App\Models\Status;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Запис замовлення (створення/оновлення) з усіма повʼязаними сутностями.
 * Винесено з OrderController — валідація лишається в контролері, тут лише
 * доменна логіка в одній транзакції. Поведінка 1:1.
 */
class OrderService
{
    /** Створити замовлення з товарами, оплатою, доставкою. */
    public function create(array $data, ?int $managerId = null): Order
    {
        return DB::transaction(function () use ($data, $managerId) {
            // Клієнт: шукаємо за телефоном або створюємо нового
            $customer = null;
            $phone = trim($data['customer']['phone'] ?? '');
            if ($phone) {
                $customer = Customer::firstOrCreate(
                    ['phone' => $phone],
                    [
                        'first_name' => $data['customer']['first_name'] ?? null,
                        'last_name' => $data['customer']['last_name'] ?? null,
                        'email' => $data['customer']['email'] ?? null,
                    ]
                );
            } elseif (!empty($data['customer'])) {
                $customer = Customer::create([
                    'first_name' => $data['customer']['first_name'] ?? null,
                    'last_name' => $data['customer']['last_name'] ?? null,
                    'phone' => null,
                    'email' => $data['customer']['email'] ?? null,
                ]);
            }

            // Генерація тимчасового номера (потім замінюємо на індекс)
            $orderNumber = $this->generateOrderNumber();
            $statusCode = $data['order']['status'] ?? 'new';
            $statusId = $this->resolveStatusId($statusCode, 'order');
            $sourceId = $data['order']['source_id'] ?? $this->resolveSourceId($data['order']['source'] ?? null);

            // Створення замовлення
            $order = Order::create([
                'order_number' => $orderNumber,
                'source' => $data['order']['source'] ?? null,
                'source_id' => $sourceId,
                'status' => $statusCode ?? 'new',
                'status_id' => $statusId,
                'payment_status' => $data['order']['payment_status'] ?? 'unpaid',
                'customer_id' => $customer?->id,
                'manager_id' => $managerId,
                'currency' => $data['order']['currency'] ?? 'UAH',
                'comment_internal' => $data['order']['comment_internal'] ?? null,
                // ДОДАНО: передаємо ТТН у пошуковий блоб
                'search_blob' => $this->buildSearchBlob($customer, $data['delivery']['ttn'] ?? null),
            ]);

            // Використовуємо індекс як номер замовлення
            $order->update(['order_number' => (string) $order->id]);

            // Теги (звʼязок через pivot)
            if (!empty($data['tag_ids'])) {
                $order->tags()->sync($this->resolveTagIds($data['tag_ids']));
            }

            // Товари
            foreach ($data['items'] as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'product_title' => $item['title'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'size' => $item['size'] ?? null,
                    'color' => $item['color'] ?? null,
                    'price' => $item['price'] ?? 0,
                    'qty' => $item['qty'] ?? 1,
                    'total' => ($item['price'] ?? 0) * ($item['qty'] ?? 1),
                ]);
            }

            // Оплата
            OrderPayment::create([
                'order_id' => $order->id,
                'method' => $data['payment']['method'] ?? 'cod',
                'prepay_amount' => $data['payment']['prepay_amount'] ?? null,
                'currency' => $data['payment']['currency'] ?? ($data['order']['currency'] ?? 'UAH'),
                'note' => $data['payment']['note'] ?? null,
            ]);

            // Доставка
            $deliveryType = $data['delivery']['delivery_type'] ?? 'warehouse';
            $serviceType = $data['delivery']['service_type'] ?? match ($deliveryType) {
                'courier' => OrderDelivery::SERVICE_DOORS,
                'postomat' => OrderDelivery::SERVICE_POSTOMAT,
                default => OrderDelivery::SERVICE_WAREHOUSE,
            };

            OrderDelivery::create([
                'order_id' => $order->id,
                'carrier' => $data['delivery']['carrier'] ?? 'nova_poshta',
                'delivery_type' => $deliveryType,
                'service_type' => $serviceType,
                'delivery_payer' => $data['delivery']['payer'] ?? 'recipient',
                'ttn' => $data['delivery']['ttn'] ?? null,
                'city_ref' => $data['delivery']['city_ref'] ?? null,
                'settlement_ref' => $data['delivery']['settlement_ref'] ?? null,
                'city_name' => $data['delivery']['city_name'] ?? null,
                'warehouse_ref' => $data['delivery']['warehouse_ref'] ?? null,
                'warehouse_name' => $data['delivery']['warehouse_name'] ?? null,
                'street_name' => $data['delivery']['street_name'] ?? null,
                'street_ref' => $data['delivery']['street_ref'] ?? null,
                'address_ref' => $data['delivery']['address_ref'] ?? null,
                'building' => $data['delivery']['building'] ?? null,
                'apartment' => $data['delivery']['apartment'] ?? null,
                'address_note' => $data['delivery']['address_note'] ?? null,
                'recipient_name' => $data['delivery']['recipient_name'] ?? null,
                'recipient_phone' => $data['delivery']['recipient_phone'] ?? null,
            ]);

            return $order;
        });
    }

    /** Оновити замовлення та повʼязані сутності. */
    public function update(Order $order, array $data): Order
    {
        DB::transaction(function () use ($data, $order) {
            // Клієнт
            $customer = $order->customer ?: new Customer();
            $customer->first_name = $data['customer']['first_name'] ?? null;
            $customer->last_name = $data['customer']['last_name'] ?? null;
            $customer->phone = $data['customer']['phone'] ?? null;
            $customer->email = $data['customer']['email'] ?? null;
            $customer->save();

            $statusCode = $data['order']['status'] ?? 'new';
            $statusId = $this->resolveStatusId($statusCode, 'order');
            $sourceId = $data['order']['source_id'] ?? $this->resolveSourceId($data['order']['source'] ?? null);

            // Замовлення
            $order->update([
                'source' => $data['order']['source'] ?? null,
                'source_id' => $sourceId,
                'status' => $statusCode,
                'status_id' => $statusId,
                'payment_status' => $data['order']['payment_status'] ?? 'unpaid',
                'customer_id' => $customer->id,
                'currency' => $data['order']['currency'] ?? 'UAH',
                'comment_internal' => $data['order']['comment_internal'] ?? null,
                // ДОДАНО: передаємо ТТН у пошуковий блоб
                'search_blob' => $this->buildSearchBlob($customer, $data['delivery']['ttn'] ?? null),
            ]);

            // Теги
            $order->tags()->sync($this->resolveTagIds($data['tag_ids'] ?? []));

            // Позиції: простий варіант — видалити старі і створити нові
            $order->items()->delete();
            foreach ($data['items'] as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'] ?? null,
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'product_title' => $item['title'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'size' => $item['size'] ?? null,
                    'color' => $item['color'] ?? null,
                    'price' => $item['price'] ?? 0,
                    'qty' => $item['qty'] ?? 1,
                    'total' => ($item['price'] ?? 0) * ($item['qty'] ?? 1),
                ]);
            }

            // Оплата
            $order->payment()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'method' => $data['payment']['method'] ?? 'cod',
                    'prepay_amount' => $data['payment']['prepay_amount'] ?? null,
                    'currency' => $data['payment']['currency'] ?? ($data['order']['currency'] ?? 'UAH'),
                    'note' => $data['payment']['note'] ?? null,
                ]
            );

            // Доставка
            $existingAddressRef = $order->delivery?->address_ref;
            $addressRef = array_key_exists('address_ref', $data['delivery'] ?? [])
                ? ($data['delivery']['address_ref'] ?? null)
                : $existingAddressRef;

            $deliveryType = $data['delivery']['delivery_type'] ?? 'warehouse';
            $serviceType = $data['delivery']['service_type'] ?? match ($deliveryType) {
                'courier' => OrderDelivery::SERVICE_DOORS,
                'postomat' => OrderDelivery::SERVICE_POSTOMAT,
                default => OrderDelivery::SERVICE_WAREHOUSE,
            };

            $order->delivery()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'carrier' => $data['delivery']['carrier'] ?? 'nova_poshta',
                    'delivery_type' => $deliveryType,
                    'service_type' => $serviceType,
                    'delivery_payer' => $data['delivery']['payer'] ?? 'recipient',
                    'ttn' => $data['delivery']['ttn'] ?? null,
                    'city_ref' => $data['delivery']['city_ref'] ?? null,
                    'settlement_ref' => $data['delivery']['settlement_ref'] ?? null,
                    'city_name' => $data['delivery']['city_name'] ?? null,
                    'warehouse_ref' => $data['delivery']['warehouse_ref'] ?? null,
                    'warehouse_name' => $data['delivery']['warehouse_name'] ?? null,
                    'street_name' => $data['delivery']['street_name'] ?? null,
                    'street_ref' => $data['delivery']['street_ref'] ?? null,
                    'address_ref' => $addressRef,
                    'building' => $data['delivery']['building'] ?? null,
                    'apartment' => $data['delivery']['apartment'] ?? null,
                    'address_note' => $data['delivery']['address_note'] ?? null,
                    'recipient_name' => $data['delivery']['recipient_name'] ?? null,
                    'recipient_phone' => $data['delivery']['recipient_phone'] ?? null,
                ]
            );
        });

        return $order;
    }

    protected function generateOrderNumber(): string
    {
        return 'TMP-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    /** Формуємо пошуковий blob для швидких фільтрів. */
    public function buildSearchBlob(?Customer $customer, $ttn = null): ?string
    {
        if (!$customer) return $ttn;

        // ОНОВЛЕНО: Додаємо ТТН в пошук
        return trim(implode(' ', array_filter([
            $customer->full_name,
            $customer->phone,
            $customer->email,
            $ttn,
        ])));
    }

    /**
     * Приймаємо tag_ids як масив числових id або кодів/назв;
     * повертаємо масив id з таблиці tags, створюючи відсутні за кодом.
     */
    public function resolveTagIds(array $tagIds): array
    {
        $ids = [];
        foreach ($tagIds as $tag) {
            if (is_numeric($tag)) {
                // Якщо передали id
                $ids[] = (int) $tag;
            } else {
                // Якщо передали код/назву
                $code = trim((string) $tag);
                if ($code === '') {
                    continue;
                }

                $tagModel = Tag::firstOrCreate(
                    ['code' => $code],
                    ['name' => $code]
                );
                $ids[] = $tagModel->id;
            }
        }
        return $ids;
    }

    protected function resolveStatusId(?string $code, string $type = 'order'): ?int
    {
        if (!$code) return null;
        return Status::where('code', $code)->where('type', $type)->value('id');
    }

    protected function resolveSourceId(?string $code): ?int
    {
        if (!$code) return null;
        return OrderSource::where('code', $code)->value('id');
    }
}
