<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Пам'ять мапінгу: відповідність товару зовнішнього сайту до товару/варіанту CRM.
 */
class ExternalProduct extends Model
{
    protected $fillable = [
        'source_id',
        'external_id',
        'external_size',
        'external_sku',
        'external_name',
        'product_id',
        'product_variant_id',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(OrderSource::class, 'source_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
