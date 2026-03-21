<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatAiTopic extends Model
{
    protected $fillable = [
        'name',
        'instruction',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public function keywords()
    {
        return $this->hasMany(ChatAiTopicKeyword::class, 'topic_id');
    }

    public function topicProducts()
    {
        return $this->hasMany(ChatAiTopicProduct::class, 'topic_id');
    }

    public function mediaItems()
    {
        return $this->hasMany(ChatAiTopicMedia::class, 'topic_id');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'chat_ai_topic_products', 'topic_id', 'product_id')
            ->withPivot(['sort_order', 'is_active'])
            ->withTimestamps();
    }
}
