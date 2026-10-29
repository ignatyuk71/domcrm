<?php

namespace App\Services\Ai\Gpt;

use App\Models\Product;
use Illuminate\Support\Facades\Validator;

/** Читає чинні ціни й залишки; не резервує товар і не змінює каталог. */
final class CheckoutPricing
{
    public function quote(array $items): array
    {
        $rules = [
            'items' => 'required|array|min:1|max:5', 'items.*' => 'required|array:product_id,size,qty',
            'items.*.product_id' => 'required|integer|min:1', 'items.*.size' => 'present|nullable|string|max:160',
            'items.*.qty' => 'required|integer|min:1|max:100',
        ];
        if (Validator::make(['items' => $items], $rules)->fails()) {
            throw new \DomainException('Передай від 1 до 5 позицій: product_id, точний size з каталогу або null і qty від 1 до 100. Ціни й суми передавати не потрібно.');
        }
        $merged = [];
        foreach ($items as $item) {
            $key = json_encode([(int) $item['product_id'], $item['size']], JSON_THROW_ON_ERROR);
            $merged[$key] ??= ['product_id' => (int) $item['product_id'], 'size' => $item['size'], 'qty' => 0];
            $merged[$key]['qty'] += (int) $item['qty'];
        }
        $products = Product::query()->where('is_active', true)->whereIn('id', array_column($merged, 'product_id'))
            ->with(['variants:id,product_id,size,stock_qty,is_active', 'color:id,name'])
            ->get(['id', 'title', 'color_id', 'sale_price', 'currency', 'stock_qty'])->keyBy('id');
        $result = [];
        $total = 0;
        foreach ($merged as $item) {
            $product = $products->get($item['product_id']);
            if (! $product || $product->currency !== 'UAH') {
                throw new \DomainException('Товар недоступний або його ціна не в гривнях. Уточни вибір за каталогом.');
            }
            $variants = $product->variants->filter(fn ($variant) => $variant->is_active && $variant->size === $item['size']);
            if (($product->variants->isNotEmpty() && $variants->count() !== 1)
                || ($product->variants->isEmpty() && $item['size'] !== null)) {
                throw new \DomainException('Розмір товару «'.$product->title.'» недоступний або неоднозначний. Перевір точний розмір через get_product.');
            }
            $variant = $variants->first();
            $stock = $variant ? $variant->stock_qty : $product->stock_qty;
            if ($stock === null || $stock < $item['qty'] || $item['qty'] > 100) {
                throw new \DomainException('Недостатня або невідома наявність «'.$product->title.'» для обраного розміру й кількості. Узгодь доступну заміну.');
            }
            $price = Money::cents((string) $product->sale_price);
            if ($price <= 0) {
                throw new \DomainException('Ціна товару «'.$product->title.'» не визначена. Потрібне уточнення магазину.');
            }
            $line = $price * $item['qty'];
            $total += $line;
            $result[] = $item + ['variant_id' => $variant?->id, 'title' => $product->title, 'color' => $product->color?->name,
                'price' => Money::decimal($price), 'line_total' => Money::decimal($line)];
        }

        return ['items' => $result, 'total' => Money::decimal($total), 'currency' => 'UAH'];
    }

    public function inputItems(array $items): array
    {
        if (collect($items)->contains(fn ($item) => ! isset($item['product_id'], $item['qty']))) {
            throw new \DomainException('Для старої заявки потрібно заново вибрати товари за product_id і розміром. Дані доставки збережені.');
        }

        return array_map(fn ($item) => ['product_id' => $item['product_id'], 'size' => $item['size'] ?? null, 'qty' => $item['qty']], $items);
    }
}
