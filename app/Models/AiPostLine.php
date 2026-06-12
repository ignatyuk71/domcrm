<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPostLine extends Model
{
    protected $fillable = ['post_id', 'ai_photo_group_id', 'source'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(AiPhotoGroup::class, 'ai_photo_group_id');
    }
}
