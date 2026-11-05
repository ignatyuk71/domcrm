<?php

namespace App\Services\Ai\Gpt;

use App\Models\{AiRun, AiSetting, GptInboxReply, InboxComment, InboxContact, InboxConversation, InboxMessage, Product};
use App\Services\Ai\OutgoingMessageWriter;
use Illuminate\Support\Facades\{Cache, DB};

/** Довготривалий знімок контексту коментаря; історичні ціни не замінюють живий каталог. */
class CommentContextStore
{
    private const INTENTS = ['price', 'order', 'availability', 'size', 'delivery', 'praise', 'complaint', 'other'];

    public function __construct(private OutgoingMessageWriter $writer) {}

    public function capture(InboxComment $comment, array $post, array $decision, array $catalog, array $result): void
    {
        if ($comment->status === 'dm_sent') return;
        $indexed = [];
        foreach ($catalog as $product) {
            if (is_array($product) && is_int($product['id'] ?? null) && $product['id'] > 0) $indexed[$product['id']] = $product;
        }
        $ids = ($decision['confidence'] ?? '') === 'clear' && is_array($decision['product_ids'] ?? null)
            ? array_values(array_unique(array_filter($decision['product_ids'], fn ($id) => is_int($id) && isset($indexed[$id])))) : [];
        $validIds = Product::whereIn('id', $ids)->where('is_active', true)->pluck('id')->all();
        $ids = array_values(array_intersect($ids, $validIds));
        if (count($ids) !== count($decision['product_ids'] ?? [])) $ids = [];
        $cleanDecision = ['intent' => in_array($decision['intent'] ?? '', self::INTENTS, true) ? $decision['intent'] : 'other',
            'product_ids' => array_slice($ids, 0, 5), 'confidence' => ($decision['confidence'] ?? '') === 'clear' ? 'clear' : 'uncertain',
            'has_color' => ($decision['has_color'] ?? false) === true, 'has_size' => ($decision['has_size'] ?? false) === true];
        if ($ids === [] && in_array($cleanDecision['intent'], ['price', 'order', 'availability', 'size'], true)) $cleanDecision['confidence'] = 'uncertain';
        $products = [];
        foreach ($cleanDecision['product_ids'] as $id) {
            $product = $indexed[$id];
            $price = null;
            if (in_array($cleanDecision['intent'], ['price', 'order'], true) && ($product['available'] ?? false)) {
                try {
                    $cents = Money::cents((string) ($product['price'] ?? ''));
                    if ($cents > 0) $price = Money::decimal($cents);
                } catch (\DomainException) {}
            }
            $products[] = ['id' => $id, 'title' => mb_substr((string) ($product['title'] ?? ''), 0, 250),
                'color' => isset($product['color']) ? mb_substr((string) $product['color'], 0, 100) : null, 'quoted_price' => $price];
        }
        $payload = $this->base($comment) + ['post_text' => mb_substr((string) ($post['text'] ?? $comment->post_excerpt ?? ''), 0, 4000),
            'image_paths' => $this->verifiedPaths($post['image_paths'] ?? []),
            'post_url' => CommentPostContext::safePostUrl($post['post_url'] ?? null),
            'decision' => $cleanDecision, 'products' => $products, 'dm_text' => (string) ($result['text'] ?? ''),
            'delivery_status' => 'prepared', 'skip_reason' => null, 'error' => null, 'handoff' => ! empty($result['handoff'])];
        $conversation = $this->conversation($comment, null, false);
        $comment->update(['gpt_context' => $payload, 'inbox_conversation_id' => $conversation?->id]);
    }

    public function sent(InboxComment $comment, ?string $messageId, ?InboxConversation $handoff = null): ?InboxConversation
    {
        return DB::transaction(function () use ($comment, $messageId, $handoff): ?InboxConversation {
            $comment->refresh();
            $payload = $this->payload($comment);
            $payload['delivery_status'] = 'sent';
            $payload['error'] = $payload['skip_reason'] = null;
            $conversation = $this->conversation($comment, $handoff, true);
            if (! $conversation) return null;
            $mid = $messageId ?: $comment->dm_message_id;
            $echo = $mid ? InboxMessage::where('external_message_id', $mid)->first() : null;
            if ($echo && $echo->inbox_conversation_id !== $conversation->id) throw new \DomainException('Відповідь Meta належить іншій розмові.');
            $existing = $echo ?? $conversation->messages()->where('direction', 'out')->where('context->type', 'gpt_comment')
                ->where('context->comment_id', $comment->id)->first();
            $comment->update(['status' => 'dm_sent', 'dm_message_id' => $mid, 'gpt_context' => $payload,
                'inbox_conversation_id' => $conversation->id]);
            if (! $existing) {
                $this->writer->persistOutgoing(['inbox_conversation_id' => $conversation->id, 'direction' => 'out', 'sender' => 'ai',
                    'text' => $payload['dm_text'], 'external_message_id' => $mid, 'context' => $payload, 'sent_at' => now()]);
            } elseif ($existing->external_message_id) {
                // persistOutgoing виправляє sender echo, а метадані додаємо окремо після ідемпотентної вставки.
                $this->writer->persistOutgoing(['inbox_conversation_id' => $conversation->id, 'direction' => 'out', 'sender' => 'ai',
                    'text' => $payload['dm_text'], 'external_message_id' => $existing->external_message_id,
                    'context' => $payload, 'sent_at' => $existing->sent_at ?? now()]);
            }
            $message = $mid ? InboxMessage::where('external_message_id', $mid)->first() : ($existing ?? $conversation->messages()->where('direction', 'out')
                ->where('context->type', 'gpt_comment')->where('context->comment_id', $comment->id)->latest('id')->first());
            if (! $message || $message->inbox_conversation_id !== $conversation->id) throw new \DomainException('Не вдалося пов’язати відповідь із розмовою.');
            $message->update(['sender' => 'ai', 'text' => $payload['dm_text'], 'context' => array_replace($message->context ?? [], $payload)]);
            $this->clearEchoPause($conversation, $echo);
            $this->note($comment, $conversation, $payload);
            if ((int) $conversation->messages()->max('id') === $message->id) {
                $conversation->update(['last_message_at' => $message->sent_at ?? now(), 'last_message_direction' => 'out',
                    'last_message_text' => mb_substr($payload['dm_text'], 0, 480)]);
            }

            return $conversation->fresh();
        });
    }

    /** Статус коментаря визначає обробник; тут зберігається лише результат доставки та звʼязок. */
    public function failed(InboxComment $comment, string $error, ?InboxConversation $handoff = null): void
    {
        $this->state($comment, 'failed', null, mb_substr($error, 0, 1000), $handoff);
    }

    public function skipped(InboxComment $comment, string $reason, ?InboxConversation $handoff = null): void
    {
        $this->state($comment, 'skipped', mb_substr($reason, 0, 250), null, $handoff);
    }

    public function source(InboxConversation $conversation, ?InboxMessage $trigger = null): ?array
    {
        $trigger ??= $this->latestTrigger($conversation);
        $contact = $conversation->contact;
        if (! $contact || $contact->meta_connection_id !== $conversation->meta_connection_id || $contact->channel !== $conversation->channel
            || ($trigger && $trigger->inbox_conversation_id !== $conversation->id)) return null;
        $messages = $conversation->messages()->where('direction', 'out')->where('id', '>', (int) $conversation->ai_context_after_id);
        $context = $trigger?->context ?? [];
        $mid = ($context['type'] ?? '') === 'reply' ? ($context['mid'] ?? null) : ($context['reply_to']['mid'] ?? null);
        if (is_string($mid) && $mid !== '' && ($quoted = (clone $messages)->where('external_message_id', $mid)->first())) {
            if ($source = $this->messageSource($conversation, $quoted)) return $source;
        }
        $legacy = InboxComment::where('meta_connection_id', $conversation->meta_connection_id)->where('channel', $conversation->channel)
            ->where('from_id', $contact->external_id)->where('status', 'dm_sent')->whereNotNull('dm_message_id')->select('dm_message_id');
        $candidates = $messages->where(function ($query) use ($legacy): void {
            $query->where('context->type', 'gpt_comment')->orWhereIn('external_message_id', $legacy);
        })->latest('id')->get();
        foreach ($candidates as $message) if ($source = $this->messageSource($conversation, $message)) return $source;

        return null;
    }

    private function messageSource(InboxConversation $conversation, InboxMessage $message): ?array
    {
        $context = $message->context ?? [];
        $raw = $context['gpt_comment'] ?? $context;
        if (($raw['type'] ?? '') === 'gpt_comment') {
            if (($raw['version'] ?? null) !== 1 || ($raw['delivery_status'] ?? '') !== 'sent' || ! is_int($raw['comment_id'] ?? null)
                || (int) ($raw['meta_connection_id'] ?? 0) !== $conversation->meta_connection_id
                || ($raw['channel'] ?? null) !== $conversation->channel || ($raw['from_id'] ?? null) !== $conversation->contact->external_id) return null;
            $comment = InboxComment::find($raw['comment_id']);
            if (! $comment) {
                if ($message->sender !== 'ai') return null;
            } elseif (! $this->matches($comment, $conversation) || $comment->status !== 'dm_sent'
                || $comment->inbox_conversation_id !== $conversation->id
                || ($comment->dm_message_id && $comment->dm_message_id !== $message->external_message_id)) return null;
        } else {
            if (! $message->external_message_id) return null;
            $comment = InboxComment::where('dm_message_id', $message->external_message_id)->where('status', 'dm_sent')
                ->where('meta_connection_id', $conversation->meta_connection_id)->where('channel', $conversation->channel)
                ->where('from_id', $conversation->contact->external_id)->first();
            if (! $comment) return null;
            $raw = $this->payload($comment);
            if ($comment->gpt_context !== null) {
                if (($raw['delivery_status'] ?? '') !== 'sent' || ($raw['from_id'] ?? null) !== $conversation->contact->external_id
                    || (int) ($raw['meta_connection_id'] ?? 0) !== $conversation->meta_connection_id
                    || ($raw['channel'] ?? null) !== $conversation->channel
                    || ($comment->inbox_conversation_id && $comment->inbox_conversation_id !== $conversation->id)) return null;
            } else {
                $key = 'gpt-comment-context:v1:'.$comment->meta_connection_id.':'.$comment->channel.':'.hash('sha256', $comment->post_id);
                if (is_array($cached = Cache::get($key))) {
                    if (is_string($cached['text'] ?? null) && $cached['text'] !== '') $raw['post_text'] = mb_substr($cached['text'], 0, 4000);
                    $paths = $this->verifiedPaths(is_array($cached['locals'] ?? null) ? $cached['locals'] : []);
                    if ($paths !== []) $raw['image_paths'] = $paths;
                    $raw['post_url'] = CommentPostContext::safePostUrl($cached['post_url'] ?? null);
                }
                $raw['delivery_status'] = 'sent';
                $raw['legacy'] = true;
            }
        }

        return $raw + ['_message_id' => $message->id, '_sent_at' => ($message->sent_at ?? $message->created_at)?->toIso8601String()];
    }

    /** API картки та GPT однаково враховують цитату перед коротким продовженням у серії. */
    private function latestTrigger(InboxConversation $conversation): ?InboxMessage
    {
        $handled = AiRun::where('inbox_conversation_id', $conversation->id)->whereIn('status', ['replied', 'replied_voice'])->max('inbox_message_id');
        $sent = GptInboxReply::where('inbox_conversation_id', $conversation->id)->where('status', 'sent')->max('inbox_message_id');
        $manual = $conversation->messages()->where('direction', 'out')->where('sender', 'agent')->max('id');
        $boundary = max((int) $conversation->ai_context_after_id, (int) $handled, (int) $sent, (int) $manual);
        $incoming = $conversation->messages()->where('direction', 'in')->where('id', '>', (int) $conversation->ai_context_after_id);
        $pending = (clone $incoming)->where('id', '>', $boundary)->latest('id')->get();
        return $pending->first(fn ($message) => (($message->context['type'] ?? '') === 'reply' && ! empty($message->context['mid']))
            || ! empty($message->context['reply_to']['mid'])) ?? $pending->first();
    }

    private function state(InboxComment $comment, string $status, ?string $reason, ?string $error, ?InboxConversation $handoff): void
    {
        $comment->refresh();
        $payload = $this->payload($comment);
        $payload['delivery_status'] = $status;
        $payload['skip_reason'] = $reason;
        $payload['error'] = $error;
        $conversation = $this->conversation($comment, $handoff, false);
        $comment->update(['gpt_context' => $payload, 'inbox_conversation_id' => $conversation?->id]);
        if ($conversation) $this->note($comment, $conversation, $payload);
    }

    private function conversation(InboxComment $comment, ?InboxConversation $handoff, bool $create): ?InboxConversation
    {
        if ($handoff && $this->matches($comment, $handoff)) return $handoff;
        $contactQuery = InboxContact::where('meta_connection_id', $comment->meta_connection_id)
            ->where('channel', $comment->channel)->where('external_id', $comment->from_id);
        $contact = $contactQuery->first();
        if (! $contact && $create) $contact = InboxContact::firstOrCreate(['meta_connection_id' => $comment->meta_connection_id,
            'channel' => $comment->channel, 'external_id' => $comment->from_id], ['name' => $comment->from_name]);
        if (! $contact) return null;
        $conversation = InboxConversation::where('inbox_contact_id', $contact->id)->first();
        if (! $conversation && $create) $conversation = InboxConversation::firstOrCreate(['inbox_contact_id' => $contact->id],
            ['meta_connection_id' => $comment->meta_connection_id, 'channel' => $comment->channel]);

        return $conversation && $this->matches($comment, $conversation) ? $conversation : null;
    }

    private function matches(InboxComment $comment, InboxConversation $conversation): bool
    {
        $contact = $conversation->contact;
        return $contact && $conversation->meta_connection_id === $comment->meta_connection_id && $conversation->channel === $comment->channel
            && $contact->meta_connection_id === $comment->meta_connection_id && $contact->channel === $comment->channel
            && $contact->external_id === $comment->from_id;
    }

    private function base(InboxComment $comment): array
    {
        return ['type' => 'gpt_comment', 'version' => 1, 'comment_id' => $comment->id,
            'meta_connection_id' => $comment->meta_connection_id, 'channel' => $comment->channel,
            'from_id' => $comment->from_id, 'post_id' => $comment->post_id, 'comment_text' => (string) $comment->text];
    }

    private function payload(InboxComment $comment): array
    {
        return $comment->gpt_context ?? ($this->base($comment) + ['post_text' => mb_substr((string) $comment->post_excerpt, 0, 4000),
            'image_paths' => $this->verifiedPaths($comment->post_image ? [$comment->post_image] : []), 'post_url' => null,
            'decision' => ['intent' => 'other', 'product_ids' => [], 'confidence' => 'uncertain', 'has_color' => false, 'has_size' => false],
            'products' => [], 'dm_text' => '', 'delivery_status' => 'prepared', 'skip_reason' => null, 'error' => null, 'handoff' => false]);
    }

    private function verifiedPaths(array $paths): array
    {
        $valid = [];
        foreach ($paths as $local) {
            if (count($valid) >= 3) break;
            if (! is_string($local) || ! preg_match('~^(?:inbox-context|inbox-media|inbox-uploads|ai-gallery)/[a-zA-Z0-9_./-]+$~D', $local)
                || str_contains($local, '..') || in_array($local, $valid, true)) continue;
            $base = realpath(public_path(explode('/', $local)[0]));
            $path = realpath(public_path($local));
            if (! $base || ! $path || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR) || ! is_file($path)
                || filesize($path) > 4 * 1024 * 1024 || CommentPostContext::validatedImageData((string) file_get_contents($path)) === null) continue;
            $valid[] = $local;
        }

        return $valid;
    }

    private function note(InboxComment $comment, InboxConversation $conversation, array $payload): void
    {
        if (empty($payload['handoff'])) return;
        $mid = 'gpt-comment-'.hash('sha256', $comment->meta_connection_id.'|'.$comment->comment_id);
        $conversation->messages()->where('external_message_id', $mid)->update(['context' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    private function clearEchoPause(InboxConversation $conversation, ?InboxMessage $echo): void
    {
        if (! $echo || $echo->sender !== 'agent' || ! $echo->created_at) return;
        $conversation->refresh();
        $pause = $conversation->ai_paused_until;
        $minutes = (int) (AiSetting::global()->operator_pause_minutes ?? 3);
        if (! $conversation->ai_enabled || ! $pause || $minutes <= 0
            || abs($pause->getTimestamp() - $echo->created_at->copy()->addMinutes($minutes)->getTimestamp()) > 1) return;
        $realOperator = $conversation->messages()->where('id', '!=', $echo->id)->where('direction', 'out')->where('sender', 'agent')
            ->where('created_at', '>=', $echo->created_at->copy()->subMinutes($minutes)->subSecond())->exists();
        if (! $realOperator) InboxConversation::whereKey($conversation->id)->where('ai_enabled', true)
            ->where('ai_paused_until', $conversation->getRawOriginal('ai_paused_until'))->update(['ai_paused_until' => null]);
    }
}
