<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FacebookMessage;
use App\Services\MetaService;
use Carbon\Carbon;
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
    public function handle(Request $request, MetaService $metaService)
    {
        $data = $request->all();

        try {
            $platform = ($data['object'] ?? '') === 'instagram' ? 'instagram' : 'messenger';

            foreach ($data['entry'] ?? [] as $entry) {
                // Обробка приватних повідомлень (Direct/Messenger)
                foreach ($entry['messaging'] ?? [] as $event) {
                    $this->processMessage($event, $platform, $metaService);
                }

                // Обробка публічних коментарів (Feed)
                foreach ($entry['changes'] ?? [] as $change) {
                    if (in_array($change['field'] ?? '', ['feed', 'comments'], true)) {
                        $this->processComment($change['value'] ?? [], $platform);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Facebook Webhook Error: '.$e->getMessage(), [
                'exception' => $e,
            ]);
            return response('Error', 500);
        }

        return response('EVENT_RECEIVED', 200);
    }

    private function processMessage(array $event, string $platform, MetaService $metaService): void
    {
        // Перевірка наявності даних
        if (!isset($event['message'])) {
            return;
        }

        $senderId = isset($event['sender']['id']) ? (string) $event['sender']['id'] : null;
        $recipientId = isset($event['recipient']['id']) ? (string) $event['recipient']['id'] : null;
        $message = $event['message'];
        $mid = $message['mid'] ?? null;
        $isEcho = $message['is_echo'] ?? false;
        $contactId = $isEcho ? $recipientId : $senderId;

        if (!$contactId || !$mid) {
            return;
        }

        if (FacebookMessage::where('mid', $mid)->exists()) {
            return;
        }

        // --- ЛОГІКА РОЗПОДІЛУ КЛІЄНТІВ ---
        if ($platform === 'instagram') {
            // ВАРІАНТ А: Це Instagram
            // Шукаємо або створюємо клієнта по instagram_user_id
            $customer = Customer::firstOrCreate(
                ['instagram_user_id' => $contactId],
                [
                    'first_name' => 'Instagram User',
                    'last_name' => '',
                    'note' => 'Створено автоматично через Instagram Direct',
                ]
            );
        } else {
            // ВАРІАНТ Б: Це Facebook (Messenger)
            // Шукаємо або створюємо клієнта по fb_user_id
            $customer = Customer::firstOrCreate(
                ['fb_user_id' => $contactId],
                [
                    'first_name' => 'Facebook User',
                    'last_name' => '',
                    'note' => 'Створено автоматично через Facebook Messenger',
                ]
            );
        }

        if (
            str_contains((string) $customer->first_name, 'User')
            || empty($customer->fb_profile_pic)
        ) {
            $metaService->updateCustomerProfile($customer);
            $customer->refresh();
        }

        if ($isEcho) {
            $text = (string) ($message['text'] ?? '');
            $hasAttachments = !empty($message['attachments'] ?? []);

            $recentEcho = FacebookMessage::query()
                ->where('customer_id', $customer->id)
                ->where('is_from_customer', false)
                ->where('created_at', '>=', now(config('app.timezone', 'Europe/Kyiv'))->subMinutes(2));

            if ($text !== '') {
                $recentEcho->where('text', $text);
            }

            if ($hasAttachments) {
                $recentEcho->whereNotNull('attachments');
            }

            if ($recentEcho->exists()) {
                return;
            }
        }

        $replyToMid = $message['reply_to']['mid'] ?? null;
        $parentId = null;
        if ($replyToMid) {
            $parentId = FacebookMessage::where('mid', $replyToMid)->value('id');
        }

        // --- ОБРОБКА ВКЛАДЕНЬ ---
        $rawAttachments = $message['attachments'] ?? [];
        $processedAttachments = [];

        if (!empty($rawAttachments)) {
            foreach ($rawAttachments as $att) {
                $processedAttachments[] = $metaService->processAttachment($att);
            }
        }

        // Зберігаємо саме повідомлення
        $text = $message['text'] ?? '';
        $hasAttachments = !empty($processedAttachments);
        $sentAt = isset($event['timestamp'])
            ? Carbon::createFromTimestampMs($event['timestamp'])->timezone(config('app.timezone', 'Europe/Kyiv'))
            : now(config('app.timezone', 'Europe/Kyiv'));

        if ($isEcho) {
            $recentEcho = FacebookMessage::query()
                ->where('customer_id', $customer->id)
                ->where('is_from_customer', false)
                ->where('created_at', '>=', now(config('app.timezone', 'Europe/Kyiv'))->subMinutes(2))
                ->get();

            foreach ($recentEcho as $existing) {
                $existingText = (string) ($existing->text ?? '');
                $existingAttachments = $existing->attachments ?? [];

                $textMatches = $existingText === $text;
                $attachmentsMatch = empty($processedAttachments) && empty($existingAttachments);

                if (!empty($processedAttachments) && !empty($existingAttachments)) {
                    $incomingUrl = $processedAttachments[0]['url'] ?? null;
                    $storedUrl = $existingAttachments[0]['url'] ?? null;
                    $attachmentsMatch = $incomingUrl && $storedUrl && basename($incomingUrl) === basename($storedUrl);
                }

                if (($textMatches && $attachmentsMatch) || ($textMatches && empty($processedAttachments))) {
                    if (!$existing->mid || str_starts_with($existing->mid, 'local-')) {
                        $existing->update(['mid' => $mid]);
                    }
                    return;
                }
            }
        }

        FacebookMessage::updateOrCreate(
            ['mid' => $mid],
            [
                'customer_id' => $customer->id,
                'parent_id' => $parentId,
                'text' => $text !== '' ? $text : ($hasAttachments ? 'Вкладення' : null),
                'attachments' => $hasAttachments ? $processedAttachments : null,
                'platform' => $platform,
                'type' => 'message',
                'is_from_customer' => !$isEcho,
                'is_private' => true,
                'sent_at' => $sentAt,
                'status' => $isEcho ? 'sent' : 'received',
                'is_read' => $isEcho,
            ]
        );

        $metaService->touchConversation(
            $customer->id,
            $platform,
            $text !== '' ? $text : ($hasAttachments ? 'Вкладення' : ''),
            $sentAt,
            !$isEcho
        );
    }

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
    }
}
