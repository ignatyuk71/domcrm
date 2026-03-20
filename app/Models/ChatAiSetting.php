<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatAiSetting extends Model
{
    protected $fillable = [
        'enabled',
        'assistant_name',
        'model',
        'max_messages',
        'reply_style',
        'company_context',
        'qualification_fields',
        'handoff_rules',
        'knowledge_base',
        'updated_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'qualification_fields' => 'array',
    ];

    public static function current(): ?self
    {
        return static::query()->first();
    }
}
