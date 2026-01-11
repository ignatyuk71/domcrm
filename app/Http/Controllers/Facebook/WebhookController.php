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
     * Підтвердження Webhook (Verification)fsdfgdsfgdd sdf gsdg sdfg
     */
    public function verify(Request $request)
    {
        // Отримуємо токен з нашої таблиці налаштувань
        $verifyToken = DB::table('facebook_settings')->value('verify_token');

        if (!$verifyToken) {
            Log::error('Facebook Webhook: verify_token missing in database.');
            return response('Forbidden', 403);
        }

        // Facebook може надсилати параметри як hub_mode або hub.mode
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
        // Логуємо все для відладки
        Log::info('Facebook Webhook Received', $data);

        $platform = ($data['object'] ?? '') === 'instagram' ? 'instagram' : 'messenger';

        foreach ($data['entry'] ?? [] as $entry) {
            // Обробка приватних повідомлень (Direct/Messenger)
            foreach ($entry['messaging'] ?? [] as $event) {
                $this->processMessage($event, $platform);
            }

            // Обробка публічних коментарів (Feed)
            foreach ($entry['changes'] ?? [] as $change) {
                if (in_array($change['field'] ?? '', ['feed', 'comments'], true)) {
                    $this->processComment($change['value'] ?? [], $platform);
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    private function processMessage(array $event, string $platform): void
    {
        if (!isset($event['message']) || !isset($event['sender']['id'])) {
            return;
        }

        $fbUserId = $event['sender']['id'];
        $message = $event['message'];

        // Шукаємо або створюємо клієнта за його FB ID
        $customer = Customer::firstOrCreate(
            ['fb_user_id' => $fbUserId],
            ['first_name' => 'Social', 'last_name' => 'User']
        );

        if ($message['is_echo'] ?? false) {
            return;
        }

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
    }

    private function processComment(array $value, string $platform): void
    {
        $fbUserId = $value['from']['id'] ?? null;
        $item = $value['item'] ?? null;

        if (!$fbUserId || $item !== 'comment') {
            return;
        }

        $customer = Customer::firstOrCreate(
            ['fb_user_id' => $fbUserId],
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
    }
}
