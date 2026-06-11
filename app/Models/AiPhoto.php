<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Фото галереї ШІ. Тип похідний від відміток:
 * 1 товар = «колір», 2+ = «колаж», 0 = не розмічене (ШІ його не бачить).
 */
class AiPhoto extends Model
{
    protected $fillable = ['ai_photo_group_id', 'path', 'sort_order'];

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
