<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderSource extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'icon',
        'color',
        'sort_order',
        'is_default',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'source_id');
    }
}
