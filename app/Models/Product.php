<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    /**
     * Масово заповнювані поля
     */
    protected $fillable = [
        'title',
        'category',
        'sku',

        'weight_g',
        'length_cm',
        'width_cm',
        'height_cm',

        'currency',
        'cost_price',
        'sale_price',

        'stock_qty',
        'min_stock',

        'description',
        'main_photo_path',

        'is_active',
    ];

    /**
     * Приведення типів
     */
    protected $casts = [
        'weight_g'   => 'integer',
        'stock_qty'  => 'integer',
        'min_stock'  => 'integer',

        'cost_price' => 'decimal:2',
        'sale_price' => 'decimal:2',

        'is_active'  => 'boolean',
    ];

    /**
     * На майбутнє: позиції замовлень
     */
    // public function orderItems()
    // {
    //     return $this->hasMany(OrderItem::class);
    // }
}
