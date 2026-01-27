<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDeliveryStatusHistory extends Model
{
    protected $fillable = [
        'order_delivery_id',
        'status_code',
        'status_label',
        'status_description',
        'source_code',
        'entered_at',
        'exited_at',
    ];

    protected $casts = [
        'entered_at' => 'datetime',
        'exited_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(OrderDelivery::class, 'order_delivery_id');
    }
}
