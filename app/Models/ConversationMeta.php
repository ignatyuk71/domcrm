<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMeta extends Model
{
    protected $table = 'conversation_meta';

    protected $fillable = [
        'conversation_id',
        'stage',
        'snooze_until',
        'assignee_id',
        'last_inbound_at',
        'last_outbound_at',
    ];

    protected $casts = [
        'snooze_until' => 'datetime',
        'last_inbound_at' => 'datetime',
        'last_outbound_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
