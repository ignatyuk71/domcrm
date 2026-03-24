<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatAiRun extends Model
{
    protected $fillable = [
        'conversation_id',
        'state_id',
        'source_message_id',
        'agent_id',
        'prompt_version_id',
        'requested_by',
        'stage_snapshot',
        'status',
        'provider',
        'model',
        'idempotency_key',
        'input_messages',
        'input_chars',
        'output_chars',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'latency_ms',
        'cost_usd',
        'output_text',
        'meta_json',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'input_messages' => 'integer',
        'input_chars' => 'integer',
        'output_chars' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'latency_ms' => 'integer',
        'cost_usd' => 'decimal:6',
        'meta_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(ChatAiConversationState::class, 'state_id');
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'source_message_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(ChatAiAgent::class, 'agent_id');
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(ChatAiPromptVersion::class, 'prompt_version_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChatAiEvent::class, 'run_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(ChatAiFeedback::class, 'run_id');
    }
}

