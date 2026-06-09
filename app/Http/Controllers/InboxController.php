<?php

namespace App\Http\Controllers;

use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Services\Meta\MetaSendService;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function index()
    {
        return view('inbox.index');
    }

    /** Список діалогів для лівої панелі. */
    public function conversations()
    {
        $items = InboxConversation::query()
            ->with(['connection:id,page_name', 'contact:id,name,external_id,profile_pic'])
            ->orderByDesc('last_message_at')
            ->limit(200)
            ->get()
            ->map(fn (InboxConversation $c) => [
                'id' => $c->id,
                'store' => $c->connection?->page_name ?? '—',
                'channel' => $c->channel,
                'contact_name' => $this->contactName($c->contact?->name, $c->contact?->external_id),
                'avatar' => $c->contact?->profile_pic,
                'last_text' => $c->last_message_text,
                'last_direction' => $c->last_message_direction,
                'last_at_human' => $c->last_message_at?->diffForHumans(),
                'unread' => (int) $c->unread_count,
            ]);

        return response()->json($items);
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
                'channel' => $conversation->channel,
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

    private function contactName(?string $name, ?string $externalId): string
    {
        if ($name) {
            return $name;
        }

        return $externalId ? ('Клієнт ' . substr($externalId, -6)) : 'Клієнт';
    }
}
