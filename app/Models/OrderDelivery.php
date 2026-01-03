<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDelivery extends Model
{
    /**
     * Масове заповнення для реквізитів доставки та одержувача.
     * Включає привʼязку до замовлення, тип/перевізник і адресу (місто/склад або вулиця),
     * а також хто платить за доставку і її вартість.
     */
    protected $fillable = [
        'order_id',
        'carrier',
        'delivery_type',
        'ttn',

        // хто платить / вартість доставки
        'delivery_payer',
        'delivery_cost',

        'city_ref',
        'city_name',

        'warehouse_ref',
        'warehouse_name',

        'street_name',
        'building',
        'apartment',
        'address_note',

        'recipient_name',
        'recipient_phone',
    ];

    protected $casts = [
        'delivery_cost' => 'decimal:2',
    ];

    /** Доставка належить замовленню. */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // (опційно) зручні константи, щоб не писати строки по коду
    public const PAYER_SENDER = 'sender';
    public const PAYER_RECIPIENT = 'recipient';
}
