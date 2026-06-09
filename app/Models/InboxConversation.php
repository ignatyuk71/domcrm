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
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_count' => 'integer',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
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
