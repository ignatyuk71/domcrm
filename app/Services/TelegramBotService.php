<?php

namespace App\Services;

use App\Models\TelegramSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class TelegramBotService
{
    private function call(string $token, string $method, array $data = []): mixed
    {
        try {
            $response = Http::connectTimeout(5)->timeout(15)
                ->withOptions(['allow_redirects' => false])
                ->post('https://api.telegram.org/bot'.$token.'/'.$method, $data);
        } catch (Throwable) {
            // Виняток HTTP містить токен в URL; не передаємо його в журнал або відповідь.
            throw ValidationException::withMessages(['connection' => 'Telegram не відповів. Перевірте з’єднання пізніше. Якщо надсилали тест, спершу перевірте групу — він міг дійти.']);
        }

        if (!$response->successful() || $response->json('ok') !== true) {
            $message = match ($response->status()) {
                401, 404 => 'Telegram не прийняв токен бота. Перевірте його в BotFather.',
                403 => 'Бот не має доступу до групи або права надсилати повідомлення.',
                429 => 'Telegram тимчасово обмежив запити. Спробуйте пізніше.',
                default => 'Не вдалося виконати запит. Перевірте ID групи й доступ бота.',
            };
            throw ValidationException::withMessages(['connection' => $message]);
        }

        return $response->json('result');
    }

    public function verify(string $token, string $chatId): array
    {
        $bot = $this->call($token, 'getMe');
        $chat = $this->call($token, 'getChat', ['chat_id' => $chatId]);
        if (!in_array($chat['type'] ?? '', ['group', 'supergroup'], true)) {
            throw ValidationException::withMessages(['chat_id' => 'Оберіть робочу групу, а не особистий чат або канал.']);
        }
        $member = $this->call($token, 'getChatMember', ['chat_id' => $chatId, 'user_id' => $bot['id']]);
        $status = $member['status'] ?? '';
        $canSend = in_array($status, ['creator', 'administrator'], true)
            || ($status === 'member' && ($chat['permissions']['can_send_messages'] ?? true))
            || ($status === 'restricted' && ($member['is_member'] ?? false) && ($member['can_send_messages'] ?? false));
        if (!$canSend) {
            throw ValidationException::withMessages(['connection' => 'Додайте бота до групи й дозвольте йому надсилати повідомлення.']);
        }

        return [
            'bot_username' => $bot['username'], 'bot_name' => $bot['first_name'],
            'chat_id' => (string) $chat['id'], 'chat_title' => $chat['title'],
        ];
    }

    public function sendTest(TelegramSetting $settings): void
    {
        if (!$settings->allows('manual_test')) {
            throw ValidationException::withMessages(['connection' => 'Увімкніть підключення та дозвіл на тестові повідомлення, потім збережіть налаштування.']);
        }
        // Лише явне натискання кнопки. Автоматично повторювати надсилання не можна.
        $this->call($settings->bot_token, 'sendMessage', [
            'chat_id' => $settings->chat_id,
            'text' => '✅ Тест із DomCRM. Підключення до робочої групи працює.',
        ]);
    }
}
