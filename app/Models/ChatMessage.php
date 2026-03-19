<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'parent_message_id',
        'external_message_id',
        'external_parent_message_id',
        'direction',
        'message_type',
        'delivery_status',
        'source',
        'text',
        'meta',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'error_message',
    ];

    protected $casts = [
        'meta' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_message_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatMessageAttachment::class, 'message_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
