<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalQueue extends Model
{
    public const STATUS_WAITING = 'waiting';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'fiscal_queue';

    protected $fillable = [
        'order_id',
        'type',
        'amount_cents',
        'available_at',
        'status',
        'attempts',
        'last_error',
        'processed_at',
    ];

    protected $casts = [
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
