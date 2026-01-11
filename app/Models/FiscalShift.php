<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalShift extends Model
{
    protected $fillable = [
        'uuid',
        'cashier_id',
        'status',
        'opened_at',
        'closed_at',
        'z_report_id',
        'error_message',
        'meta',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function receipts(): HasMany
    {
        return $this->hasMany(FiscalReceipt::class, 'shift_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
