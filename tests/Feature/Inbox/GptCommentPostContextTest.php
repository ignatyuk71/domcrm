<?php

namespace Tests\Feature\Inbox;

use App\Models\{InboxComment, MetaConnection};
use App\Services\Ai\Gpt\CommentPostContext;
use GuzzleHttp\Psr7\{StreamDecoratorTrait, Utils};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

class GptCommentPostContextTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00'));
        $this->fixtureRoot = sys_get_temp_dir().'/gpt-comment-context-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->fixtureRoot.'/inbox-context');
        $this->app->usePublicPath($this->fixtureRoot);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureRoot);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function png(string $variant = ''): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWPUqvJlYGBgYmBgYGBgAAALUwD1s8HsnAAAAABJRU5ErkJggg==').$variant;
    }

    private function dataImage(string $variant = ''): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png($variant));
    }

    private function jpeg(): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODUK/9sAQwAFAwQEBAMFBAQEBQUFBgcMCAcHBwcPCwsJDBEPEhIRDxERExYcFxMUGhURERghGBodHR8fHxMXIiQiHiQcHh8e/9sAQwEFBQUHBgcOCAgOHhQRFB4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4e/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A4eiiiuE+FP/Z');
    }

    private function shortStream(string $bytes, int $chunkBytes): StreamInterface
    {
        return new class(Utils::streamFor($bytes), $chunkBytes) implements StreamInterface
        {
            use StreamDecoratorTrait;

            public int $reads = 0;

            public function __construct(StreamInterface $stream, private int $chunkBytes)
            {
                $this->stream = $stream;
            }

            public function read($length): string
            {
                $this->reads++;
                return $this->stream->read(min($length, $this->chunkBytes));
            }

            public function getContents(): string
            {
                throw new \LogicException('Необмежене читання потоку заборонене в цьому тесті.');
            }
        };
    }

    private function comment(array $changes = [], ?MetaConnection $connection = null): InboxComment
    {
        $connection ??= MetaConnection::create(['page_id' => 'PAGE-'.bin2hex(random_bytes(4)),
            'page_name' => 'Магазин', 'page_access_token' => 'test-page-token', 'status' => 'active']);

        return InboxComment::create(array_replace(['meta_connection_id' => $connection->id, 'channel' => 'facebook',
            'post_id' => '123_456', 'comment_id' => 'comment-'.bin2hex(random_bytes(4)), 'from_id' => 'USER',
            'text' => 'Ціна?', 'status' => 'new'], $changes));
    }

    public function test_missing_preview_fetches_full_caption_and_image_and_reuses_cache_without_touching_shared_fields(): void
    {
        config(['services.meta.graph_version' => 'v99.0']);
        $comment = $this->comment();
        $caption = str_repeat('Повний опис капців. ', 300);
        Http::fake([
            'https://graph.facebook.com/v99.0/123_456*' => function ($request) use ($caption) {
                $this->assertStringContainsString('attachments', $request['fields']);
                $this->assertSame('Bearer test-page-token', $request->header('Authorization')[0]);
                return Http::response(['message' => $caption, 'full_picture' => 'https://scontent.fbcdn.net/post.png']);
            },
            'https://scontent.fbcdn.net/post.png' => fn () => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame(mb_substr(trim($caption), 0, 4000), $context['text']);
        $this->assertSame([$this->dataImage()], $context['images']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $context['fingerprint']);
        $this->assertNull($comment->fresh()->post_excerpt);
        $this->assertNull($comment->fresh()->post_image);
        $this->assertSame('new', $comment->fresh()->status);
        $nextComment = $this->comment([], $comment->connection);
        $this->assertSame($context, app(CommentPostContext::class)->load($nextComment));
        Http::assertSentCount(2);
        $this->assertCount(1, File::files($this->fixtureRoot.'/inbox-context/gpt-comments'));
        Carbon::setTestNow(now()->addHours(5));
        $this->assertSame($context, app(CommentPostContext::class)->load($nextComment));
        Http::assertSentCount(2);
        Carbon::setTestNow(now()->addHour()->addSecond());
        $this->assertSame($context, app(CommentPostContext::class)->load($nextComment));
        Http::assertSentCount(4);
    }

    public function test_valid_stored_local_preview_needs_no_graph_or_cdn_request(): void
    {
        File::put($this->fixtureRoot.'/inbox-context/stored.png', $this->png());
        $comment = $this->comment(['post_excerpt' => 'Домашні капці', 'post_image' => 'inbox-context/stored.png']);
        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame('Домашні капці', $context['text']);
        $this->assertSame([$this->dataImage()], $context['images']);
        $this->assertSame($context, app(CommentPostContext::class)->load($comment));
        Http::assertNothingSent();
    }

    public function test_truncated_shared_excerpt_is_refreshed_even_when_stored_photo_is_valid(): void
    {
        File::put($this->fixtureRoot.'/inbox-context/stored.png', $this->png());
        $comment = $this->comment(['post_excerpt' => str_repeat('А', 200), 'post_image' => 'inbox-context/stored.png']);
        Http::fake(['https://graph.facebook.com/*' => Http::response(['message' => 'Повний підпис із назвою моделі та ціною.'])]);

        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame('Повний підпис із назвою моделі та ціною.', $context['text']);
        $this->assertSame([$this->dataImage()], $context['images']);
        $this->assertSame(str_repeat('А', 200), $comment->fresh()->post_excerpt);
        Http::assertSentCount(1);
    }

    public function test_facebook_reel_without_full_picture_uses_attachment_thumbnail(): void
    {
        $comment = $this->comment();
        Http::fake([
            'https://graph.facebook.com/*/123_456/attachments*' => Http::response(['data' => [
                ['media' => ['image' => ['src' => 'https://video.fbcdn.net/reel-cover.png']]],
            ]]),
            'https://graph.facebook.com/*/123_456*' => Http::response(['message' => 'Домашні капці з відео', 'full_picture' => null]),
            'https://video.fbcdn.net/reel-cover.png' => Http::response($this->png()),
        ]);

        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame('Домашні капці з відео', $context['text']);
        $this->assertSame([$this->dataImage()], $context['images']);
        Http::assertSentCount(3);
    }

    public function test_instagram_video_uses_thumbnail_instead_of_downloading_the_video(): void
    {
        $comment = $this->comment(['channel' => 'instagram', 'post_id' => '987654']);
        Http::fake([
            'https://graph.facebook.com/*' => function ($request) {
                $this->assertStringContainsString('children.limit(3)', $request['fields']);
                return Http::response(['caption' => 'Теплі моделі', 'media_type' => 'VIDEO',
                    'media_url' => 'https://scontent.cdninstagram.com/movie.mp4',
                    'thumbnail_url' => 'https://scontent.cdninstagram.com/cover.png']);
            },
            'https://scontent.cdninstagram.com/cover.png' => Http::response($this->png()),
        ]);

        $this->assertSame([$this->dataImage()], app(CommentPostContext::class)->load($comment)['images']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'movie.mp4'));
        Http::assertSentCount(2);
    }

    public function test_instagram_carousel_limits_children_and_keeps_image_and_video_thumbnail_order(): void
    {
        $comment = $this->comment(['channel' => 'instagram']);
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['caption' => 'Три моделі', 'media_type' => 'CAROUSEL_ALBUM',
                'media_url' => 'https://scontent.cdninstagram.com/parent.png', 'children' => ['data' => [
                    ['media_type' => 'IMAGE', 'media_url' => 'https://scontent.cdninstagram.com/1.png'],
                    ['media_type' => 'VIDEO', 'media_url' => 'https://scontent.cdninstagram.com/2.mp4', 'thumbnail_url' => 'https://scontent.cdninstagram.com/2.png'],
                    ['media_type' => 'IMAGE', 'media_url' => 'https://scontent.cdninstagram.com/3.png'],
                    ['media_type' => 'IMAGE', 'media_url' => 'https://scontent.cdninstagram.com/4.png'],
                ]]]),
            'https://scontent.cdninstagram.com/1.png' => Http::response($this->png('1')),
            'https://scontent.cdninstagram.com/2.png' => Http::response($this->png('2')),
            'https://scontent.cdninstagram.com/3.png' => Http::response($this->png('3')),
        ]);

        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame([$this->dataImage('1'), $this->dataImage('2'), $this->dataImage('3')], $context['images']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/4.png') || str_contains($request->url(), '/parent.png') || str_contains($request->url(), '.mp4'));
        Http::assertSentCount(4);
    }

    public function test_duplicate_sources_and_bytes_do_not_spend_more_than_three_cdn_attempts(): void
    {
        $comment = $this->comment();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['message' => 'Кілька фото', 'full_picture' => 'https://scontent.fbcdn.net/1.png',
                'attachments' => ['data' => [['media' => ['image' => ['src' => 'https://scontent.fbcdn.net/1.png']],
                    'subattachments' => ['data' => [
                        ['media' => ['image' => ['src' => 'https://scontent.fbcdn.net/2.png']]],
                        ['media' => ['image' => ['src' => 'https://scontent.fbcdn.net/3.png']]],
                        ['media' => ['image' => ['src' => 'https://scontent.fbcdn.net/4.png']]],
                    ]]]]]]),
            'https://scontent.fbcdn.net/1.png' => Http::response($this->png('same')),
            'https://scontent.fbcdn.net/2.png' => Http::response($this->png('same')),
            'https://scontent.fbcdn.net/3.png' => Http::response($this->png('other')),
        ]);

        $this->assertSame([$this->dataImage('same'), $this->dataImage('other')], app(CommentPostContext::class)->load($comment)['images']);
        Http::assertSentCount(4);
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/1.png')));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/4.png'));
    }

    public function test_failed_graph_refresh_is_cached_briefly_then_retried_successfully(): void
    {
        $comment = $this->comment(['post_excerpt' => 'Збережений підпис']);
        Http::fake([
            'https://graph.facebook.com/*' => Http::sequence()->push(['error' => ['code' => 190]], 500)
                ->push(['message' => 'Новий повний підпис', 'full_picture' => 'https://scontent.fbcdn.net/retry.png']),
            'https://scontent.fbcdn.net/retry.png' => Http::response($this->png()),
        ]);

        $first = app(CommentPostContext::class)->load($comment);
        $this->assertSame('Збережений підпис', $first['text']);
        $this->assertSame([], $first['images']);
        $this->assertSame($first, app(CommentPostContext::class)->load($comment));
        Http::assertSentCount(1);
        Carbon::setTestNow(now()->addSeconds(61));
        $next = app(CommentPostContext::class)->load($comment);
        $this->assertSame('Новий повний підпис', $next['text']);
        $this->assertSame([$this->dataImage()], $next['images']);
        $this->assertNotSame($first['fingerprint'], $next['fingerprint']);
        Http::assertSentCount(3);
    }

    public function test_cdn_response_must_contain_an_actual_allowed_image_regardless_of_header_or_extension(): void
    {
        $comment = $this->comment();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['message' => 'Підпис', 'full_picture' => 'https://scontent.fbcdn.net/fake.png']),
            'https://scontent.fbcdn.net/fake.png' => Http::response('<html>Це не фото</html>', 200, ['Content-Type' => 'image/png']),
        ]);
        $this->assertSame([], app(CommentPostContext::class)->load($comment)['images']);
        $this->assertFalse(is_dir($this->fixtureRoot.'/inbox-context/gpt-comments'));
        Http::assertSentCount(2);
    }

    public function test_cdn_redirect_is_not_followed_and_oversized_payload_is_not_used(): void
    {
        foreach (['redirect', 'large'] as $case) {
            $comment = $this->comment(['post_id' => $case]);
            Http::fake([
                'https://graph.facebook.com/*' => Http::response(['message' => 'Підпис', 'full_picture' => 'https://scontent.fbcdn.net/'.$case.'.png']),
                'https://scontent.fbcdn.net/'.$case.'.png' => $case === 'redirect'
                    ? Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])
                    : Http::response($this->png(), 200, ['Content-Length' => 4 * 1024 * 1024 + 1]),
            ]);
            $this->assertSame([], app(CommentPostContext::class)->load($comment)['images']);
        }
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
    }

    public function test_untrusted_urls_and_path_traversal_are_rejected_before_any_download(): void
    {
        $urls = ['http://scontent.fbcdn.net/photo.png', 'https://127.0.0.1/photo.png',
            'https://scontent.fbcdn.net.evil.example/photo.png', 'https://evilfbcdn.net/photo.png',
            'https://user:password@scontent.fbcdn.net/photo.png', 'https://scontent.fbcdn.net:8443/photo.png',
            'file:///etc/passwd'];
        Http::fake(function ($request) use ($urls) {
            if (! str_contains($request->url(), 'https://graph.facebook.com/')) return Http::response('Неприпустимий запит', 500);
            preg_match('~/unsafe_(\d+)~', $request->url(), $match);
            return Http::response(['message' => 'Підпис', 'full_picture' => $urls[(int) $match[1]]]);
        });
        foreach ($urls as $index => $url) {
            $comment = $this->comment(['post_id' => 'unsafe_'.$index, 'post_image' => '../.env']);
            $this->assertSame([], app(CommentPostContext::class)->load($comment)['images']);
        }
        Http::assertSentCount(count($urls));
        Http::assertNotSent(fn ($request) => ! str_contains($request->url(), 'https://graph.facebook.com/'));
    }

    public function test_cache_is_scoped_to_store_and_channel_even_for_the_same_post_id(): void
    {
        $first = $this->comment();
        $otherStore = $this->comment();
        $instagram = $this->comment(['channel' => 'instagram'], $first->connection);
        Http::fake([
            'https://graph.facebook.com/*' => Http::sequence()
                ->push(['message' => 'Перший магазин', 'full_picture' => 'https://scontent.fbcdn.net/1.png'])
                ->push(['message' => 'Другий магазин', 'full_picture' => 'https://scontent.fbcdn.net/1.png'])
                ->push(['caption' => 'Instagram', 'media_type' => 'IMAGE', 'media_url' => 'https://scontent.fbcdn.net/1.png']),
            'https://scontent.fbcdn.net/1.png' => fn () => Http::response($this->png()),
        ]);

        $this->assertSame('Перший магазин', app(CommentPostContext::class)->load($first)['text']);
        $this->assertSame('Другий магазин', app(CommentPostContext::class)->load($otherStore)['text']);
        $this->assertSame('Instagram', app(CommentPostContext::class)->load($instagram)['text']);
        Http::assertSentCount(6);
    }

    public function test_failed_cdn_image_is_retried_after_negative_cache_expires(): void
    {
        $comment = $this->comment();
        Http::fake([
            'https://graph.facebook.com/*' => fn () => Http::response(['message' => 'Модель', 'full_picture' => 'https://scontent.fbcdn.net/retry.png']),
            'https://scontent.fbcdn.net/retry.png' => Http::sequence()->push('Не зображення', 200, ['Content-Type' => 'image/png'])
                ->push($this->png(), 200, ['Content-Type' => 'application/octet-stream']),
        ]);
        $this->assertSame([], app(CommentPostContext::class)->load($comment)['images']);
        $this->assertSame([], app(CommentPostContext::class)->load($comment)['images']);
        Http::assertSentCount(2);
        Carbon::setTestNow(now()->addSeconds(61));
        $this->assertSame([$this->dataImage()], app(CommentPostContext::class)->load($comment)['images']);
        Http::assertSentCount(4);
    }

    public function test_invalid_post_id_and_remote_stored_image_never_become_request_targets(): void
    {
        $comment = $this->comment(['post_id' => '../me?fields=accounts', 'post_image' => 'https://evil.example/photo.png']);
        $this->assertSame([], app(CommentPostContext::class)->load($comment)['images']);
        Http::assertNothingSent();
    }

    public function test_transport_limits_redirects_and_does_not_leak_graph_bearer_token_to_cdn(): void
    {
        $comment = $this->comment();
        $seen = [];
        Http::fake(function ($request, array $options) use (&$seen) {
            $seen[] = ['url' => $request->url(), 'options' => $options, 'authorization' => $request->header('Authorization')];
            return str_contains($request->url(), 'graph.facebook.com')
                ? Http::response(['message' => 'Модель', 'full_picture' => 'https://scontent.fbcdn.net/photo.png'])
                : Http::response($this->png());
        });

        $this->assertCount(1, app(CommentPostContext::class)->load($comment)['images']);
        $this->assertCount(2, $seen);
        foreach ($seen as $index => $request) {
            $this->assertFalse($request['options']['allow_redirects']);
            $this->assertTrue($request['options']['stream']);
            $this->assertLessThanOrEqual($index === 0 ? 5 : 3, $request['options']['timeout']);
            $this->assertLessThanOrEqual($index === 0 ? 5 : 3, $request['options']['read_timeout']);
            $this->assertLessThanOrEqual(2, $request['options']['connect_timeout']);
        }
        $this->assertSame(['Bearer test-page-token'], $seen[0]['authorization']);
        $this->assertSame([], $seen[1]['authorization']);
    }

    public function test_actual_oversized_graph_body_is_rejected_without_cdn_request(): void
    {
        $comment = $this->comment(['post_excerpt' => 'Збережений підпис']);
        Http::fake(['https://graph.facebook.com/*' => Http::response(['message' => str_repeat('x', 256 * 1024),
            'full_picture' => 'https://scontent.fbcdn.net/photo.png'])]);
        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame('Збережений підпис', $context['text']);
        $this->assertSame([], $context['images']);
        Http::assertSentCount(1);
    }

    public function test_actual_oversized_image_body_is_rejected_without_trusting_missing_content_length(): void
    {
        $comment = $this->comment();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['message' => 'Модель', 'full_picture' => 'https://scontent.fbcdn.net/large.png']),
            'https://scontent.fbcdn.net/large.png' => Http::response($this->png().str_repeat('x', 4 * 1024 * 1024)),
        ]);
        $this->assertSame([], app(CommentPostContext::class)->load($comment)['images']);
        $this->assertFalse(is_dir($this->fixtureRoot.'/inbox-context/gpt-comments'));
        Http::assertSentCount(2);
    }

    public function test_unsupported_instagram_optional_fields_retry_minimal_caption_and_image_query(): void
    {
        $comment = $this->comment(['channel' => 'instagram']);
        $fields = [];
        Http::fake([
            'https://graph.facebook.com/*' => function ($request) use (&$fields) {
                $fields[] = $request['fields'];
                return str_contains($request['fields'], 'children')
                    ? Http::response(['error' => ['code' => 100, 'message' => 'Unsupported field']], 400)
                    : Http::response(['caption' => 'Повний підпис Instagram', 'media_type' => 'IMAGE',
                        'media_url' => 'https://scontent.cdninstagram.com/minimal.png']);
            },
            'https://scontent.cdninstagram.com/minimal.png' => Http::response($this->png()),
        ]);

        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame('Повний підпис Instagram', $context['text']);
        $this->assertSame([$this->dataImage()], $context['images']);
        $this->assertCount(2, $fields);
        $this->assertSame('caption,media_type,media_url', $fields[1]);
        Http::assertSentCount(3);
    }

    public function test_failed_facebook_full_and_minimal_post_queries_still_try_attachment_thumbnail(): void
    {
        $comment = $this->comment(['post_excerpt' => 'Збережений опис Reels']);
        $fields = [];
        Http::fake([
            'https://graph.facebook.com/*' => function ($request) use (&$fields) {
                $fields[] = $request['fields'];
                return str_contains($request->url(), '/attachments?')
                    ? Http::response(['data' => [['media' => ['image' => ['src' => 'https://video.fbcdn.net/fallback.png']]]]])
                    : Http::response(['error' => ['code' => 100, 'message' => 'Unsupported field']], 400);
            },
            'https://video.fbcdn.net/fallback.png' => Http::response($this->png()),
        ]);

        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame('Збережений опис Reels', $context['text']);
        $this->assertSame([$this->dataImage()], $context['images']);
        $this->assertCount(3, $fields);
        $this->assertSame('message,full_picture', $fields[1]);
        $this->assertSame('media,subattachments.limit(3){media}', $fields[2]);
        Http::assertSentCount(4);
    }

    public function test_instagram_video_minimal_fallback_fetches_thumbnail_in_a_separate_bounded_query(): void
    {
        $comment = $this->comment(['channel' => 'instagram']);
        $fields = [];
        Http::fake([
            'https://graph.facebook.com/*' => function ($request) use (&$fields) {
                $fields[] = $request['fields'];
                return match ($request['fields']) {
                    'caption,media_type,media_url' => Http::response(['caption' => 'Відео моделі', 'media_type' => 'VIDEO',
                        'media_url' => 'https://scontent.cdninstagram.com/movie.mp4']),
                    'thumbnail_url' => Http::response(['thumbnail_url' => 'https://scontent.cdninstagram.com/fallback.png']),
                    default => Http::response(['error' => ['code' => 100]], 400),
                };
            },
            'https://scontent.cdninstagram.com/fallback.png' => Http::response($this->png()),
        ]);

        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame('Відео моделі', $context['text']);
        $this->assertSame([$this->dataImage()], $context['images']);
        $this->assertSame('thumbnail_url', $fields[2]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '.mp4'));
        Http::assertSentCount(4);
    }

    public function test_instagram_carousel_fallback_can_retry_children_without_unsupported_thumbnail_field(): void
    {
        $comment = $this->comment(['channel' => 'instagram']);
        Http::fake([
            'https://graph.facebook.com/*' => function ($request) {
                if ($request['fields'] === 'caption,media_type,media_url') {
                    return Http::response(['caption' => 'Карусель', 'media_type' => 'CAROUSEL_ALBUM']);
                }
                if (str_contains($request->url(), '/children?') && $request['fields'] === 'media_type,media_url') {
                    return Http::response(['data' => [
                        ['media_type' => 'IMAGE', 'media_url' => 'https://scontent.cdninstagram.com/first.png'],
                        ['media_type' => 'IMAGE', 'media_url' => 'https://scontent.cdninstagram.com/second.png'],
                    ]]);
                }
                return Http::response(['error' => ['code' => 100]], 400);
            },
            'https://scontent.cdninstagram.com/first.png' => Http::response($this->png('1')),
            'https://scontent.cdninstagram.com/second.png' => Http::response($this->png('2')),
        ]);

        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame('Карусель', $context['text']);
        $this->assertSame([$this->dataImage('1'), $this->dataImage('2')], $context['images']);
        Http::assertSentCount(6);
        $children = Http::recorded(fn ($request) => str_contains($request->url(), '/children?'));
        $this->assertCount(2, $children);
        foreach ($children as [$request]) $this->assertSame(3, $request['limit']);
    }

    public function test_graph_and_cdn_streams_are_read_until_eof_even_when_every_read_returns_a_short_chunk(): void
    {
        $comment = $this->comment();
        $graph = $this->shortStream(json_encode(['message' => 'Повний підпис із мережевих фрагментів',
            'full_picture' => 'https://scontent.fbcdn.net/chunked.jpg'], JSON_UNESCAPED_UNICODE), 7);
        $cdn = $this->shortStream($this->jpeg(), 31);
        Http::fake([
            'https://graph.facebook.com/*' => Http::response($graph),
            'https://scontent.fbcdn.net/chunked.jpg' => Http::response($cdn, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame('Повний підпис із мережевих фрагментів', $context['text']);
        $this->assertSame(['data:image/jpeg;base64,'.base64_encode($this->jpeg())], $context['images']);
        $this->assertGreaterThan(1, $graph->reads);
        $this->assertGreaterThan(1, $cdn->reads);
        $this->assertTrue($graph->eof());
        $this->assertTrue($cdn->eof());
        $files = File::files($this->fixtureRoot.'/inbox-context/gpt-comments');
        $this->assertCount(1, $files);
        $this->assertSame($this->jpeg(), File::get($files[0]->getPathname()));
        Http::assertSentCount(2);
    }

    public function test_truncated_jpeg_without_end_marker_is_rejected_even_when_header_and_gd_can_read_it(): void
    {
        $comment = $this->comment();
        $truncated = substr($this->jpeg(), 0, -2);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($truncated));
        $this->assertIsArray(getimagesizefromstring($truncated));
        if (function_exists('imagecreatefromstring')) $this->assertNotFalse(@imagecreatefromstring($truncated));
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['message' => 'Модель', 'full_picture' => 'https://scontent.fbcdn.net/truncated.jpg']),
            'https://scontent.fbcdn.net/truncated.jpg' => Http::response($truncated),
        ]);

        $this->assertSame([], app(CommentPostContext::class)->load($comment)['images']);
        $this->assertFalse(is_dir($this->fixtureRoot.'/inbox-context/gpt-comments'));
        Http::assertSentCount(2);
    }

    public function test_truncated_cached_and_stored_jpeg_are_refreshed_instead_of_becoming_an_openai_image(): void
    {
        File::put($this->fixtureRoot.'/inbox-context/truncated.jpg', substr($this->jpeg(), 0, -2));
        $comment = $this->comment(['post_excerpt' => 'Старий підпис', 'post_image' => 'inbox-context/truncated.jpg']);
        $key = 'gpt-comment-context:v1:'.$comment->meta_connection_id.':'.$comment->channel.':'.hash('sha256', $comment->post_id);
        Cache::put($key, ['text' => 'Старий кеш', 'locals' => ['inbox-context/truncated.jpg']], now()->addHours(6));
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['message' => 'Актуальний підпис', 'full_picture' => 'https://scontent.fbcdn.net/full.jpg']),
            'https://scontent.fbcdn.net/full.jpg' => Http::response($this->jpeg()),
        ]);

        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame('Актуальний підпис', $context['text']);
        $this->assertSame(['data:image/jpeg;base64,'.base64_encode($this->jpeg())], $context['images']);
        $this->assertNotSame(['inbox-context/truncated.jpg'], Cache::get($key)['locals']);
        Http::assertSentCount(2);
    }

    public function test_old_unversioned_context_cache_is_ignored_after_stream_validation_fix(): void
    {
        $comment = $this->comment();
        File::put($this->fixtureRoot.'/inbox-context/old.png', $this->png());
        $key = 'gpt-comment-context:'.$comment->meta_connection_id.':'.$comment->channel.':'.hash('sha256', $comment->post_id);
        Cache::put($key, ['text' => 'Старий кеш', 'locals' => ['inbox-context/old.png']], now()->addHours(6));
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['message' => 'Оновлений контекст', 'full_picture' => 'https://scontent.fbcdn.net/new.png']),
            'https://scontent.fbcdn.net/new.png' => Http::response($this->png('new')),
        ]);

        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame('Оновлений контекст', $context['text']);
        $this->assertSame([$this->dataImage('new')], $context['images']);
        Http::assertSentCount(2);
    }

    public function test_non_progressing_stream_does_not_spin_until_deadline_or_return_a_partial_context(): void
    {
        $comment = $this->comment(['post_excerpt' => 'Збережений підпис']);
        $stream = $this->shortStream('{"message":"невидимі байти"}', 0);
        Http::fake(['https://graph.facebook.com/*' => Http::response($stream)]);

        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame('Збережений підпис', $context['text']);
        $this->assertSame([], $context['images']);
        $this->assertSame(1, $stream->reads);
        Http::assertSentCount(1);
    }
}
