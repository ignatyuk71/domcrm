<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Status;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatusController extends Controller
{
    /** Повертає список статусів (можна фільтрувати за type). */
    public function index(Request $request)
    {
        if (!$request->expectsJson()) {
            return view('settings.statuses');
        }

        $type = $request->get('type');

        $query = Status::query()
            ->select('id', 'code', 'name', 'type', 'icon', 'color', 'sort_order', 'is_default')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($type) {
            $query->where('type', $type);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:statuses,code'],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:30'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $data['type'] = $data['type'] ?? 'order';
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);

        $status = DB::transaction(function () use ($data) {
            if ($data['is_default']) {
                Status::where('type', $data['type'])->update(['is_default' => false]);
            }

            return Status::create($data);
        });

        return response()->json(['data' => $status], 201);
    }

    public function update(Status $status, Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:statuses,code,' . $status->id],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:30'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $data['type'] = $data['type'] ?? 'order';
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);

        DB::transaction(function () use ($data, $status) {
            if ($data['is_default']) {
                Status::where('type', $data['type'])
                    ->where('id', '!=', $status->id)
                    ->update(['is_default' => false]);
            }

            $status->update($data);
        });

        return response()->json(['data' => $status->fresh()]);
    }

    public function destroy(Status $status): JsonResponse
    {
        if ($status->is_default) {
            return response()->json([
                'message' => 'Не можна видалити статус за замовчуванням.',
            ], 409);
        }

        if (Order::where('status_id', $status->id)->exists()) {
            return response()->json([
                'message' => 'Статус використовується в замовленнях.',
            ], 409);
        }

        $status->delete();

        return response()->json(['success' => true]);
    }
}
