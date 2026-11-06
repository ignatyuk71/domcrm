<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        if ($request->expectsJson()) {
            $q = $request->get('q');
            $category = $request->get('category');
            $perPage = min((int) $request->get('per_page', 15), 200);
            $withVariants = $request->boolean('with_variants');
            $products = Product::query()
                ->with([
                    'category:id,name',
                    'color:id,name,hex_code',
                ])
                ->when($withVariants, fn ($query) => $query->with(['variants:id,product_id,size,sku,stock_qty,is_active']))
                ->when($q, function ($query) use ($q) {
                    $query->where(function ($sub) use ($q) {
                        $sub->where('title', 'like', '%' . $q . '%')
                            ->orWhere('sku', 'like', '%' . $q . '%')
                            ->orWhereHas('variants', function ($variantQuery) use ($q) {
                                $variantQuery->where('sku', 'like', '%' . $q . '%');
                            });
                    });
                })
                ->when($category, function ($query) use ($category) {
                    if (is_numeric($category)) {
                        $query->where('category_id', $category);
                    } else {
                        $query->where('category', $category);
                    }
                })
                ->orderByDesc('id')
                ->paginate($perPage);
            return response()->json($products);
        }

        return view('products.index');
    }

    public function categories(Request $request)
    {
        $categories = Category::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($categories);
    }

    public function colors(Request $request)
    {
        $colors = Color::query()
            ->orderBy('name')
            ->get(['id', 'name', 'hex_code']);

        return response()->json($colors);
    }

    public function create()
    {
        return view('products.form', ['product' => null]);
    }

    public function edit(Product $product)
    {
        $product->load(['variants:id,product_id,size,sku,stock_qty,is_active', 'category:id,name', 'color:id,name,hex_code']);
        return view('products.form', ['product' => $product]);
    }

    public function store(Request $request)
    {
        $this->normalizeVariantInput($request);
        $data = $this->validateData($request);
        $variants = $data['variants'] ?? null;
        $deletedVariantIds = $data['deleted_variant_ids'] ?? null;
        unset($data['variants'], $data['deleted_variant_ids']);
        if ($request->hasFile('main_photo')) {
            $data['main_photo_path'] = $this->storeMainPhoto($request);
        }
        $product = DB::transaction(function () use ($data, $variants, $deletedVariantIds) {
            $product = Product::create($data);
            $this->syncVariants($product, $variants, $deletedVariantIds);

            return $product;
        });
        return response()->json(['data' => $product], 201);
    }

    public function update(Product $product, Request $request)
    {
        $previousPhotoPath = $product->main_photo_path;

        $this->normalizeVariantInput($request);
        $data = $this->validateData($request, $product);
        $variants = $data['variants'] ?? null;
        $deletedVariantIds = $data['deleted_variant_ids'] ?? null;
        unset($data['variants'], $data['deleted_variant_ids']);
        if ($request->hasFile('main_photo')) {
            $data['main_photo_path'] = $this->storeMainPhoto($request);
        }
        $product = DB::transaction(function () use ($product, $data, $variants, $deletedVariantIds) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $locked->update($data);
            $this->syncVariants($locked, $variants, $deletedVariantIds);

            return $locked;
        });

        if (($data['main_photo_path'] ?? null) !== null && $data['main_photo_path'] !== $previousPhotoPath) {
            $this->deletePhotoIfUnused($previousPhotoPath);
        }

        return response()->json(['data' => $product]);
    }

    public function updateVariant(Request $request, ProductVariant $variant)
    {
        $data = $request->validate([
            'stock_qty' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($variant, $data) {
            // Спільний порядок блокувань із редагуванням повної форми: товар, потім варіант.
            $product = Product::query()->whereKey($variant->product_id)->lockForUpdate()->firstOrFail();
            $locked = $product->variants()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'stock_qty' => (int) $data['stock_qty'],
                'is_active' => (bool) $data['is_active'],
            ]);

            $this->recalculateProductStock($product);
        });

        $variant->refresh();
        $product = $variant->product()->first(['id', 'stock_qty', 'min_stock']);

        return response()->json([
            'message' => 'Наявність варіанту оновлено.',
            'data' => [
                'variant' => $variant,
                'product' => $product,
            ],
        ]);
    }

    public function updateInline(Request $request, Product $product)
    {
        $data = $request->validate([
            'sale_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'stock_qty' => ['sometimes', 'integer', 'min:0'],
        ]);

        $payload = [];

        if ($request->exists('sale_price')) {
            $payload['sale_price'] = $data['sale_price'] ?? null;
        }

        if ($request->exists('stock_qty')) {
            $payload['stock_qty'] = (int) ($data['stock_qty'] ?? 0);
        }

        if ($payload === []) {
            return response()->json([
                'message' => 'Немає даних для оновлення.',
            ], 422);
        }

        $product = DB::transaction(function () use ($product, $payload) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('stock_qty', $payload) && $locked->variants()->exists()) {
                throw ValidationException::withMessages([
                    'stock_qty' => 'Запас для товару з варіантами змінюється через список варіантів.',
                ]);
            }
            $locked->update($payload);

            return $locked;
        });

        return response()->json([
            'message' => 'Товар оновлено.',
            'data' => [
                'product' => $product->fresh([
                    'category:id,name',
                    'color:id,name,hex_code',
                    'variants:id,product_id,size,sku,stock_qty,is_active',
                ]),
            ],
        ]);
    }

    public function destroy(Product $product, Request $request)
    {
        $photoPath = $product->main_photo_path;
        $product->delete();
        $this->deletePhotoIfUnused($photoPath);

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect('/products')->with('success', 'Товар видалено');
    }

    protected function validateData(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'color_id' => ['nullable', 'integer', 'exists:colors,id'],
            'weight_g' => ['nullable', 'numeric', 'min:0'],
            'length_cm' => ['nullable', 'numeric', 'min:0'],
            'width_cm' => ['nullable', 'numeric', 'min:0'],
            'height_cm' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'sku' => ['nullable', 'string', 'max:64'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'main_photo' => ['nullable', 'image', 'max:5120'], // до 5 МБ
            'variants' => ['nullable', 'array', 'list'],
            'variants.*' => ['required', 'array'],
            'variants.*.id' => $product
                ? ['nullable', 'integer', 'distinct', Rule::exists('product_variants', 'id')->where('product_id', $product->id)]
                : ['prohibited'],
            'variants.*.size' => ['nullable', 'string', 'max:50'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.*.stock_qty' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'deleted_variant_ids' => [$product ? 'sometimes' : 'prohibited', 'array', 'list'],
            'deleted_variant_ids.*' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')->where('product_id', $product?->id ?? 0)],
        ], [
            'variants.*.id.exists' => 'Варіант уже видалено або він належить іншому товару. Оновіть сторінку.',
            'variants.*.id.distinct' => 'Один варіант не можна передавати кілька разів.',
            'variants.*.id.prohibited' => 'Нові варіанти потрібно створювати без ID.',
            'deleted_variant_ids.*.exists' => 'Варіант для видалення вже відсутній або належить іншому товару. Оновіть сторінку.',
            'deleted_variant_ids.*.distinct' => 'Варіант для видалення вказано кілька разів.',
        ]);
    }

    private function normalizeVariantInput(Request $request): void
    {
        foreach (['variants', 'deleted_variant_ids'] as $field) {
            $value = $request->input($field);
            if (!is_string($value)) {
                continue;
            }

            try {
                $decoded = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages([$field => 'Некоректний список варіантів. Оновіть сторінку та повторіть збереження.']);
            }

            // JSON-об'єкт або null не повинен перетворюватися на команду очищення списку.
            if (!is_array($decoded)) {
                throw ValidationException::withMessages([$field => 'Передайте список варіантів масивом.']);
            }
            $request->merge([$field => array_map(
                fn ($row) => $row instanceof \stdClass ? (array) $row : $row,
                $decoded
            )]);
        }
    }

    private function syncVariants(Product $product, ?array $variants, ?array $deletedVariantIds): void
    {
        $existing = $product->variants()->lockForUpdate()->get()->keyBy('id');
        $deletedIds = array_map('intval', $deletedVariantIds ?? []);
        $explicitIds = array_map('intval', array_filter(array_column($variants ?? [], 'id'), fn ($id) => $id !== null));

        // Перевіряємо повторно під блокуванням: після валідації варіант могли видалити.
        foreach (array_merge($explicitIds, $deletedIds) as $id) {
            if (!$existing->has($id)) {
                throw ValidationException::withMessages(['variants' => 'Список варіантів змінився. Оновіть сторінку та повторіть збереження.']);
            }
        }
        if (array_intersect($explicitIds, $deletedIds)) {
            throw ValidationException::withMessages(['deleted_variant_ids' => 'Варіант не можна одночасно змінювати й видаляти.']);
        }

        $claimedIds = array_fill_keys(array_merge($explicitIds, $deletedIds), true);
        $savedIds = [];
        foreach ($variants ?? [] as $index => $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;
            $variant = $id ? $existing->get($id) : null;

            if (!$variant) {
                $sku = trim((string) ($row['sku'] ?? ''));
                $size = trim((string) ($row['size'] ?? ''));
                if ($sku === '' && $size === '') {
                    continue;
                }

                // У новій формі рядок без ID завжди новий; зіставляємо лише старі запити.
                $legacyKeys = $deletedVariantIds === null ? ['sku' => $sku, 'size' => $size] : [];
                foreach ($legacyKeys as $field => $value) {
                    if ($value === '') {
                        continue;
                    }
                    $matches = $existing->except(array_keys($claimedIds))->where($field, $value);
                    if ($matches->count() > 1) {
                        throw ValidationException::withMessages(["variants.{$index}.{$field}" => 'Неможливо однозначно визначити варіант. Оновіть сторінку.']);
                    }
                    if ($matches->isNotEmpty()) {
                        $variant = $matches->first();
                        break;
                    }
                }
            }

            $attributes = [
                'size' => array_key_exists('size', $row) ? trim((string) $row['size']) : $variant?->size,
                'sku' => array_key_exists('sku', $row) ? trim((string) $row['sku']) : $variant?->sku,
                'stock_qty' => max(0, (int) ($row['stock_qty'] ?? $variant?->stock_qty ?? 0)),
                'is_active' => (bool) ($row['is_active'] ?? $variant?->is_active ?? true),
            ];
            if (trim((string) $attributes['size']) === '' && trim((string) $attributes['sku']) === '') {
                throw ValidationException::withMessages(["variants.{$index}.size" => 'Вкажіть розмір або SKU варіанта. Для видалення скористайтеся кнопкою видалення.']);
            }

            if ($variant) {
                $variant->update($attributes);
            } else {
                $variant = $product->variants()->create($attributes);
            }
            $savedIds[] = $variant->id;
            $claimedIds[$variant->id] = true;
        }

        if ($variants !== null && $deletedVariantIds === null && $existing->keys()->diff($savedIds)->isNotEmpty()) {
            throw ValidationException::withMessages(['variants' => 'Для безпечного видалення варіантів оновіть сторінку та повторіть зміни.']);
        }

        // Відсутність рядка в застарілій формі не є дозволом видаляти нові варіанти.
        if ($deletedIds !== []) {
            $product->variants()->whereKey($deletedIds)->delete();
        }
        if ($existing->isNotEmpty() || $product->variants()->exists()) {
            $this->recalculateProductStock($product);
        }
    }

    private function recalculateProductStock(Product $product): void
    {
        $stockQty = (int) $product->variants()
            ->where('is_active', true)
            ->sum('stock_qty');

        $product->update(['stock_qty' => $stockQty]);
    }

    private function storeMainPhoto(Request $request): string
    {
        $file = $request->file('main_photo');
        $dir = public_path('storage/products');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Оригінали з камери важать мегабайти — одразу тиснемо в JPEG ~1200px.
        $name = Str::random(40);
        if (ImageOptimizer::toJpeg($file->getRealPath(), $dir . '/' . $name . '.jpg')) {
            return 'products/' . $name . '.jpg';
        }

        // GD не впорався (екзотичний формат) — зберігаємо як є.
        $extension = $file->getClientOriginalExtension();
        $filename = $name . ($extension ? '.' . $extension : '');
        $file->move($dir, $filename);

        return 'products/' . $filename;
    }

    /** Видаляємо файл лише коли жоден товар більше на нього не посилається. */
    private function deletePhotoIfUnused(?string $photoPath): void
    {
        if (!$photoPath || Product::query()->where('main_photo_path', $photoPath)->exists()) {
            return;
        }

        $clean = ltrim($photoPath, '/');
        $fullPath = str_starts_with($clean, 'storage/')
            ? public_path($clean)
            : public_path('storage/' . $clean);

        if (File::isFile($fullPath)) {
            File::delete($fullPath);
        }
    }
}
