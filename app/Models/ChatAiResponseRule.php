<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatAiResponseRule extends Model
{
    protected $fillable = [
        'code',
        'title',
        'instruction',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];
}
