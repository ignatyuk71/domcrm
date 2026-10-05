<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NovaPayAccount extends Model
{
    protected $table = 'novapay_accounts';

    protected $guarded = ['id'];

    protected $hidden = ['iban'];

    protected $casts = ['enabled' => 'boolean', 'import_from' => 'date'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(NovaPayConnection::class, 'connection_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(NovaPayBalance::class, 'account_id');
    }

    public function maskedIban(): string
    {
        return substr($this->iban, 0, 6).' •••• '.substr($this->iban, -4);
    }

    public function setImportFromAttribute($value): void
    {
        $this->attributes['import_from'] = $value === null ? null : \Carbon\CarbonImmutable::parse($value)->toDateString();
    }
}
