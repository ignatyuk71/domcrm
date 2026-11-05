<?php

namespace Tests\Feature\Inbox;

use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Services\Ai\Gpt\MessageBuffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GptMessageBufferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 12:00:00.250000');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_each_message_keeps_its_own_precise_arrival_deadline(): void
    {
        $conversation = $this->conversation();
        $buffer = new MessageBuffer;
        $first = $this->incoming($conversation);
        $buffer->received($first);

        $this->advance(1.5);
        $second = $this->incoming($conversation);
        $buffer->received($second);

        $this->assertEqualsWithDelta(2.5, $buffer->remaining($first), 0.00001);
        $this->assertEqualsWithDelta(4.0, $buffer->remaining($second), 0.00001);
        $this->advance(2.5);
        $this->assertSame(0.0, $buffer->remaining($first));
        $this->assertEqualsWithDelta(1.5, $buffer->remaining($second), 0.00001);
    }

    public function test_new_message_during_pause_restarts_the_quiet_period(): void
    {
        $conversation = $this->conversation();
        $buffer = $this->buffer();
        $first = $this->incoming($conversation);
        $buffer->received($first);
        $pauses = [];
        $second = null;
        $buffer->shouldReceive('pause')->twice()->andReturnUsing(function (float $seconds) use ($conversation, $buffer, &$pauses, &$second): void {
            $pauses[] = $seconds;
            if ($second === null) {
                $this->advance(2);
                $second = $this->incoming($conversation);
                $buffer->received($second);
                $this->advance($seconds - 2);

                return;
            }
            $this->advance($seconds);
        });

        $result = $buffer->waitForQuiet($conversation, $first->id);

        $this->assertSame($second->id, $result);
        $this->assertSame([4.0, 2.0], $pauses);
    }

    public function test_old_meta_sent_at_does_not_shorten_the_server_arrival_wait(): void
    {
        $conversation = $this->conversation();
        $message = $this->incoming($conversation, ['sent_at' => now()->subDay()]);
        $buffer = $this->buffer();
        $buffer->received($message);
        $buffer->shouldReceive('pause')->once()->with(4.0)->andReturnUsing(fn (float $seconds) => $this->advance($seconds));

        $this->assertSame($message->id, $buffer->waitForQuiet($conversation, $message->id));
    }

    public function test_missing_cache_uses_created_at_with_a_conservative_second(): void
    {
        $conversation = $this->conversation();
        $message = $this->incoming($conversation, ['sent_at' => now()->subDay()])->fresh();
        $buffer = new MessageBuffer;

        $this->assertEqualsWithDelta(4.75, $buffer->remaining($message), 0.00001);
        $this->advance(4.5);
        $this->assertEqualsWithDelta(0.25, $buffer->remaining($message), 0.00001);
        $this->advance(0.25);
        $this->assertSame(0.0, $buffer->remaining($message));
    }

    public function test_operator_message_stops_waiting(): void
    {
        $conversation = $this->conversation();
        $message = $this->incoming($conversation);
        $buffer = $this->buffer();
        $buffer->received($message);
        $buffer->shouldReceive('pause')->once()->andReturnUsing(function (float $seconds) use ($conversation): void {
            $this->advance($seconds);
            $conversation->messages()->create(['direction' => 'out', 'sender' => 'agent', 'text' => 'Зараз допоможу.', 'sent_at' => now()]);
        });

        $this->assertNull($buffer->waitForQuiet($conversation, $message->id));
    }

    public function test_disabling_ai_during_pause_stops_waiting(): void
    {
        $conversation = $this->conversation();
        $message = $this->incoming($conversation);
        $buffer = $this->buffer();
        $buffer->received($message);
        $buffer->shouldReceive('pause')->once()->andReturnUsing(function (float $seconds) use ($conversation): void {
            $this->advance($seconds);
            $conversation->update(['ai_enabled' => false]);
        });

        $this->assertNull($buffer->waitForQuiet($conversation, $message->id));
    }

    public function test_continuous_messages_cannot_extend_one_wait_past_sixty_seconds(): void
    {
        $conversation = $this->conversation();
        $message = $this->incoming($conversation);
        $buffer = $this->buffer();
        $buffer->received($message);
        $waited = 0.0;
        $buffer->shouldReceive('pause')->times(15)->andReturnUsing(function (float $seconds) use ($conversation, $buffer, &$waited): void {
            $waited += $seconds;
            $this->advance($seconds);
            $buffer->received($this->incoming($conversation));
        });

        $this->assertNull($buffer->waitForQuiet($conversation, $message->id));
        $this->assertSame(60.0, $waited);
    }

    public function test_already_quiet_latest_incoming_message_ignores_ai_output(): void
    {
        $conversation = $this->conversation();
        $first = $this->incoming($conversation);
        $second = $this->incoming($conversation);
        $buffer = $this->buffer();
        $buffer->received($second);
        $this->advance(4);
        $conversation->messages()->create(['direction' => 'out', 'sender' => 'ai', 'text' => 'Фото.', 'sent_at' => now()]);
        $buffer->shouldNotReceive('pause');

        $this->assertSame($second->id, $buffer->waitForQuiet($conversation, $first->id));
    }

    private function buffer(): MessageBuffer
    {
        return \Mockery::mock(MessageBuffer::class)->makePartial()->shouldAllowMockingProtectedMethods();
    }

    private function advance(float $seconds): void
    {
        Carbon::setTestNow(now()->addMicroseconds((int) round($seconds * 1_000_000)));
    }

    private function conversation(): InboxConversation
    {
        $connection = MetaConnection::create(['page_id' => 'BUFFER_PAGE', 'page_name' => 'Магазин', 'page_access_token' => 'test-token', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $connection->id, 'channel' => 'facebook', 'external_id' => 'BUFFER_USER']);

        return InboxConversation::create(['meta_connection_id' => $connection->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook', 'ai_enabled' => true]);
    }

    private function incoming(InboxConversation $conversation, array $attributes = []): InboxMessage
    {
        return $conversation->messages()->create($attributes + ['direction' => 'in', 'sender' => 'contact', 'text' => 'Хочу замовити.', 'sent_at' => now()]);
    }
}
