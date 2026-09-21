<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpensePayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'amount_uah_minor' => 'integer', 'exchange_rate' => 'decimal:6'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ExpenseAccount::class, 'account_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ExpenseReceipt::class, 'payment_id');
    }
}
