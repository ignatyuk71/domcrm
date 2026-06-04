<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сирий лог вхідних замовлень із зовнішніх сайтів (для надійності та ідемпотентності).
 */
class ExternalOrderRaw extends Model
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    protected $table = 'external_orders_raw';

    protected $fillable = [
        'source_id',
        'external_order_id',
        'adapter',
        'payload',
        'status',
        'error',
        'order_id',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(OrderSource::class, 'source_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
