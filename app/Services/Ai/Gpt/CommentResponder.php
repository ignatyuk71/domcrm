<?php

namespace App\Services\Ai\Gpt;

use App\Models\{AiSetting, ChatStatus, InboxComment, InboxContact, InboxConversation, InboxMessage};
use App\Services\Ai\Channels\DispatchContext;
use App\Services\Ai\Channels\ProviderUnavailable;
use App\Services\Meta\MetaSendService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, DB, Log};

/** Незалежна обробка коментарів GPT: намір покупця та перевірений каталог. */
class CommentResponder
{
    public const VERSION = 'gpt-comments-v2-2026-11-02';

    public function __construct(private CommentPostContext $context, private CommentCatalog $catalog,
        private CommentDecision $decision, private CommentReply $reply, private CommentContextStore $store) {}

    public function handle(int $commentId, MetaSendService $send): void
    {
        $comment = InboxComment::with('connection')->find($commentId);
        if (! $comment || ! $this->allowed($comment)) return;
        $lock = Cache::lock('ai-comment-'.$comment->id, 300);
        if (! $lock->get()) return;
        $contactLock = Cache::lock('gpt-comment-contact-'.hash('sha256', $comment->meta_connection_id.'|'.$comment->channel.'|'.$comment->from_id), 300);
        $contactLocked = false;
        $captured = false;
        $handoff = null;
        $post = ['text' => mb_substr((string) $comment->post_excerpt, 0, 4000),
            'image_paths' => $comment->post_image ? [$comment->post_image] : [], 'post_url' => null];
        $catalog = [];
        $decision = $this->decision->fallback((string) $comment->text);
        $result = ['text' => '', 'handoff' => $decision['intent'] === 'complaint'];

        try {
            $contactLocked = $contactLock->get();
            if (! $contactLocked) return;
            $comment->refresh();
            if (! $this->allowed($comment)) return;
            app(DispatchContext::class)->assertCurrent('gpt');

            // Явну скаргу передаємо людині навіть за недоступності моделі або медіа.
            if ($decision['intent'] !== 'complaint') {
                try {
                    $post = $this->context->load($comment);
                    $decision = $this->decision->decide($comment, $post, $this->catalog->snapshot());
                } catch (\Throwable $e) {
                    app(DispatchContext::class)->assertCurrent('gpt');
                    Log::warning('GPT comment analysis unavailable', ['comment' => $comment->id, 'error' => mb_substr($e->getMessage(), 0, 250)]);
                }
            }

            // Ціни й залишки перечитуємо після роботи моделі, без довіри до її цифр.
            $catalog = $this->catalog->snapshot();
            $result = $this->reply->render($decision, $catalog, (string) $comment->text);
            $this->store->capture($comment, $post, $decision, $catalog, $result);
            $captured = true;
            app(DispatchContext::class)->assertCurrent('gpt');
            $comment->refresh();
            if (! $this->allowed($comment)) {
                $this->store->skipped($comment, 'state_changed');
                return;
            }
            $handoff = ! empty($result['handoff']) ? $this->handoff($comment) : null;

            $recentDm = InboxComment::where('meta_connection_id', $comment->meta_connection_id)
                ->where('channel', $comment->channel)->where('from_id', $comment->from_id)
                ->where('id', '!=', $comment->id)->where('status', 'dm_sent')
                ->where('updated_at', '>=', now()->subHours(10))->exists();
            if (! empty($result['skip']) || $recentDm) {
                $comment->update(['status' => 'dm_skipped']);
                $this->store->skipped($comment, ! empty($result['skip']) ? $decision['intent'] : 'contact_cooldown', $handoff);
                Log::info('GPT comment private reply skipped', ['comment' => $comment->id,
                    'reason' => ! empty($result['skip']) ? $decision['intent'] : 'contact_cooldown']);
                return;
            }

            app(DispatchContext::class)->assertCurrent('gpt');
            $sent = $send->sendPrivateReply($comment->connection, $comment->comment_id, $result['text']);
            if (! ($sent['ok'] ?? false)) {
                $comment->update(['status' => 'dm_failed']);
                $this->store->failed($comment, (string) ($sent['error'] ?? 'Meta не підтвердила надсилання.'), $handoff);
                Log::warning('GPT comment private reply failed', ['comment' => $comment->id, 'error' => mb_substr((string) ($sent['error'] ?? ''), 0, 250)]);
                return;
            }
            // Квитанція Meta зберігається до локального відновлення чату: помилка контексту не повторює DM.
            $payload = $comment->gpt_context;
            if ($payload !== null) {
                $payload['delivery_status'] = 'sent';
                $payload['error'] = $payload['skip_reason'] = null;
            }
            $comment->update(['status' => 'dm_sent', 'dm_message_id' => $sent['message_id'] ?? null,
                'matched_group_name' => $result['matched_group_name'] ?? null, 'gpt_context' => $payload]);
            $this->store->sent($comment, $sent['message_id'] ?? null, $handoff);
        } catch (\Throwable $e) {
            if ($comment->fresh()?->status === 'dm_sent') {
                Log::error('GPT sent comment context persistence failed', ['comment' => $commentId, 'error' => mb_substr($e->getMessage(), 0, 250)]);
                return;
            }
            try {
                if (! $captured) $this->store->capture($comment, $post, $decision, $catalog, $result);
                if ($e instanceof ProviderUnavailable) $this->store->skipped($comment, 'state_changed', $handoff);
                else $this->store->failed($comment, $e->getMessage(), $handoff);
            } catch (\Throwable $storeError) {
                Log::error('GPT comment context persistence failed', ['comment' => $commentId, 'error' => mb_substr($storeError->getMessage(), 0, 250)]);
            }
            Log::error('GPT comment processing failed', ['comment' => $commentId, 'error' => mb_substr($e->getMessage(), 0, 250)]);
        } finally {
            if ($contactLocked) $contactLock->release();
            $lock->release();
        }
    }

    private function allowed(InboxComment $comment): bool
    {
        if ($comment->status !== 'new' || ! $comment->connection) return false;
        $settings = AiSetting::global()->comment_settings ?? [];
        if (empty($settings['enabled']) || ! ($settings[$comment->channel] ?? true)) return false;
        return empty($settings['enabled_at']) || ! $comment->created_at
            || ! $comment->created_at->lt(Carbon::parse($settings['enabled_at']));
    }

    private function handoff(InboxComment $comment): InboxConversation
    {
        return DB::transaction(function () use ($comment): InboxConversation {
            $contact = InboxContact::firstOrCreate(['meta_connection_id' => $comment->meta_connection_id,
                'channel' => $comment->channel, 'external_id' => $comment->from_id], ['name' => $comment->from_name]);
            $conversation = InboxConversation::firstOrCreate(['inbox_contact_id' => $contact->id],
                ['meta_connection_id' => $comment->meta_connection_id, 'channel' => $comment->channel, 'ai_enabled' => false]);
            $conversation = InboxConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $message = InboxMessage::firstOrCreate(['external_message_id' => 'gpt-comment-'.hash('sha256', $comment->meta_connection_id.'|'.$comment->comment_id)],
                ['inbox_conversation_id' => $conversation->id, 'direction' => 'in', 'sender' => 'contact',
                    'text' => 'Коментар до допису: '.(string) $comment->text, 'context' => $comment->gpt_context,
                    'sent_at' => $comment->commented_at ?? now()]);
            $status = ChatStatus::firstOrCreate(['code' => 'needs_human'],
                ['name' => 'Потрібна увага', 'icon' => '🔴', 'color' => '#dc2626', 'sort_order' => 0]);
            $data = ['chat_status_id' => $status->id, 'ai_enabled' => false, 'ai_paused_until' => null];
            if ($message->wasRecentlyCreated) {
                $data += ['ai_provider_version' => $conversation->ai_provider_version + 1,
                    'unread_count' => $conversation->unread_count + 1, 'last_message_at' => now(),
                    'last_message_direction' => 'in', 'last_message_text' => mb_substr($message->text, 0, 480)];
            }
            $conversation->update($data);
            $comment->update(['inbox_conversation_id' => $conversation->id]);

            return $conversation;
        });
    }
}
