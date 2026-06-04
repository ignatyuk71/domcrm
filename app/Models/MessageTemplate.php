<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    use HasFactory;

    /**
     * Атрибути, які можна присвоювати масово.
     */
    protected $fillable = [
        'title',
        'content',
        'sort_order',
        'is_active',
    ];

    /**
     * Атрибути, які слід перетворити до базових типів.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
