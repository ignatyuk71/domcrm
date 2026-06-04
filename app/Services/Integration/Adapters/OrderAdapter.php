<?php

namespace App\Services\Integration\Adapters;

interface OrderAdapter
{
    /**
     * Перетворює сирий payload платформи у канонічний формат CRM.
     *
     * Канонічний формат:
     * [
     *   'external_order_id' => string,
     *   'customer' => ['first_name','last_name','phone','email'],
     *   'items' => [ ['external_id','sku','name','size','color','qty','price'], ... ],
     *   'delivery' => ['type','city_name','warehouse_name','recipient_name','recipient_phone', ...],
     *   'payment' => ['method','total','prepay_amount','currency'],
     *   'currency' => string,
     *   'note' => string|null,
     * ]
     */
    public function normalize(array $raw): array;
}
