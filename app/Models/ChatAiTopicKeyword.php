<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatAiTopicKeyword extends Model
{
    protected $fillable = [
        'topic_id',
        'phrase',
        'match_type',
        'weight',
        'is_active',
    ];

    protected $casts = [
        'weight' => 'integer',
        'is_active' => 'boolean',
    ];

    public function topic()
    {
        return $this->belongsTo(ChatAiTopic::class, 'topic_id');
    }
}
