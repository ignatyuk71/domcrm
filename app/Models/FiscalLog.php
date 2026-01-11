<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalLog extends Model
{
    protected $fillable = [
        'fiscal_receipt_id',
        'order_id',
        'receipt_uuid',
        'fiscal_code',
        'status',
        'visual_url',
        'stage',
        'method',
        'request_body',
        'response_body',
        'response_code',
        'duration_ms',
    ];

    protected $casts = [
        'request_body' => 'array',
        'response_body' => 'array',
        'duration_ms' => 'integer',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(FiscalReceipt::class, 'fiscal_receipt_id');
    }
}
