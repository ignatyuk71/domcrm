<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatAiPromptVersion extends Model
{
    protected $fillable = [
        'agent_id',
        'stage',
        'version',
        'system_prompt',
        'policy_json',
        'is_current',
        'created_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'policy_json' => 'array',
        'is_current' => 'boolean',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(ChatAiAgent::class, 'agent_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ChatAiRun::class, 'prompt_version_id');
    }
}

