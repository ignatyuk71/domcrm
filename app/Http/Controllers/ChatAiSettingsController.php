<?php

namespace App\Http\Controllers;

use App\Services\ChatAiSettingsService;
use Illuminate\Http\Request;

class ChatAiSettingsController extends Controller
{
    public function __construct(
        private readonly ChatAiSettingsService $chatAiSettings
    ) {
    }

    public function index(Request $request)
    {
        $payload = $this->chatAiSettings->payload();

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return view('settings.ai');
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'assistant_name' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'max_messages' => ['required', 'integer', 'min:4', 'max:30'],
            'reply_style' => ['nullable', 'string', 'max:2000'],
            'company_context' => ['nullable', 'string', 'max:5000'],
            'qualification_fields' => ['nullable', 'array'],
            'qualification_fields.*' => ['nullable', 'string', 'max:80'],
            'handoff_rules' => ['nullable', 'string', 'max:5000'],
            'knowledge_base' => ['nullable', 'string', 'max:15000'],
        ]);

        $this->chatAiSettings->save($data, $request->user());

        return response()->json([
            'message' => 'Налаштування AI збережено.',
            ...$this->chatAiSettings->payload(),
        ]);
    }
}
