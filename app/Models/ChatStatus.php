<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatStatus extends Model
{
    protected $fillable = [
        'code',
        'name',
        'icon',
        'color',
        'sort_order',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];
}
