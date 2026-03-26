<?php

namespace App\Services;

use App\Models\ChatAiKnowledgeItem;
use App\Models\ChatAiProductModelMap;

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
    public function resolveMappedProduct(string $inputText): ?array
    {
        $text = mb_strtolower(trim($inputText));
        if ($text === '') {
            return null;
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
}
