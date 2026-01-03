<?php

namespace App\Http\Controllers;

use App\Models\OrderSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderSourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = OrderSource::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->orderBy('sort_order')
            ->orderBy('name');

        $data = $query->get(['id', 'code', 'name', 'type', 'icon', 'color', 'sort_order', 'is_default']);

        return response()->json(['data' => $data]);
    }
}
