<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Color extends Model
{
    protected $fillable = [
        'name',
        'hex_code',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function productMedia(): HasMany
    {
        return $this->hasMany(ProductMedia::class, 'color_id')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
