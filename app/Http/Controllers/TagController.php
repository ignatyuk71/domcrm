<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    /** Повертає список тегів для UI. */
    public function index(Request $request)
    {
        if ($request->expectsJson()) {
            $tags = Tag::select('id', 'name', 'code', 'color', 'icon')
                ->orderBy('name')
                ->get();

            return response()->json(['data' => $tags]);
        }

        return view('settings.tags');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:tags,code'],
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:50'],
        ]);

        $tag = Tag::create($data);

        return response()->json(['data' => $tag], 201);
    }

    public function update(Tag $tag, Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:tags,code,' . $tag->id],
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:50'],
        ]);

        $tag->update($data);

        return response()->json(['data' => $tag]);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $tag->orders()->detach();
        $tag->delete();

        return response()->json(['success' => true]);
    }
}
