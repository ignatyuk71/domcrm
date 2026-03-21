<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatAiTopicMedia extends Model
{
    protected $fillable = [
        'topic_id',
        'saved_file_id',
        'label',
        'media_type',
        'url',
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

    public function savedFile()
    {
        return $this->belongsTo(SavedFile::class, 'saved_file_id');
    }
}
