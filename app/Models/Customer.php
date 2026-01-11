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
        'fb_user_id',
        'fb_profile_pic',
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

    /**
     * Повідомлення клієнта з Facebook.
     */
    public function fbMessages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FacebookMessage::class)->orderBy('created_at', 'asc');
    }
}
