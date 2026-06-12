<?php

namespace App\Http\Controllers;

use App\Models\ChatStatus;
use App\Models\Customer;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Models\Order;
use App\Models\SavedFile;
use App\Services\Meta\MetaSendService;
use App\Services\Meta\MetaSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class InboxController extends Controller
{
    public function index()
    {
        return view('inbox.index');
    }

    /** Імпорт історії розмов з Meta (Graph API) для всіх активних підключень. */
    public function sync(MetaSyncService $sync)
    {
        $imported = 0;
        foreach (MetaConnection::where('status', 'active')->get() as $conn) {
            $imported += $sync->syncConnection($conn);
        }

        return response()->json(['ok' => true, 'imported' => $imported]);
    }

    /** Список діалогів для лівої панелі (пачками: offset/limit + has_more). */
    public function conversations(Request $request)
    {
        $limit = min(max((int) $request->query('limit', 25), 1), 200);
        $offset = max((int) $request->query('offset', 0), 0);

        $items = InboxConversation::query()
            ->with(['connection:id,page_name,page_id', 'contact:id,name,external_id,profile_pic'])
            ->orderByDesc('last_message_at')
            ->skip($offset)
            ->take($limit + 1) // +1, щоб дізнатись чи є ще
            ->get();

        $hasMore = $items->count() > $limit;

        $data = $items->take($limit)->map(fn (InboxConversation $c) => [
            'id' => $c->id,
            'store' => $c->connection?->page_name ?? '—',
            'store_id' => $c->connection?->page_id,
            'channel' => $c->channel,
            'contact_name' => $this->contactName($c->contact?->name, $c->contact?->external_id),
            'avatar' => $c->contact?->profile_pic,
            'last_text' => $c->last_message_text,
            'last_direction' => $c->last_message_direction,
            'last_at_human' => $this->shortTime($c->last_message_at),
            'unread' => (int) $c->unread_count,
            'chat_status_id' => $c->chat_status_id,
        ])->values();

        return response()->json(['data' => $data, 'has_more' => $hasMore]);
    }

    /** Повідомлення діалогу + позначити прочитаним. */
    public function messages(InboxConversation $conversation)
    {
        $conversation->loadMissing(['connection:id,page_name', 'contact:id,name,external_id,profile_pic']);

        $items = $conversation->messages()
            ->orderBy('sent_at')
            ->orderBy('id')
            ->limit(500)
            ->get();

        // Цитати: mid → процитоване повідомлення (одним запитом по розмові)
        $quotedMids = $items->pluck('context.mid')->filter()->unique()->values();
        $quoted = $quotedMids->isEmpty()
            ? collect()
            : $conversation->messages()->whereIn('external_message_id', $quotedMids)->get()->keyBy('external_message_id');

        $messages = $items->map(function (InboxMessage $m) use ($quoted) {
            $ctx = null;
            $c = $m->context;
            if ($c) {
                if (($c['type'] ?? '') === 'reply') {
                    $q = $quoted[$c['mid'] ?? ''] ?? null;
                    $qImage = $q ? collect($q->attachments ?? [])->first(fn ($a) => !empty($a['url']))['url'] ?? null : null;
                    $ctx = [
                        'type' => 'reply',
                        'text' => $q ? mb_substr((string) ($q->text ?: ($qImage ? 'фото' : 'повідомлення')), 0, 120) : 'повідомлення',
                        'image' => $qImage,
                    ];
                } else {
                    $ctx = [
                        'type' => $c['type'],
                        'image' => !empty($c['local']) ? url($c['local']) : ($c['url'] ?? null),
                        'label' => ($c['type'] ?? '') === 'story' ? 'Відповідь на сторіс' : 'Пересланий пост',
                    ];
                }
            }

            return [
                'id' => $m->id,
                'direction' => $m->direction,
                'sender' => $m->sender,
                'text' => $m->text,
                'attachments' => $m->attachments ?? [],
                'context' => $ctx,
                'sent_at_human' => ($m->sent_at ?? $m->created_at)?->format('d.m H:i'),
            ];
        });

        $conversation->update(['unread_count' => 0]);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'store' => $conversation->connection?->page_name ?? '—',
                'store_id' => $conversation->connection?->page_id,
                'conn_id' => $conversation->connection?->id,
                'channel' => $conversation->channel,
                'chat_status_id' => $conversation->chat_status_id,
                'ai_enabled' => (bool) $conversation->ai_enabled,
                'contact_name' => $this->contactName($conversation->contact?->name, $conversation->contact?->external_id),
                'avatar' => $conversation->contact?->profile_pic,
            ],
            'messages' => $messages,
        ]);
    }

    /** Відповісти клієнту (Send API) + зберегти вихідне. */
    public function send(Request $request, InboxConversation $conversation)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);

        $conversation->loadMissing(['connection', 'contact']);
        $conn = $conversation->connection;
        $contact = $conversation->contact;

        if (!$conn || !$contact) {
            return response()->json(['ok' => false, 'error' => 'Немає підключення або контакту'], 422);
        }

        $res = app(MetaSendService::class)->sendText($conn, $contact->external_id, $data['text']);
        if (!($res['ok'] ?? false)) {
            return response()->json(['ok' => false, 'error' => $res['error'] ?? 'Помилка відправки'], 502);
        }

        $msg = InboxMessage::create([
            'inbox_conversation_id' => $conversation->id,
            'direction' => 'out',
            'sender' => 'agent',
            'external_message_id' => $res['message_id'] ?? null,
            'text' => $data['text'],
            'sent_at' => now(),
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'last_message_text' => mb_substr($data['text'], 0, 480),
            'last_message_direction' => 'out',
            'unread_count' => 0,
        ]);

        return response()->json([
            'ok' => true,
            'message' => [
                'id' => $msg->id,
                'direction' => 'out',
                'sender' => 'agent',
                'text' => $msg->text,
                'attachments' => [],
                'sent_at_human' => now()->format('d.m H:i'),
            ],
        ]);
    }

    /** Надіслати щойно завантажений файл (фото/файл) клієнту. */
    public function sendAttachment(Request $request, InboxConversation $conversation)
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        $file = $request->file('file');
        $mime = (string) $file->getMimeType();
        $ext = $file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'bin');
        $name = Str::random(24) . '.' . $ext;
        $file->move(public_path('inbox-uploads'), $name);
        $type = str_starts_with($mime, 'image/') ? 'image' : 'file';

        return $this->dispatchAttachment($conversation, $type, url('inbox-uploads/' . $name));
    }

    /** Надіслати зображення з галереї (SavedFile, вже на сервері) клієнту. */
    public function sendGallery(Request $request, InboxConversation $conversation)
    {
        $data = $request->validate(['id' => ['required', 'integer']]);
        $file = SavedFile::find($data['id']);
        if (!$file) {
            return response()->json(['ok' => false, 'error' => 'Зображення не знайдено'], 404);
        }

        $type = $file->type === 'video' ? 'video' : 'image';

        return $this->dispatchAttachment($conversation, $type, url($file->path));
    }

    /** Спільна логіка: відправити вкладення за публічним URL + зберегти вихідне повідомлення. */
    private function dispatchAttachment(InboxConversation $conversation, string $type, string $url)
    {
        $conversation->loadMissing(['connection', 'contact']);
        $conn = $conversation->connection;
        $contact = $conversation->contact;
        if (!$conn || !$contact) {
            return response()->json(['ok' => false, 'error' => 'Немає підключення або контакту'], 422);
        }

        $res = app(MetaSendService::class)->sendAttachment($conn, $contact->external_id, $type, $url);
        if (!($res['ok'] ?? false)) {
            return response()->json(['ok' => false, 'error' => $res['error'] ?? 'Помилка відправки'], 502);
        }

        InboxMessage::create([
            'inbox_conversation_id' => $conversation->id,
            'direction' => 'out',
            'sender' => 'agent',
            'external_message_id' => $res['message_id'] ?? null,
            'text' => null,
            'attachments' => [['type' => $type, 'url' => $url]],
            'sent_at' => now(),
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'last_message_text' => '[вкладення]',
            'last_message_direction' => 'out',
            'unread_count' => 0,
        ]);

        return response()->json(['ok' => true]);
    }

    /** Кнопка «оновити»: очистити розмову й перезалити історію контакту з Meta з нуля. */
    public function refresh(InboxConversation $conversation, MetaSyncService $sync)
    {
        $conversation->loadMissing(['connection', 'contact']);
        if (!$conversation->connection || !$conversation->contact) {
            return response()->json(['ok' => false, 'error' => 'Немає підключення або контакту'], 422);
        }

        $imported = $sync->syncContactConversation($conversation->connection, $conversation->contact, 100, true);
        if ($imported === false) {
            return response()->json(['ok' => false, 'error' => 'Facebook не відповів — чат не чіпали, спробуйте ще раз'], 502);
        }

        return response()->json(['ok' => true, 'imported' => $imported]);
    }

    /** Очистити чат: видалити всі повідомлення розмови з бази. */
    public function clear(InboxConversation $conversation)
    {
        $conversation->messages()->delete();
        $conversation->update([
            'last_message_at' => null,
            'last_message_text' => null,
            'last_message_direction' => null,
            'unread_count' => 0,
        ]);

        return response()->json(['ok' => true]);
    }

    /** Видалити чат повністю: повідомлення + розмова + контакт. */
    public function destroy(InboxConversation $conversation)
    {
        $contact = $conversation->contact;
        $conversation->messages()->delete();
        $conversation->delete();
        $contact?->delete();

        return response()->json(['ok' => true]);
    }

    /** Права панель: привʼязаний клієнт + його замовлення. */
    public function panel(InboxConversation $conversation)
    {
        $conversation->loadMissing('contact');
        $contact = $conversation->contact;
        $customer = $contact?->customer_id ? Customer::find($contact->customer_id) : null;

        $orders = collect();
        if ($customer) {
            $orders = Order::query()
                ->where('customer_id', $customer->id)
                ->with(['statusRef:id,name,color', 'items:id,order_id,product_id,product_title,size,qty', 'items.product:id,title,main_photo_path', 'delivery:id,order_id,ttn'])
                ->withSum('items as items_total', 'total')
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(fn (Order $o) => [
                    'id' => $o->id,
                    'number' => $o->order_number,
                    'status' => $o->statusRef?->name ?? $o->status,
                    'status_color' => $o->statusRef?->color,
                    'total' => (float) ($o->items_total ?? 0),
                    'date' => $o->created_at?->format('d.m.Y'),
                    'ttn' => $o->delivery?->ttn,
                    'items' => $o->items->map(fn ($it) => [
                        'title' => $it->product_title ?: $it->product?->title,
                        'size' => $it->size,
                        'qty' => (int) $it->qty,
                        'photo' => $it->product?->main_photo_url,
                    ])->values(),
                ]);
        }

        return response()->json([
            'customer' => $customer ? [
                'id' => $customer->id,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'phone' => $customer->phone,
            ] : null,
            'orders' => $orders,
        ]);
    }

    /** Зберегти клієнта (пошук за телефоном або створення) і привʼязати до контакту чату. */
    public function attachCustomer(Request $request, InboxConversation $conversation)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255', "regex:/^[\p{Cyrillic}'’ʼ\-\s]+$/u"],
            'last_name' => ['nullable', 'string', 'max:255', "regex:/^[\p{Cyrillic}'’ʼ\-\s]+$/u"],
            'phone' => ['required', 'string', 'max:32', 'regex:/^(\+?38)?0\d{9}$/'],
        ], [
            'first_name.regex' => 'Імʼя — лише кирилицею (укр/рос)',
            'last_name.regex' => 'Прізвище — лише кирилицею (укр/рос)',
            'phone.regex' => 'Телефон у форматі 0XXXXXXXXX або +380XXXXXXXXX',
        ]);

        $customer = Customer::firstOrCreate(
            ['phone' => trim($data['phone'])],
            ['first_name' => $data['first_name'] ?? null, 'last_name' => $data['last_name'] ?? null]
        );

        if (!$customer->first_name && !empty($data['first_name'])) {
            $customer->update([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? $customer->last_name,
            ]);
        }

        $conversation->loadMissing('contact');
        $conversation->contact?->update(['customer_id' => $customer->id]);

        return response()->json(['ok' => true, 'customer_id' => $customer->id]);
    }

    /** Після збереження замовлення: привʼязати клієнта і поставити статус чату «Замовлення». */
    public function attachOrder(Request $request, InboxConversation $conversation)
    {
        $data = $request->validate(['order_id' => ['required', 'integer', 'exists:orders,id']]);
        $order = Order::find($data['order_id']);

        $conversation->loadMissing('contact');
        if ($order->customer_id && $conversation->contact) {
            $conversation->contact->update(['customer_id' => $order->customer_id]);
        }

        $statusId = ChatStatus::where('code', 'order')->value('id');
        if ($statusId) {
            $conversation->update(['chat_status_id' => $statusId]);
        }

        return response()->json(['ok' => true]);
    }

    /** Увімкнути/вимкнути AI-агента для цієї розмови. */
    public function setAi(Request $request, InboxConversation $conversation)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $conversation->update(['ai_enabled' => $data['enabled']]);

        return response()->json(['ok' => true]);
    }

    /** Скинути памʼять ШІ: агент читає розмову лише з цього моменту (повідомлення в чаті лишаються). */
    public function resetAiContext(InboxConversation $conversation)
    {
        $lastId = (int) $conversation->messages()->max('id');
        $conversation->update(['ai_context_after_id' => $lastId ?: null]);

        return response()->json(['ok' => true]);
    }

    /** Змінити статус чату для розмови. */
    public function setStatus(Request $request, InboxConversation $conversation)
    {
        $data = $request->validate(['chat_status_id' => ['nullable', 'integer', 'exists:chat_statuses,id']]);
        $conversation->update(['chat_status_id' => $data['chat_status_id'] ?? null]);

        return response()->json(['ok' => true]);
    }

    /** Проксі аватара сторінки (Graph picture) — фб не віддає картинку без токена, тому тягнемо з токеном на сервері. */
    public function pageAvatar(MetaConnection $connection)
    {
        $url = Cache::remember("page_pic_{$connection->id}", 3600, function () use ($connection) {
            $ver = config('services.meta.graph_version', 'v21.0');
            $r = Http::get("https://graph.facebook.com/{$ver}/{$connection->page_id}/picture", [
                'redirect' => 'false',
                'type' => 'square',
                'width' => 160,
                'height' => 160,
                'access_token' => $connection->page_access_token,
            ]);

            return $r->ok() ? $r->json('data.url') : null;
        });

        abort_unless($url, 404);

        return redirect()->away($url);
    }

    /** Компактний відносний час як у ФБ: щойно / 8 хв / 2 год / 3 дн / 05.06. */
    private function shortTime($time): ?string
    {
        if (!$time) {
            return null;
        }

        $sec = (int) abs($time->diffInSeconds(now()));

        return match (true) {
            $sec < 60 => 'щойно',
            $sec < 3600 => intdiv($sec, 60) . ' хв',
            $sec < 86400 => intdiv($sec, 3600) . ' год',
            $sec < 604800 => intdiv($sec, 86400) . ' дн',
            default => $time->format('d.m'),
        };
    }

    /** Коментарі під постами (вкладка «Коментарі»). */
    public function comments(Request $request)
    {
        $channel = $request->query('channel');

        $items = \App\Models\InboxComment::query()
            ->with('connection:id,page_name')
            ->when(in_array($channel, ['facebook', 'instagram'], true), fn ($q) => $q->where('channel', $channel))
            ->orderByDesc('commented_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (\App\Models\InboxComment $c) => [
                'id' => $c->id,
                'channel' => $c->channel,
                'store' => $c->connection?->page_name ?? '—',
                'from_name' => $c->from_name ?: ('Користувач ' . substr($c->from_id, -6)),
                'text' => $c->text,
                'post_excerpt' => $c->post_excerpt,
                'post_image' => $c->post_image ? url($c->post_image) : null,
                'status' => $c->status,
                'at_human' => ($c->commented_at ?? $c->created_at)?->diffForHumans(),
            ]);

        return response()->json([
            'items' => $items,
            'new_count' => \App\Models\InboxComment::where('status', 'new')->count(),
        ]);
    }

    /** Приватна відповідь на коментар → людині в Messenger/Direct. */
    public function commentDm(Request $request, \App\Models\InboxComment $comment)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:1900']]);

        if ($comment->status === 'dm_sent') {
            return response()->json(['ok' => false, 'error' => 'На цей коментар уже відповідали в директ (Meta дозволяє лише раз)'], 422);
        }

        $conn = $comment->connection;
        if (!$conn) {
            return response()->json(['ok' => false, 'error' => 'Немає підключення'], 422);
        }

        $res = app(MetaSendService::class)->sendPrivateReply($conn, $comment->comment_id, $data['text']);
        if (!($res['ok'] ?? false)) {
            return response()->json(['ok' => false, 'error' => $res['error'] ?? 'Помилка відправки'], 502);
        }

        $comment->update(['status' => 'dm_sent']);

        return response()->json(['ok' => true]);
    }

    private function contactName(?string $name, ?string $externalId): string
    {
        if ($name) {
            return $name;
        }

        return $externalId ? ('Клієнт ' . substr($externalId, -6)) : 'Клієнт';
    }
}
