<?php

namespace App\Http\Controllers;

use App\Models\TelegramSetting;
use App\Services\TelegramBotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TelegramSettingsController extends Controller
{
    public function index(Request $request)
    {
        return $request->expectsJson()
            ? response()->json(TelegramSetting::current()->publicSettings())
            : view('settings.telegram');
    }

    public function connect(Request $request, TelegramBotService $telegram)
    {
        $data = $request->validate([
            'bot_token' => ['nullable', 'string', 'max:200', 'regex:/^\d{5,}:[A-Za-z0-9_-]{30,}$/'],
            'chat_id' => ['required', 'string', 'regex:/^-[1-9][0-9]{0,19}$/'],
        ], [
            'bot_token.regex' => 'Скопіюйте повний токен бота з BotFather, без пробілів.',
            'bot_token.max' => 'Токен занадто довгий. Перевірте скопійоване значення.',
            'chat_id.required' => 'Вкажіть ID робочої групи.',
            'chat_id.regex' => 'ID групи — це число зі знаком мінус, без пробілів.',
        ]);
        $settings = TelegramSetting::current();
        $token = $data['bot_token'] ?? $settings->bot_token;
        if (!$token) {
            throw ValidationException::withMessages(['bot_token' => 'Вставте токен бота з BotFather.']);
        }
        $identity = $telegram->verify($token, $data['chat_id']);
        $settings = DB::transaction(function () use ($identity, $token) {
            $settings = TelegramSetting::query()->lockForUpdate()->find(1) ?? new TelegramSetting;
            if ($settings->bot_token !== $token || $settings->chat_id !== $identity['chat_id']) {
                // Зміна одержувача завжди ставить надсилання на паузу.
                $settings->enabled = false;
                $settings->last_test_at = null;
            }
            $settings->id = 1;
            $settings->fill($identity);
            $settings->bot_token = $token;
            $settings->verified_at = now();
            $settings->save();
            return $settings;
        });

        return response()->json($settings->publicSettings());
    }

    public function update(Request $request)
    {
        $rules = ['enabled' => ['required', 'boolean'], 'permissions' => ['required', 'array:'.implode(',', TelegramSetting::PERMISSIONS)]];
        foreach (TelegramSetting::PERMISSIONS as $key) {
            $rules['permissions.'.$key] = ['required', 'boolean'];
        }
        $data = $request->validate($rules);
        $settings = TelegramSetting::current();
        if ($data['enabled'] && (!$settings->verified_at || !$settings->bot_token || !$settings->chat_id)) {
            throw ValidationException::withMessages(['enabled' => 'Спочатку перевірте й збережіть підключення бота до групи.']);
        }
        $settings->id = 1;
        $settings->enabled = $data['enabled'];
        $settings->permissions = array_map(fn ($value) => (bool) $value, $data['permissions']);
        $settings->save();

        return response()->json($settings->publicSettings());
    }

    public function test(TelegramBotService $telegram)
    {
        $settings = TelegramSetting::current();
        $telegram->sendTest($settings);
        $settings->last_test_at = now();
        $settings->save();

        return response()->json($settings->publicSettings());
    }
}
