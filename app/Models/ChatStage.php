<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatStage extends Model
{
    protected $fillable = [
        'code',
        'name',
        'color',
        'sort_order',
        'is_default',
        'is_final',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_default' => 'boolean',
        'is_final' => 'boolean',
    ];

    public function conversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class, 'stage_id');
    }
}
