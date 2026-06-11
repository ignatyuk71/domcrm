<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Група галереї ШІ — модельна лінійка («Вуличні пухнасті тапки»).
 * Тримає товари лінійки та її фото (колажі + кольори).
 */
class AiPhotoGroup extends Model
{
    protected $fillable = ['name', 'ai_description', 'sort_order'];

    public function photos(): HasMany
    {
        return $this->hasMany(AiPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'ai_photo_group_product');
    }
}
