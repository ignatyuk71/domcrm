<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

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

        // Статус доставки (оновлюється через трекінг)
        'delivery_status_code',
        'delivery_status_label',
        'delivery_status_description',
        'delivery_status_color',
        'delivery_status_icon',
        'delivery_status_updated_at',
        'last_tracked_at',
    ];

    protected $casts = [
        'delivery_cost' => 'decimal:2',
        'delivery_status_updated_at' => 'datetime',
        'last_tracked_at' => 'datetime',
    ];

    /** Доставка належить замовленню. */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderDeliveryStatusHistory::class, 'order_delivery_id');
    }

    public function activeWarehouseStatus(): HasOne
    {
        return $this->hasOne(OrderDeliveryStatusHistory::class, 'order_delivery_id')
            ->where('status_code', 'at_warehouse')
            ->whereNull('exited_at')
            ->latestOfMany('entered_at');
    }

    public function syncStatusHistory(array $normalized, Carbon $timestamp): void
    {
        $code = $normalized['code'] ?? null;
        if (!$code) {
            return;
        }

        $active = $this->statusHistory()
            ->whereNull('exited_at')
            ->latest('entered_at')
            ->first();

        if (!$active || $active->status_code !== $code) {
            if ($active) {
                $active->forceFill(['exited_at' => $timestamp])->saveQuietly();
            }

            $this->statusHistory()->create([
                'status_code' => $code,
                'status_label' => $normalized['label'] ?? null,
                'status_description' => $normalized['description'] ?? null,
                'source_code' => $normalized['source_code'] ?? null,
                'entered_at' => $timestamp,
            ]);
        }
    }

    // Константи платника
    public const PAYER_SENDER = 'sender';
    public const PAYER_RECIPIENT = 'recipient';

    // Константи типів сервісу Нової Пошти
    public const SERVICE_WAREHOUSE = 'WarehouseWarehouse';
    public const SERVICE_POSTOMAT = 'WarehousePostomat';
    public const SERVICE_DOORS = 'WarehouseDoors';
}
