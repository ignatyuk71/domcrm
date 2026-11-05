<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxComment extends Model
{
    protected $fillable = [
        'meta_connection_id',
        'channel',
        'post_id',
        'post_excerpt',
        'post_image',
        'comment_id',
        'parent_comment_id',
        'from_id',
        'from_name',
        'text',
        'status',
        'dm_message_id',
        'matched_group_name',
        'commented_at',
        'inbox_conversation_id',
        'gpt_context',
    ];

    protected $hidden = ['gpt_context'];

    protected $casts = [
        'commented_at' => 'datetime',
        'gpt_context' => 'encrypted:array',
        'inbox_conversation_id' => 'integer',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(InboxConversation::class, 'inbox_conversation_id');
    }
}
