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
     *   product_id:int,
     *   product_title:?string,
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
     * @return array{
     *   product_id:int,
     *   variant_id:?int,
     *   color_id:?int,
     *   size_hint:?string,
     *   model_phrase:string
     * }|null
     */
    public function resolveMappedProduct(string $inputText): ?array
    {
        $text = mb_strtolower(trim($inputText));
        if ($text === '') {
            return null;
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
                $best = [
                    'product_id' => (int) $map['product_id'],
                    'variant_id' => isset($map['variant_id']) ? (int) $map['variant_id'] : null,
                    'color_id' => isset($map['color_id']) ? (int) $map['color_id'] : null,
                    'size_hint' => isset($map['size_hint']) ? (string) $map['size_hint'] : null,
                    'model_phrase' => (string) $map['model_phrase'],
                ];
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
                'product:id,title',
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
                'product_id',
                'variant_id',
                'color_id',
                'size_hint',
                'priority',
            ])
            ->map(fn (ChatAiProductModelMap $map) => [
                'id' => (int) $map->id,
                'model_phrase' => (string) $map->model_phrase,
                'product_id' => (int) $map->product_id,
                'product_title' => $map->product?->title,
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
}
