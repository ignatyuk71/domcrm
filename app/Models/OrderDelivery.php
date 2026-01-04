<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDelivery extends Model
{
    /**
     * Масове заповнення для реквізитів доставки та одержувача.
     */
    protected $fillable = [
        'order_id',
        'carrier',
        'delivery_type',
        'service_type', 
        'ttn',
        'ttn_ref', // ДОДАНО: Внутрішній ідентифікатор НП для видалення/редагування

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

    // Константи платника
    public const PAYER_SENDER = 'sender';
    public const PAYER_RECIPIENT = 'recipient';

    // Константи типів сервісу Нової Пошти
    public const SERVICE_WAREHOUSE = 'WarehouseWarehouse';
    public const SERVICE_POSTOMAT = 'WarehousePostomat';
    public const SERVICE_DOORS = 'WarehouseDoors';
}