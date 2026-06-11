<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboxConversation extends Model
{
    protected $fillable = [
        'meta_connection_id',
        'inbox_contact_id',
        'channel',
        'last_message_at',
        'last_message_text',
        'last_message_direction',
        'unread_count',
        'status',
        'chat_status_id',
        'ai_enabled',
        'ai_context_after_id',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_count' => 'integer',
        'ai_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Нова розмова одразу отримує статус чату за замовчуванням («Новий»).
        static::creating(function (self $c) {
            $c->chat_status_id ??= ChatStatus::query()->where('is_default', true)->value('id');
        });
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    public function chatStatus(): BelongsTo
    {
        return $this->belongsTo(ChatStatus::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(InboxContact::class, 'inbox_contact_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InboxMessage::class);
    }
}
