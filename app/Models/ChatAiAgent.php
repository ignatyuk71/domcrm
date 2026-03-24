<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatAiAgent extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_active',
        'provider',
        'model',
        'temperature',
        'max_output_tokens',
        'config_json',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'temperature' => 'decimal:2',
        'max_output_tokens' => 'integer',
        'config_json' => 'array',
    ];

    public function promptVersions(): HasMany
    {
        return $this->hasMany(ChatAiPromptVersion::class, 'agent_id');
    }

    public function conversationStates(): HasMany
    {
        return $this->hasMany(ChatAiConversationState::class, 'agent_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ChatAiRun::class, 'agent_id');
    }
}

