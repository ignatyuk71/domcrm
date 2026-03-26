<?php

namespace App\Services;

use App\Models\ChatAiKnowledgeItem;
use App\Models\ChatAiProductModelMap;
use App\Models\ProductMedia;
use App\Models\ProductVariant;

class ChatAiKnowledgeService
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $activeKnowledgeCache = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $activeModelMapCache = null;

    public function buildKnowledgePromptBlock(): string
    {
        $items = $this->activeKnowledgeItems();
        if ($items === []) {
            return '';
        }

        $lines = [];
        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $content = trim((string) ($item['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            if ($title !== '') {
                $lines[] = "- {$title}: {$content}";
            } else {
                $lines[] = "- {$content}";
            }
        }

        if ($lines === []) {
            return '';
        }

        return "ДОДАТКОВА БАЗА ЗНАНЬ CRM:\n" . implode("\n", $lines);
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   model_phrase:string,
     *   item_code:?string,
     *   collage_url:?string,
     *   product_id:int,
     *   product_title:?string,
     *   product_sale_price:?float,
     *   variant_id:?int,
     *   variant_size:?string,
     *   color_id:?int,
     *   color_name:?string,
     *   size_hint:?string,
     *   priority:int
     * }>
     */
    public function productMapContext(int $limit = 30): array
    {
        $maps = $this->activeModelMaps();
        if ($limit > 0 && count($maps) > $limit) {
            return array_slice($maps, 0, $limit);
        }

        return $maps;
    }

    /**
     * @return array<int, array{
     *   model_phrase:string,
     *   collage_urls:array<int, string>,
     *   price_min:?float,
     *   price_max:?float,
     *   items:array<int, array{
     *     item_code:?string,
     *     product_id:int,
     *     product_title:?string,
     *     price:?float,
     *     color_name:?string,
     *     available_sizes:array<int, string>,
     *     has_media:bool
     *   }>
     * }>
     */
    public function productCatalogContext(int $modelLimit = 20, int $itemsPerModel = 12): array
    {
        $maps = $this->activeModelMaps();
        if ($maps === []) {
            return [];
        }

        $productIds = array_values(array_unique(array_filter(array_map(
            fn (array $map) => isset($map['product_id']) ? (int) $map['product_id'] : null,
            $maps
        ))));

        $sizesByProduct = ProductVariant::query()
            ->whereIn('product_id', $productIds)
            ->where('is_active', true)
            ->where('stock_qty', '>', 0)
            ->orderBy('id')
            ->get(['product_id', 'size'])
            ->groupBy('product_id')
            ->map(function ($variants): array {
                return collect($variants)
                    ->map(fn (ProductVariant $variant) => trim((string) $variant->size))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            })
            ->all();

        $mediaProductIds = ProductMedia::query()
            ->whereIn('product_id', $productIds)
            ->where('is_active', true)
            ->where('media_type', 'image')
            ->distinct()
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $mediaProductLookup = array_fill_keys($mediaProductIds, true);

        $grouped = [];
        foreach ($maps as $map) {
            $phrase = trim((string) ($map['model_phrase'] ?? ''));
            if ($phrase === '') {
                continue;
            }

            $key = mb_strtolower($phrase);
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'model_phrase' => $phrase,
                    'collage_urls' => [],
                    'price_min' => null,
                    'price_max' => null,
                    'items' => [],
                ];
            }

            $collageUrl = trim((string) ($map['collage_url'] ?? ''));
            if ($collageUrl !== '' && !in_array($collageUrl, $grouped[$key]['collage_urls'], true)) {
                $grouped[$key]['collage_urls'][] = $collageUrl;
            }

            $price = isset($map['product_sale_price']) ? (float) $map['product_sale_price'] : null;
            if ($price !== null) {
                $grouped[$key]['price_min'] = $grouped[$key]['price_min'] === null
                    ? $price
                    : min((float) $grouped[$key]['price_min'], $price);
                $grouped[$key]['price_max'] = $grouped[$key]['price_max'] === null
                    ? $price
                    : max((float) $grouped[$key]['price_max'], $price);
            }

            $productId = (int) ($map['product_id'] ?? 0);
            $itemKey = implode('|', [
                (string) ($map['item_code'] ?? ''),
                (string) $productId,
                (string) ($map['color_id'] ?? ''),
            ]);

            if (!isset($grouped[$key]['items'][$itemKey])) {
                $grouped[$key]['items'][$itemKey] = [
                    'item_code' => $map['item_code'] ?? null,
                    'product_id' => $productId,
                    'product_title' => $map['product_title'] ?? null,
                    'price' => $price,
                    'color_name' => $map['color_name'] ?? null,
                    'available_sizes' => $sizesByProduct[$productId] ?? [],
                    'has_media' => isset($mediaProductLookup[$productId]),
                ];
            }
        }

        $catalog = array_map(function (array $group) use ($itemsPerModel): array {
            $items = array_values($group['items']);
            usort($items, function (array $left, array $right): int {
                $leftCode = trim((string) ($left['item_code'] ?? ''));
                $rightCode = trim((string) ($right['item_code'] ?? ''));

                if ($leftCode !== '' && $rightCode !== '' && ctype_digit($leftCode) && ctype_digit($rightCode)) {
                    return (int) $leftCode <=> (int) $rightCode;
                }

                return $leftCode <=> $rightCode;
            });

            if ($itemsPerModel > 0 && count($items) > $itemsPerModel) {
                $items = array_slice($items, 0, $itemsPerModel);
            }

            return [
                'model_phrase' => $group['model_phrase'],
                'collage_urls' => $group['collage_urls'],
                'price_min' => $group['price_min'],
                'price_max' => $group['price_max'],
                'items' => $items,
            ];
        }, array_values($grouped));

        usort($catalog, fn (array $left, array $right): int => mb_strlen($right['model_phrase']) <=> mb_strlen($left['model_phrase']));

        if ($modelLimit > 0 && count($catalog) > $modelLimit) {
            $catalog = array_slice($catalog, 0, $modelLimit);
        }

        return array_values($catalog);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveModelMapForProduct(?int $productId, ?int $colorId = null): ?array
    {
        if (!$productId) {
            return null;
        }

        $matches = array_values(array_filter($this->activeModelMaps(), function (array $map) use ($productId, $colorId): bool {
            if ((int) ($map['product_id'] ?? 0) !== $productId) {
                return false;
            }

            if ($colorId === null) {
                return true;
            }

            $mapColorId = isset($map['color_id']) ? (int) $map['color_id'] : null;

            return $mapColorId === null || $mapColorId === $colorId;
        }));

        if ($matches === []) {
            return null;
        }

        usort($matches, function (array $left, array $right): int {
            $leftPriority = (int) ($left['priority'] ?? 100);
            $rightPriority = (int) ($right['priority'] ?? 100);

            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });

        return $matches[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveModelMapByPhrase(?string $modelPhrase): ?array
    {
        $phrase = mb_strtolower(trim((string) $modelPhrase));
        if ($phrase === '') {
            return null;
        }

        $matches = array_values(array_filter($this->activeModelMaps(), function (array $map) use ($phrase): bool {
            $candidate = mb_strtolower(trim((string) ($map['model_phrase'] ?? '')));
            if ($candidate === '') {
                return false;
            }

            return $candidate === $phrase
                || mb_stripos($candidate, $phrase) !== false
                || mb_stripos($phrase, $candidate) !== false;
        }));

        if ($matches === []) {
            return null;
        }

        usort($matches, function (array $left, array $right): int {
            $leftPriority = (int) ($left['priority'] ?? 100);
            $rightPriority = (int) ($right['priority'] ?? 100);

            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            $leftLength = mb_strlen((string) ($left['model_phrase'] ?? ''));
            $rightLength = mb_strlen((string) ($right['model_phrase'] ?? ''));

            if ($leftLength !== $rightLength) {
                return $rightLength <=> $leftLength;
            }

            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });

        return $matches[0] ?? null;
    }

    /**
     * @return array{
     *   product_id:int,
     *   variant_id:?int,
     *   color_id:?int,
     *   size_hint:?string,
     *   model_phrase:string,
     *   item_code:?string,
     *   collage_url:?string
     * }|null
     */
    public function resolveMappedProduct(string $inputText, ?string $modelPhrase = null): ?array
    {
        $text = mb_strtolower(trim($inputText));
        if ($text === '') {
            return null;
        }

        $scopedModelPhrase = mb_strtolower(trim((string) $modelPhrase));
        if ($scopedModelPhrase === '') {
            $scopedModelPhrase = null;
        }

        $codeMatches = [];
        foreach ($this->activeModelMaps() as $map) {
            $itemCode = trim((string) ($map['item_code'] ?? ''));
            if ($itemCode === '') {
                continue;
            }

            if ($this->textHasToken($text, mb_strtolower($itemCode))) {
                $codeMatches[] = $map;
            }
        }

        if ($scopedModelPhrase !== null && $codeMatches !== []) {
            $scopedMatches = array_values(array_filter($codeMatches, function (array $map) use ($scopedModelPhrase): bool {
                return $this->modelPhraseMatchesScope((string) ($map['model_phrase'] ?? ''), $scopedModelPhrase);
            }));

            if (count($scopedMatches) === 1) {
                return $this->normalizeResolvedMap($scopedMatches[0]);
            }

            if (count($scopedMatches) > 1) {
                usort($scopedMatches, fn (array $left, array $right): int => ((int) ($left['priority'] ?? 100)) <=> ((int) ($right['priority'] ?? 100)));
                return $this->normalizeResolvedMap($scopedMatches[0]);
            }
        }

        if (count($codeMatches) === 1) {
            return $this->normalizeResolvedMap($codeMatches[0]);
        }

        if (count($codeMatches) > 1) {
            $scopedByPhrase = array_values(array_filter($codeMatches, function (array $map) use ($text) {
                $phrase = mb_strtolower(trim((string) ($map['model_phrase'] ?? '')));
                return $phrase !== '' && mb_stripos($text, $phrase) !== false;
            }));

            if (count($scopedByPhrase) === 1) {
                return $this->normalizeResolvedMap($scopedByPhrase[0]);
            }
        }

        $best = null;
        $bestLength = -1;
        $bestPriority = PHP_INT_MAX;

        foreach ($this->activeModelMaps() as $map) {
            $phrase = mb_strtolower(trim((string) ($map['model_phrase'] ?? '')));
            if ($phrase === '') {
                continue;
            }

            if ($scopedModelPhrase !== null && !$this->modelPhraseMatchesScope($phrase, $scopedModelPhrase)) {
                continue;
            }

            if (mb_stripos($text, $phrase) === false) {
                continue;
            }

            $phraseLength = mb_strlen($phrase);
            $priority = (int) ($map['priority'] ?? 100);

            if ($phraseLength > $bestLength || ($phraseLength === $bestLength && $priority < $bestPriority)) {
                $best = $this->normalizeResolvedMap($map);
                $bestLength = $phraseLength;
                $bestPriority = $priority;
            }
        }

        return $best;
    }

    /**
     * @return array{
     *   product_id:int,
     *   variant_id:?int,
     *   color_id:?int,
     *   size_hint:?string,
     *   model_phrase:string,
     *   item_code:?string,
     *   collage_url:?string
     * }|null
     */
    public function resolveProductForModelColor(?string $modelPhrase, ?int $colorId = null, ?string $colorName = null): ?array
    {
        $phrase = mb_strtolower(trim((string) $modelPhrase));
        if ($phrase === '') {
            return null;
        }

        $normalizedColorName = mb_strtolower(trim((string) $colorName));
        if ($normalizedColorName === '') {
            $normalizedColorName = null;
        }

        $matches = array_values(array_filter($this->activeModelMaps(), function (array $map) use ($phrase, $colorId, $normalizedColorName): bool {
            $candidatePhrase = mb_strtolower(trim((string) ($map['model_phrase'] ?? '')));
            if (!$this->modelPhraseMatchesScope($candidatePhrase, $phrase)) {
                return false;
            }

            $mapColorId = isset($map['color_id']) ? (int) $map['color_id'] : null;
            $mapColorName = mb_strtolower(trim((string) ($map['color_name'] ?? '')));

            if ($colorId !== null) {
                return $mapColorId === $colorId;
            }

            if ($normalizedColorName !== null) {
                return $mapColorName !== '' && (
                    $mapColorName === $normalizedColorName
                    || mb_stripos($mapColorName, $normalizedColorName) !== false
                    || mb_stripos($normalizedColorName, $mapColorName) !== false
                );
            }

            return false;
        }));

        if ($matches === []) {
            return null;
        }

        usort($matches, fn (array $left, array $right): int => ((int) ($left['priority'] ?? 100)) <=> ((int) ($right['priority'] ?? 100)));

        return $this->normalizeResolvedMap($matches[0]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeKnowledgeItems(): array
    {
        if ($this->activeKnowledgeCache !== null) {
            return $this->activeKnowledgeCache;
        }

        $this->activeKnowledgeCache = ChatAiKnowledgeItem::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'title', 'content'])
            ->map(fn (ChatAiKnowledgeItem $item) => [
                'id' => (int) $item->id,
                'title' => (string) $item->title,
                'content' => (string) $item->content,
            ])
            ->values()
            ->all();

        return $this->activeKnowledgeCache;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeModelMaps(): array
    {
        if ($this->activeModelMapCache !== null) {
            return $this->activeModelMapCache;
        }

        $this->activeModelMapCache = ChatAiProductModelMap::query()
            ->with([
                'product:id,title,sale_price,color_id',
                'variant:id,size',
                'color:id,name',
            ])
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderByRaw('LENGTH(model_phrase) DESC')
            ->orderBy('id')
            ->get([
                'id',
                'model_phrase',
                'item_code',
                'collage_url',
                'product_id',
                'variant_id',
                'color_id',
                'size_hint',
                'priority',
            ])
            ->map(fn (ChatAiProductModelMap $map) => [
                'id' => (int) $map->id,
                'model_phrase' => (string) $map->model_phrase,
                'item_code' => $map->item_code ? (string) $map->item_code : null,
                'collage_url' => $map->collage_url ? (string) $map->collage_url : null,
                'product_id' => (int) $map->product_id,
                'product_title' => $map->product?->title,
                'product_sale_price' => $map->product?->sale_price !== null ? (float) $map->product->sale_price : null,
                'variant_id' => $map->variant_id ? (int) $map->variant_id : null,
                'variant_size' => $map->variant?->size,
                'color_id' => $map->color_id ? (int) $map->color_id : null,
                'color_name' => $map->color?->name,
                'size_hint' => $map->size_hint,
                'priority' => (int) $map->priority,
            ])
            ->values()
            ->all();

        return $this->activeModelMapCache;
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array{
     *   product_id:int,
     *   variant_id:?int,
     *   color_id:?int,
     *   size_hint:?string,
     *   model_phrase:string,
     *   item_code:?string,
     *   collage_url:?string
     * }
     */
    private function normalizeResolvedMap(array $map): array
    {
        return [
            'product_id' => (int) ($map['product_id'] ?? 0),
            'variant_id' => isset($map['variant_id']) ? (int) $map['variant_id'] : null,
            'color_id' => isset($map['color_id']) ? (int) $map['color_id'] : null,
            'size_hint' => isset($map['size_hint']) ? (string) $map['size_hint'] : null,
            'model_phrase' => (string) ($map['model_phrase'] ?? ''),
            'item_code' => isset($map['item_code']) ? (string) $map['item_code'] : null,
            'collage_url' => isset($map['collage_url']) ? (string) $map['collage_url'] : null,
        ];
    }

    private function textHasToken(string $text, string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($token, '/') . '(?![\p{L}\p{N}])/u';
        return (bool) preg_match($pattern, $text);
    }

    private function modelPhraseMatchesScope(string $candidatePhrase, string $scopedModelPhrase): bool
    {
        $candidate = mb_strtolower(trim($candidatePhrase));
        if ($candidate === '' || $scopedModelPhrase === '') {
            return false;
        }

        return $candidate === $scopedModelPhrase
            || mb_stripos($candidate, $scopedModelPhrase) !== false
            || mb_stripos($scopedModelPhrase, $candidate) !== false;
    }
}
