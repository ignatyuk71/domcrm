<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OrderStatusChange extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Журнал у застосунку доступний лише для додавання та читання.
        static::updating(fn () => throw new LogicException('Записи журналу не можна змінювати.'));
        static::deleting(fn () => throw new LogicException('Записи журналу не можна видаляти.'));
    }
}
