<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        if ($request->expectsJson()) {
            $q = $request->get('q');
            $category = $request->get('category');
            $perPage = min((int) $request->get('per_page', 15), 200);
            $products = Product::query()
                ->when($q, function ($query) use ($q) {
                    $query->where(function ($sub) use ($q) {
                        $sub->where('title', 'like', '%' . $q . '%')
                            ->orWhere('sku', 'like', '%' . $q . '%');
                    });
                })
                ->when($category, fn ($query) => $query->where('category', $category))
                ->orderByDesc('id')
                ->paginate($perPage);
            return response()->json($products);
        }

        return view('products.index');
    }

    public function categories(Request $request)
    {
        $categories = Product::query()
            ->select('category')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->values();

        return response()->json($categories);
    }

    public function create()
    {
        return view('products.form', ['product' => null]);
    }

    public function edit(Product $product)
    {
        return view('products.form', ['product' => $product]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        if ($request->hasFile('main_photo')) {
            $data['main_photo_path'] = $this->storeMainPhoto($request);
        }
        $product = Product::create($data);
        return response()->json(['data' => $product], 201);
    }

    public function update(Product $product, Request $request)
    {
        $data = $this->validateData($request);
        if ($request->hasFile('main_photo')) {
            $data['main_photo_path'] = $this->storeMainPhoto($request);
        }
        $product->update($data);
        return response()->json(['data' => $product]);
    }

    public function destroy(Product $product, Request $request)
    {
        $product->delete();

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
        ]);
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
