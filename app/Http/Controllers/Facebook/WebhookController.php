<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FacebookMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Підтвердження Webhook (Verification)
     */
    public function verify(Request $request)
    {
        $verifyToken = DB::table('facebook_settings')->value('verify_token');

        if (!$verifyToken) {
            Log::error('Facebook Webhook: verify_token missing in database.');
            return response('Forbidden', 403);
        }

        $mode = $request->input('hub_mode') ?? $request->input('hub.mode');
        $token = $request->input('hub_verify_token') ?? $request->input('hub.verify_token');
        $challenge = $request->input('hub_challenge') ?? $request->input('hub.challenge');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge);
        }

        return response('Forbidden', 403);
    }

    /**
     * Обробка вхідних даних (Messages & Comments)
     */
    public function handle(Request $request)
    {
        $data = $request->all();
        
        Log::info('Facebook Webhook Received', [
            'headers' => $request->headers->all(),
            'body' => $data
        ]);

        try {
            $platform = ($data['object'] ?? '') === 'instagram' ? 'instagram' : 'messenger';

            foreach ($data['entry'] ?? [] as $entry) {
                // 1. Приватні повідомлення
                if (isset($entry['messaging'])) {
                    foreach ($entry['messaging'] as $event) {
                        $this->processMessage($event, $platform);
                    }
                }

                // 2. Коментарі та зміни у фіді
                if (isset($entry['changes'])) {
                    foreach ($entry['changes'] as $change) {
                        if (in_array($change['field'] ?? '', ['feed', 'comments'], true)) {
                            $this->processComment($change['value'] ?? [], $platform);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Facebook Webhook Error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return response('Error', 500);
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * Обробка приватних повідомлень
     */
    private function processMessage(array $event, string $platform): void
    {
        if (!isset($event['message']) || !isset($event['sender']['id'])) {
            return;
        }

        $senderId = (string) $event['sender']['id'];
        $message = $event['message'];

        // Ігноруємо "відлуння"
        if ($message['is_echo'] ?? false) {
            return;
        }

        // Пошук або створення клієнта
        $customer = Customer::firstOrCreate(
            $platform === 'instagram' 
                ? ['instagram_user_id' => $senderId] 
                : ['fb_user_id' => $senderId],
            [
                'first_name' => $platform === 'instagram' ? 'Instagram User' : 'Facebook User',
                'platform' => $platform,
                'note' => "Автоматично створено через $platform",
            ]
        );

        // Формуємо текст для прев'ю (якщо є вкладення без тексту)
        $previewText = $message['text'] ?? (isset($message['attachments']) ? '📷 Зображення/Файл' : '...');

        // Зберігаємо повідомлення
        FacebookMessage::create([
            'customer_id' => $customer->id,
            'mid' => $message['mid'] ?? null,
            'text' => $message['text'] ?? null,
            'attachments' => $message['attachments'] ?? null,
            'platform' => $platform,
            'type' => 'message',
            'is_from_customer' => true,
            'is_private' => true,
        ]);

        // Оновлюємо клієнта для сайдбару чату
        $customer->update([
            'last_message_at' => now(),
            'last_message_text' => $previewText,
        ]);
    }

    /**
     * Обробка публічних коментарів
     */
    private function processComment(array $value, string $platform): void
    {
        $fbUserId = $value['from']['id'] ?? null;
        $item = $value['item'] ?? null;

        if (!$fbUserId || $item !== 'comment') {
            return;
        }

        $customer = Customer::firstOrCreate(
            ['fb_user_id' => (string) $fbUserId],
            ['first_name' => $value['from']['name'] ?? 'Social User']
        );

        FacebookMessage::create([
            'customer_id' => $customer->id,
            'mid' => $value['comment_id'] ?? null,
            'parent_id' => $value['parent_id'] ?? null,
            'text' => $value['message'] ?? null,
            'type' => 'comment',
            'platform' => $platform,
            'is_from_customer' => true,
            'is_private' => false,
            'post_id' => $value['post_id'] ?? null,
            'permalink' => $value['permalink_url'] ?? ($value['permalink'] ?? null),
        ]);

        // Оновлюємо клієнта
        $customer->update([
            'last_message_at' => now(),
            'last_message_text' => '💬 Коментар: ' . ($value['message'] ?? '...'),
        ]);
    }
}