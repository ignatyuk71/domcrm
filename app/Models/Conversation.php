<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $fillable = [
        'customer_id',
        'platform',
        'last_message_text',
        'last_message_at',
        'unread_count',
        'status',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_count' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function meta(): HasOne
    {
        return $this->hasOne(ConversationMeta::class, 'conversation_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            ConversationTag::class,
            'conversation_tag_links'
        )->withTimestamps();
    }
}
