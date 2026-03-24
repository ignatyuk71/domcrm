<?php

namespace App\Http\Controllers;

use App\Models\ChatAiAgent;
use App\Services\ChatAiSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatAiSettingsController extends Controller
{
    public function __construct(
        private readonly ChatAiSettingsService $chatAiSettingsService
    ) {
    }

    public function index(Request $request)
    {
        $settings = $this->chatAiSettingsService->get();
        $agents = ChatAiAgent::query()
            ->orderBy('id')
            ->get([
                'id',
                'code',
                'name',
                'is_active',
                'provider',
                'model',
                'temperature',
                'max_output_tokens',
            ]);

        if ($request->expectsJson()) {
            return response()->json([
                'settings' => $settings,
                'agents' => $agents,
                'meta' => [
                    'save_url' => route('settings.ai.save'),
                    'agent_update_url' => route('settings.ai.agents.update', ['agent' => '__ID__']),
                ],
            ]);
        }

        return view('settings.ai');
    }

    public function save(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'default_agent_code' => ['required', 'string', 'max:80', Rule::exists('chat_ai_agents', 'code')],
            'reply_delay_seconds' => ['required', 'integer', 'min:5', 'max:60'],
            'allow_assigned_conversations' => ['required', 'boolean'],
            'max_messages' => ['required', 'integer', 'min:4', 'max:30'],
        ]);

        $settings = $this->chatAiSettingsService->save($validated, $request->user()?->id);

        return response()->json([
            'message' => 'AI налаштування збережено.',
            'settings' => $settings,
        ]);
    }

    public function updateAgent(Request $request, ChatAiAgent $agent): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $agent->is_active = (bool) $validated['is_active'];
        $agent->save();

        return response()->json([
            'message' => 'Статус агента оновлено.',
            'agent' => $agent->only([
                'id',
                'code',
                'name',
                'is_active',
                'provider',
                'model',
                'temperature',
                'max_output_tokens',
            ]),
        ]);
    }
}
