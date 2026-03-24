<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatAiSetting extends Model
{
    protected $fillable = [
        'enabled',
        'default_agent_code',
        'reply_delay_seconds',
        'allow_assigned_conversations',
        'max_messages',
        'updated_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'reply_delay_seconds' => 'integer',
        'allow_assigned_conversations' => 'boolean',
        'max_messages' => 'integer',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
