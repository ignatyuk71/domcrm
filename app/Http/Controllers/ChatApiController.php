<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\MetaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatApiController extends Controller
{
    public function list()
    {
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
    }

    public function messages($id, MetaService $metaService)
    {
        $customer = Customer::findOrFail($id);

        // Синхронізуємо історію (обережно, це може сповільнити відповідь)
        try {
            // Переконайся, що метод syncHistory існує в MetaService, 
            // або використовуй getConversationHistory, якщо ми його так назвали
            if (method_exists($metaService, 'syncHistory')) {
                $metaService->syncHistory($customer);
            }
        } catch (\Throwable $e) {
            // Логуємо, але не ламаємо інтерфейс, якщо FB лежить
            Log::error("Failed to sync history for customer {$id}: " . $e->getMessage());
        }

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
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'text' => 'nullable|string',
            'attachments' => 'array',
            // Важливо: дозволяємо фронту вказати платформу явно, 
            // бо в одного клієнта може бути і FB, і Insta
            'platform' => 'nullable|string|in:messenger,instagram',
        ]);

        if (empty($validated['text']) && empty($validated['attachments'])) {
            return response()->json(['error' => 'Повідомлення порожнє'], 422);
        }

        $customer = Customer::findOrFail($validated['customer_id']);
        
        // Визначаємо платформу: пріоритет з запиту -> потім Instagram -> потім Messenger
        $platform = $validated['platform'] 
            ?? ($customer->instagram_user_id ? 'instagram' : 'messenger');

        // Спроба відправки через сервіс
        $metaResult = $metaService->sendMessage(
            $customer,
            $validated['text'] ?? '',
            $validated['attachments'] ?? [],
            $platform // Передаємо платформу в сервіс
        );

        // Якщо сервіс повернув false або null (помилка API)
        if (!$metaResult) {
            return response()->json(['error' => 'Не вдалося відправити повідомлення через Meta API. Перевірте 24-годинне вікно або токен.'], 500);
        }

        // Отримуємо MID. Якщо сервіс повертає масив ['message_id' => '...'], беремо його.
        // Якщо просто true (стара версія сервісу), ставимо null або генеруємо тимчасовий.
        $mid = is_array($metaResult) ? ($metaResult['message_id'] ?? null) : null;

        $sentAt = Carbon::now(config('app.timezone', 'Europe/Kyiv'));

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

        $metaService->touchConversation(
            $customer->id,
            $platform,
            $validated['text'] ?? (empty($validated['attachments']) ? '' : 'Вкладення'),
            $sentAt,
            false
        );

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

        Conversation::where('customer_id', $validated['customer_id'])->update(['unread_count' => 0]);
        
        ChatMessage::where('customer_id', $validated['customer_id'])
            ->where('is_from_customer', true)
            ->update(['is_read' => true]);

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

        $messages = ChatMessage::query()
            ->where('customer_id', $id)
            ->where('id', '>', $sinceId)
            ->orderByRaw('COALESCE(sent_at, created_at) asc')
            ->get();

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