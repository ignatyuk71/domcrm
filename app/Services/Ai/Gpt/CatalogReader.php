<?php

namespace App\Services\Ai\Gpt;

use App\Models\AiPhoto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/** Фото робочого каталогу: лише чинні звʼязки товарів і файли галереї CRM. */
class CatalogReader
{
    public function photos(array $ids): array
    {
        $columns = ['ai_photos.id', 'ai_photo_group_id', 'path', 'sort_order'];
        $hasPurpose = Schema::hasColumn('ai_photos', 'purpose');
        if ($hasPurpose) $columns[] = 'purpose';

        return $this->photoQuery()->whereIn('ai_photos.id', $ids)
            ->with(['products:products.id,title,is_active', 'group:id,name', 'group.products:products.id'])
            ->orderBy('sort_order')->orderBy('ai_photos.id')->get($columns)
            ->filter(fn ($photo) => $this->hasFile($photo))
            ->map(function ($photo) use ($hasPurpose) {
                $products = $photo->products->filter(fn ($product) => $product->is_active && $photo->group->products->contains('id', $product->id));
                $purpose = $hasPurpose && $photo->purpose === 'size_guide' ? 'size_guide' : 'product';
                $kind = $purpose === 'size_guide' ? 'size_guide' : ($photo->products->count() > 1 ? 'collage' : 'product');
                return ['id' => $photo->id, 'purpose' => $purpose, 'kind' => $kind, 'group_id' => $photo->ai_photo_group_id,
                    'product_ids' => $products->pluck('id')->values()->all(),
                    'label' => match ($kind) {
                        'size_guide' => 'Пояснення розміру · '.$photo->group->name,
                        'collage' => $photo->group->name,
                        default => $products->pluck('title')->implode(' · '),
                    }];
            })->values()->all();
    }

    private function photoQuery(): Builder
    {
        return AiPhoto::query()->whereExists(fn ($q) => $q->selectRaw('1')->from('ai_photo_product as tag')
            ->join('products as tagged_product', 'tagged_product.id', '=', 'tag.product_id')
            ->join('ai_photo_group_product as membership', function ($join) {
                $join->on('membership.product_id', '=', 'tag.product_id')->on('membership.ai_photo_group_id', '=', 'ai_photos.ai_photo_group_id');
            })->whereColumn('tag.ai_photo_id', 'ai_photos.id')->where('tagged_product.is_active', true));
    }

    private function hasFile(AiPhoto $photo): bool
    {
        $relative = ltrim($photo->path, '/');
        if (! str_starts_with($relative, 'ai-gallery/') || str_contains($relative, '..') || str_contains($relative, '\\')) return false;
        $base = realpath(public_path('ai-gallery'));
        $file = realpath(public_path($relative));
        if (! $base || ! $file || ! str_starts_with($file, $base.DIRECTORY_SEPARATOR) || ! is_file($file)) return false;
        return in_array((new \finfo(FILEINFO_MIME_TYPE))->file($file), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    }
}
