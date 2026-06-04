<?php

namespace App\Http\Controllers;

use App\Models\ExternalProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Екран ручного зіставлення позицій, які не розпізналися автоматично (needs_review).
 * Доступ: власник + оператор.
 */
class IntegrationMappingController extends Controller
{
    public function review()
    {
        $items = OrderItem::query()
            ->whereNull('product_id')
            ->whereHas('order', fn ($q) => $q->where('needs_review', true))
            ->with(['order:id,order_number,source_id,created_at', 'order.source:id,name'])
            ->orderByDesc('order_id')
            ->paginate(50);

        return view('integrations.review', compact('items'));
    }

    public function map(Request $request)
    {
        $data = $request->validate([
            'order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $item = OrderItem::with('order')->findOrFail($data['order_item_id']);
        $order = $item->order;
        $variantId = $data['product_variant_id'] ?? null;
        $remember = (bool) ($data['remember'] ?? true);

        // Варіант (якщо вказано) має належати обраному товару.
        if ($variantId) {
            $belongs = ProductVariant::where('id', $variantId)
                ->where('product_id', $data['product_id'])
                ->exists();
            if (!$belongs) {
                return back()->with('error', 'Обраний розмір не належить обраному товару.');
            }
        }

        DB::transaction(function () use ($item, $order, $data, $variantId, $remember) {
            $externalId = (string) ($item->external_id ?? '');
            $size = (string) ($item->size ?? '');

            // 1) Сама позиція замовлення.
            $item->update([
                'product_id' => $data['product_id'],
                'product_variant_id' => $variantId,
            ]);

            // 2) Пам'ять — щоб надалі цей товар мапився автоматично.
            if ($remember && $externalId !== '' && $order && $order->source_id) {
                ExternalProduct::updateOrCreate(
                    [
                        'source_id' => $order->source_id,
                        'external_id' => $externalId,
                        'external_size' => $size,
                    ],
                    [
                        'external_sku' => $item->sku,
                        'external_name' => $item->product_title,
                        'product_id' => $data['product_id'],
                        'product_variant_id' => $variantId,
                    ]
                );
            }

            // 3) Застосувати до інших незмаплених позицій того ж товару й джерела.
            $affectedOrderIds = [$order?->id];
            if ($externalId !== '' && $order && $order->source_id) {
                $siblings = OrderItem::query()
                    ->whereNull('product_id')
                    ->where('external_id', $externalId)
                    ->where(function ($q) use ($size) {
                        $q->where('size', $size);
                        if ($size === '') {
                            $q->orWhereNull('size');
                        }
                    })
                    ->whereHas('order', fn ($q) => $q->where('source_id', $order->source_id))
                    ->get();

                foreach ($siblings as $sibling) {
                    $sibling->update([
                        'product_id' => $data['product_id'],
                        'product_variant_id' => $variantId,
                    ]);
                    $affectedOrderIds[] = $sibling->order_id;
                }
            }

            // 4) Перерахувати needs_review для зачеплених замовлень.
            foreach (array_unique(array_filter($affectedOrderIds)) as $orderId) {
                $affected = Order::find($orderId);
                if ($affected) {
                    $affected->needs_review = $affected->items()->whereNull('product_id')->exists();
                    $affected->save();
                }
            }
        });

        return back()->with('success', 'Товар зіставлено. Наступні замовлення з цим товаром підуть автоматично.');
    }
}
