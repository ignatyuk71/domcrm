<?php

namespace App\Services\Costs;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductionCostModels
{
    public function profile(int $modelId): string
    {
        return DB::table('production_cost_models')->where('id', $modelId)->first()?->cost_profile ?? 'sewn';
    }

    public function components(string $profile): array
    {
        return $profile === 'outdoor' ? ['soles', 'fur'] : ['soles', 'cardboard', 'foam', 'fur', 'laminate', 'tape'];
    }

    public function resolve(?int $modelId = null): int
    {
        $id = $modelId ?? DB::table('production_cost_models')->where('legacy_key', 'halluci-fur')->value('id');
        abort_unless($id && DB::table('production_cost_models')->where('id', $id)->exists(), 404, 'Категорію розрахунку не знайдено.');

        return (int) $id;
    }

    public function catalog(): array
    {
        $models = DB::table('production_cost_models')->orderBy('id')->get();
        $categories = Category::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $totals = DB::table('production_cost_batches')->selectRaw('model_id, COUNT(*) AS records_count, COUNT(DISTINCT component) AS materials_count')->groupBy('model_id')->get()->keyBy('model_id');
        // Одне фото на категорію без N+1 і без завантаження всього каталогу товарів.
        $photoIds = DB::table('products')->selectRaw('MIN(id)')->whereNotNull('category_id')->whereNotNull('main_photo_path')->where('main_photo_path', '!=', '')->groupBy('category_id');
        $photos = Product::query()->whereIn('id', $photoIds)->get(['id', 'category_id', 'main_photo_path'])->keyBy('category_id');
        $cards = [];
        foreach ($models as $model) {
            $category = $categories->firstWhere('id', $model->category_id);
            $cards[] = ['id' => (int) $model->id, 'category_id' => $model->category_id, 'name' => $category?->name ?? $model->name,
                'photo_url' => $photos->get($model->category_id)?->main_photo_url, 'is_default' => $model->legacy_key === 'halluci-fur', 'cost_profile' => $model->cost_profile ?? 'sewn',
                'records_count' => (int) ($totals->get($model->id)?->records_count ?? 0), 'materials_count' => (int) ($totals->get($model->id)?->materials_count ?? 0)];
        }
        $available = [];
        foreach ($categories as $category) {
            if ($models->contains('category_id', $category->id)) {
                continue;
            }
            $name = mb_strtolower($category->name);
            $available[] = ['id' => (int) $category->id, 'name' => $category->name, 'photo_url' => $photos->get($category->id)?->main_photo_url,
                'footwear' => str_contains($name, 'капц') || str_contains($name, 'тапоч') || str_contains($name, 'чун')];
        }

        return ['models' => $cards, 'categories' => $available];
    }

    public function openCategory(int $categoryId): array
    {
        $id = DB::transaction(function () use ($categoryId) {
            // Блокуємо категорію: повторні кліки не створюють двох незалежних розрахунків.
            $category = Category::query()->lockForUpdate()->findOrFail($categoryId);
            $existing = DB::table('production_cost_models')->where('category_id', $categoryId)->first();
            if ($existing) {
                return $existing->id;
            }
            $legacy = DB::table('production_cost_models')->where('legacy_key', 'halluci-fur')->whereNull('category_id')->lockForUpdate()->first();
            if ($legacy && $legacy->name === $category->name) {
                DB::table('production_cost_models')->where('id', $legacy->id)->update(['category_id' => $categoryId, 'updated_at' => now()]);

                return $legacy->id;
            }

            return DB::table('production_cost_models')->insertGetId(['category_id' => $categoryId, 'name' => $category->name,
                'cost_profile' => $category->name === 'Капці для вулиці (хутряні)' ? 'outdoor' : 'sewn', 'created_at' => now(), 'updated_at' => now()]);
        });
        $model = DB::table('production_cost_models')->where('id', $id)->first();

        return ['id' => (int) $id, 'category_id' => $categoryId, 'name' => Category::find($categoryId)?->name ?? $model->name, 'cost_profile' => $model->cost_profile ?? 'sewn'];
    }
}
