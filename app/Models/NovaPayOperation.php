<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NovaPayOperation extends Model
{
    protected $table = 'novapay_operations';

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'booked_on' => 'date'];

    public function getStatusChangedAtAttribute($value): ?\Carbon\CarbonImmutable
    {
        // У БД час статусу завжди UTC, незалежно від timezone застосунку.
        return $value ? \Carbon\CarbonImmutable::parse($value, 'UTC') : null;
    }

    public function maskedCounterpartyIban(): ?string
    {
        return $this->counterparty_iban ? substr($this->counterparty_iban, 0, 6).' •••• '.substr($this->counterparty_iban, -4) : null;
    }

    public function maskedPartyIban(string $side): ?string
    {
        if (! in_array($side, ['sender', 'recipient'], true)) {
            return null;
        }
        $iban = $this->getAttribute($side.'_iban');
        if (! $iban) {
            return null;
        }

        return str_starts_with($iban, 'UA')
            ? 'UA '.substr($iban, 2, 2).' '.substr($iban, 4, 6).' •••• '.substr($iban, -4)
            : substr($iban, 0, 6).' •••• '.substr($iban, -4);
    }

    public function setBookedOnAttribute($value): void
    {
        // DATE зберігаємо однаково в MySQL і SQLite, без неявного часу 00:00:00.
        $this->attributes['booked_on'] = \Carbon\CarbonImmutable::parse($value)->toDateString();
    }
}
