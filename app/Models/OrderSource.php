<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        // --- Інтеграція із зовнішніми сайтами ---
        'is_integration',
        'mode',         // push | pull
        'adapter',      // custom | shopify | opencart | prom
        'api_key',
        'api_secret',
        'pull_config',
        'is_enabled',
        'last_pulled_at',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_integration' => 'boolean',
        'is_enabled' => 'boolean',
        'pull_config' => 'array',
        'last_pulled_at' => 'datetime',
    ];

    /** Тільки джерела-інтеграції (підключені сайти). */
    public function scopeIntegrations(Builder $query): Builder
    {
        return $query->where('is_integration', true);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'source_id');
    }

    /** Збережені відповідності товарів цього джерела (пам'ять мапінгу). */
    public function externalProducts(): HasMany
    {
        return $this->hasMany(ExternalProduct::class, 'source_id');
    }
}
