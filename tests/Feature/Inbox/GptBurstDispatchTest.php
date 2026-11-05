<?php

namespace Tests\Feature\Inbox;

use App\Models\{AiChannelSetting, AiSetting, GptInboxReply, InboxContact, InboxConversation, InboxMessage, MetaConnection, Product};
use App\Services\Ai\Channels\{Dispatcher, GptHistory};
use App\Services\Ai\Gpt\{CheckoutPricing, CheckoutSession, MessageBuffer, OrderTexts};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GptBurstDispatchTest extends TestCase
{
    use RefreshDatabase;

    private array $pauses = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00'));
        Storage::fake('local');
        Http::preventStrayRequests();
        $buffer = \Mockery::mock(MessageBuffer::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $buffer->shouldReceive('pause')->andReturnUsing(function (float $seconds): void {
            $this->pauses[] = $seconds;
            Carbon::setTestNow(now()->addMicroseconds((int) ceil($seconds * 1_000_000)));
        });
        $this->app->instance(MessageBuffer::class, $buffer);
        AiChannelSetting::current()->update(['gpt_enabled' => true, 'claude_enabled' => false,
            'default_provider' => 'gpt', 'gpt_api_key' => 'sk-test-burst', 'gpt_model' => 'gpt-test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function conversation(): InboxConversation
    {
        $connection = MetaConnection::create(['page_id' => 'BURST-PAGE', 'page_name' => 'Магазин',
            'page_access_token' => 'test-token', 'status' => 'active']);
        AiSetting::forConnection($connection->id)->update(['enabled' => true, 'system_prompt' => 'Короткі відповіді.']);
        $contact = InboxContact::create(['meta_connection_id' => $connection->id, 'channel' => 'facebook', 'external_id' => 'BURST-USER']);

        return InboxConversation::create(['meta_connection_id' => $connection->id, 'inbox_contact_id' => $contact->id,
            'channel' => 'facebook', 'ai_enabled' => true]);
    }

    private function incoming(InboxConversation $conversation, ?string $text, array $attachments = []): InboxMessage
    {
        $message = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => $text,
            'attachments' => $attachments ?: null, 'sent_at' => now()]);
        app(MessageBuffer::class)->received($message);

        return $message;
    }

    private function answer(string $text): array
    {
        return ['status' => 'completed', 'usage' => ['input_tokens' => 15, 'output_tokens' => 5],
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]]];
    }

    private function tool(string $name, array $arguments = []): array
    {
        return ['status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'output' => [['type' => 'function_call', 'name' => $name, 'call_id' => $name.'-'.uniqid(),
                'arguments' => json_encode($arguments ?: (object) [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]];
    }

    private function product(): Product
    {
        return Product::create(['title' => 'Домашні капці', 'sale_price' => '530.50', 'currency' => 'UAH',
            'stock_qty' => 10, 'is_active' => true]);
    }

    private function orderArgs(Product $product, string $payment = 'cod', int $qty = 1): array
    {
        return ['items' => [['product_id' => $product->id, 'size' => null, 'qty' => $qty]],
            'customer_name' => 'Олена Тестова', 'phone' => '+380671234567', 'address' => 'Київ, Нова пошта 5',
            'payment' => $payment, 'confirm_total' => false];
    }

    private function draft(InboxConversation $conversation, Product $product, int $turnId, string $payment = 'cod'): CheckoutSession
    {
        $session = new CheckoutSession(app(CheckoutPricing::class), null, ['turn_id' => $turnId]);
        $this->assertArrayNotHasKey('помилка', $session->run('update_order', $this->orderArgs($product, $payment)));
        $conversation->update(['ai_checkout_data' => $session->data, 'ai_checkout_active' => true]);

        return $session;
    }

    private function userTexts(array $input): array
    {
        return collect($input)->filter(fn ($item) => ($item['role'] ?? '') === 'user')
            ->flatMap(fn ($item) => is_array($item['content']) ? $item['content'] : [])
            ->where('type', 'input_text')->pluck('text')->values()->all();
    }

    public function test_more_than_twenty_five_messages_ending_in_contact_check_are_read_together_after_four_seconds(): void
    {
        $conversation = $this->conversation();
        $texts = array_map(fn ($index) => 'Уточнення покупця '.$index, range(1, 26));
        $messages = [];
        foreach ($texts as $text) $messages[] = $this->incoming($conversation, $text);
        Carbon::setTestNow(now()->addSecond());
        $texts[] = 'Ви тут?';
        $last = $this->incoming($conversation, 'Ви тут?');
        $receivedAt = (float) now()->format('U.u');
        $calls = $sent = 0;
        Http::fake(function ($request) use ($texts, $receivedAt, &$calls, &$sent) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $calls++;
                $this->assertEquals(4.0, (float) now()->format('U.u') - $receivedAt);
                $this->assertSame($texts, array_slice($this->userTexts($request['input']), -count($texts)));

                return Http::response($this->answer('Так, усі уточнення враховано.'));
            }
            $this->assertStringContainsString('graph.facebook.com', $request->url());
            return Http::response(['message_id' => 'burst-'.(++$sent)]);
        });

        // Найстаріша задача також повинна дочекатися останнього повідомлення серії.
        $run = app(Dispatcher::class)->respond($conversation, $messages[0]->id);
        $this->assertSame('replied', $run->status);
        $this->assertSame($last->id, $run->inbox_message_id);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $sent);
        $this->assertEquals(4.0, array_sum($this->pauses));
        app(Dispatcher::class)->respond($conversation->fresh(), $last->id);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $sent);
    }

    public function test_new_input_during_function_response_discards_old_tools_and_restarts_quiet_window(): void
    {
        $conversation = $this->conversation();
        $first = $this->incoming($conversation, 'Хочу капці');
        $calls = $sent = 0;
        $next = null;
        $newReceivedAt = null;
        Http::fake(function ($request) use ($conversation, &$calls, &$sent, &$next, &$newReceivedAt) {
            if (str_contains($request->url(), 'api.openai.com')) {
                if (++$calls === 1) {
                    $next = $this->incoming($conversation, 'Чорні, 38/39');
                    $newReceivedAt = (float) now()->format('U.u');
                    $this->assertSame('skipped_in_progress', app(Dispatcher::class)->respond($conversation->fresh(), $next->id)->status);

                    return Http::response($this->tool('ask_delivery_details'));
                }
                $this->assertEquals(4.0, (float) now()->format('U.u') - $newReceivedAt);
                $this->assertNull($conversation->fresh()->ai_checkout_data);
                $this->assertFalse(collect($request['input'])->contains('type', 'function_call_output'));
                $this->assertSame(['Хочу капці', 'Чорні, 38/39'], array_slice($this->userTexts($request['input']), -2));

                return Http::response($this->answer('Уточнення кольору й розміру враховано.'));
            }
            $this->assertSame('Уточнення кольору й розміру враховано.', $request['message']['text']);
            return Http::response(['message_id' => 'fresh-'.(++$sent)]);
        });

        app(Dispatcher::class)->respond($conversation, $first->id);
        $this->assertSame(2, $calls);
        $this->assertSame(1, $sent);
        $this->assertEquals(8.0, array_sum($this->pauses));
        $this->assertDatabaseHas('ai_runs', ['inbox_message_id' => $next->id, 'status' => 'replied']);
        $this->assertDatabaseMissing('inbox_messages', ['text' => OrderTexts::all()['delivery_template'], 'sender' => 'ai']);
    }

    public function test_new_input_halfway_through_initial_pause_moves_the_first_api_call_to_four_seconds_after_it(): void
    {
        $conversation = $this->conversation();
        $first = $this->incoming($conversation, 'Хочу домашні');
        $startedAt = (float) now()->format('U.u');
        $next = null;
        $receivedAt = null;
        $pauses = 0;
        $buffer = \Mockery::mock(MessageBuffer::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $buffer->shouldReceive('pause')->andReturnUsing(function (float $seconds) use ($conversation, &$next, &$receivedAt, &$pauses): void {
            if (++$pauses === 1) {
                Carbon::setTestNow(now()->addSeconds(2));
                $next = $this->incoming($conversation, 'Чорні, 38/39');
                $receivedAt = (float) now()->format('U.u');
                Carbon::setTestNow(now()->addMicroseconds((int) ceil(($seconds - 2) * 1_000_000)));
                return;
            }
            Carbon::setTestNow(now()->addMicroseconds((int) ceil($seconds * 1_000_000)));
        });
        $this->app->instance(MessageBuffer::class, $buffer);
        $calls = 0;
        Http::fake(function ($request) use ($startedAt, &$receivedAt, &$calls) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $calls++;
                $this->assertEquals(6.0, (float) now()->format('U.u') - $startedAt);
                $this->assertEquals(4.0, (float) now()->format('U.u') - $receivedAt);
                $this->assertSame(['Хочу домашні', 'Чорні, 38/39'], array_slice($this->userTexts($request['input']), -2));
                return Http::response($this->answer('Усі побажання враховано.'));
            }
            return Http::response(['message_id' => 'initial-reset']);
        });

        $run = app(Dispatcher::class)->respond($conversation, $first->id);
        $this->assertSame('replied', $run->status);
        $this->assertSame($next->id, $run->inbox_message_id);
        $this->assertSame(1, $calls);
        $this->assertSame(2, $pauses);
    }

    public function test_message_during_final_cod_delivery_keeps_draft_and_completes_the_updated_quantity(): void
    {
        $conversation = $this->conversation();
        $first = $this->incoming($conversation, 'Оформляйте замовлення');
        $product = $this->product();
        $this->draft($conversation, $product, $first->id);
        $calls = $sent = 0;
        $next = null;
        $newReceivedAt = null;
        Http::fake(function ($request) use ($conversation, $product, &$calls, &$sent, &$next, &$newReceivedAt) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $calls++;
                if ($calls === 3) {
                    $draft = $conversation->fresh()->ai_checkout_data;
                    $this->assertTrue($conversation->fresh()->ai_enabled);
                    $this->assertSame('draft', $draft['status']);
                    $this->assertTrue($draft['completion_pending']);
                    $this->assertSame(1, $draft['items'][0]['qty']);
                    $this->assertEquals(4.0, (float) now()->format('U.u') - $newReceivedAt);
                }

                return Http::response(match ($calls) {
                    1, 4 => $this->tool('complete_order'),
                    3 => $this->tool('update_order', $this->orderArgs($product, 'cod', 2)),
                    default => $this->answer('Замовлення оформлено.'),
                });
            }
            if (++$sent === 1) {
                $this->assertTrue($conversation->fresh()->ai_enabled);
                $next = $this->incoming($conversation, 'Зачекайте, потрібно дві пари');
                $newReceivedAt = (float) now()->format('U.u');
            }
            return Http::response(['message_id' => 'final-quantity-'.$sent]);
        });

        app(Dispatcher::class)->respond($conversation, $first->id);
        $conversation->refresh();
        $this->assertSame(5, $calls);
        $this->assertSame(2, $sent);
        $this->assertFalse($conversation->ai_enabled);
        $this->assertSame('ready_for_manager', $conversation->ai_checkout_data['status']);
        $this->assertSame(2, $conversation->ai_checkout_data['items'][0]['qty']);
        $this->assertSame('1061.00', $conversation->ai_checkout_data['total']);
        $this->assertSame(2, $conversation->ai_order_items[0]['qty']);
        $this->assertArrayNotHasKey('completion_pending', $conversation->ai_checkout_data);
        $this->assertDatabaseHas('gpt_inbox_replies', ['inbox_message_id' => $first->id, 'status' => 'superseded']);
        $this->assertDatabaseHas('gpt_inbox_replies', ['inbox_message_id' => $next->id, 'status' => 'sent']);
    }

    public function test_payment_delivery_interrupted_after_card_resumes_only_the_two_iban_parts(): void
    {
        $conversation = $this->conversation();
        $first = $this->incoming($conversation, 'Надішліть реквізити');
        $product = $this->product();
        $this->draft($conversation, $product, $first->id, 'full');
        $calls = 0;
        $sent = [];
        $signature = null;
        $next = null;
        Http::fake(function ($request) use ($conversation, $first, &$calls, &$sent, &$signature, &$next) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $calls++;
                if ($calls === 3) {
                    $data = $conversation->fresh()->ai_checkout_data;
                    $this->assertSame('draft', $data['status']);
                    $this->assertSame('530.50', $data['total']);
                    $this->assertSame($signature, $data['payment_request']['signature']);
                    $this->assertSame($first->id, $data['payment_request']['turn_id']);
                    $this->assertSame(2, $data['payment_request']['messages_sent']);
                    $this->assertFalse($data['payment_request']['sent']);
                }
                return Http::response($calls % 2 === 1 ? $this->tool('send_payment_details') : $this->answer(''));
            }
            $sent[] = $request['message']['text'];
            if (count($sent) === 2) {
                $signature = $conversation->fresh()->ai_checkout_data['payment_request']['signature'];
                $next = $this->incoming($conversation, 'А де ще IBAN?');
            }
            return Http::response(['message_id' => 'partial-payment-'.count($sent)]);
        });

        app(Dispatcher::class)->respond($conversation, $first->id);
        $conversation->refresh();
        $texts = OrderTexts::all();
        $this->assertSame(4, $calls);
        $this->assertCount(4, $sent);
        $this->assertStringContainsString('Повна оплата — 530,50 грн', $sent[0]);
        $this->assertSame($texts['card_number'], $sent[1]);
        $this->assertSame(sprintf($texts['iban_intro'], '530,50'), $sent[2]);
        $this->assertSame($texts['iban_number'], $sent[3]);
        $this->assertTrue($conversation->ai_enabled);
        $this->assertSame('draft', $conversation->ai_checkout_data['status']);
        $this->assertTrue($conversation->ai_checkout_data['payment_request']['sent']);
        $this->assertSame(4, $conversation->ai_checkout_data['payment_request']['messages_sent']);
        $this->assertSame($signature, $conversation->ai_checkout_data['payment_request']['signature']);
        $this->assertSame($first->id, $conversation->ai_checkout_data['payment_request']['turn_id']);
        $this->assertDatabaseHas('gpt_inbox_replies', ['inbox_message_id' => $first->id, 'status' => 'superseded', 'next_index' => 2]);
        $this->assertDatabaseHas('gpt_inbox_replies', ['inbox_message_id' => $next->id, 'status' => 'sent', 'next_index' => 2]);
    }

    public function test_receipt_image_followed_by_text_in_same_burst_can_complete_the_order(): void
    {
        $conversation = $this->conversation();
        $requestMessage = $this->incoming($conversation, 'Реквізити для оплати');
        $session = $this->draft($conversation, $this->product(), $requestMessage->id, 'full');
        $session->run('send_payment_details', []);
        $conversation->update(['ai_checkout_data' => $session->data]);
        GptInboxReply::create(['inbox_conversation_id' => $conversation->id, 'inbox_message_id' => $requestMessage->id,
            'provider_version' => 0, 'payload' => [], 'status' => 'sent']);
        $this->incoming($conversation, null, [['type' => 'image', 'url' => 'receipt-image']]);
        $last = $this->incoming($conversation, 'Оплатила, дякую');
        $history = \Mockery::mock(GptHistory::class)->makePartial();
        $history->shouldReceive('image')->andReturn('data:image/png;base64,receipt');
        $this->app->instance(GptHistory::class, $history);
        $calls = $sent = 0;
        Http::fake(function ($request) use (&$calls, &$sent) {
            if (str_contains($request->url(), 'api.openai.com')) {
                if (++$calls === 1) {
                    $blocks = collect($request['input'])->flatMap(fn ($item) => is_array($item['content'] ?? null) ? $item['content'] : []);
                    $this->assertTrue($blocks->contains('type', 'input_image'));
                    $this->assertTrue($blocks->contains('text', 'Оплатила, дякую'));
                    return Http::response($this->tool('complete_order'));
                }
                return Http::response($this->answer('Замовлення прийнято.'));
            }
            return Http::response(['message_id' => 'receipt-burst-'.(++$sent)]);
        });

        $this->assertSame('replied', app(Dispatcher::class)->respond($conversation->fresh(), $last->id)->status);
        $this->assertSame(2, $calls);
        $this->assertSame(1, $sent);
        $this->assertFalse($conversation->fresh()->ai_enabled);
        $this->assertSame('provided_unverified', $conversation->fresh()->ai_checkout_data['receipt']['status']);
        $this->assertSame($last->id, $conversation->fresh()->ai_checkout_data['receipt']['turn_id']);
    }

    public function test_text_followed_by_voice_does_not_hide_the_text_question(): void
    {
        $conversation = $this->conversation();
        $first = $this->incoming($conversation, 'Скільки коштують капці?');
        $last = $this->incoming($conversation, null, [['type' => 'audio', 'url' => 'voice-message']]);
        $calls = $sent = 0;
        Http::fake(function ($request) use (&$calls, &$sent) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $calls++;
                $texts = implode('\n', $this->userTexts($request['input']));
                $this->assertStringContainsString('Скільки коштують капці?', $texts);
                $this->assertStringContainsString('голосове повідомлення', $texts);
                return Http::response($this->answer('Капці — 530,50 грн. Зміст голосового напишіть, будь ласка, текстом.'));
            }
            $this->assertStringContainsString('530,50 грн', $request['message']['text']);
            return Http::response(['message_id' => 'voice-burst-'.(++$sent)]);
        });

        $run = app(Dispatcher::class)->respond($conversation, $first->id);
        $this->assertSame('replied', $run->status);
        $this->assertSame($last->id, $run->inbox_message_id);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $sent);
        $this->assertDatabaseMissing('inbox_messages', ['sender' => 'ai', 'text' => OrderTexts::all()['voice_reject']]);
    }
}
