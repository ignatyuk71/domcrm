<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer; // Клієнт, з яким іде чат
use App\Models\ChatMessage; // Модель повідомлень
use App\Services\MetaChatService;
use Illuminate\Http\JsonResponse;

class ChatController extends Controller
{
    /**
     * GET /api/chat/list
     * Отримання списку чатів (conversations)
     */
    public function index(Request $request): JsonResponse
    {
        // Ваш useChat очікує дані у форматі { data: [...], current_page: 1, last_page: 5 }
        $chats = Customer::query()
            ->has('chatMessages') // Тільки ті, у кого є повідомлення
            ->withLastChatMessage() // Кастомний scope для отримання останнього повідомлення
            ->orderBy('last_message_at', 'desc')
            ->paginate(20);

        return response()->json($chats);
    }

    /**
     * GET /api/chat/{customerId}/messages
     * Отримання історії повідомлень для конкретного клієнта
     */
    public function messages($customerId): JsonResponse
    {
        $messages = ChatMessage::where('customer_id', $customerId)
            ->orderBy('created_at', 'asc') // Від старіших до новіших
            ->get();

        // useChat очікує масив або { data: [...] }
        return response()->json([
            'data' => $messages
        ]);
    }

    /**
     * POST /api/chat/send
     * Відправка повідомлення клієнту
     */
    public function send(Request $request, MetaChatService $metaService): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required',
            'message' => 'required|string',
        ]);

        // Відправка через Meta API
        $metaResponse = $metaService->sendMessage(
            $validated['customer_id'], 
            $validated['message']
        );

        // Збереження в локальну базу
        $message = ChatMessage::create([
            'customer_id' => $validated['customer_id'],
            'text' => $validated['message'],
            'direction' => 'outbound',
            'meta_id' => $metaResponse['id'] ?? null,
            'created_at' => now(),
        ]);

        return response()->json([
            'data' => $message
        ]);
    }

    /**
     * GET /api/chat/threads/{threadId}/messages/updates
     * Полінг нових повідомлень (використовує since_id)
     */
    public function updates(Request $request, $threadId): JsonResponse
    {
        $sinceId = $request->query('since_id');

        $newMessages = ChatMessage::where('customer_id', $threadId)
            ->where('id', '>', $sinceId)
            ->where('direction', 'inbound')
            ->get();

        // Оновлюємо дані чату в сайдбарі (останнє повідомлення)
        $thread = null;
        if ($newMessages->isNotEmpty()) {
            $last = $newMessages->last();
            $thread = [
                'id' => $threadId,
                'last_message_text' => $last->text,
                'last_message_at' => $last->created_at->toISOString(),
            ];
        }

        return response()->json([
            'messages' => $newMessages,
            'thread' => $thread
        ]);
    }

    /**
     * POST /api/chat/{customerId}/sync
     * Примусова синхронізація з Meta
     */
    public function sync($customerId, MetaChatService $metaService): JsonResponse
    {
        $metaService->syncHistory($customerId);
        
        return response()->json([
            'message' => 'Синхронізація виконана успішно'
        ]);
    }
}