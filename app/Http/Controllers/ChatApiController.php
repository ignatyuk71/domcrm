<?php

namespace App\Http\Controllers;

use App\Models\ConversationMeta;
use App\Models\ConversationTag;
use App\Models\FacebookMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\MetaService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ChatApiController extends Controller
{
    public function list()
    {
        try {
            $conversations = Conversation::query()
                ->with(['customer', 'meta', 'tags'])
                ->orderByDesc('last_message_at')
                ->paginate(20);

            if ($conversations->total() === 0) {
                return $this->listFromMessages();
            }

            $conversations->getCollection()->transform(
                fn (Conversation $conversation) => $this->formatConversation($conversation)
            );

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
                'customers.phone',
                'customers.email',
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
                    'first_name' => $message->first_name ?? null,
                    'last_name' => $message->last_name ?? null,
                    'phone' => $message->phone ?? null,
                    'email' => $message->email ?? null,
                    'last_message' => $message->last_message,
                    'last_message_time' => $message->last_message_time,
                    'unread_count' => 0,
                    'platform' => $message->platform,
                    'status' => 'open',
                    'stage' => null,
                    'tags' => [],
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

    public function funnel()
    {
        $conversations = Conversation::query()
            ->with(['customer', 'meta', 'tags'])
            ->orderByDesc('last_message_at')
            ->get();

        $groups = ['none' => []];
        foreach ($this->availableStages() as $stage) {
            $groups[$stage] = [];
        }

        foreach ($conversations as $conversation) {
            $data = $this->formatConversation($conversation);
            $stage = $data['stage'] ?: 'none';
            if (!array_key_exists($stage, $groups)) {
                $groups[$stage] = [];
            }
            $groups[$stage][] = $data;
        }

        return response()->json(['data' => $groups]);
    }

    public function listConversationTags()
    {
        $tags = ConversationTag::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'color', 'icon']);

        return response()->json(['data' => $tags]);
    }

    public function updateStage(Request $request, Conversation $conversation)
    {
        $validated = $request->validate([
            'stage' => ['nullable', 'string', Rule::in($this->availableStages())],
        ]);

        $meta = ConversationMeta::firstOrCreate(['conversation_id' => $conversation->id]);
        $meta->stage = $validated['stage'] ?? null;
        $meta->save();

        return response()->json(['stage' => $meta->stage]);
    }

    public function updateTags(Request $request, Conversation $conversation)
    {
        $validated = $request->validate([
            'tag_ids' => 'array',
            'tag_ids.*' => 'integer|exists:conversation_tags,id',
        ]);

        $tagIds = $validated['tag_ids'] ?? [];
        $conversation->tags()->sync($tagIds);

        $tags = $conversation->tags()
            ->orderBy('name')
            ->get(['id', 'name', 'color', 'icon']);

        return response()->json(['data' => $tags]);
    }

    public function showByCustomer(Request $request, int $customerId)
    {
        $platform = $request->query('platform');

        $query = Conversation::query()
            ->with(['customer', 'meta', 'tags'])
            ->where('customer_id', $customerId);

        if ($platform) {
            $query->where('platform', $platform);
        }

        $conversation = $query->orderByDesc('last_message_at')->first();

        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        return response()->json(['data' => $this->formatConversation($conversation)]);
    }

    private function availableStages(): array
    {
        return [
            'new',
            'waiting_reply',
            'order_confirmed',
            'done',
            'closed',
        ];
    }

    private function formatConversation(Conversation $conversation): array
    {
        $customer = $conversation->customer;
        $name = $customer
            ? trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))
            : '';

        $stage = $conversation->meta?->stage;
        if ($stage && !in_array($stage, $this->availableStages(), true)) {
            $stage = null;
        }

        return [
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'customer_name' => $name !== '' ? $name : 'Невідомий клієнт',
            'customer_avatar' => $customer?->fb_profile_pic,
            'first_name' => $customer?->first_name,
            'last_name' => $customer?->last_name,
            'phone' => $customer?->phone,
            'email' => $customer?->email,
            'last_message' => $conversation->last_message_text,
            'last_message_time' => optional($conversation->last_message_at)->toDateTimeString(),
            'unread_count' => $conversation->unread_count,
            'platform' => $conversation->platform,
            'status' => $conversation->status,
            'stage' => $stage,
            'tags' => $conversation->tags->map(function ($tag) {
                return [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                    'icon' => $tag->icon,
                ];
            }),
        ];
    }

    public function messages($id, MetaService $metaService)
    {
        try {
            $customer = Customer::findOrFail($id);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        $platform = request()->query('platform');

        // Первинна синхронізація тільки якщо немає локальних повідомлень
        try {
            $hasMessages = FacebookMessage::where('customer_id', $id)
                ->when($platform, fn ($q) => $q->where('platform', $platform))
                ->exists();
            $preferredPlatform = $customer->instagram_user_id ? 'instagram' : 'messenger';
            if (
                !$hasMessages
                && method_exists($metaService, 'syncHistory')
                && (!$platform || $platform === $preferredPlatform)
            ) {
                $metaService->syncHistory($customer);
            }
        } catch (\Throwable $e) {
            Log::error('Chat initial sync failed', [
                'customer_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $messages = FacebookMessage::query()
                ->with('parent')
                ->where('customer_id', $id)
                ->when($platform, fn ($q) => $q->where('platform', $platform))
                ->orderByRaw('COALESCE(sent_at, created_at) asc')
                ->get()
                ->map(function (FacebookMessage $message) {
                    $parent = $message->parent;
                    $replyTo = null;
                    if ($parent) {
                        $replyTo = [
                            'text' => $parent->text ?? null,
                            'direction' => $parent->is_from_customer ? 'inbound' : 'outbound',
                            'attachments' => $parent->attachments ?? [],
                        ];
                    }

                    return [
                        'id' => $message->id,
                        'text' => $message->text ?? null,
                        'direction' => $message->is_from_customer ? 'inbound' : 'outbound',
                        'created_at' => ($message->sent_at ?? $message->created_at)?->toDateTimeString(),
                        'attachments' => $message->attachments ?? [],
                        'status' => $message->status ?? null,
                        'is_read' => $message->is_read ?? null,
                        'mid' => $message->mid,
                        'reply_to' => $replyTo,
                    ];
                });

            // Скидаємо лічильники
            Conversation::where('customer_id', $id)
                ->when($platform, fn ($q) => $q->where('platform', $platform))
                ->update(['unread_count' => 0]);
            
            FacebookMessage::where('customer_id', $id)
                ->where('is_from_customer', true)
                ->when($platform, fn ($q) => $q->where('platform', $platform))
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
                'files' => 'nullable|array',
                'files.*' => 'file|mimes:jpg,jpeg,png,webp,gif,heic,heif|max:5120',
                'remote_urls' => 'nullable|array',
                'remote_urls.*' => ['string', 'max:2048', function ($attribute, $value, $fail) {
                    $url = trim((string) $value);
                    if ($url === '') {
                        return;
                    }
                    if (preg_match('/^(javascript|data):/i', $url)) {
                        $fail('Invalid url');
                        return;
                    }
                    if (!str_starts_with($url, 'http') && !str_starts_with($url, '/')) {
                        $fail('Invalid url');
                    }
                }],
                'platform' => 'nullable|string|in:messenger,instagram',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Chat send validation failed', ['payload' => $request->all()]);
            throw $e;
        }

        if (empty($validated['text']) && !$request->hasFile('files') && empty($validated['remote_urls'])) {
            return response()->json(['error' => 'Повідомлення порожнє'], 422);
        }

        $customer = Customer::find($validated['customer_id']);
        
        $platform = $validated['platform'] 
            ?? ($customer->instagram_user_id ? 'instagram' : 'messenger');

        $attachments = [];
        $metaAttachments = [];

        $files = $request->file('files', []);
        if (!is_array($files)) {
            $files = [$files];
        }

        foreach ($files as $file) {
            $datePath = now()->format('Y/m/d');
            $destinationPath = public_path("chat/attachments/{$datePath}");

            if (!is_dir($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            $extension = $file->getClientOriginalExtension() ?: 'jpg';
            $fileName = time().'_'.bin2hex(random_bytes(4)).'.'.strtolower($extension);
            $file->move($destinationPath, $fileName);
            @chmod($destinationPath.'/'.$fileName, 0644);

            $relativeUrl = "chat/attachments/{$datePath}/{$fileName}";
            $attachments[] = ['type' => 'image', 'url' => $relativeUrl];
            $metaAttachments[] = ['type' => 'image', 'url' => url($relativeUrl)];
        }

        if (!empty($validated['remote_urls'])) {
            foreach ($validated['remote_urls'] as $remoteUrl) {
                $remoteUrl = trim((string) $remoteUrl);
                if ($remoteUrl === '') {
                    continue;
                }
                $type = $this->inferRemoteAttachmentType($remoteUrl);
                $attachments[] = ['type' => $type, 'url' => $remoteUrl];
                $metaAttachments[] = [
                    'type' => $type,
                    'url' => str_starts_with($remoteUrl, 'http') ? $remoteUrl : url(ltrim($remoteUrl, '/')),
                ];
            }
        }

        $createdMessages = [];
        $sentAt = Carbon::now(config('app.timezone', 'Europe/Kyiv'));

        try {
            if (!empty($metaAttachments)) {
                foreach ($metaAttachments as $index => $attachment) {
                    $text = $index === 0 ? ($validated['text'] ?? '') : '';

                    $metaResult = $metaService->sendMessage(
                        $customer,
                        $text,
                        [$attachment],
                        $platform
                    );

                    if (!$metaResult) {
                        return response()->json(['error' => 'Не вдалося відправити повідомлення через Meta API.'], 500);
                    }

                    $mid = is_array($metaResult) ? ($metaResult['message_id'] ?? null) : null;
                    if (!$mid) {
                        $mid = 'local-'.uniqid('', true);
                    }

                    $message = FacebookMessage::create([
                        'customer_id' => $customer->id,
                        'mid' => $mid,
                        'text' => $text !== '' ? $text : null,
                        'attachments' => $attachments[$index] ? [$attachments[$index]] : null,
                        'is_from_customer' => false,
                        'platform' => $platform,
                        'is_private' => true,
                        'sent_at' => $sentAt,
                        'status' => 'sent',
                        'is_read' => true,
                        'created_at' => $sentAt,
                        'updated_at' => $sentAt,
                    ]);

                    $createdMessages[] = [
                        'id' => $message->id,
                        'text' => $message->text ?? null,
                        'direction' => 'outbound',
                        'created_at' => $sentAt->toDateTimeString(),
                        'attachments' => $message->attachments ?? [],
                        'status' => $message->status,
                        'is_read' => $message->is_read,
                    ];
                }
            } else {
                $metaResult = $metaService->sendMessage(
                    $customer,
                    $validated['text'] ?? '',
                    [],
                    $platform
                );

                if (!$metaResult) {
                    return response()->json(['error' => 'Не вдалося відправити повідомлення через Meta API.'], 500);
                }

                $mid = is_array($metaResult) ? ($metaResult['message_id'] ?? null) : null;
                if (!$mid) {
                    $mid = 'local-'.uniqid('', true);
                }

                $message = FacebookMessage::create([
                    'customer_id' => $customer->id,
                    'mid' => $mid,
                    'text' => $validated['text'] ?? null,
                    'attachments' => null,
                    'is_from_customer' => false,
                    'platform' => $platform,
                    'is_private' => true,
                    'sent_at' => $sentAt,
                    'status' => 'sent',
                    'is_read' => true,
                    'created_at' => $sentAt,
                    'updated_at' => $sentAt,
                ]);

                $createdMessages[] = [
                    'id' => $message->id,
                    'text' => $message->text ?? null,
                    'direction' => 'outbound',
                    'created_at' => $sentAt->toDateTimeString(),
                    'attachments' => $message->attachments ?? [],
                    'status' => $message->status,
                    'is_read' => $message->is_read,
                ];
            }

            $lastText = $createdMessages[0]['text'] ?? null;
            $metaService->touchConversation(
                $customer->id,
                $platform,
                $lastText ?? (!empty($attachments) ? 'Вкладення' : ''),
                $sentAt,
                false
            );

            return response()->json(['data' => $createdMessages]);
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
            'platform' => 'nullable|string|in:messenger,instagram',
        ]);

        try {
            Conversation::where('customer_id', $validated['customer_id'])
                ->when(!empty($validated['platform']), fn ($q) => $q->where('platform', $validated['platform']))
                ->update(['unread_count' => 0]);

            FacebookMessage::where('customer_id', $validated['customer_id'])
                ->where('is_from_customer', true)
                ->when(!empty($validated['platform']), fn ($q) => $q->where('platform', $validated['platform']))
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
        $platform = $request->query('platform');

        try {
            $baseQuery = FacebookMessage::query()
                ->with('parent')
                ->where('customer_id', $id)
                ->when($platform, fn ($q) => $q->where('platform', $platform));

            if ($sinceId > 0) {
                $messages = (clone $baseQuery)
                    ->where('id', '>', $sinceId)
                    ->orderByRaw('COALESCE(sent_at, created_at) asc')
                    ->get();
            } else {
                $messages = (clone $baseQuery)
                    ->orderByRaw('COALESCE(sent_at, created_at) desc')
                    ->limit(50)
                    ->get()
                    ->reverse()
                    ->values();
            }

            $normalizedMessages = $messages->map(function (FacebookMessage $message) {
                // 🔥 ДОДАНО: Логіка формування відповіді (цитати)
                $parent = $message->parent;
                $replyTo = null;
                if ($parent) {
                    $replyTo = [
                        'text' => $parent->text ?? null,
                        'direction' => $parent->is_from_customer ? 'inbound' : 'outbound',
                        'attachments' => $parent->attachments ?? [],
                    ];
                }

                return [
                    'id' => $message->id,
                    'text' => $message->text ?? null,
                    'direction' => $message->is_from_customer ? 'inbound' : 'outbound',
                    'created_at' => ($message->sent_at ?? $message->created_at)?->toDateTimeString(),
                    'attachments' => $message->attachments ?? [],
                    'status' => $message->status ?? null,
                    'is_read' => $message->is_read ?? null,
                    'reply_to' => $replyTo, // 🔥 ДОДАНО: повертаємо об'єкт цитати
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

    public function sync($id, MetaService $metaService)
    {
        try {
            $customer = Customer::findOrFail($id);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        try {
            $count = $metaService->syncHistory($customer);
        } catch (\Throwable $e) {
            Log::error('Chat force sync failed', [
                'customer_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false], 500);
        }

        return response()->json(['success' => true, 'count' => $count]);
    }

    public function getUnreadCount()
    {
        $count = Conversation::where('unread_count', '>', 0)->count();

        return response()->json(['count' => $count]);
    }

    public function refreshProfile(int $id, MetaService $metaService): JsonResponse
    {
        try {
            $customer = Customer::findOrFail($id);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        $metaService->updateCustomerProfile($customer);
        $customer->refresh();

        return response()->json([
            'data' => [
                'id' => $customer->id,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'fb_profile_pic' => $customer->fb_profile_pic,
                'fb_user_id' => $customer->fb_user_id,
                'instagram_user_id' => $customer->instagram_user_id,
            ],
        ]);
    }

    private function inferRemoteAttachmentType(string $url): string
    {
        $lower = strtolower(parse_url($url, PHP_URL_PATH) ?? $url);
        if (preg_match('/\.(mp4|mov|webm)$/i', $lower)) {
            return 'video';
        }
        if (preg_match('/\.(mp3|wav|ogg)$/i', $lower)) {
            return 'audio';
        }
        if (preg_match('/\.(pdf|doc|docx|xls|xlsx)$/i', $lower)) {
            return 'file';
        }
        return 'image';
    }
}
