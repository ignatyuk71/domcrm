<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Customer;
use App\Services\MetaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $settings = DB::table('facebook_settings')->first();

        if ($mode === 'subscribe' && $settings && $token === $settings->verify_token) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    public function handle(Request $request, MetaService $metaService)
    {
        if (!$this->isSignatureValid($request)) {
            return response('Invalid signature', 403);
        }

        $payload = $request->all();
        $platform = ($payload['object'] ?? '') === 'instagram' ? 'instagram' : 'messenger';

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $event) {
                $this->handleMessageEvent($event, $metaService, $platform);
            }
        }

        return response('OK', 200);
    }

    private function handleMessageEvent(array $event, MetaService $metaService, string $platform): void
    {
        $senderId = $event['sender']['id'] ?? null;
        $message = $event['message'] ?? [];
        $mid = $message['mid'] ?? null;

        if (!$senderId || !$mid || !empty($message['is_echo'])) {
            return;
        }

        if (ChatMessage::where('mid', $mid)->exists()) {
            return;
        }

        $customer = Customer::where('instagram_user_id', $senderId)
            ->orWhere('fb_user_id', $senderId)
            ->first();

        if (!$customer) {
            $customer = Customer::create([
                'first_name' => 'Meta User',
                'instagram_user_id' => $platform === 'instagram' ? $senderId : null,
                'fb_user_id' => $platform === 'messenger' ? $senderId : null,
            ]);
        } else {
            if ($platform === 'instagram' && !$customer->instagram_user_id) {
                $customer->update(['instagram_user_id' => $senderId]);
            }
            if ($platform === 'messenger' && !$customer->fb_user_id) {
                $customer->update(['fb_user_id' => $senderId]);
            }
        }

        $sentAt = isset($event['timestamp'])
            ? Carbon::createFromTimestampMs($event['timestamp'])->timezone(config('app.timezone', 'Europe/Kyiv'))
            : now(config('app.timezone', 'Europe/Kyiv'));

        $text = $message['text'] ?? '';
        $hasAttachments = !empty($message['attachments'] ?? []);

        ChatMessage::create([
            'customer_id' => $customer->id,
            'mid' => $mid,
            'text' => $text !== '' ? $text : ($hasAttachments ? 'Вкладення' : ''),
            'attachments' => $message['attachments'] ?? null,
            'is_from_customer' => true,
            'platform' => $platform,
            'is_private' => true,
            'sent_at' => $sentAt,
            'status' => 'received',
            'is_read' => false,
            'created_at' => $sentAt,
            'updated_at' => $sentAt,
        ]);

        $metaService->touchConversation(
            $customer->id,
            $platform,
            $text !== '' ? $text : ($hasAttachments ? 'Вкладення' : ''),
            $sentAt,
            true
        );
    }

    private function isSignatureValid(Request $request): bool
    {
        $signature = $request->header('X-Hub-Signature-256');
        $appSecret = config('services.facebook.app_secret');

        if (!$signature || !$appSecret) {
            return true;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $signature);
    }
}
