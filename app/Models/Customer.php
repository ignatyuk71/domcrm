<?php

namespace App\Models;

use App\Support\EmailNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    /**
     * Масово заповнювані поля
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'phone',
        'phone_normalized',
        'email',
        'note',
    ];

    /** Однакове очищення email для ручного введення та імпорту замовлень. */
    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = EmailNormalizer::normalize($value);
    }

    /**
     * Повне імʼя (НЕ зберігається в БД)
     */
    public function getFullNameAttribute(): string
    {
        return trim(
            ($this->first_name ?? '') . ' ' . ($this->last_name ?? '')
        );
    }

    /**
     * Замовлення цього клієнта.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
