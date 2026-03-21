<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatAiTopicProduct extends Model
{
    protected $fillable = [
        'topic_id',
        'product_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function topic()
    {
        return $this->belongsTo(ChatAiTopic::class, 'topic_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
