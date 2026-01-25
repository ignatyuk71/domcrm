<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ConversationTag extends Model
{
    protected $fillable = [
        'code',
        'name',
        'color',
        'icon',
    ];

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(
            Conversation::class,
            'conversation_tag_links'
        )->withTimestamps();
    }
}
