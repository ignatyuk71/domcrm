<?php

namespace App\Services\Ai;

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
    public function __construct(private TextScrubber $scrubber)
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

            $parts = array_filter([
                '#' . $p->id,
                $this->scrub($p->title),
                $this->scrub($p->category?->name),
                $p->color?->name,
                'SKU ' . $p->sku,
                round((float) $p->sale_price) . ' грн',
                $sizes !== '' ? "розміри: {$sizes}" : 'НЕМАЄ в наявності',
                !empty($i['фото']) ? 'фото:[' . implode(',', $i['фото']) . ']' : null,
                !empty($i['колаж']) ? 'колаж:' . $i['колаж'] : null,
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
            $collages = $g->photos->filter(fn (AiPhoto $p) => $p->products->count() >= 2)->pluck('id')->all();
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
        $showcaseOnly = AiSetting::global()->catalog_mode === 'showcase';
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
            'ціна' => round((float) $p->sale_price) . ' грн',
            'категорія' => $this->scrub($p->category?->name),
            'колір' => $p->color?->name,
            'наявність' => $inStockSizes !== '' ? 'є в наявності' : 'немає в наявності',
            'розміри_в_наявності' => $inStockSizes ?: null,
            'група' => $info['група'] ?? null,
            'фото' => $info['фото'] ?? null,
            'колаж' => $info['колаж'] ?? null,
            'колажі_групи' => $info['колажі_групи'] ?? null,
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

        $groups = AiPhotoGroup::query()
            ->whereHas('products', fn ($q) => $q->whereIn('products.id', $productIds))
            ->with(['products:products.id', 'photos.products:products.id'])
            ->get();

        $map = [];
        foreach ($groups as $group) {
            $collages = $group->photos->filter(fn (AiPhoto $p) => $p->products->count() >= 2)->values();
            $collageIds = $collages->pluck('id')->all();

            foreach ($group->products as $product) {
                if (!in_array($product->id, $productIds) || isset($map[$product->id])) {
                    continue;
                }

                // ВСІ фото цього кольору (різні ракурси), по порядку.
                $own = $group->photos
                    ->filter(fn (AiPhoto $p) => $p->products->count() === 1 && (int) $p->products->first()->id === (int) $product->id)
                    ->pluck('id')
                    ->values()
                    ->all();

                // Колаж саме з цим товаром; як нема — перший колаж групи.
                $ownCollage = $collages->first(fn (AiPhoto $p) => $p->products->contains('id', $product->id));

                $map[$product->id] = [
                    'група' => $this->scrub($group->name),
                    'фото' => $own ?: null,
                    'колаж' => $ownCollage?->id ?? ($collages->first()?->id),
                    'колажі_групи' => $collageIds ?: null,
                ];
            }
        }

        return $map;
    }
}
