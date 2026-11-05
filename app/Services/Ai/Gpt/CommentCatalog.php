<?php

namespace App\Services\Ai\Gpt;

use App\Models\AiPhotoGroup;
use App\Models\AiSetting;
use App\Models\Product;

/** Мінімальний живий каталог для незалежної відповіді GPT на коментар. */
class CommentCatalog
{
    public function snapshot(): array
    {
        $showcase = AiSetting::query()->whereNull('meta_connection_id')->value('catalog_mode') === 'showcase';
        $products = Product::query()->select(['id', 'title', 'color_id', 'sale_price', 'stock_qty'])
            ->where('is_active', true)
            ->when($showcase, fn ($query) => $query->whereExists(fn ($membership) => $membership->selectRaw('1')
                ->from('ai_photo_group_product')->whereColumn('product_id', 'products.id')))
            ->withCount('variants')
            ->with(['color:id,name', 'variants' => fn ($query) => $query->select(['id', 'product_id', 'size', 'stock_qty'])
                ->where('is_active', true)->where('stock_qty', '>', 0)->orderBy('id')])
            ->orderBy('id')->limit(300)->get();
        if ($products->isEmpty()) return [];

        $groups = AiPhotoGroup::query()->select(['id', 'name', 'ai_description'])
            ->whereHas('products', fn ($query) => $query->whereIn('products.id', $products->modelKeys()))
            ->with(['products' => fn ($query) => $query->select('products.id')->whereIn('products.id', $products->modelKeys())])
            ->orderBy('sort_order')->orderBy('id')->get();
        $memberships = [];
        $scrubber = new TextScrubber;
        foreach ($groups as $group) {
            $entry = ['id' => $group->id, 'name' => $scrubber->scrub(strip_tags($group->name)),
                'description' => $scrubber->scrub(strip_tags((string) $group->ai_description))];
            foreach ($group->products as $product) $memberships[$product->id][] = $entry;
        }

        return $products->map(fn ($product) => [
            'id' => $product->id,
            'title' => $scrubber->scrub(strip_tags($product->title)),
            'groups' => $memberships[$product->id] ?? [],
            'color' => $product->color?->name,
            'sizes' => $product->variants->pluck('size')->filter(fn ($size) => trim((string) $size) !== '')->unique()->values()->all(),
            'price' => (string) $product->sale_price,
            // Залишок батьківського товару не підміняє наявність його варіантів.
            'available' => $product->variants->isNotEmpty() || ($product->variants_count === 0 && $product->stock_qty > 0),
        ])->all();
    }
}
