<?php

namespace App\Http\Controllers;

use App\Models\{AiChannelSetting, InboxConversation};
use App\Services\Ai\Channels\ProviderPolicy;
use App\Services\Ai\Gpt\OpenAiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Validation\ValidationException;

class AiChannelsController extends Controller
{
    public function data() { return response()->json(AiChannelSetting::current()->present()); }

    public function save(Request $request)
    {
        $data = $request->validate([
            'claude_enabled' => 'required|boolean', 'gpt_enabled' => 'required|boolean',
            'default_provider' => 'required|in:claude,gpt', 'gpt_api_key' => ['nullable', 'string', 'max:512', 'regex:/^\S+$/'],
            'gpt_model' => ['nullable', 'string', 'max:120', 'regex:/^gpt-[a-zA-Z0-9._-]+$/'],
        ]);
        return Cache::lock('ai-channel-settings', 15)->block(3, function () use ($data) {
            return DB::transaction(function () use ($data) {
                $setting = AiChannelSetting::query()->lockForUpdate()->findOrFail(1);
                if ($setting->default_provider !== $data['default_provider'] && InboxConversation::whereNull('ai_provider')
                    ->where('ai_checkout_active', true)->exists()) {
                    throw ValidationException::withMessages(['default_provider' => 'Є незавершене оформлення. Завершіть його або спочатку закріпіть чинного агента за цим чатом.']);
                }
                // Порожнє поле не стирає чинний зашифрований ключ.
                if (empty($data['gpt_api_key'])) unset($data['gpt_api_key']);
                $setting->fill($data);
                if ($setting->gpt_enabled && (! $setting->gpt_api_key || ! $setting->gpt_model)) {
                    throw ValidationException::withMessages(['gpt_api_key' => 'Для ввімкнення GPT потрібні API-ключ і модель.']);
                }
                $setting->save();
                return response()->json($setting->present());
            });
        });
    }

    public function models(OpenAiClient $client)
    {
        $setting = AiChannelSetting::current();
        if (! $setting->gpt_enabled || ! $setting->gpt_api_key) return response()->json(['message' => 'Збережіть ключ і ввімкніть GPT API.'], 422);
        try { return response()->json(['models' => $client->models($setting->gpt_api_key)]); }
        catch (\Throwable $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function select(Request $request, InboxConversation $conversation, ProviderPolicy $policy)
    {
        $data = $request->validate(['provider' => 'present|nullable|in:claude,gpt']);
        $settingsLock = Cache::lock('ai-channel-settings', 15);
        if (! $settingsLock->get()) return response()->json(['message' => 'Налаштування агента оновлюються. Повторіть.'], 409);
        $lock = Cache::lock('ai-channel-conversation-'.$conversation->id, 300);
        try {
            if (! $lock->get()) return response()->json(['message' => 'Агент готує відповідь. Дочекайтесь завершення та повторіть.'], 409);
            $conversation->refresh();
            $provider = $data['provider'] ?: AiChannelSetting::current()->default_provider;
            if ($provider !== $policy->selected($conversation) && $conversation->ai_checkout_active) {
                return response()->json(['message' => 'Завершіть оформлення або скиньте памʼять агента перед перемиканням.'], 409);
            }
            if ($provider !== $policy->selected($conversation) && ! $policy->ready($provider)) {
                return response()->json(['message' => 'Спочатку налаштуйте та ввімкніть вибраний API.'], 422);
            }
            InboxConversation::whereKey($conversation->id)->update(['ai_provider' => $data['provider'], 'ai_provider_version' => DB::raw('ai_provider_version + 1')]);
            $conversation->refresh();
            return response()->json(['provider' => $conversation->ai_provider, 'active_provider' => $provider]);
        } finally { $lock->release(); $settingsLock->release(); }
    }
}
