<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
        'email',
        'note',
    ];

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
    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
