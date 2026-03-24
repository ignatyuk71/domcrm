<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatAiFeedback extends Model
{
    protected $table = 'chat_ai_feedback';

    protected $fillable = [
        'run_id',
        'conversation_id',
        'user_id',
        'decision',
        'edited_text',
        'note',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ChatAiRun::class, 'run_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

