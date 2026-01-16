<?php

namespace App\Http\Controllers;

use App\Models\FacebookMessage;
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
            $conversations = Conversation::query()
                ->with('customer')
                ->orderByDesc('last_message_at')
                ->paginate(20);

            if ($conversations->total() === 0) {
                return $this->listFromMessages();
            }

            Log::info('Chat list conversations', [
                'total' => $conversations->total(),
            ]);

            $conversations->getCollection()->transform(function (Conversation $conversation) {
                $customer = $conversation->customer;
                $name = $customer
                    ? trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))
                    : '';

                return [
                    'conversation_id' => $conversation->id,
                    'customer_id' => $conversation->customer_id,
                    'customer_name' => $name !== '' ? $name : 'Невідомий клієнт',
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
            return $this->listFromMessages();
        }
    }

    private function listFromMessages()
    {
        try {
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

            Log::info('Chat list fallback messages', [
                'count' => $latestMessages->count(),
            ]);

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
        } catch (\Throwable $e) {
            Log::error('Chat list fallback failed', [
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
            return response()->json(['error' => 'Customer not found'], 404);
        }

        // Синхронізуємо історію
        try {
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
            $messages = FacebookMessage::query()
                ->where('customer_id', $id)
                ->orderByRaw('COALESCE(sent_at, created_at) asc')
                ->get()
                ->map(function (FacebookMessage $message) {
                    return [
                        'id' => $message->id,
                        'text' => $message->text ?? null,
                        'direction' => $message->is_from_customer ? 'inbound' : 'outbound',
                        'created_at' => ($message->sent_at ?? $message->created_at)?->toDateTimeString(),
                        'attachments' => $message->attachments ?? [],
                        'status' => $message->status ?? null,
                        'is_read' => $message->is_read ?? null,
                        'mid' => $message->mid,
                    ];
                });

            // Скидаємо лічильники
            Conversation::where('customer_id', $id)->update(['unread_count' => 0]);
            
            FacebookMessage::where('customer_id', $id)
                ->where('is_from_customer', true)
                ->where('is_read', false)
                ->update(['is_read' => true]);

            return response()->json(['data' => $messages]);

        } catch (\Throwable $e) {
            Log::error('Chat messages query failed', [
                'customer_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    }

    public function send(Request $request, MetaService $metaService)
    {
        try {
            $validated = $request->validate([
                'customer_id' => 'required|integer|exists:customers,id',
                'text' => 'nullable|string',
                'attachments' => 'array',
                'platform' => 'nullable|string|in:messenger,instagram',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Chat send validation failed', ['payload' => $request->all()]);
            throw $e;
        }

        if (empty($validated['text']) && empty($validated['attachments'])) {
            return response()->json(['error' => 'Повідомлення порожнє'], 422);
        }

        $customer = Customer::find($validated['customer_id']);
        
        $platform = $validated['platform'] 
            ?? ($customer->instagram_user_id ? 'instagram' : 'messenger');

        try {
            $metaResult = $metaService->sendMessage(
                $customer,
                $validated['text'] ?? '',
                $validated['attachments'] ?? [],
                $platform
            );
        } catch (\Throwable $e) {
            Log::error('Chat send Meta API failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Meta API Error'], 500);
        }

        if (!$metaResult) {
            return response()->json(['error' => 'Не вдалося відправити повідомлення через Meta API.'], 500);
        }

        $mid = is_array($metaResult) ? ($metaResult['message_id'] ?? null) : null;
        if (!$mid) {
            $mid = 'local-'.uniqid('', true);
        }
        $sentAt = Carbon::now(config('app.timezone', 'Europe/Kyiv'));

        try {
            $message = FacebookMessage::create([
                'customer_id' => $customer->id,
                'mid' => $mid, 
                'text' => $validated['text'] ?? null,
                'attachments' => $validated['attachments'] ?? null,
                'is_from_customer' => false,
                'platform' => $platform,
                'is_private' => true,
                'sent_at' => $sentAt,
                'status' => 'sent',
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

        } catch (\Throwable $e) {
            Log::error('Chat send save DB failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'DB Error'], 500);
        }
    }

    public function markRead(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        try {
            Conversation::where('customer_id', $validated['customer_id'])->update(['unread_count' => 0]);

            FacebookMessage::where('customer_id', $validated['customer_id'])
                ->where('is_from_customer', true)
                ->update(['is_read' => true]);
        } catch (\Throwable $e) {
            Log::error('Chat markRead failed', [
                'customer_id' => $validated['customer_id'],
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Error'], 500);
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
            $messages = FacebookMessage::query()
                ->where('customer_id', $id)
                ->where('id', '>', $sinceId)
                ->orderByRaw('COALESCE(sent_at, created_at) asc')
                ->get();

            $normalizedMessages = $messages->map(function (FacebookMessage $message) {
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

        } catch (\Throwable $e) {
            Log::error('Chat updates failed', [
                'customer_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Error'], 500);
        }
    }
}
