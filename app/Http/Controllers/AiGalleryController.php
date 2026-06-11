<?php

namespace App\Http\Controllers;

use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Галерея ШІ: групи (модельні лінійки), їх товари та фото з відмітками.
 * Наповнює власник; агент бачить лише готові номери фото в результатах пошуку.
 */
class AiGalleryController extends Controller
{
    public function page()
    {
        return view('settings.ai-gallery');
    }

    /** Всі групи з товарами і фото для сторінки. */
    public function data()
    {
        $groups = AiPhotoGroup::with(['products.color:id,name', 'photos.products:products.id'])
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(function (AiPhotoGroup $g) {
                $markedIds = $g->photos->flatMap(fn ($p) => $p->products->pluck('id'))->unique();

                return [
                    'id' => $g->id,
                    'name' => $g->name,
                    'products' => $g->products->map(fn (Product $p) => [
                        'id' => $p->id,
                        'label' => $p->title . ' (' . $p->sku . ')',
                        'short' => $p->color?->name ?: $p->title,
                        'has_photo' => $markedIds->contains($p->id),
                    ])->values(),
                    'photos' => $g->photos->map(fn (AiPhoto $p) => [
                        'id' => $p->id,
                        'url' => $p->url(),
                        'product_ids' => $p->products->pluck('id')->values(),
                    ])->values(),
                ];
            });

        return response()->json($groups);
    }

    /** Пошук товару для додавання в групу. */
    public function productSearch(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $items = Product::with('color:id,name')
            ->where(function ($w) use ($q) {
                $w->where('title', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "%{$q}%")
                    ->orWhereHas('color', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            })
            ->orderBy('title')
            ->limit(10)
            ->get()
            ->map(fn (Product $p) => ['id' => $p->id, 'label' => $p->title . ' (' . $p->sku . ')']);

        return response()->json($items);
    }

    public function storeGroup(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $group = AiPhotoGroup::create([
            'name' => $data['name'],
            'sort_order' => (int) AiPhotoGroup::max('sort_order') + 1,
        ]);

        return response()->json(['ok' => true, 'id' => $group->id]);
    }

    public function updateGroup(Request $request, AiPhotoGroup $group)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $group->update(['name' => $data['name']]);

        return response()->json(['ok' => true]);
    }

    public function destroyGroup(AiPhotoGroup $group)
    {
        foreach ($group->photos as $photo) {
            $this->deleteFile($photo->path);
        }
        $group->delete();

        return response()->json(['ok' => true]);
    }

    public function attachProduct(Request $request, AiPhotoGroup $group)
    {
        $data = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id']]);
        $group->products()->syncWithoutDetaching([$data['product_id']]);

        return response()->json(['ok' => true]);
    }

    public function detachProduct(AiPhotoGroup $group, Product $product)
    {
        $group->products()->detach($product->id);
        // Прибираємо і відмітки цього товару на фото групи — щоб не лишались «привиди».
        foreach ($group->photos as $photo) {
            $photo->products()->detach($product->id);
        }

        return response()->json(['ok' => true]);
    }

    /** Завантажити фото (можна кілька одразу). */
    public function uploadPhotos(Request $request, AiPhotoGroup $group)
    {
        $request->validate(['files' => ['required', 'array'], 'files.*' => ['required', 'image', 'max:10240']]);

        $dir = public_path('ai-gallery');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $sort = (int) $group->photos()->max('sort_order');
        foreach ($request->file('files') as $file) {
            $ext = $file->getClientOriginalExtension() ?: 'jpg';
            $name = Str::random(24) . '.' . $ext;
            $file->move($dir, $name);
            AiPhoto::create([
                'ai_photo_group_id' => $group->id,
                'path' => 'ai-gallery/' . $name,
                'sort_order' => ++$sort,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /** Оновити фото: відмітки товарів і/або порядок (up|down). */
    public function updatePhoto(Request $request, AiPhoto $photo)
    {
        $data = $request->validate([
            'product_ids' => ['sometimes', 'array'],
            'product_ids.*' => ['integer'],
            'move' => ['sometimes', 'in:up,down'],
        ]);

        if (array_key_exists('product_ids', $data)) {
            // Дозволяємо відмічати лише товари цієї групи.
            $allowed = $photo->group->products()->pluck('products.id')->all();
            $photo->products()->sync(array_values(array_intersect(array_map('intval', $data['product_ids']), $allowed)));
        }

        if (!empty($data['move'])) {
            $neighbor = $photo->group->photos()
                ->where('id', '!=', $photo->id)
                ->where('sort_order', $data['move'] === 'up' ? '<' : '>', $photo->sort_order)
                ->orderBy('sort_order', $data['move'] === 'up' ? 'desc' : 'asc')
                ->first();
            if ($neighbor) {
                [$a, $b] = [$photo->sort_order, $neighbor->sort_order];
                $photo->update(['sort_order' => $b]);
                $neighbor->update(['sort_order' => $a]);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function destroyPhoto(AiPhoto $photo)
    {
        $this->deleteFile($photo->path);
        $photo->delete();

        return response()->json(['ok' => true]);
    }

    private function deleteFile(string $path): void
    {
        $full = public_path($path);
        if (is_file($full)) {
            @unlink($full);
        }
    }
}
