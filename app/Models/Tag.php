<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $fillable = [
        'code',
        'name',
        'color',
        'icon',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function orders()
    {
        return $this->belongsToMany(
            \App\Models\Order::class,
            'order_tags'
        )->withTimestamps();
    }
}
