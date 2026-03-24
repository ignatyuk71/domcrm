<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatAiEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'state_id',
        'run_id',
        'event_type',
        'from_stage',
        'to_stage',
        'payload_json',
        'created_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(ChatAiConversationState::class, 'state_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ChatAiRun::class, 'run_id');
    }
}

