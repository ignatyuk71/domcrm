<?php

namespace App\Modules\GptSandbox\Application;

use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\AiSetting;
use App\Models\Product;

/**
 * Побудова каталогу-вітрини для системного промпта та безпечних карток товару.
 * Винесено з AiAgentService. Цифри завжди з бази, службові слова чистить TextScrubber.
 */
class CatalogBuilder
{
    public function __construct(private TextScrubber $scrubber, private \App\Modules\GptSandbox\Infrastructure\CatalogReader $reader)
    {
    }

    private function scrub(?string $text): ?string
    {
        return $this->scrubber->scrub($text);
    }

    /**
     * Повний каталог для системного промпта, структурований по ЛІНІЯХ (вітрина):
     * заголовок лінії → «Від магазину» (знання продавчині з ai_description) →
     * кольори лінії з живими цінами/розмірами/фото. Товари поза лініями — внизу
     * блоком «ІНШІ ТОВАРИ». Цифри завжди з бази, слова — від власника.
     */
    public function buildCatalog(): string
    {
        $products = Product::query()
            ->where('is_active', true)
            ->withCount('variants')
            ->with(['category:id,name', 'color:id,name', 'variants' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('category_id')
            ->orderBy('title')
            ->limit(300)
            ->get()
            ->keyBy('id');

        if ($products->isEmpty()) {
            return '(каталог порожній)';
        }

        $photoInfo = $this->photoInfoFor($products->keys()->all());

        $line = function (Product $p) use ($photoInfo): string {
            $sizes = $p->variants->filter(fn ($v) => $v->stock_qty > 0)->pluck('size')->implode(',');
            $i = $photoInfo[$p->id] ?? [];
            $available = $p->variants->contains(fn ($variant) => $variant->stock_qty > 0)
                || ($p->variants_count === 0 && $p->stock_qty > 0);

            $parts = array_filter([
                '#' . $p->id,
                $this->scrub($p->title),
                $this->scrub($p->category?->name),
                $p->color?->name,
                'SKU ' . $p->sku,
                Money::label($p->sale_price),
                $sizes !== '' ? "розміри: {$sizes}" : ($available ? 'є в наявності' : 'НЕМАЄ в наявності'),
                !empty($i['фото']) ? 'фото:[' . implode(',', $i['фото']) . ']' : null,
                !empty($i['колаж']) ? 'колаж:' . $i['колаж'] : null,
                !empty($i['пояснення_розміру']) ? 'фото заміру:[' . implode(',', $i['пояснення_розміру']) . ']' : null,
            ], fn ($x) => $x !== null && $x !== '');

            return implode(' | ', $parts);
        };

        $sections = [];
        $used = [];

        $groups = AiPhotoGroup::with(['products:products.id', 'photos.products:products.id'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($groups as $g) {
            $ids = $g->products->pluck('id')
                ->filter(fn ($id) => $products->has($id) && !isset($used[$id]))
                ->values();
            if ($ids->isEmpty()) {
                continue;
            }

            $head = '═ ЛІНІЯ: ' . $this->scrub($g->name) . ' ═';
            $collages = array_values(array_unique(array_merge(...array_map(fn ($id) => $photoInfo[$id]['колажі_групи'] ?? [], $ids->all()))));
            if ($collages) {
                $head .= "\nКолажі лінії: [" . implode(',', $collages) . ']';
            }
            if (trim((string) $g->ai_description) !== '') {
                $head .= "\nВід магазину: " . trim((string) $this->scrub($g->ai_description));
            }

            $sections[] = $head . "\n" . $ids->map(fn ($id) => '  ' . $line($products[$id]))->implode("\n");
            foreach ($ids as $id) {
                $used[$id] = true;
            }
        }

        // Жорсткий режим «лише вітрина»: що не розкладено по лініях — того для ШІ не існує.
        $showcaseOnly = AiSetting::query()->whereNull('meta_connection_id')->value('catalog_mode') === 'showcase';
        if (!$showcaseOnly) {
            $rest = $products->keys()->reject(fn ($id) => isset($used[$id]))->values();
            if ($rest->isNotEmpty()) {
                $sections[] = "═ ІНШІ ТОВАРИ (поза лініями, без опису від магазину) ═\n"
                    . $rest->map(fn ($id) => '  ' . $line($products[$id]))->implode("\n");
            }
        }

        if (empty($sections)) {
            return '(вітрина порожня — додай групи з товарами в Галереї ШІ)';
        }

        return implode("\n\n", $sections);
    }

    /** Безпечний підсумок товару: БЕЗ собівартости, ваги і службових полів. */
    public function productSummary(Product $p, array $photoInfo = []): array
    {
        $inStockSizes = $p->variants
            ->filter(fn ($v) => $v->is_active !== false && $v->stock_qty > 0)
            ->pluck('size')
            ->implode(', ');

        $info = $photoInfo[$p->id] ?? [];

        return [
            'id' => $p->id,
            'назва' => $this->scrub($p->title),
            'sku' => $p->sku,
            'ціна' => Money::label($p->sale_price),
            'категорія' => $this->scrub($p->category?->name),
            'колір' => $p->color?->name,
            'наявність' => ($p->variants->contains(fn ($variant) => $variant->is_active !== false && $variant->stock_qty > 0)
                || ($p->variants_count === 0 && $p->stock_qty > 0)) ? 'є в наявності' : 'немає в наявності',
            'розміри_в_наявності' => $inStockSizes ?: null,
            'група' => $info['група'] ?? null,
            'фото' => $info['фото'] ?? null,
            'колаж' => $info['колаж'] ?? null,
            'колажі_групи' => $info['колажі_групи'] ?? null,
            'пояснення_розміру' => $info['пояснення_розміру'] ?? null,
        ];
    }

    /**
     * Мапа товар → його фото з галереї ШІ: «фото» (де він один),
     * «колаж» (перше спільне фото групи), «група» (назва лінійки).
     */
    public function photoInfoFor(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $photos = collect($this->reader->photos(AiPhoto::query()->pluck('id')->all()));
        $groups = AiPhotoGroup::query()->whereHas('products', fn ($q) => $q->whereIn('products.id', $productIds))
            ->with('products:products.id')->orderBy('sort_order')->orderBy('id')->get();
        $map = [];
        foreach ($groups as $group) {
            $groupPhotos = $photos->where('group_id', $group->id)->where('purpose', 'product');
            $collages = $groupPhotos->where('kind', 'collage');
            foreach ($group->products as $product) {
                if (! in_array($product->id, $productIds) || isset($map[$product->id])) continue;
                $own = $groupPhotos->where('kind', 'product')->filter(fn ($p) => in_array($product->id, $p['product_ids'], true));
                $ownCollage = $collages->first(fn ($p) => in_array($product->id, $p['product_ids'], true));
                $map[$product->id] = ['група' => $this->scrub($group->name), 'фото' => $own->pluck('id')->all() ?: null,
                    'колаж' => $ownCollage['id'] ?? ($collages->first()['id'] ?? null),
                    'колажі_групи' => $collages->pluck('id')->all() ?: null,
                    'пояснення_розміру' => $photos->where('group_id', $group->id)->where('purpose', 'size_guide')->filter(fn ($p) => in_array($product->id, $p['product_ids'], true))->pluck('id')->all() ?: null];
            }
        }
        return $map;
    }
}
