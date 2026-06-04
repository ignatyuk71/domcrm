<?php

namespace App\Services\Integration;

use App\Models\ExternalProduct;
use App\Models\OrderSource;
use App\Models\Product;
use App\Models\ProductVariant;

class ProductMatcher
{
    /**
     * Зіставляє позицію з зовнішнього сайту з товаром/варіантом CRM.
     * Каскад: 1) пам'ять (external_products) → 2) SKU варіанту/товару → 3) нічого (needs_review).
     *
     * @param  array  $item  Канонічна позиція: external_id, sku, name, size, qty, price
     * @return array{product_id: int|null, product_variant_id: int|null, matched: bool, via: string}
     */
    public function match(OrderSource $source, array $item): array
    {
        $externalId = isset($item['external_id']) ? trim((string) $item['external_id']) : '';
        $size = isset($item['size']) ? trim((string) $item['size']) : '';
        $sku = isset($item['sku']) ? trim((string) $item['sku']) : '';

        // 1) ПАМ'ЯТЬ — ручні правки/раніше збережені відповідності мають пріоритет.
        if ($externalId !== '') {
            $map = ExternalProduct::query()
                ->where('source_id', $source->id)
                ->where('external_id', $externalId)
                ->where('external_size', $size)
                ->first();

            if ($map && ($map->product_id || $map->product_variant_id)) {
                return $this->result($map->product_id, $map->product_variant_id, true, 'memory');
            }
        }

        // 2) SKU — основний авто-ключ. Спершу варіантний SKU (точний розмір), потім товарний.
        if ($sku !== '') {
            $variant = ProductVariant::query()->where('sku', $sku)->first();
            if ($variant) {
                $this->remember($source, $externalId, $size, $sku, $item['name'] ?? null, $variant->product_id, $variant->id);

                return $this->result($variant->product_id, $variant->id, true, 'sku_variant');
            }

            $product = Product::query()->where('sku', $sku)->first();
            if ($product) {
                // Якщо є розмір — спробуємо довизначити варіант усередині товару.
                $variantId = null;
                if ($size !== '') {
                    $variantId = ProductVariant::query()
                        ->where('product_id', $product->id)
                        ->where('size', $size)
                        ->value('id');
                }

                $this->remember($source, $externalId, $size, $sku, $item['name'] ?? null, $product->id, $variantId);

                return $this->result($product->id, $variantId, true, $variantId ? 'sku_product_size' : 'sku_product');
            }
        }

        // 3) Нічого не знайшли — позиція піде у needs_review.
        return $this->result(null, null, false, 'none');
    }

    /**
     * Самонаповнення пам'яті: зберігаємо знайдену по SKU відповідність,
     * щоб оператор її бачив і майбутні зіставлення були миттєвими.
     */
    protected function remember(OrderSource $source, string $externalId, string $size, ?string $sku, ?string $name, ?int $productId, ?int $variantId): void
    {
        if ($externalId === '') {
            return; // без зовнішнього id немає стабільного ключа пам'яті
        }

        ExternalProduct::updateOrCreate(
            [
                'source_id' => $source->id,
                'external_id' => $externalId,
                'external_size' => $size,
            ],
            [
                'external_sku' => $sku,
                'external_name' => $name,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
            ]
        );
    }

    /**
     * @return array{product_id: int|null, product_variant_id: int|null, matched: bool, via: string}
     */
    protected function result(?int $productId, ?int $variantId, bool $matched, string $via): array
    {
        return [
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'matched' => $matched,
            'via' => $via,
        ];
    }
}
