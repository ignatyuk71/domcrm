<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class FiscalReceipt extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_CANCELED = 'canceled';

    public const TYPE_SELL = 'sell';
    public const TYPE_RETURN = 'return';
    public const TYPE_SERVICE_IN = 'service_in';
    public const TYPE_SERVICE_OUT = 'service_out';

    protected $fillable = [
        'order_id',
        'shift_id',
        'cashier_id',
        'uuid',
        'fiscal_code',
        'type',
        'status',
        'payload_hash',
        'check_link',
        'total_amount',
        'error_message',
        'retry_count',
        'is_offline',
        'meta',
    ];

    protected $casts = [
        'total_amount' => 'integer',
        'retry_count' => 'integer',
        'is_offline' => 'boolean',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (FiscalReceipt $receipt) {
            if (empty($receipt->uuid)) {
                $receipt->uuid = (string) Str::uuid();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(FiscalShift::class, 'shift_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(FiscalLog::class, 'fiscal_receipt_id');
    }

    public function scopeSuccess(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }
}
