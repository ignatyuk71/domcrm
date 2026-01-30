<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NovaPoshtaSetting extends Model
{
    protected $fillable = [
        'api_key',
        'sender_ref',
        'contact_ref',
        'sender_phone',
        'sender_city_ref',
        'sender_warehouse_ref',
    ];

    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }
}
