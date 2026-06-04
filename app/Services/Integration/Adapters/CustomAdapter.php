<?php

namespace App\Services\Integration\Adapters;

/**
 * Адаптер для власних PHP-сайтів: сайт надсилає вже канонічний формат,
 * тут лише нормалізація типів і значення за замовчуванням (passthrough).
 */
class CustomAdapter implements OrderAdapter
{
    public function normalize(array $raw): array
    {
        $customer = (array) ($raw['customer'] ?? []);
        $delivery = (array) ($raw['delivery'] ?? []);
        $payment = (array) ($raw['payment'] ?? []);
        $currency = (string) ($raw['currency'] ?? ($payment['currency'] ?? 'UAH'));

        $items = [];
        foreach ((array) ($raw['items'] ?? []) as $item) {
            $item = (array) $item;
            $qty = (int) ($item['qty'] ?? 1);
            $items[] = [
                'external_id' => isset($item['external_id']) ? (string) $item['external_id'] : '',
                'sku' => isset($item['sku']) ? (string) $item['sku'] : '',
                'name' => (string) ($item['name'] ?? $item['title'] ?? ''),
                'size' => isset($item['size']) ? (string) $item['size'] : '',
                'color' => isset($item['color']) ? (string) $item['color'] : '',
                'qty' => $qty > 0 ? $qty : 1,
                'price' => (float) ($item['price'] ?? 0),
            ];
        }

        return [
            'external_order_id' => isset($raw['external_order_id']) ? (string) $raw['external_order_id'] : '',
            'customer' => [
                'first_name' => $customer['first_name'] ?? null,
                'last_name' => $customer['last_name'] ?? null,
                'phone' => $customer['phone'] ?? null,
                'email' => $customer['email'] ?? null,
            ],
            'items' => $items,
            'delivery' => [
                'type' => $delivery['type'] ?? null,
                'city_name' => $delivery['city_name'] ?? null,
                'warehouse_name' => $delivery['warehouse_name'] ?? null,
                'street_name' => $delivery['street_name'] ?? null,
                'building' => $delivery['building'] ?? null,
                'apartment' => $delivery['apartment'] ?? null,
                'recipient_name' => $delivery['recipient_name'] ?? null,
                'recipient_phone' => $delivery['recipient_phone'] ?? null,
            ],
            'payment' => [
                'method' => $payment['method'] ?? 'cod',
                'total' => isset($payment['total']) ? (float) $payment['total'] : null,
                'prepay_amount' => isset($payment['prepay_amount']) ? (float) $payment['prepay_amount'] : null,
                'currency' => $currency,
            ],
            'currency' => $currency,
            'note' => $raw['note'] ?? null,
        ];
    }
}
