<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'amount_minor' => 'integer', 'version' => 'integer', 'expected_exchange_rate' => 'decimal:6'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ExpenseAccount::class, 'account_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ExpenseGroup::class, 'group_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }
}
