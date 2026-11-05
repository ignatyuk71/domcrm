<?php

namespace Tests\Feature\Inbox;

use App\Models\AiRun;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Services\Ai\Channels\GptHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GptImageAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_instagram_post_without_text_is_kept_before_the_following_customer_question(): void
    {
        $this->withFixtures(function (array $files): void {
            $conversation = $this->conversation();
            $this->incoming($conversation, null, ['attachments' => [['type' => 'ig_post', 'local' => $files['png']]]]);
            $this->incoming($conversation, 'Яка ціна таких?');
            $history = new GptHistory;

            $input = $history->build($conversation);

            $this->assertCount(3, $input);
            $this->assertSame('user', $input[1]['role']);
            $this->assertSame([['type' => 'input_image', 'image_url' => $this->fixtureDataUrl($files['png'], 'image/png')]], $input[1]['content']);
            $this->assertSame([['type' => 'input_text', 'text' => 'Яка ціна таких?']], $input[2]['content']);
            $this->assertTrue($history->hasCurrentImage());
        });
    }

    public function test_handled_instagram_jpeg_stays_in_history_without_becoming_a_new_receipt(): void
    {
        $this->withFixtures(function (array $files): void {
            $conversation = $this->conversation();
            $photo = $this->incoming($conversation, null, ['attachments' => [['type' => 'ig_post', 'local' => $files['jpeg']]]]);
            AiRun::create(['inbox_conversation_id' => $conversation->id, 'inbox_message_id' => $photo->id, 'status' => 'replied']);
            $this->incoming($conversation, 'Ще підкажіть розмір');
            $history = new GptHistory;

            $input = $history->build($conversation);

            $this->assertSame([$this->fixtureDataUrl($files['jpeg'], 'image/jpeg')], array_column($this->images($input), 'image_url'));
            $this->assertFalse($history->hasCurrentImage());
            $this->assertSame('Ще підкажіть розмір', $input[2]['content'][0]['text']);
        });
    }

    public function test_two_later_local_pdf_files_do_not_displace_the_pending_instagram_jpeg(): void
    {
        $this->withFixtures(function (array $files): void {
            $conversation = $this->conversation();
            $this->incoming($conversation, null, ['attachments' => [['type' => 'ig_post', 'local' => $files['jpeg']]]]);
            $this->incoming($conversation, 'Перший документ', ['attachments' => [['type' => 'file', 'local' => $files['pdf']]]]);
            $this->incoming($conversation, 'Другий документ', ['attachments' => [['type' => 'file', 'local' => $files['pdf']]]]);
            $history = new GptHistory;

            $input = $history->build($conversation);

            $this->assertSame([$this->fixtureDataUrl($files['jpeg'], 'image/jpeg')], array_column($this->images($input), 'image_url'));
            $this->assertSame('input_image', $input[1]['content'][0]['type']);
            $this->assertSame('Перший документ', $input[2]['content'][0]['text']);
            $this->assertSame('Другий документ', $input[3]['content'][0]['text']);
            $this->assertTrue($history->hasCurrentImage());
        });
    }

    public function test_share_and_story_contexts_keep_their_original_sources_when_read_as_images(): void
    {
        $conversation = $this->conversation();
        $contexts = [
            ['type' => 'share', 'local' => 'inbox-context/shared.png', 'url' => 'https://scontent.fbcdn.net/shared.png'],
            ['type' => 'story', 'local' => 'inbox-media/story.jpg', 'url' => 'https://scontent.cdninstagram.com/story.jpg'],
        ];
        foreach ($contexts as $context) {
            $this->incoming($conversation, null, ['context' => $context]);
        }
        $seen = [];
        $history = \Mockery::mock(GptHistory::class)->makePartial();
        $history->shouldReceive('image')->twice()->andReturnUsing(function (array $attachment) use (&$seen): string {
            $seen[] = $attachment;

            return 'data:image/png;base64,'.base64_encode($attachment['local']);
        });

        $input = $history->build($conversation);

        $this->assertCount(2, $seen);
        foreach ($contexts as $index => $context) {
            $this->assertSame('image', $seen[$index]['type']);
            $this->assertSame($context['local'], $seen[$index]['local']);
            $this->assertSame($context['url'], $seen[$index]['url']);
        }
        $this->assertCount(2, $this->images($input));
        $this->assertTrue($history->hasCurrentImage());
    }

    public function test_the_same_picture_in_attachment_and_context_is_not_sent_twice(): void
    {
        $this->withFixtures(function (array $files): void {
            $conversation = $this->conversation();
            $source = ['local' => $files['png'], 'url' => url($files['png'])];
            $this->incoming($conversation, null, [
                'attachments' => [['type' => 'ig_post'] + $source],
                'context' => ['type' => 'share'] + $source,
            ]);
            $history = new GptHistory;

            $this->assertSame([$this->fixtureDataUrl($files['png'], 'image/png')], array_column($this->images($history->build($conversation)), 'image_url'));
            $this->assertTrue($history->hasCurrentImage());
        });
    }

    public function test_generic_file_uses_actual_image_mime_and_rejects_pdf_or_html_disguised_as_png(): void
    {
        $this->withFixtures(function (array $files): void {
            $history = new GptHistory;
            $this->assertSame($this->fixtureDataUrl($files['png'], 'image/png'), $history->image(['type' => 'file', 'local' => $files['png']]));
            $this->assertNull($history->image(['type' => 'file', 'local' => $files['pdf']]));
            $this->assertNull($history->image(['type' => 'file', 'local' => $files['html']]));

            $conversation = $this->conversation();
            $this->incoming($conversation, null, ['attachments' => [['type' => 'file', 'local' => $files['png']]]]);
            $this->assertCount(1, $this->images($history->build($conversation)));

            $this->incoming($conversation, 'Це документ', ['attachments' => [
                ['type' => 'file', 'local' => $files['pdf']],
                ['type' => 'file', 'local' => $files['html']],
            ]]);
            $input = $history->build($conversation);
            $this->assertCount(1, $this->images($input));
            $this->assertFalse(collect($input[count($input) - 1]['content'])->contains('type', 'input_image'));
        });
    }

    public function test_unreadable_duplicate_url_does_not_hide_the_working_local_context_image(): void
    {
        $this->withFixtures(function (array $files): void {
            $conversation = $this->conversation();
            $url = 'https://example.com/unavailable.jpg';
            $this->incoming($conversation, null, [
                'attachments' => [['type' => 'ig_post', 'url' => $url]],
                'context' => ['type' => 'share', 'url' => $url, 'local' => $files['png']],
            ]);
            $history = new GptHistory;

            $input = $history->build($conversation);

            $this->assertSame([$this->fixtureDataUrl($files['png'], 'image/png')], array_column($this->images($input), 'image_url'));
            $this->assertTrue($history->hasCurrentImage());
        });
    }

    public function test_unsafe_urls_and_local_traversal_do_not_trigger_network_requests(): void
    {
        $this->withFixtures(function (array $files): void {
            $history = new GptHistory;
            foreach ([
                'https://example.com/private.png',
                'http://scontent.fbcdn.net/photo.png',
                'https://fbcdn.net.attacker.example/photo.png',
                'https://scontent.fbcdn.net:8443/photo.png',
                'https://user:password@scontent.fbcdn.net/photo.png',
                'https://127.0.0.1/private.png',
                'https://cdninstagram.com@127.0.0.1/photo.png',
                'file:///inbox-media/photo.png',
                'data:image/png;base64,AAAA',
            ] as $url) {
                $this->assertNull($history->image(['type' => 'ig_post', 'url' => $url]), $url);
            }
            $this->assertNull($history->image(['type' => 'file', 'local' => 'inbox-media/../'.$files['png']]));
            $this->assertNull($history->image(['type' => 'image', 'local' => str_replace('inbox-media/', 'other-directory/', $files['png'])]));
        });
    }

    public function test_only_the_last_two_image_messages_and_three_candidate_images_per_message_are_read(): void
    {
        $conversation = $this->conversation();
        for ($message = 1; $message <= 3; $message++) {
            $attachments = [
                ['type' => 'audio', 'local' => 'inbox-media/audio-'.$message.'.mp3'],
                ['type' => 'video', 'local' => 'inbox-media/video-'.$message.'.mp4'],
            ];
            for ($image = 1; $image <= 4; $image++) {
                $attachments[] = ['type' => 'ig_post', 'local' => 'inbox-media/picture-'.$message.'-'.$image.'.png'];
            }
            $this->incoming($conversation, 'Повідомлення '.$message, ['attachments' => $attachments]);
        }
        $seen = [];
        $history = \Mockery::mock(GptHistory::class)->makePartial();
        $history->shouldReceive('image')->times(6)->andReturnUsing(function (array $attachment) use (&$seen): string {
            $seen[] = $attachment['local'];

            return 'data:image/png;base64,'.base64_encode($attachment['local']);
        });

        $input = $history->build($conversation);

        $this->assertSame([
            'inbox-media/picture-2-1.png', 'inbox-media/picture-2-2.png', 'inbox-media/picture-2-3.png',
            'inbox-media/picture-3-1.png', 'inbox-media/picture-3-2.png', 'inbox-media/picture-3-3.png',
        ], $seen);
        $this->assertCount(6, $this->images($input));
        $this->assertStringContainsString('не вдалося переглянути в цьому ході', json_encode($input[1], JSON_UNESCAPED_UNICODE));
        $this->assertTrue($history->hasCurrentImage());
    }

    public function test_three_invalid_candidates_exhaust_the_read_limit_without_inspecting_the_fourth(): void
    {
        $conversation = $this->conversation();
        $attachments = [];
        for ($i = 1; $i <= 4; $i++) {
            $attachments[] = ['type' => 'file', 'local' => 'inbox-media/candidate-'.$i.'.bin'];
        }
        $this->incoming($conversation, null, ['attachments' => $attachments]);
        $seen = [];
        $history = \Mockery::mock(GptHistory::class)->makePartial();
        $history->shouldReceive('image')->times(3)->andReturnUsing(function (array $attachment) use (&$seen): ?string {
            $seen[] = $attachment['local'];

            return $attachment['local'] === 'inbox-media/candidate-4.bin' ? 'data:image/png;base64,AAAA' : null;
        });

        $input = $history->build($conversation);

        $this->assertSame(['inbox-media/candidate-1.bin', 'inbox-media/candidate-2.bin', 'inbox-media/candidate-3.bin'], $seen);
        $this->assertSame([], $this->images($input));
        $this->assertFalse($history->hasCurrentImage());
        $this->assertStringContainsString('Не вигадуй його вміст', $input[1]['content'][0]['text']);
    }

    /** Усі медіафайли цього тесту видаляються навіть після невдалої перевірки. */
    private function withFixtures(callable $callback): void
    {
        $directory = 'inbox-media/gpt-image-test-'.Str::uuid();
        $absolute = public_path($directory);
        $files = [];
        try {
            if (! is_dir($absolute)) mkdir($absolute, 0775, true);
            foreach ([
                'png' => ['actual-image.bin', UploadedFile::fake()->image('photo.png', 2, 2)->get()],
                'jpeg' => ['photo.jpg', UploadedFile::fake()->image('photo.jpg', 2, 2)->get()],
                'pdf' => ['document.png', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"],
                'html' => ['page.png', '<!doctype html><html><body>Це сторінка, не зображення.</body></html>'],
            ] as $name => [$filename, $bytes]) {
                $files[$name] = $directory.'/'.$filename;
                file_put_contents(public_path($files[$name]), $bytes);
            }
            $callback($files);
        } finally {
            foreach ($files as $path) {
                if (is_file(public_path($path))) unlink(public_path($path));
            }
            if (is_dir($absolute)) rmdir($absolute);
        }
    }

    private function fixtureDataUrl(string $path, string $mime): string
    {
        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents(public_path($path)));
    }

    private function conversation(): InboxConversation
    {
        $connection = MetaConnection::create(['page_id' => 'GPT_IMAGE_PAGE', 'page_name' => 'Магазин', 'page_access_token' => 'test-token', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $connection->id, 'channel' => 'instagram', 'external_id' => 'GPT_IMAGE_USER']);

        return InboxConversation::create(['meta_connection_id' => $connection->id, 'inbox_contact_id' => $contact->id, 'channel' => 'instagram']);
    }

    private function incoming(InboxConversation $conversation, ?string $text, array $attributes = []): InboxMessage
    {
        return $conversation->messages()->create($attributes + ['direction' => 'in', 'sender' => 'contact', 'text' => $text, 'sent_at' => now()]);
    }

    private function images(array $input): array
    {
        return collect($input)->flatMap(fn (array $message) => is_array($message['content']) ? $message['content'] : [])
            ->where('type', 'input_image')->values()->all();
    }
}
