<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    /**
     * Автододавані в JSON атрибути.
     */
    protected $appends = ['main_photo_url'];

    /**
     * Масово заповнювані поля
     */
    protected $fillable = [
        'title',
        'category_id',
        'color_id',
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

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function color()
    {
        return $this->belongsTo(Color::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Повний URL до головного фото (для фронту).
     */
    public function getMainPhotoUrlAttribute(): ?string
    {
        $path = $this->main_photo_path;
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        $clean = ltrim($path, '/');
        return str_starts_with($clean, 'storage/')
            ? "/{$clean}"
            : "/storage/{$clean}";
    }

    /**
     * На майбутнє: позиції замовлень
     */
    // public function orderItems()
    // {
    //     return $this->hasMany(OrderItem::class);
    // }
}
