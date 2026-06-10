<?php

namespace App\Http\Controllers;

use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
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

        $messages = $conversation->messages()
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (InboxMessage $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'sender' => $m->sender,
                'text' => $m->text,
                'attachments' => $m->attachments ?? [],
                'sent_at_human' => ($m->sent_at ?? $m->created_at)?->format('d.m H:i'),
            ]);

        $conversation->update(['unread_count' => 0]);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'store' => $conversation->connection?->page_name ?? '—',
                'store_id' => $conversation->connection?->page_id,
                'conn_id' => $conversation->connection?->id,
                'channel' => $conversation->channel,
                'chat_status_id' => $conversation->chat_status_id,
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

    /** Підтягнути з Meta всю історію цього контакту (кнопка «оновити»). */
    public function refresh(InboxConversation $conversation, MetaSyncService $sync)
    {
        $conversation->loadMissing(['connection', 'contact']);
        if (!$conversation->connection || !$conversation->contact) {
            return response()->json(['ok' => false, 'error' => 'Немає підключення або контакту'], 422);
        }

        $imported = $sync->syncContactConversation($conversation->connection, $conversation->contact);

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

    private function contactName(?string $name, ?string $externalId): string
    {
        if ($name) {
            return $name;
        }

        return $externalId ? ('Клієнт ' . substr($externalId, -6)) : 'Клієнт';
    }
}
