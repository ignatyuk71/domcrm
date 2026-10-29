<?php

namespace App\Http\Controllers;

use App\Models\AiChannelSetting;
use App\Models\AiSetting;
use App\Models\MetaConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AiSettingsController extends Controller
{
    /** Сторінка налаштувань AI-агента. */
    public function page()
    {
        return view('settings.ai');
    }

    /** Дані для сторінки: глобальні + по магазинах. */
    public function data()
    {
        $global = AiSetting::global();
        $key = $global->api_key;

        $stores = MetaConnection::where('status', 'active')->get()->map(function (MetaConnection $c) {
            $s = AiSetting::forConnection($c->id);

            return [
                'meta_connection_id' => $c->id,
                'page_name' => $c->page_name,
                'enabled' => (bool) $s->enabled,
                'system_prompt' => $s->system_prompt,
                'schedule' => $s->schedule ?: ['mode' => 'always', 'from' => '20:00', 'to' => '09:00'],
            ];
        })->values();

        return response()->json([
            'connection' => AiChannelSetting::current()->present(),
            'global' => [
                'has_key' => (bool) $key,
                'key_hint' => $key ? ('•••'.substr($key, -4)) : null,
                'model' => $global->model ?: 'claude-sonnet-4-6',
                'debounce_seconds' => $global->debounce_seconds ?? 10,
                'catalog_mode' => $global->catalog_mode ?: 'all',
                'operator_pause_minutes' => $global->operator_pause_minutes ?? 3,
                'follow_up_hours' => $global->follow_up_hours ?? 3,
                'comment_settings' => array_merge([
                    'enabled' => false,
                    'facebook' => true,
                    'instagram' => true,
                    'opener' => 'Доброго дня! 💛 Підкажіть, які саме тапулі вас цікавлять — домашні, вуличні чи дитячі? Підберу варіанти, покажу фото й ціни 🙂',
                ], $global->comment_settings ?? []),
            ],
            'stores' => $stores,
        ]);
    }

    /** Зберегти все (ключ оновлюється лише якщо передали непорожній). */
    public function save(Request $request)
    {
        $data = $request->validate([
            'provider' => ['prohibited'],
            'agent' => ['sometimes', 'required', 'in:claude,gpt,off'],
            'gpt_api_key' => ['exclude_unless:agent,gpt', 'nullable', 'string', 'max:512', 'regex:/^\S+$/'],
            'gpt_model' => ['exclude_unless:agent,gpt', 'nullable', 'string', 'max:120', 'regex:/^gpt-[a-zA-Z0-9._-]+$/'],
            'api_key' => ['nullable', 'string', 'max:300'],
            'model' => ['required', 'string', 'max:100'],
            'debounce_seconds' => ['nullable', 'integer', 'min:0', 'max:60'],
            'catalog_mode' => ['nullable', 'in:all,showcase'],
            'operator_pause_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'follow_up_hours' => ['nullable', 'integer', 'min:0', 'max:48'],
            'comment_settings' => ['nullable', 'array'],
            'comment_settings.enabled' => ['nullable', 'boolean'],
            'comment_settings.facebook' => ['nullable', 'boolean'],
            'comment_settings.instagram' => ['nullable', 'boolean'],
            'comment_settings.opener' => ['nullable', 'string', 'max:1000'],
            'stores' => ['array'],
            'stores.*.meta_connection_id' => ['required', 'integer', 'exists:meta_connections,id'],
            'stores.*.enabled' => ['required', 'boolean'],
            'stores.*.system_prompt' => ['nullable', 'string', 'max:20000'],
            'stores.*.schedule' => ['nullable', 'array'],
            'stores.*.schedule.mode' => ['nullable', 'in:always,window'],
            'stores.*.schedule.from' => ['nullable', 'date_format:H:i'],
            'stores.*.schedule.to' => ['nullable', 'date_format:H:i'],
        ]);

        AiChannelSetting::current();

        return DB::transaction(function () use ($data) {
            $connection = AiChannelSetting::query()->lockForUpdate()->findOrFail(1);
            $global = AiSetting::global();
            if (isset($data['agent'])) {
                // Один вибір одночасно вмикає потрібний API та вимикає інший.
                $connection->claude_enabled = $data['agent'] === 'claude';
                $connection->gpt_enabled = $data['agent'] === 'gpt';
                if ($data['agent'] !== 'off') {
                    $connection->default_provider = $data['agent'];
                }
                if (! empty($data['gpt_api_key'])) {
                    $connection->gpt_api_key = $data['gpt_api_key'];
                }
                if (array_key_exists('gpt_model', $data)) {
                    $connection->gpt_model = $data['gpt_model'];
                }
                $errors = [];
                if ($connection->gpt_enabled && ! $connection->gpt_api_key) {
                    $errors['gpt_api_key'] = 'Вкажіть ключ GPT API.';
                }
                if ($connection->gpt_enabled && ! $connection->gpt_model) {
                    $errors['gpt_model'] = 'Вкажіть модель GPT.';
                }
                if ($connection->claude_enabled && empty($data['api_key']) && ! $global->api_key) {
                    $errors['api_key'] = 'Вкажіть ключ Claude API.';
                }
                if ($errors) {
                    throw ValidationException::withMessages($errors);
                }
                $connection->save();
            }
            $global->model = $data['model'];
            $global->debounce_seconds = $data['debounce_seconds'] ?? 10;
            $global->catalog_mode = $data['catalog_mode'] ?? 'all';
            $global->operator_pause_minutes = $data['operator_pause_minutes'] ?? 3;
            $global->follow_up_hours = $data['follow_up_hours'] ?? 3;
            if (array_key_exists('comment_settings', $data)) {
                $new = $data['comment_settings'] ?? [];
                $old = $global->comment_settings ?? [];
                // Момент увімкнення: бот відповідає лише на коментарі ПІСЛЯ нього.
                if (($new['enabled'] ?? false) && ! ($old['enabled'] ?? false)) {
                    $new['enabled_at'] = now()->toDateTimeString();
                } elseif (($new['enabled'] ?? false) && isset($old['enabled_at'])) {
                    $new['enabled_at'] = $old['enabled_at'];
                }
                $global->comment_settings = $new;
            }
            if (! empty($data['api_key'])) {
                $global->api_key = trim($data['api_key']);
            }
            $global->save();

            foreach ($data['stores'] ?? [] as $row) {
                AiSetting::forConnection($row['meta_connection_id'])->update([
                    'enabled' => $row['enabled'],
                    'system_prompt' => $row['system_prompt'] ?? null,
                    'schedule' => $row['schedule'] ?? null,
                ]);
            }

            return response()->json(['ok' => true]);
        });
    }

    /** Перевірка ключа: GET /v1/models (безкоштовно, без токенів). */
    public function test(Request $request)
    {
        if (! app(\App\Services\Ai\Channels\ProviderPolicy::class)->enabled('claude')) {
            return response()->json(['ok' => false, 'error' => 'Claude API вимкнено.'], 422);
        }
        // Стара відкрита форма не повинна надіслати ключ іншого API до Anthropic.
        $request->validate(['provider' => ['prohibited']], [
            'provider.prohibited' => 'Налаштування підключення змінено. Оновіть сторінку та повторіть спробу.',
        ]);
        $key = trim((string) $request->input('api_key', ''));
        if ($key === '') {
            $key = (string) (AiSetting::global()->api_key ?? '');
        }
        if ($key === '') {
            return response()->json(['ok' => false, 'error' => 'Ключ не вказано'], 422);
        }

        try {
            $r = Http::timeout(10)->withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ])->get('https://api.anthropic.com/v1/models');

            if ($r->successful()) {
                return response()->json(['ok' => true]);
            }

            return response()->json([
                'ok' => false,
                'error' => $r->json('error.message') ?? ('HTTP '.$r->status()),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Немає зʼєднання з api.anthropic.com'], 422);
        }
    }
}
