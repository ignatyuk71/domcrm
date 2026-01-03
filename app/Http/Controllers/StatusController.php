<?php

namespace App\Http\Controllers;

use App\Models\Status;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    /** Повертає список статусів (можна фільтрувати за type). */
    public function index(Request $request): JsonResponse
    {
        $type = $request->get('type');

        $query = Status::query()
            ->select('id', 'code', 'name', 'type', 'icon', 'color', 'sort_order', 'is_default')
            ->orderBy('sort_order');

        if ($type) {
            $query->where('type', $type);
        }

        return response()->json(['data' => $query->get()]);
    }
}
