<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxComment extends Model
{
    protected $fillable = [
        'meta_connection_id',
        'channel',
        'post_id',
        'post_excerpt',
        'post_image',
        'comment_id',
        'parent_comment_id',
        'from_id',
        'from_name',
        'text',
        'status',
        'commented_at',
    ];

    protected $casts = [
        'commented_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }
}
