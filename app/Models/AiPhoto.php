<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Фото галереї ШІ: товарне або пояснення розміру.
 * Для товарних фото: 1 товар = «колір», 2+ = «колаж», 0 = не розмічене.
 */
class AiPhoto extends Model
{
    public const PURPOSE_PRODUCT = 'product';

    public const PURPOSE_SIZE_GUIDE = 'size_guide';

    protected $fillable = ['ai_photo_group_id', 'path', 'sort_order', 'phash', 'purpose'];

    protected $attributes = ['purpose' => self::PURPOSE_PRODUCT];

    public function isSizeGuide(): bool
    {
        return $this->purpose === self::PURPOSE_SIZE_GUIDE;
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AiPhotoGroup::class, 'ai_photo_group_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'ai_photo_product');
    }

    public function url(): string
    {
        return url($this->path);
    }
}
