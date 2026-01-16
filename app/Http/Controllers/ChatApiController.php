<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\FacebookMessage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatApiController extends Controller
{
    public function list()
    {
        $latestMessages = DB::table('facebook_messages as m1')
            ->select('m1.*')
            ->whereRaw(
                'm1.id = (SELECT MAX(m2.id) FROM facebook_messages as m2 WHERE m2.customer_id = m1.customer_id)'
            )
            ->orderByDesc('m1.created_at')
            ->paginate(20);

        $latestMessages->getCollection()->transform(function ($message) {
            $customer = DB::table('customers')->where('id', $message->customer_id)->first();
            $firstName = $customer->first_name ?? '';
            $lastName = $customer->last_name ?? '';
            $name = trim($firstName.' '.$lastName);

            return [
                'customer_id' => $message->customer_id,
                'customer_name' => $name !== '' ? $name : 'Невідомий клієнт',
                'customer_avatar' => null,
                'platform' => $message->platform,
                'last_message' => $message->text ?? null,
                'last_message_time' => $message->created_at,
                'unread_count' => 0,
            ];
        });

        return response()->json($latestMessages);
    }

    public function messages($id)
    {
        $messages = FacebookMessage::query()
            ->where('customer_id', $id)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function (FacebookMessage $message) {
                return [
                    'id' => $message->id,
                    'text' => $message->text ?? null,
                    'direction' => $message->is_from_customer ? 'inbound' : 'outbound',
                    'created_at' => $message->created_at?->toDateTimeString(),
                    'attachments' => $message->attachments ?? [],
                ];
            });

        return response()->json(['data' => $messages]);
    }

    public function getThreadUpdates(Request $request, $id)
    {
        $sinceId = (int) $request->query('since_id');

        if ($sinceId <= 0) {
            return response()->json([
                'messages' => [],
                'thread' => null,
                'has_updates' => false,
            ]);
        }

        $messages = FacebookMessage::query()
            ->where('customer_id', $id)
            ->where('id', '>', $sinceId)
            ->orderBy('id', 'asc')
            ->get();

        $normalizedMessages = $messages->map(function (FacebookMessage $message) {
            return [
                'id' => $message->id,
                'text' => $message->text ?? null,
                'direction' => $message->is_from_customer ? 'inbound' : 'outbound',
                'created_at' => $message->created_at?->toDateTimeString(),
                'attachments' => $message->attachments ?? [],
            ];
        });

        $lastMessage = $messages->last();

        return response()->json([
            'messages' => $normalizedMessages,
            'thread' => $lastMessage ? [
                'id' => (int) $id,
                'last_message_text' => $lastMessage->text ?? null,
                'last_message_at' => $lastMessage->created_at?->toDateTimeString(),
            ] : null,
            'has_updates' => $messages->isNotEmpty(),
        ]);
    }

    public function send(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'message' => 'required|string',
            'attachments' => 'array',
        ]);

        $customer = Customer::findOrFail($validated['customer_id']);

        $settings = DB::table('facebook_settings')->first();
        if (!$settings || !$settings->access_token) {
            return response()->json(['error' => 'Токен Meta не налаштовано'], 500);
        }

        $recipientId = null;
        if ($customer->instagram_user_id) {
            $recipientId = $customer->instagram_user_id;
        } elseif ($customer->fb_user_id) {
            $recipientId = $customer->fb_user_id;
        } else {
            return response()->json(['error' => 'Клієнт не має ID соцмережі'], 400);
        }

        $url = 'https://graph.facebook.com/v19.0/me/messages';

        try {
            $response = Http::withToken($settings->access_token)->post($url, [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $validated['message']],
                'messaging_type' => 'RESPONSE',
            ]);

            if ($response->failed()) {
                Log::error('Meta API Error', $response->json());
                return response()->json(['error' => 'Meta API Error', 'details' => $response->json()], 500);
            }

            $metaData = $response->json();
            $mid = $metaData['message_id'] ?? ('local-'.uniqid('', true));
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Connection Error: '.$e->getMessage()], 500);
        }

        $platform = $customer->instagram_user_id ? 'instagram' : 'messenger';

        $message = FacebookMessage::create([
            'customer_id' => $validated['customer_id'],
            'mid' => $mid,
            'text' => $validated['message'],
            'attachments' => $validated['attachments'] ?? null,
            'type' => 'message',
            'is_from_customer' => false,
            'platform' => $platform,
            'is_private' => true,
        ]);

        return response()->json([
            'data' => [
                'id' => $message->id,
                'text' => $message->text ?? null,
                'direction' => 'outbound',
                'created_at' => $message->created_at?->toDateTimeString(),
                'attachments' => $message->attachments ?? [],
            ],
        ]);
    }

    /**
     * Синхронізація історії повідомлень з Facebook/Instagram.
     */
    public function sync($customerId)
    {
        $customer = Customer::findOrFail($customerId);

        $settings = DB::table('facebook_settings')->first();
        if (!$settings || !$settings->access_token) {
            return response()->json(['error' => 'Токен Meta не налаштовано'], 500);
        }

        $targetId = $customer->instagram_user_id ?? $customer->fb_user_id;
        if (!$targetId) {
            return response()->json(['error' => 'Клієнт не прив\'язаний до FB/Instagram'], 400);
        }

        $accessToken = $settings->access_token;
        $platformParam = $customer->instagram_user_id ? 'instagram' : 'messenger';
        $conversationsUrl = 'https://graph.facebook.com/v19.0/me/conversations';

        $response = Http::withToken($accessToken)->get($conversationsUrl, [
            'platform' => $platformParam,
            'fields' => 'participants,updated_time',
            'limit' => 20,
        ]);

        if ($response->failed()) {
            return response()->json(['error' => 'Meta API Error', 'details' => $response->json()], 500);
        }

        $conversations = $response->json()['data'] ?? [];
        $threadId = null;

        foreach ($conversations as $convo) {
            $participants = $convo['participants']['data'] ?? [];
            foreach ($participants as $participant) {
                if ($participant['id'] == $targetId) {
                    $threadId = $convo['id'];
                    break 2;
                }
            }
        }

        if (!$threadId) {
            return response()->json(['message' => 'Активний діалог не знайдено в останніх 20-ти. Напишіть клієнту першим.'], 404);
        }

        $msgsUrl = "https://graph.facebook.com/v19.0/{$threadId}/messages";
        $msgsResponse = Http::withToken($accessToken)->get($msgsUrl, [
            'fields' => 'message,created_time,from,attachments,id',
            'limit' => 50,
        ]);

        if ($msgsResponse->failed()) {
            return response()->json(['error' => 'Meta API Error', 'details' => $msgsResponse->json()], 500);
        }

        $remoteMessages = $msgsResponse->json()['data'] ?? [];
        $addedCount = 0;

        foreach (array_reverse($remoteMessages) as $msgData) {
            $mid = $msgData['id'] ?? null;
            if (!$mid) {
                continue;
            }

            if (FacebookMessage::where('mid', $mid)->exists()) {
                continue;
            }

            $isFromCustomer = isset($msgData['from']['id']) && $msgData['from']['id'] == $targetId;

            FacebookMessage::create([
                'customer_id' => $customer->id,
                'mid' => $mid,
                'text' => $msgData['message'] ?? '',
                'attachments' => $msgData['attachments']['data'] ?? null,
                'is_from_customer' => $isFromCustomer,
                'platform' => $platformParam,
                'is_private' => true,
                'created_at' => Carbon::parse($msgData['created_time']),
                'updated_at' => Carbon::parse($msgData['created_time']),
            ]);

            $addedCount++;
        }

        return response()->json([
            'success' => true,
            'added' => $addedCount,
            'message' => "Синхронізовано {$addedCount} повідомлень",
        ]);
    }

}
