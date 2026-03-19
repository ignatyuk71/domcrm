<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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
        'instagram_user_id',
        'instagram_username',
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
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Контакти клієнта у чат-каналах.
     */
    public function chatContacts(): HasMany
    {
        return $this->hasMany(ChatContact::class)->orderBy('id');
    }

    /**
     * Діалоги клієнта в новому чат-ядрі.
     */
    public function chatConversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class)->orderByDesc('last_message_at');
    }

    /**
     * Повідомлення клієнта через нове ядро чату.
     */
    public function fbMessages(): HasManyThrough
    {
        return $this->hasManyThrough(
            ChatMessage::class,
            ChatConversation::class,
            'customer_id',
            'conversation_id'
        )->orderBy('sent_at');
    }
}
