<?php

namespace App\Services\Ai\Channels;

use App\Models\{AiChannelSetting, AiPhoto, AiSetting, ChatStatus, GptInboxReply, InboxConversation};
use App\Services\Ai\Gpt\{CatalogAssistant, OrderTexts};
use App\Services\Ai\Gpt\ConversationOpening;
use App\Services\Ai\{AiAgentService, OutgoingMessageWriter};
use App\Services\Meta\MetaSendService;

/** Робочий GPT має власний стан оформлення та не викликає реалізацію Claude. */
class GptInboxAgent
{
    public function __construct(private CatalogAssistant $assistant, private GptHistory $history,
        private OutgoingMessageWriter $writer, private MetaSendService $send) {}

    public function respond(InboxConversation $conversation, int $messageId): array
    {
        $conversation->loadMissing(['connection', 'contact']);
        if (! $conversation->ai_enabled) return ['status' => 'skipped_conversation_off'];
        if (! $conversation->connection || ! $conversation->contact) return ['status' => 'skipped_no_connection'];
        $settings = AiChannelSetting::current();
        if (! $settings->gpt_api_key || ! $settings->gpt_model) return ['status' => 'skipped_no_key'];
        $store = AiSetting::where('meta_connection_id', $conversation->meta_connection_id)->first();
        if (! $store?->enabled) return ['status' => 'skipped_store_off'];
        if (! AiAgentService::scheduleAllows($store->schedule)) return ['status' => 'skipped_schedule'];
        if ($conversation->ai_paused_until?->isFuture()) return ['status' => 'skipped_operator_pause'];
        $checkoutData = $conversation->ai_checkout_data;
        if (($checkoutData['status'] ?? '') === 'needs_human') {
            // Після ручного відновлення старий стан передачі не повертає зняту паузу.
            $checkoutData['status'] = 'draft';
            $checkoutData['items'] ??= [];
            unset($checkoutData['paused_until']);
        }
        $last = $conversation->messages()->where('sender', '!=', 'ai')->latest('id')->first();
        if (! $last || $last->id !== $messageId || $last->direction !== 'in') return ['status' => 'skipped_stale'];
        $reply = GptInboxReply::where('inbox_message_id', $messageId)->first();
        if ($reply && $reply->provider_version !== $conversation->ai_provider_version) return ['status' => 'skipped_stale'];
        if (! $reply) {
            $voice = trim((string) $last->text) === '' && collect($last->attachments ?? [])->contains(fn ($a) => ($a['type'] ?? '') === 'audio');
            if ($voice) {
                $result = ['text' => OrderTexts::all()['voice_reject'], 'photos' => [], 'checkout_messages' => [], 'checkout' => $checkoutData];
            } elseif (($opening = ConversationOpening::reply($conversation, $last)) !== null) {
                $result = ['text' => $opening, 'photos' => [], 'checkout_messages' => [], 'checkout' => $checkoutData];
            } else {
                $input = $this->history->build($conversation);
                if (! $input) return ['status' => 'skipped_empty_history'];
                $recentIds = $conversation->messages()->where('direction', 'out')->latest('id')->limit(10)->pluck('attachments')
                    ->filter()->flatten(1)->pluck('ai_photo_id')->filter()->map(fn ($id) => (int) $id)->all();
                $hasImage = collect($input)->where('role', 'user')->last();
                $result = $this->assistant->reply($settings->gpt_api_key, $settings->gpt_model, $input,
                    $checkoutData, $conversation->meta_connection_id, $recentIds,
                    ['turn_id' => $messageId, 'foot_length_cm' => ConversationOpening::footLength($conversation, $last), 'has_image' => collect($hasImage['content'] ?? [])->contains('type', 'input_image')]);
            }
            app(DispatchContext::class)->assertCurrent();
            unset($result['output']);
            // Порядок фіксуємо у відповіді: старі незавершені відправлення зберігають свій курсор.
            $result['text_first'] = empty($result['checkout_messages']) && ! in_array($result['checkout']['status'] ?? '', ['ready_for_manager', 'needs_human'], true);
            $reply = GptInboxReply::create(['inbox_conversation_id' => $conversation->id, 'inbox_message_id' => $messageId,
                'provider_version' => $conversation->ai_provider_version, 'payload' => $result,
                'tokens_in' => $result['tokens_in'] ?? 0, 'tokens_out' => $result['tokens_out'] ?? 0]);
        }
        if ($reply->status === 'sent') return ['status' => 'replied'];
        $result = $reply->payload;
        if (($result['checkout']['status'] ?? '') === 'ready_for_manager') {
            $pricing = app(\App\Services\Ai\Gpt\CheckoutPricing::class);
            $quote = $pricing->quote($pricing->inputItems($result['checkout']['items']));
            if ($quote['items'] !== $result['checkout']['items'] || $quote['total'] !== $result['checkout']['total']) {
                throw new \RuntimeException('Ціна або товар змінилися перед відправленням. Потрібна перевірка менеджера.');
            }
        }
        $messages = [];
        if (($result['text_first'] ?? false) && $result['text'] !== '') $messages[] = ['text' => $result['text']];
        foreach ($result['photos'] as $photo) $messages[] = ['photo' => $photo['id']];
        foreach ($result['checkout_messages'] as $text) $messages[] = ['text' => $text];
        if (! ($result['text_first'] ?? false) && $result['text'] !== '') $messages[] = ['text' => $result['text']];
        // Повтор після збою Meta продовжує з курсора, без повторного платного запиту GPT.
        foreach ($messages as $index => $message) {
            if ($index < $reply->next_index) continue;
            if ($conversation->messages()->where('id', '>', $messageId)->where('sender', '!=', 'ai')->exists()) return ['status' => 'skipped_stale_late'];
            app(DispatchContext::class)->assertCurrent();
            if (isset($message['photo'])) {
                $photo = AiPhoto::findOrFail($message['photo']);
                $sent = $this->send->sendAttachment($conversation->connection, $conversation->contact->external_id, 'image', $photo->url());
                if (! ($sent['ok'] ?? false)) throw new \RuntimeException('Meta не надіслала фото; повтор продовжить відправлення.');
                $this->writer->persistOutgoing(['inbox_conversation_id' => $conversation->id, 'direction' => 'out', 'sender' => 'ai',
                    'external_message_id' => $sent['message_id'] ?? null, 'attachments' => [['type' => 'image', 'url' => $photo->url(), 'ai_photo_id' => $photo->id]], 'sent_at' => now()]);
            } elseif (! $this->writer->sendBotMessage($conversation, $message['text'])) {
                throw new \RuntimeException('Meta не надіслала текст; повтор продовжить відправлення.');
            }
            $reply->update(['next_index' => $index + 1]);
        }
        $this->saveCheckout($conversation, $result['checkout']);
        $reply->update(['status' => 'sent']);
        return ['status' => 'replied', 'tokens_in' => $reply->tokens_in, 'tokens_out' => $reply->tokens_out];
    }

    private function saveCheckout(InboxConversation $conversation, ?array $checkout): void
    {
        $data = ['ai_checkout_data' => $checkout, 'ai_checkout_active' => (! empty($checkout['items']) || ! empty($checkout['delivery_requested'])) && ($checkout['status'] ?? '') === 'draft'];
        if (($checkout['status'] ?? '') === 'ready_for_manager') {
            $status = ChatStatus::firstOrCreate(['code' => 'ai_order'], ['name' => 'Замовлення від ШІ', 'icon' => '🤖', 'color' => '#7c3aed', 'sort_order' => 90]);
            $data += ['chat_status_id' => $status->id, 'ai_enabled' => false, 'ai_order_items' => $checkout['items'],
                'ai_order_summary' => mb_substr(collect($checkout['items'])->map(fn ($i) => $i['title'].' '.$i['size'].' ×'.$i['qty'])->implode('; '), 0, 250),
                'ai_order_customer_name' => $checkout['customer_name'], 'ai_order_phone' => $checkout['phone'], 'ai_order_address' => $checkout['address'],
                'ai_order_payment' => match ($checkout['payment']) { 'deposit' => 'Передоплата 100 грн; при отриманні '.$checkout['pay_on_delivery'].' грн', 'full' => 'Повна оплата', default => 'При отриманні' },
                'ai_order_handled_at' => null, 'ai_order_needs_iban' => false];
        } elseif (($checkout['status'] ?? '') === 'needs_human') {
            // Передача людині діє до ручного відновлення без довільного таймера.
            $data['ai_enabled'] = false;
            $data['ai_paused_until'] = null;
            $status = ChatStatus::firstOrCreate(['code' => 'needs_human'], ['name' => 'Потрібна увага', 'icon' => '🔴', 'color' => '#dc2626', 'sort_order' => 0]);
            $data['chat_status_id'] = $status->id;
        } elseif ($checkout || $conversation->messages()->where('sender', 'ai')->whereNotNull('attachments')->exists()) {
            if ($status = ChatStatus::where('code', 'in_progress')->first()) $data['chat_status_id'] = $status->id;
        }
        $conversation->update($data);
    }
}
