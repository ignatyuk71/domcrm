<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\MetaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ChatApiController extends Controller
{
    public function list()
    {
        try {
            if (!Schema::hasTable('conversations') || !Schema::hasColumn('conversations', 'last_message_at')) {
                if (!Schema::hasTable('facebook_messages')) {
                    return response()->json(['data' => []]);
                }

                $hasAvatar = Schema::hasColumn('customers', 'fb_profile_pic');
                $selectColumns = [
                    'messages.customer_id',
                    'messages.text as last_message',
                    'messages.created_at as last_message_time',
                    'messages.platform',
                    'customers.first_name',
                    'customers.last_name',
                ];
                if ($hasAvatar) {
                    $selectColumns[] = 'customers.fb_profile_pic';
                }

                $latestMessages = DB::table('facebook_messages as messages')
                    ->join(
                        DB::raw('(SELECT customer_id, MAX(id) AS last_id FROM facebook_messages GROUP BY customer_id) AS latest'),
                        'messages.id',
                        '=',
                        'latest.last_id'
                    )
                    ->leftJoin('customers as customers', 'customers.id', '=', 'messages.customer_id')
                    ->orderByDesc('messages.created_at')
                    ->get($selectColumns);

                $data = $latestMessages->map(function ($message) use ($hasAvatar) {
                    $name = trim(($message->first_name ?? '').' '.($message->last_name ?? ''));

                    return [
                        'conversation_id' => null,
                        'customer_id' => (int) $message->customer_id,
                        'customer_name' => $name !== '' ? $name : 'Невідомий клієнт',
                        'customer_avatar' => $hasAvatar ? ($message->fb_profile_pic ?? null) : null,
                        'last_message' => $message->last_message,
                        'last_message_time' => $message->last_message_time,
                        'unread_count' => 0,
                        'platform' => $message->platform,
                        'status' => 'open',
                    ];
                });

                return response()->json(['data' => $data]);
            }

            $conversations = Conversation::query()
                ->with('customer')
                ->orderByDesc('last_message_at')
                ->paginate(20);

            $conversations->getCollection()->transform(function (Conversation $conversation) {
                $customer = $conversation->customer;
                $name = $customer
                    ? trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))
                    : '';

                return [
                    'conversation_id' => $conversation->id,
                    'customer_id' => $conversation->customer_id,
                    'customer_name' => $name !== '' ? $name : 'Невідомий клієнт',
                    // Додаємо аватарку, якщо є
                    'customer_avatar' => $customer?->fb_profile_pic,
                    'last_message' => $conversation->last_message_text,
                    'last_message_time' => optional($conversation->last_message_at)->toDateTimeString(),
                    'unread_count' => $conversation->unread_count,
                    'platform' => $conversation->platform,
                    'status' => $conversation->status,
                ];
            });

            return response()->json($conversations);
        } catch (\Throwable $e) {
            Log::error('Chat list failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['data' => []]);
        }
    }

    public function messages($id, MetaService $metaService)
    {
        try {
            $customer = Customer::findOrFail($id);
        } catch (\Throwable $e) {
            Log::error('Chat messages customer lookup failed', [
                'customer_id' => $id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Синхронізуємо історію (обережно, це може сповільнити відповідь)
        try {
            // Переконайся, що метод syncHistory існує в MetaService, 
            // або використовуй getConversationHistory, якщо ми його так назвали
            if (method_exists($metaService, 'syncHistory')) {
                $metaService->syncHistory($customer);
            }
        } catch (\Throwable $e) {
            Log::error('Chat sync history failed', [
                'customer_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $messages = ChatMessage::query()
                ->where('customer_id', $id)
                ->orderByRaw('COALESCE(sent_at, created_at) asc')
                ->get()
                ->map(function (ChatMessage $message) {
                    return [
                        'id' => $message->id,
                        'text' => $message->text ?? null,
                        'direction' => $message->is_from_customer ? 'inbound' : 'outbound',
                        'created_at' => ($message->sent_at ?? $message->created_at)?->toDateTimeString(),
                        'attachments' => $message->attachments ?? [],
                        'status' => $message->status ?? null,
                        'is_read' => $message->is_read ?? null,
                        'mid' => $message->mid, // Корисно для дебагу
                    ];
                });
        } catch (\Throwable $e) {
            Log::error('Chat messages query failed', [
                'customer_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        // Скидаємо лічильники
        Conversation::where('customer_id', $id)->update(['unread_count' => 0]);
        
        // Позначаємо вхідні повідомлення як прочитані в нашій БД
        ChatMessage::where('customer_id', $id)
            ->where('is_from_customer', true)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['data' => $messages]);
    }

    public function send(Request $request, MetaService $metaService)
    {
        try {
            $validated = $request->validate([
                'customer_id' => 'required|integer|exists:customers,id',
                'text' => 'nullable|string',
                'attachments' => 'array',
                // Важливо: дозволяємо фронту вказати платформу явно, 
                // бо в одного клієнта може бути і FB, і Insta
                'platform' => 'nullable|string|in:messenger,instagram',
            ]);
        } catch (\Throwable $e) {
            Log::error('Chat send validation failed', [
                'payload' => $request->all(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        if (empty($validated['text']) && empty($validated['attachments'])) {
            return response()->json(['error' => 'Повідомлення порожнє'], 422);
        }

        try {
            $customer = Customer::findOrFail($validated['customer_id']);
        } catch (\Throwable $e) {
            Log::error('Chat send customer lookup failed', [
                'customer_id' => $validated['customer_id'],
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
        
        // Визначаємо платформу: пріоритет з запиту -> потім Instagram -> потім Messenger
        $platform = $validated['platform'] 
            ?? ($customer->instagram_user_id ? 'instagram' : 'messenger');

        // Спроба відправки через сервіс
        try {
            $metaResult = $metaService->sendMessage(
                $customer,
                $validated['text'] ?? '',
                $validated['attachments'] ?? [],
                $platform // Передаємо платформу в сервіс
            );
        } catch (\Throwable $e) {
            Log::error('Chat send Meta API failed', [
                'customer_id' => $customer->id,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Якщо сервіс повернув false або null (помилка API)
        if (!$metaResult) {
            return response()->json(['error' => 'Не вдалося відправити повідомлення через Meta API. Перевірте 24-годинне вікно або токен.'], 500);
        }

        // Отримуємо MID. Якщо сервіс повертає масив ['message_id' => '...'], беремо його.
        // Якщо просто true (стара версія сервісу), ставимо null або генеруємо тимчасовий.
        $mid = is_array($metaResult) ? ($metaResult['message_id'] ?? null) : null;

        $sentAt = Carbon::now(config('app.timezone', 'Europe/Kyiv'));

        try {
            $message = ChatMessage::create([
                'customer_id' => $customer->id,
                'mid' => $mid, 
                'text' => $validated['text'] ?? null,
                'attachments' => $validated['attachments'] ?? null,
                'is_from_customer' => false,
                'platform' => $platform,
                'is_private' => true,
                'sent_at' => $sentAt,
                'status' => 'sent', // Статус "відправлено" (успішно пішло на API)
                'is_read' => true,
                'created_at' => $sentAt,
                'updated_at' => $sentAt,
            ]);
        } catch (\Throwable $e) {
            Log::error('Chat send save failed', [
                'customer_id' => $customer->id,
                'platform' => $platform,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        try {
            $metaService->touchConversation(
                $customer->id,
                $platform,
                $validated['text'] ?? (empty($validated['attachments']) ? '' : 'Вкладення'),
                $sentAt,
                false
            );
        } catch (\Throwable $e) {
            Log::error('Chat send touch conversation failed', [
                'customer_id' => $customer->id,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $message->id,
                'text' => $message->text ?? null,
                'direction' => 'outbound',
                'created_at' => $sentAt->toDateTimeString(),
                'attachments' => $message->attachments ?? [],
                'status' => $message->status,
                'is_read' => $message->is_read,
            ],
        ]);
    }

    public function markRead(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        try {
            Conversation::where('customer_id', $validated['customer_id'])->update(['unread_count' => 0]);

            ChatMessage::where('customer_id', $validated['customer_id'])
                ->where('is_from_customer', true)
                ->update(['is_read' => true]);
        } catch (\Throwable $e) {
            Log::error('Chat markRead failed', [
                'customer_id' => $validated['customer_id'],
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return response()->json(['success' => true]);
    }

    public function updates(Request $request, $id)
    {
        $sinceId = (int) $request->query('since_id');

        if ($sinceId <= 0) {
            return response()->json([
                'messages' => [],
                'thread' => null,
                'has_updates' => false,
            ]);
        }

        try {
            $messages = ChatMessage::query()
                ->where('customer_id', $id)
                ->where('id', '>', $sinceId)
                ->orderByRaw('COALESCE(sent_at, created_at) asc')
                ->get();
        } catch (\Throwable $e) {
            Log::error('Chat updates query failed', [
                'customer_id' => $id,
                'since_id' => $sinceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        $normalizedMessages = $messages->map(function (ChatMessage $message) {
            return [
                'id' => $message->id,
                'text' => $message->text ?? null,
                'direction' => $message->is_from_customer ? 'inbound' : 'outbound',
                'created_at' => ($message->sent_at ?? $message->created_at)?->toDateTimeString(),
                'attachments' => $message->attachments ?? [],
                'status' => $message->status ?? null,
                'is_read' => $message->is_read ?? null,
            ];
        });

        $lastMessage = $messages->last();

        return response()->json([
            'messages' => $normalizedMessages,
            'thread' => $lastMessage ? [
                'id' => (int) $id,
                'last_message_text' => $lastMessage->text ?? null,
                'last_message_at' => ($lastMessage->sent_at ?? $lastMessage->created_at)?->toDateTimeString(),
            ] : null,
            'has_updates' => $messages->isNotEmpty(),
        ]);
    }
}
