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
    public function verify(Request $request)
    {
        $verifyToken = DB::table('facebook_settings')->value('verify_token');

        if (!$verifyToken) {
            Log::error('Facebook Webhook: verify_token missing in database.');
            return response('Forbidden', 403);
        }

        // Підтримка різних варіантів параметрів (іноді FB шле hub.mode, іноді hub_mode)
        $mode = $request->input('hub_mode') ?? $request->input('hub.mode');
        $token = $request->input('hub_verify_token') ?? $request->input('hub.verify_token');
        $challenge = $request->input('hub_challenge') ?? $request->input('hub.challenge');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    public function handle(Request $request, MetaService $metaService)
    {
        if (!$this->verifySignature($request)) {
            Log::warning('Facebook Webhook: invalid signature', [
                'ip' => $request->ip(),
            ]);
            return response('Invalid signature', 403);
        }

        $data = $request->all();

        try {
            $platform = ($data['object'] ?? '') === 'instagram' ? 'instagram' : 'messenger';

            foreach ($data['entry'] ?? [] as $entry) {
                // Messaging (Direct)
                foreach ($entry['messaging'] ?? [] as $event) {
                    $this->processMessage($event, $platform, $metaService);
                }

                // Changes (Feed/Comments)
                foreach ($entry['changes'] ?? [] as $change) {
                    if (in_array($change['field'] ?? '', ['feed', 'comments'], true)) {
                        $this->processComment($change['value'] ?? [], $platform);
                    }
                }
            }
        } catch (\Throwable $e) {
            // ВАЖЛИВО: Логуємо, але повертаємо 200, щоб FB не вимкнув вебхук
            Log::error('Facebook Webhook Error: ' . $e->getMessage(), [
                'exception' => $e,
                'payload' => $request->all() // Корисно бачити, на чому впало
            ]);
        }

        return response('EVENT_RECEIVED', 200);
    }

    private function verifySignature(Request $request): bool
    {
        $secret = (string) env('FB_WEBHOOK_SECRET');
        if ($secret === '') {
            return true; // Без секрету не блокуємо, щоб не зламати інтеграцію
        }

        $signature = (string) $request->header('X-Hub-Signature-256');
        if ($signature === '' || !str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $payload = $request->getContent();
        $hash = hash_hmac('sha256', $payload, $secret);
        return hash_equals('sha256='.$hash, $signature);
    }

    private function processMessage(array $event, string $platform, MetaService $metaService): void
    {
        if (!isset($event['message'])) {
            return;
        }

        $senderId = $event['sender']['id'] ?? null;
        $recipientId = $event['recipient']['id'] ?? null;
        $message = $event['message'];
        $mid = $message['mid'] ?? null;
        $isEcho = $message['is_echo'] ?? false;
        
        // Визначаємо ID клієнта (співрозмовника)
        $contactId = $isEcho ? $recipientId : $senderId;

        if (!$contactId || !$mid) {
            return;
        }

        // Швидка перевірка: якщо такий MID вже є, виходимо (дедуплікація)
        if (FacebookMessage::where('mid', $mid)->exists()) {
            return;
        }

        // --- ПОШУК / СТВОРЕННЯ КЛІЄНТА ---
        if ($platform === 'instagram') {
            $customer = Customer::firstOrCreate(
                ['instagram_user_id' => (string) $contactId],
                [
                    'first_name' => 'Instagram User',
                    'last_name' => '',
                    'note' => 'Auto-created via Instagram',
                ]
            );
        } else {
            $customer = Customer::firstOrCreate(
                ['fb_user_id' => (string) $contactId],
                [
                    'first_name' => 'Facebook User',
                    'last_name' => '',
                    'note' => 'Auto-created via Messenger',
                ]
            );
        }

        // Оновлюємо профіль, якщо ім'я дефолтне або немає фото
        if (str_contains($customer->first_name, 'User') || empty($customer->fb_profile_pic)) {
            // Робимо це через чергу або try-catch, щоб не впав весь вебхук через API помилку
            try {
                $metaService->updateCustomerProfile($customer);
                $customer->refresh();
            } catch (\Exception $e) {
                Log::warning("Failed to update profile for {$contactId}: " . $e->getMessage());
            }
        }

        // --- ОБРОБКА ECHO ТА ДЕДУПЛІКАЦІЯ ---
        $text = (string) ($message['text'] ?? '');
        
        // Обробка вкладень
        $rawAttachments = $message['attachments'] ?? [];
        $processedAttachments = [];
        if (!empty($rawAttachments)) {
            foreach ($rawAttachments as $att) {
                $processedAttachments[] = $metaService->processAttachment($att);
            }
        }
        $hasAttachments = !empty($processedAttachments);

        // Логіка для "Echo" (повідомлень, відправлених нами/сторінкою)
        // Ми намагаємось знайти повідомлення, яке ми створили локально перед відправкою,
        // щоб оновити йому MID, а не створювати дублікат.
        if ($isEcho) {
            // Шукаємо повідомлення за останні 5 хвилин
            $recentLocalMessage = FacebookMessage::query()
                ->where('customer_id', $customer->id)
                ->where('is_from_customer', false)
                ->whereNull('mid') // Або mid починається з 'local-' або 'temp-'
                ->where('created_at', '>=', now()->subMinutes(5))
                ->where(function ($query) use ($text, $hasAttachments) {
                    // Проста евристика: збігається текст АБО є вкладення (якщо тексту немає)
                    if ($text !== '') {
                        $query->where('text', $text);
                    } elseif ($hasAttachments) {
                        $query->whereNotNull('attachments');
                    }
                })
                ->latest()
                ->first();

            if ($recentLocalMessage) {
                // Знайшли локальне повідомлення -> оновлюємо йому MID і статус
                $recentLocalMessage->update([
                    'mid' => $mid,
                    'status' => 'sent',
                    'attachments' => $hasAttachments ? $processedAttachments : $recentLocalMessage->attachments // Оновлюємо лінки, бо FB міг їх змінити
                ]);
                return; // Не створюємо нове
            }
        }

        // Parent Message (Reply)
        $parentId = null;
        if (isset($message['reply_to']['mid'])) {
            $parentId = FacebookMessage::where('mid', $message['reply_to']['mid'])->value('id');
        }

        // Timestamp
        $sentAt = isset($event['timestamp'])
            ? Carbon::createFromTimestampMs($event['timestamp'])->timezone(config('app.timezone'))
            : now();

        // Зберігаємо повідомлення
        FacebookMessage::create([
            'mid' => $mid,
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
            'is_read' => $isEcho, // Свої повідомлення вважаємо прочитаними
        ]);

        // Оновлюємо "останнє повідомлення" в чаті
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

        // Увага: Instagram Comments можуть мати інший ID користувача, ніж Instagram Direct.
        // Поки залишаємо логіку як є, але май на увазі.
        $customer = Customer::firstOrCreate(
            ['fb_user_id' => (string) $fbUserId],
            ['first_name' => $value['from']['name'] ?? 'Social User']
        );

        // Перевірка на дублікат коментаря
        $commentId = $value['comment_id'] ?? null;
        if ($commentId && FacebookMessage::where('mid', $commentId)->exists()) {
            return;
        }

        FacebookMessage::create([
            'customer_id' => $customer->id,
            'mid' => $commentId,
            'parent_id' => $value['parent_id'] ?? null, // Це ID батьківського коментаря FB, треба перевіряти чи є він у нас в базі як ID
            'text' => $value['message'] ?? null,
            'type' => 'comment',
            'platform' => $platform,
            'is_from_customer' => true, // Коментар зазвичай від юзера
            'is_private' => false,
            'post_id' => $value['post_id'] ?? null,
            'permalink' => $value['permalink_url'] ?? ($value['permalink'] ?? null),
            'sent_at' => now(),
        ]);
    }
}
