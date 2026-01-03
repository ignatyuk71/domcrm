<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\JsonResponse;

class TagController extends Controller
{
    /** Повертає список тегів для UI. */
    public function index(): JsonResponse
    {
        $tags = Tag::select('id', 'name', 'code', 'color', 'icon')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $tags]);
    }
}
