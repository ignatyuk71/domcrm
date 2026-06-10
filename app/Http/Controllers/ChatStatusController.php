<?php

namespace App\Http\Controllers;

use App\Models\ChatStatus;
use Illuminate\Http\Request;

class ChatStatusController extends Controller
{
    /** Сторінка налаштувань «Статуси чату». */
    public function page()
    {
        return view('settings.chat-statuses');
    }

    /** Список статусів (для налаштувань і селекта в чаті). */
    public function index()
    {
        return response()->json(
            ChatStatus::query()->orderBy('sort_order')->orderBy('id')->get()
        );
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $status = ChatStatus::create($data);
        $this->ensureSingleDefault($status);

        return response()->json($status->refresh(), 201);
    }

    public function update(Request $request, ChatStatus $chatStatus)
    {
        $data = $this->validated($request);
        $chatStatus->update($data);
        $this->ensureSingleDefault($chatStatus);

        return response()->json($chatStatus->refresh());
    }

    public function destroy(ChatStatus $chatStatus)
    {
        if ($chatStatus->is_default) {
            return response()->json(['ok' => false, 'error' => 'Не можна видалити статус за замовчуванням'], 422);
        }

        $chatStatus->delete(); // chat_status_id у розмовах стане NULL (nullOnDelete)

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_default'] = (bool) ($data['is_default'] ?? false);

        return $data;
    }

    /** Статус за замовчуванням може бути лише один. */
    private function ensureSingleDefault(ChatStatus $status): void
    {
        if ($status->is_default) {
            ChatStatus::where('id', '!=', $status->id)->update(['is_default' => false]);
        } elseif (!ChatStatus::where('is_default', true)->exists()) {
            $status->update(['is_default' => true]);
        }
    }
}
