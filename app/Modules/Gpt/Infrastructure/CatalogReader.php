<?php

namespace App\Modules\Gpt\Infrastructure;

use App\Models\AiPhotoGroup;
use App\Models\Product;
use App\Modules\Gpt\Domain\CatalogQuery;
use App\Modules\Gpt\Domain\DraftFailure;
use Illuminate\Database\Eloquent\Builder;

class CatalogReader
{
    private const PRODUCT_COLUMNS = ['id', 'title', 'sku', 'color_id', 'category_id', 'sale_price', 'currency', 'stock_qty'];

    public function searchProducts(string $query, ?string $size = null): array
    {
        $terms = (new CatalogQuery)->terms($query);
        if ($terms === []) {
            return ['products' => [], 'has_more' => false];
        }

        // Каталог доступний незалежно від наявності товару в галереї.
        $products = Product::query()->where('is_active', true);
        foreach ($terms as $alternatives) {
            $products->where(function (Builder $match) use ($alternatives) {
                foreach ($alternatives as $term) {
                    $like = '%'.str_replace(['%', '_', '\\'], '', $term).'%';
                    $match->orWhereRaw('LOWER(title) LIKE ?', [$like])->orWhereRaw('LOWER(sku) LIKE ?', [$like])
                        ->orWhereHas('color', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like]))
                        ->orWhereHas('category', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like]))
                        ->orWhereHas('variants', fn ($q) => $q->where('is_active', true)
                            ->where(fn ($v) => $v->whereRaw('LOWER(sku) LIKE ?', [$like])->orWhere('size', 'like', $like)));
                }
            });
        }
        $found = $products->with(['color:id,name', 'category:id,name', 'variants' => fn ($q) => $q->orderBy('id')])
            ->orderBy('title')->orderBy('id')->limit(9)->get(self::PRODUCT_COLUMNS);

        return [
            'products' => $found->take(8)->map(fn ($product) => $this->offerData($product, $size))->all(),
            'has_more' => $found->count() > 8,
        ];
    }

    public function groups(): array
    {
        return AiPhotoGroup::query()->whereHas('products', fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('id')->limit(200)
            ->get(['id', 'name'])->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->all();
    }

    public function search(string $query): array
    {
        $query = trim(str_replace(['%', '_', '\\'], '', $query));
        if ($query === '') {
            return ['models' => []];
        }
        $like = '%'.mb_substr($query, 0, 100).'%';

        $groups = AiPhotoGroup::query()
            ->whereHas('products', fn ($p) => $p->where('is_active', true))
            ->where(fn ($g) => $g->where('name', 'like', $like)->orWhere('ai_description', 'like', $like)->orWhereHas('products', function ($p) use ($like) {
                $p->where('is_active', true)->where(fn ($q) => $q->where('title', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhereHas('color', fn ($c) => $c->where('name', 'like', $like))
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', $like))
                    ->orWhereHas('variants', fn ($v) => $v->where('is_active', true)->where('sku', 'like', $like)));
            }))
            ->orderBy('sort_order')->orderBy('id')->limit(8)->get(['id', 'name', 'ai_description']);

        return ['models' => $groups->map(fn ($g) => [
            'id' => $g->id, 'name' => $g->name,
            'description' => mb_substr((string) $g->ai_description, 0, 500),
        ])->all()];
    }

    public function describe(int $groupId): array
    {
        $group = AiPhotoGroup::find($groupId);
        if (! $group) {
            throw new DraftFailure('catalog_missing', 'Модельної лінійки вже немає в галереї.');
        }
        $products = $group->products()->where('is_active', true)->with('color:id,name')
            ->orderBy('products.id')->limit(41)->get(['products.id', 'title', 'sku', 'color_id']);
        if ($products->isEmpty() || $products->count() > 40) {
            throw new DraftFailure('catalog_unavailable', 'Перевірте активні товари та розмір групи галереї.');
        }
        $ids = $products->pluck('id')->all();
        $photos = [];
        foreach ($group->photos()->with('products:products.id')->limit(120)->get() as $photo) {
            // Пояснення розміру не видаємо за фото кольору в першій версії модуля.
            if (($photo->purpose ?? 'product') !== 'product') {
                continue;
            }
            $marked = $photo->products->pluck('id')->all();
            // Фото повинне мати однозначні відмітки товарів саме цієї активної групи.
            if ($marked === [] || array_diff($marked, $ids) !== []
                || ! preg_match('~\Aai-gallery/[a-zA-Z0-9][a-zA-Z0-9._-]*\.(?:jpe?g|png|webp|gif|avif)\z~i', $photo->path)) {
                continue;
            }
            $photos[] = [
                'id' => $photo->id, 'url' => '/'.$photo->path,
                'kind' => count($marked) > 1 ? 'collage' : 'color', 'product_ids' => $marked,
            ];
        }

        return [
            'id' => $group->id, 'name' => $group->name,
            'description' => mb_substr((string) $group->ai_description, 0, 3000),
            'products' => $products->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->title, 'sku' => $p->sku, 'color' => $p->color?->name,
            ])->all(),
            'photos' => $photos,
            'sources' => ['ai_photo_groups', 'ai_photo_group_product', 'ai_photos', 'ai_photo_product', 'colors'],
        ];
    }

    public function offer(int $productId, ?string $size = null): array
    {
        return $this->offers([$productId], $productId, $size)[$productId];
    }

    public function offers(array $productIds, ?int $selectedId = null, ?string $size = null): array
    {
        if ($productIds === []) {
            return [];
        }
        $products = Product::query()->where('is_active', true)->whereIn('id', $productIds)
            ->with(['color:id,name', 'category:id,name', 'variants' => fn ($q) => $q->orderBy('id')])
            ->get(self::PRODUCT_COLUMNS)->keyBy('id');
        if ($products->count() !== count(array_unique($productIds))) {
            throw new DraftFailure('product_unavailable', 'Товар недоступний або потребує перевірки менеджером.');
        }
        $offers = [];
        foreach ($productIds as $id) {
            $offers[$id] = $this->offerData($products[$id], $id === $selectedId ? $size : null);
        }

        return $offers;
    }

    private function offerData(Product $product, ?string $size): array
    {
        if ($product->variants->count() > 100) {
            throw new DraftFailure('product_unavailable', 'Товар недоступний або потребує перевірки менеджером.');
        }
        $variants = $product->variants->where('is_active', true)->map(fn ($v) => [
            'id' => $v->id, 'size' => $v->size, 'sku' => $v->sku,
            'stock_qty' => (int) $v->stock_qty, 'available' => (int) $v->stock_qty > 0,
        ])->values()->all();
        $hasVariants = $product->variants->isNotEmpty();
        $selected = $size === null ? $variants : array_values(array_filter($variants, fn ($v) => $v['size'] === $size));
        $qty = $hasVariants ? array_sum(array_map(fn ($v) => max(0, $v['stock_qty']), $selected)) : (int) $product->stock_qty;

        return [
            'id' => $product->id, 'title' => $product->title, 'sku' => $product->sku,
            'color' => $product->color?->name, 'category' => $product->category?->name,
            'sale_price' => $product->sale_price, 'currency' => $product->currency,
            'has_variants' => $hasVariants, 'variants' => $variants, 'requested_size' => $size,
            'stock_qty' => $qty, 'available' => $qty > 0 && ($hasVariants || $size === null),
            'checked_at' => now()->toIso8601String(),
            'sources' => ['products', 'product_variants', 'colors', 'categories'],
        ];
    }
}
