<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

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
        if ($request->has('variants')) {
            $request->merge(['variants' => $this->normalizeVariants($request->input('variants'))]);
        }
        $data = $this->validateData($request);
        $variants = $data['variants'] ?? null;
        unset($data['variants']);
        if ($request->hasFile('main_photo')) {
            $data['main_photo_path'] = $this->storeMainPhoto($request);
        }
        $product = Product::create($data);
        $this->syncVariants($product, $variants);
        return response()->json(['data' => $product], 201);
    }

    public function update(Product $product, Request $request)
    {
        if ($request->has('variants')) {
            $request->merge(['variants' => $this->normalizeVariants($request->input('variants'))]);
        }
        $data = $this->validateData($request);
        $variants = $data['variants'] ?? null;
        unset($data['variants']);
        if ($request->hasFile('main_photo')) {
            $data['main_photo_path'] = $this->storeMainPhoto($request);
        }
        $product->update($data);
        $this->syncVariants($product, $variants);
        return response()->json(['data' => $product]);
    }

    public function destroy(Product $product, Request $request)
    {
        $photoPath = $product->main_photo_path;
        $product->delete();
        if ($photoPath) {
            $clean = ltrim($photoPath, '/');
            $fullPath = str_starts_with($clean, 'storage/')
                ? public_path($clean)
                : public_path('storage/' . $clean);
            if (File::exists($fullPath)) {
                File::delete($fullPath);
            }
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect('/products')->with('success', 'Товар видалено');
    }

    protected function validateData(Request $request): array
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
            'variants' => ['nullable', 'array'],
            'variants.*.size' => ['nullable', 'string', 'max:50'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.*.stock_qty' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function normalizeVariants($variants): array
    {
        if (is_string($variants)) {
            $decoded = json_decode($variants, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($variants) ? $variants : [];
    }

    private function syncVariants(Product $product, ?array $variants): void
    {
        if ($variants === null) {
            return;
        }

        $clean = collect($variants)
            ->map(function ($row) {
                return [
                    'size' => isset($row['size']) ? trim((string) $row['size']) : null,
                    'sku' => isset($row['sku']) ? trim((string) $row['sku']) : null,
                    'stock_qty' => max(0, (int) ($row['stock_qty'] ?? 0)),
                    'is_active' => isset($row['is_active']) ? (bool) $row['is_active'] : true,
                ];
            })
            ->filter(function ($row) {
                return ($row['size'] !== null && $row['size'] !== '') || ($row['sku'] !== null && $row['sku'] !== '');
            })
            ->values();

        $product->variants()->delete();
        if ($clean->isNotEmpty()) {
            $product->variants()->createMany($clean->all());
            $product->update(['stock_qty' => $clean->sum('stock_qty')]);
        }
    }

    private function storeMainPhoto(Request $request): string
    {
        $file = $request->file('main_photo');
        $dir = public_path('storage/products');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $extension = $file->getClientOriginalExtension();
        $filename = Str::random(40) . ($extension ? '.' . $extension : '');
        $file->move($dir, $filename);

        return 'products/' . $filename;
    }
}
