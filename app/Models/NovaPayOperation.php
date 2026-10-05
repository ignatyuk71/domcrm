<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NovaPayOperation extends Model
{
    protected $table = 'novapay_operations';

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'booked_on' => 'date'];

    public function setBookedOnAttribute($value): void
    {
        // DATE зберігаємо однаково в MySQL і SQLite, без неявного часу 00:00:00.
        $this->attributes['booked_on'] = \Carbon\CarbonImmutable::parse($value)->toDateString();
    }
}
