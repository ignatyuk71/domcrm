<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessageAttachment extends Model
{
    protected $fillable = [
        'message_id',
        'attachment_type',
        'storage_disk',
        'storage_path',
        'original_url',
        'public_url',
        'mime_type',
        'file_name',
        'file_size',
        'width',
        'height',
        'duration_seconds',
        'sort_order',
        'meta',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_seconds' => 'integer',
        'sort_order' => 'integer',
        'meta' => 'array',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }
}
