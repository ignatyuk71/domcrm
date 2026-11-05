<?php

namespace Tests\Feature\Inbox;

use App\Models\AiRun;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Services\Ai\Channels\GptHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GptHistoryBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_replied_run_keeps_twenty_past_messages_and_the_entire_pending_batch_in_order(): void
    {
        $conversation = $this->conversation();
        $past = $this->messages($conversation, 'Давнє', 30);
        $this->handled($conversation, $past[29]);
        $pending = $this->messages($conversation, 'Поточне', 28);

        $history = new GptHistory;
        $input = $history->build($conversation);

        $this->assertSame(
            array_column([...array_slice($past, -20), ...$pending], 'text'),
            $this->messageTexts($input),
        );
        $this->assertCount(49, $input);
        $this->assertSame('user', $input[0]['role']);
        $this->assertTrue($history->hasPendingBatch($conversation));
    }

    public function test_latest_manual_reply_limits_long_past_history_without_truncating_pending_messages(): void
    {
        $conversation = $this->conversation();
        $old = $this->messages($conversation, 'Попереднє', 35);
        $firstManual = $this->outgoing($conversation, 'Перша відповідь оператора', 'agent');
        $later = $this->messages($conversation, 'Після першої відповіді', 6);
        $manual = $this->outgoing($conversation, 'Остання відповідь оператора', 'agent');
        $pending = $this->messages($conversation, 'Нова серія', 27);

        $history = new GptHistory;
        $input = $history->build($conversation);
        $expectedPast = array_slice([...$old, $firstManual, ...$later, $manual], -20);

        $this->assertSame(array_column([...$expectedPast, ...$pending], 'text'), $this->messageTexts($input));
        $this->assertCount(48, $input);
        $this->assertTrue($history->hasPendingBatch($conversation));
    }

    public function test_partial_ai_output_does_not_mark_earlier_pending_messages_as_handled(): void
    {
        $conversation = $this->conversation();
        $past = $this->messages($conversation, 'Опрацьоване', 30);
        $this->handled($conversation, $past[29]);
        $pending = $this->messages($conversation, 'Неопрацьоване', 26);
        $partial = $this->outgoing($conversation, 'Частково надіслана відповідь ШІ', 'ai');
        $last = $this->incoming($conversation, 'Ще одне уточнення');

        $history = new GptHistory;
        $input = $history->build($conversation);

        $this->assertSame(
            array_column([...array_slice($past, -20), ...$pending, $partial, $last], 'text'),
            $this->messageTexts($input),
        );
        $this->assertSame('assistant', $input[count($input) - 2]['role']);
        $this->assertTrue($history->hasPendingBatch($conversation));
    }

    public function test_image_in_an_earlier_pending_message_is_current_when_followed_by_text(): void
    {
        $conversation = $this->conversation();
        $handled = $this->incoming($conversation, 'Попередній вибір');
        $this->handled($conversation, $handled);
        $this->incoming($conversation, 'Фото покупця', ['attachments' => [$this->attachment('pending-photo')]]);
        $this->incoming($conversation, 'Підкажіть розмір');
        $history = $this->historyWithImages();

        $input = $history->build($conversation);

        $this->assertTrue($history->hasCurrentImage());
        $this->assertSame([$this->dataUrl('pending-photo')], array_column($this->images($input), 'image_url'));
        $this->assertSame('Підкажіть розмір', $this->messageTexts($input)[2]);
    }

    public function test_image_before_the_handled_boundary_is_historical_and_current_flag_resets_on_rebuild(): void
    {
        $conversation = $this->conversation();
        $photo = $this->incoming($conversation, 'Попереднє фото', ['attachments' => [$this->attachment('past-photo')]]);
        $history = $this->historyWithImages();
        $history->build($conversation);
        $this->assertTrue($history->hasCurrentImage());

        $this->handled($conversation, $photo);
        $this->incoming($conversation, 'Нове питання без фото');
        $input = $history->build($conversation);

        $this->assertFalse($history->hasCurrentImage());
        $this->assertSame([$this->dataUrl('past-photo')], array_column($this->images($input), 'image_url'));
        $this->assertFalse($history->hasPendingBatch($conversation));
    }

    public function test_images_older_than_the_last_two_get_an_unavailable_placeholder(): void
    {
        $conversation = $this->conversation();
        for ($i = 1; $i <= 4; $i++) {
            $this->incoming($conversation, 'Зображення '.$i, ['attachments' => [$this->attachment('photo-'.$i)]]);
        }
        $history = $this->historyWithImages();
        $history->shouldReceive('image')->with($this->attachment('photo-1'))->never();
        $history->shouldReceive('image')->with($this->attachment('photo-2'))->never();

        $input = $history->build($conversation);

        $this->assertSame([$this->dataUrl('photo-3'), $this->dataUrl('photo-4')], array_column($this->images($input), 'image_url'));
        foreach ([1, 2] as $index) {
            $content = $input[$index]['content'];
            $this->assertSame('Зображення '.$index, $content[0]['text']);
            $this->assertStringContainsString('не вдалося переглянути в цьому ході', $content[1]['text']);
            $this->assertStringContainsString('Не вигадуй його вміст', $content[1]['text']);
            $this->assertFalse(collect($content)->contains('type', 'input_image'));
        }
        foreach ([3, 4] as $index) {
            $this->assertCount(2, $input[$index]['content']);
            $this->assertSame('input_image', $input[$index]['content'][1]['type']);
        }
    }

    private function conversation(): InboxConversation
    {
        $connection = MetaConnection::create(['page_id' => 'GPT_HISTORY_PAGE', 'page_name' => 'Магазин', 'page_access_token' => 'test-token', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $connection->id, 'channel' => 'facebook', 'external_id' => 'GPT_HISTORY_USER']);

        return InboxConversation::create(['meta_connection_id' => $connection->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);
    }

    /** Дати навмисно зворотні: порядок історії має відповідати порядку отримання. */
    private function messages(InboxConversation $conversation, string $prefix, int $count): array
    {
        $messages = [];
        for ($i = 1; $i <= $count; $i++) {
            $messages[] = $this->incoming($conversation, $prefix.' '.$i, ['sent_at' => now()->subMinutes($i)]);
        }

        return $messages;
    }

    private function incoming(InboxConversation $conversation, string $text, array $attributes = []): InboxMessage
    {
        return $conversation->messages()->create($attributes + ['direction' => 'in', 'sender' => 'contact', 'text' => $text, 'sent_at' => now()]);
    }

    private function outgoing(InboxConversation $conversation, string $text, string $sender): InboxMessage
    {
        return $conversation->messages()->create(['direction' => 'out', 'sender' => $sender, 'text' => $text, 'sent_at' => now()]);
    }

    private function handled(InboxConversation $conversation, InboxMessage $message): void
    {
        AiRun::create(['inbox_conversation_id' => $conversation->id, 'inbox_message_id' => $message->id, 'status' => 'replied']);
    }

    private function historyWithImages(): GptHistory
    {
        $history = \Mockery::mock(GptHistory::class)->makePartial();
        $history->shouldReceive('image')->andReturnUsing(fn (array $attachment) => $this->dataUrl($attachment['url']));

        return $history;
    }

    private function attachment(string $name): array
    {
        return ['type' => 'image', 'url' => $name];
    }

    private function dataUrl(string $name): string
    {
        return 'data:image/png;base64,'.base64_encode($name);
    }

    /** Системну позначку часу пропускаємо; перевіряємо порядок клієнтського контексту. */
    private function messageTexts(array $input): array
    {
        return array_map(fn (array $message) => is_array($message['content']) ? $message['content'][0]['text'] : $message['content'], array_slice($input, 1));
    }

    private function images(array $input): array
    {
        return collect($input)->flatMap(fn (array $message) => is_array($message['content']) ? $message['content'] : [])
            ->where('type', 'input_image')->values()->all();
    }
}
