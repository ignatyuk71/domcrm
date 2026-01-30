<?php

namespace App\Http\Controllers;

use App\Models\Color;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ColorController extends Controller
{
    public function index(Request $request)
    {
        if ($request->expectsJson()) {
            $colors = Color::query()
                ->orderBy('name')
                ->get(['id', 'name', 'hex_code']);

            return response()->json(['data' => $colors]);
        }

        return view('settings.colors');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'hex_code' => ['nullable', 'regex:/^#[A-Fa-f0-9]{6}$/'],
        ]);

        $color = Color::create($data);

        return response()->json(['data' => $color], 201);
    }

    public function update(Color $color, Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'hex_code' => ['nullable', 'regex:/^#[A-Fa-f0-9]{6}$/'],
        ]);

        $color->update($data);

        return response()->json(['data' => $color]);
    }

    public function destroy(Color $color): JsonResponse
    {
        Product::where('color_id', $color->id)->update(['color_id' => null]);
        $color->delete();

        return response()->json(['success' => true]);
    }
}
