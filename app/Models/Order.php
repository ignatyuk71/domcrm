<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\User;
use App\Models\Customer;
use App\Models\OrderDelivery;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\OrderSource;
use App\Models\Tag;
use App\Models\Status;

class Order extends Model
{
    protected $fillable = [
        'order_number',
        'source',
        'status',
        'payment_status',
        'status_id',
        'customer_id',
        'manager_id',
        'packer_id',
        'source_id',
        'currency',
        'comment_internal',
        'search_blob',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /** Покупець, що оформив замовлення. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Статус з довідника (нова логіка). */
    public function statusRef(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    /** Менеджер, що веде замовлення. */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** Пакувальник, закріплений за замовленням. */
    public function packer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packer_id');
    }

    /** Джерело замовлення (довідник). */
    public function source(): BelongsTo
    {
        return $this->belongsTo(OrderSource::class, 'source_id');
    }

    /** Позиції замовлення. */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Теги замовлення. */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'order_tags')->withTimestamps();
    }

    /** Реквізити оплати (1:1). */
    public function payment(): HasOne
    {
        return $this->hasOne(OrderPayment::class);
    }

    /** Дані доставки (1:1). */
    public function delivery(): HasOne
    {
        return $this->hasOne(OrderDelivery::class);
    }
}
