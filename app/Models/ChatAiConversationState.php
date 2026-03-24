<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatAiConversationState extends Model
{
    protected $fillable = [
        'conversation_id',
        'agent_id',
        'stage',
        'last_intent',
        'intent_purchase',
        'requires_human',
        'slots_json',
        'missing_slots_json',
        'selected_product_id',
        'selected_variant_id',
        'selected_color_id',
        'selected_size',
        'last_customer_message_id',
        'last_agent_message_id',
        'stage_updated_at',
        'turn_count',
    ];

    protected $casts = [
        'intent_purchase' => 'boolean',
        'requires_human' => 'boolean',
        'slots_json' => 'array',
        'missing_slots_json' => 'array',
        'stage_updated_at' => 'datetime',
        'turn_count' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(ChatAiAgent::class, 'agent_id');
    }

    public function selectedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'selected_product_id');
    }

    public function selectedVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'selected_variant_id');
    }

    public function selectedColor(): BelongsTo
    {
        return $this->belongsTo(Color::class, 'selected_color_id');
    }

    public function lastCustomerMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'last_customer_message_id');
    }

    public function lastAgentMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'last_agent_message_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ChatAiRun::class, 'state_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChatAiEvent::class, 'state_id');
    }
}

