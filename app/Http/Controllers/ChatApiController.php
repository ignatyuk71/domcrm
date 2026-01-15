<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\FacebookMessage;
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
}
