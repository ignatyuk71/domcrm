<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatConversation extends Model
{
    public const THREAD_KIND_DIRECT = 'direct';

    public const THREAD_KIND_COMMENT = 'comment';

    protected $fillable = [
        'meta_connection_id',
        'contact_id',
        'customer_id',
        'stage_id',
        'assigned_user_id',
        'status',
        'thread_kind',
        'external_thread_id',
        'last_message_id',
        'last_message_preview',
        'last_message_at',
        'last_inbound_at',
        'last_outbound_at',
        'unread_count',
        'snooze_until',
        'closed_at',
        'meta',
    ];

    protected $casts = [
        'last_message_id' => 'integer',
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'last_outbound_at' => 'datetime',
        'unread_count' => 'integer',
        'snooze_until' => 'datetime',
        'closed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function metaConnection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ChatContact::class, 'contact_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ChatStage::class, 'stage_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'last_message_id');
    }

    public function aiState(): HasOne
    {
        return $this->hasOne(ChatAiConversationState::class, 'conversation_id');
    }

    public function isCommentThread(): bool
    {
        return $this->thread_kind === self::THREAD_KIND_COMMENT;
    }
}
