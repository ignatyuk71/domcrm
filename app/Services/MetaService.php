<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaService
{
    public function sendMessage(Customer $customer, string $text, array $attachments = []): array
    {
        $settings = $this->getSettings();
        $recipientId = $customer->instagram_user_id ?: $customer->fb_user_id;

        if (!$recipientId) {
            throw new \RuntimeException('Клієнт не має ID соцмережі.');
        }

        $validAttachments = array_values(array_filter($attachments, function ($attachment) {
            return !empty($attachment['url']) && str_starts_with($attachment['url'], 'http');
        }));

        $payload = [
            'recipient' => ['id' => $recipientId],
            'messaging_type' => 'RESPONSE',
        ];

        if (!empty($validAttachments)) {
            $attachment = $validAttachments[0];
            $payload['message'] = [
                'attachment' => [
                    'type' => $attachment['type'] ?? 'image',
                    'payload' => [
                        'url' => $attachment['url'] ?? '',
                        'is_reusable' => true,
                    ],
                ],
            ];
        } else {
            $payload['message'] = ['text' => $text];
        }

        $response = Http::withToken($settings->access_token)->post($this->graphUrl('/me/messages'), $payload);

        if ($response->failed()) {
            Log::error('Meta API Error', $response->json());
            throw new \RuntimeException('Meta API Error');
        }

        return $response->json();
    }

    public function syncHistory(Customer $customer): int
    {
        $settings = $this->getSettings();
        $recipientId = $customer->instagram_user_id ?: $customer->fb_user_id;
        $platform = $customer->instagram_user_id ? 'instagram' : 'messenger';

        if (!$recipientId) {
            return 0;
        }

        $threadId = $this->findThreadId($settings->access_token, $recipientId, $platform);
        if (!$threadId) {
            return 0;
        }

        $messagesUrl = $this->graphUrl("/{$threadId}/messages");
        $response = Http::withToken($settings->access_token)->get($messagesUrl, [
            'fields' => 'message,created_time,from,attachments,id',
            'limit' => 50,
        ]);

        if ($response->failed()) {
            Log::error('Meta API Error', $response->json());
            return 0;
        }

        $remoteMessages = $response->json()['data'] ?? [];
        $addedCount = 0;

        foreach (array_reverse($remoteMessages) as $msgData) {
            $mid = $msgData['id'] ?? null;
            if (!$mid) {
                continue;
            }

            if (ChatMessage::where('mid', $mid)->exists()) {
                continue;
            }

            $isFromCustomer = isset($msgData['from']['id']) && $msgData['from']['id'] == $recipientId;
            $sentAt = $this->normalizeTimestamp($msgData['created_time'] ?? null);

            $text = $msgData['message'] ?? '';
            $hasAttachments = !empty($msgData['attachments']['data'] ?? []);

            ChatMessage::create([
                'customer_id' => $customer->id,
                'mid' => $mid,
                'text' => $text !== '' ? $text : ($hasAttachments ? 'Вкладення' : ''),
                'attachments' => $msgData['attachments']['data'] ?? null,
                'is_from_customer' => $isFromCustomer,
                'platform' => $platform,
                'is_private' => true,
                'sent_at' => $sentAt,
                'status' => $isFromCustomer ? 'received' : 'sent',
                'is_read' => !$isFromCustomer,
                'created_at' => $sentAt,
                'updated_at' => $sentAt,
            ]);

            $this->touchConversation(
                $customer->id,
                $platform,
                $text !== '' ? $text : ($hasAttachments ? 'Вкладення' : ''),
                $sentAt,
                $isFromCustomer
            );
            $addedCount++;
        }

        return $addedCount;
    }

    public function getUserProfile(string $psid): array
    {
        $settings = $this->getSettings();
        $response = Http::withToken($settings->access_token)->get($this->graphUrl("/{$psid}"), [
            'fields' => 'name,profile_pic',
        ]);

        return $response->ok() ? $response->json() : [];
    }

    public function touchConversation(
        int $customerId,
        string $platform,
        string $text,
        ?Carbon $sentAt,
        bool $isInbound
    ): void {
        $payload = [
            'platform' => $platform,
            'last_message_text' => $text,
            'last_message_at' => $sentAt,
        ];

        Conversation::updateOrCreate(
            ['customer_id' => $customerId, 'platform' => $platform],
            array_merge($payload, [
                'status' => 'open',
            ])
        );

        if ($isInbound) {
            Conversation::where('customer_id', $customerId)
                ->where('platform', $platform)
                ->increment('unread_count');
        } else {
            Conversation::where('customer_id', $customerId)
                ->where('platform', $platform)
                ->update(['unread_count' => 0]);
        }
    }

    private function getSettings()
    {
        $settings = DB::table('facebook_settings')->first();

        if (!$settings || !$settings->access_token) {
            throw new \RuntimeException('Meta token is missing.');
        }

        return $settings;
    }

    private function findThreadId(string $accessToken, string $recipientId, string $platform): ?string
    {
        $response = Http::withToken($accessToken)->get($this->graphUrl('/me/conversations'), [
            'platform' => $platform,
            'fields' => 'participants,updated_time',
            'limit' => 20,
        ]);

        if ($response->failed()) {
            Log::error('Meta API Error', $response->json());
            return null;
        }

        foreach (($response->json()['data'] ?? []) as $conversation) {
            $participants = $conversation['participants']['data'] ?? [];
            foreach ($participants as $participant) {
                if (($participant['id'] ?? null) == $recipientId) {
                    return $conversation['id'] ?? null;
                }
            }
        }

        return null;
    }

    private function normalizeTimestamp(?string $timestamp): ?Carbon
    {
        if (!$timestamp) {
            return null;
        }

        $timezone = config('app.timezone', 'Europe/Kyiv');

        return Carbon::parse($timestamp)->timezone($timezone);
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/v19.0'.$path;
    }
}
