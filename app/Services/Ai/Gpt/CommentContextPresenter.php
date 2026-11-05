<?php

namespace App\Services\Ai\Gpt;

use App\Models\{InboxComment, InboxConversation, InboxMessage};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/** Публічна картка джерела: без сирого encrypted payload, ключів або мережевих запитів. */
class CommentContextPresenter
{
    private array $imageUrls = [];
    private array $cachedPosts = [];

    public function forComments(Collection $comments): array
    {
        $mids = $comments->pluck('dm_message_id')->filter()->unique()->values();
        $messages = $mids->isEmpty() ? collect() : InboxMessage::whereIn('external_message_id', $mids)
            ->where('direction', 'out')->get(['id', 'inbox_conversation_id', 'external_message_id', 'context', 'sent_at'])->keyBy('external_message_id');
        $conversationIds = $comments->pluck('inbox_conversation_id')->merge($messages->pluck('inbox_conversation_id'))->filter()->unique();
        $conversations = $conversationIds->isEmpty() ? collect() : InboxConversation::with('contact:id,external_id,meta_connection_id,channel')
            ->whereIn('id', $conversationIds)->get()->keyBy('id');
        $cards = [];
        foreach ($comments as $comment) {
            $message = $messages->get($comment->dm_message_id);
            $conversation = $conversations->get($comment->inbox_conversation_id ?: $message?->inbox_conversation_id);
            if ($conversation && ! $this->sameAuthor($conversation, $comment->meta_connection_id, $comment->channel, (string) $comment->from_id)) $conversation = null;
            $source = $this->stored($comment) ?? $this->legacy($comment);
            $cards[$comment->id] = $this->card($source, $comment, $conversation?->id, $comment->connection?->page_name);
        }

        return $cards;
    }

    public function forMessages(Collection $messages, InboxConversation $conversation): array
    {
        $ids = $messages->map(fn ($message) => $message->context['comment_id'] ?? null)->filter(fn ($id) => is_int($id) && $id > 0)->unique();
        $comments = $ids->isEmpty() ? collect() : InboxComment::whereIn('id', $ids)->get()->keyBy('id');
        $cards = [];
        foreach ($messages as $message) {
            $source = $message->context;
            if ($message->direction !== 'out' || $message->sender !== 'ai' || ! is_array($source) || ($source['type'] ?? '') !== 'gpt_comment') continue;
            // Знімок залишається в історії також після видалення початкового коментаря.
            if (! $this->sameAuthor($conversation, $source['meta_connection_id'] ?? null, $source['channel'] ?? '', (string) ($source['from_id'] ?? ''))) continue;
            $comment = $comments->get($source['comment_id'] ?? 0);
            if ($comment && ! $this->sameAuthor($conversation, $comment->meta_connection_id, $comment->channel, (string) $comment->from_id)) continue;
            $cards[$message->id] = $this->card($source, $comment, $conversation->id, $conversation->connection?->page_name,
                $message->sent_at?->format('d.m H:i'));
        }

        return $cards;
    }

    public function forSource(array $source, InboxConversation $conversation): ?array
    {
        if (! $this->sameAuthor($conversation, $source['meta_connection_id'] ?? null,
            (string) ($source['channel'] ?? ''), (string) ($source['from_id'] ?? ''))) return null;

        return $this->card($source, null, $conversation->id, $conversation->connection?->page_name,
            isset($source['_sent_at']) ? \Illuminate\Support\Carbon::parse($source['_sent_at'])->format('d.m H:i') : null);
    }

    private function stored(InboxComment $comment): ?array
    {
        try { $source = $comment->gpt_context; } catch (\Throwable) { return null; }
        if (! is_array($source) || ($source['type'] ?? '') !== 'gpt_comment'
            || (int) ($source['comment_id'] ?? 0) !== (int) $comment->id
            || (int) ($source['meta_connection_id'] ?? 0) !== (int) $comment->meta_connection_id
            || ($source['channel'] ?? '') !== $comment->channel || (string) ($source['from_id'] ?? '') !== (string) $comment->from_id) return null;

        return $source;
    }

    private function sameAuthor(InboxConversation $conversation, mixed $connectionId, string $channel, string $author): bool
    {
        $contact = $conversation->contact;
        return (int) $conversation->meta_connection_id === (int) $connectionId && $conversation->channel === $channel
            && $contact && $contact->meta_connection_id === $conversation->meta_connection_id && $contact->channel === $channel
            && $author !== '' && (string) $contact->external_id === $author;
    }

    private function legacy(InboxComment $comment): array
    {
        $key = 'gpt-comment-context:v1:'.$comment->meta_connection_id.':'.$comment->channel.':'.hash('sha256', (string) $comment->post_id);
        if (! array_key_exists($key, $this->cachedPosts)) $this->cachedPosts[$key] = Cache::get($key, []);
        $cached = is_array($this->cachedPosts[$key]) ? $this->cachedPosts[$key] : [];

        return ['type' => 'gpt_comment', 'channel' => $comment->channel, 'post_id' => $comment->post_id,
            'comment_text' => $comment->text, 'post_text' => $cached['text'] ?? $comment->post_excerpt,
            'image_paths' => $cached['locals'] ?? array_filter([$comment->post_image]), 'post_url' => $cached['post_url'] ?? null,
            'decision' => ['confidence' => 'uncertain', 'intent' => 'other'], 'products' => [], 'dm_text' => ''];
    }

    private function card(array $source, ?InboxComment $comment, ?int $conversationId, ?string $store, ?string $at = null): array
    {
        $status = $source['delivery_status'] ?? 'prepared';
        if ($comment) $status = match ($comment->status) { 'dm_sent' => 'sent', 'dm_failed' => 'failed', 'dm_skipped' => 'skipped', default => 'prepared' };
        $handoff = ($source['handoff'] ?? false) === true;
        $label = match ($status) {
            'sent' => $handoff ? 'Передано оператору' : 'Надіслано в директ',
            'failed' => $handoff ? 'Передано оператору · DM не надіслано' : 'Помилка надсилання',
            'skipped' => ($source['skip_reason'] ?? '') === 'praise' ? 'Відгук · без торгового DM'
                : (($source['skip_reason'] ?? '') === 'contact_cooldown' ? 'Повторний DM не надсилали' : 'Без авто-DM'),
            default => 'Очікує відповіді',
        };
        if ($handoff && $status === 'skipped') $label = 'Передано оператору · без повторного DM';
        $products = [];
        foreach (array_slice($source['products'] ?? [], 0, 5) as $product) {
            if (! is_array($product) || ! is_int($product['id'] ?? null) || $product['id'] < 1 || ! is_string($product['title'] ?? null)) continue;
            $products[] = ['id' => $product['id'], 'title' => $this->text($product['title'], 180),
                'color' => $this->text($product['color'] ?? '', 50) ?: null,
                'price' => Money::label((string) ($product['quoted_price'] ?? ''))];
        }
        $image = null;
        foreach (array_slice($source['image_paths'] ?? [], 0, 3) as $path) if (is_string($path) && ($image = $this->imageUrl($path))) break;
        $postUrl = $this->postUrl($source['post_url'] ?? null);
        if (! $postUrl && ($source['channel'] ?? '') === 'facebook' && preg_match('/^(\d+)_(\d+)$/D', (string) ($source['post_id'] ?? ''), $parts)) {
            $postUrl = 'https://www.facebook.com/'.$parts[1].'/posts/'.$parts[2];
        }
        $intent = $source['decision']['intent'] ?? 'other';
        if (! in_array($intent, ['price', 'order', 'availability', 'size', 'delivery', 'praise', 'complaint', 'other'], true)) $intent = 'other';

        return ['type' => 'gpt_comment', 'label' => 'Коментар до допису',
            'channel' => in_array($source['channel'] ?? '', ['facebook', 'instagram'], true) ? $source['channel'] : 'facebook',
            'store' => $this->text($store ?? '', 100), 'comment_text' => $this->text($source['comment_text'] ?? $comment?->text ?? '', 2000),
            'post_text' => $this->text($source['post_text'] ?? '', 500), 'image' => $image, 'post_url' => $postUrl,
            'products' => $products, 'intent' => $intent, 'confidence' => ($source['decision']['confidence'] ?? '') === 'clear' ? 'clear' : 'uncertain',
            'status' => $handoff && $status === 'sent' ? 'handoff' : $status, 'status_label' => $label,
            'dm_text' => $this->text($source['dm_text'] ?? '', 1900), 'error' => $this->text($source['error'] ?? '', 250) ?: null,
            'skip_reason' => $this->text($source['skip_reason'] ?? '', 50) ?: null,
            'at' => $at ?? ($comment?->commented_at ?? $comment?->created_at)?->format('d.m H:i'), 'conversation_id' => $conversationId];
    }

    private function text(mixed $value, int $limit): string
    {
        return is_string($value) ? mb_substr(trim(strip_tags($value)), 0, $limit) : '';
    }

    private function imageUrl(string $relative): ?string
    {
        if (array_key_exists($relative, $this->imageUrls)) return $this->imageUrls[$relative];
        $this->imageUrls[$relative] = null;
        if (! preg_match('~^(inbox-context|inbox-media|inbox-uploads|ai-gallery)/[A-Za-z0-9_./-]+$~D', $relative) || str_contains($relative, '..')) return null;
        $base = realpath(public_path(explode('/', $relative)[0]));
        $file = realpath(public_path($relative));
        if (! $base || ! $file || ! str_starts_with($file, $base.DIRECTORY_SEPARATOR) || ! is_file($file)) return null;
        if (! in_array((new \finfo(FILEINFO_MIME_TYPE))->file($file), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return null;

        return $this->imageUrls[$relative] = url($relative);
    }

    private function postUrl(mixed $url): ?string
    {
        return CommentPostContext::safePostUrl($url);
    }
}
