<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRun extends Model
{
    protected $fillable = [
        'inbox_conversation_id',
        'inbox_message_id',
        'status',
        'error',
        'tools_called',
        'tokens_in',
        'tokens_out',
        'duration_ms',
    ];

    protected $casts = [
        'tools_called' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(InboxConversation::class, 'inbox_conversation_id');
    }
}
