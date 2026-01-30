<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        if ($request->expectsJson()) {
            $categories = Category::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'sort_order']);

            return response()->json(['data' => $categories]);
        }

        return view('settings.categories');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $category = Category::create($data);

        return response()->json(['data' => $category], 201);
    }

    public function update(Category $category, Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $category->update($data);

        return response()->json(['data' => $category]);
    }

    public function destroy(Category $category): JsonResponse
    {
        Product::where('category_id', $category->id)->update(['category_id' => null]);
        $category->delete();

        return response()->json(['success' => true]);
    }
}
