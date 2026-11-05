<?php

namespace App\Services\Ai\Gpt;

use App\Models\InboxComment;
use App\Services\Ai\Channels\GptHistory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/** Контекст допису для GPT не змінює спільне превʼю та стан інших агентів. */
class CommentPostContext
{
    private const IMAGE_BYTES = 4 * 1024 * 1024;
    private const GRAPH_BYTES = 256 * 1024;
    private const MAX_IMAGES = 3;
    private const BUDGET_SECONDS = 12;
    private const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public function __construct(private GptHistory $history) {}

    /** @return array{text: string, images: array, image_paths: array, post_url: ?string, fingerprint: string} */
    public function load(InboxComment $comment): array
    {
        $key = 'gpt-comment-context:v1:'.$comment->meta_connection_id.':'.$comment->channel.':'.hash('sha256', (string) $comment->post_id);
        if (is_array($cached = Cache::get($key))) {
            $images = [];
            foreach ($cached['locals'] ?? [] as $local) {
                if ($image = $this->validatedDataImage($this->history->image(['local' => $local]))) $images[] = $image;
            }
            if (count($images) === count($cached['locals'] ?? [])) {
                return $this->context((string) ($cached['text'] ?? ''), $images, $cached['locals'] ?? [], self::safePostUrl($cached['post_url'] ?? null));
            }
            Cache::forget($key);
        }

        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $text = mb_substr(trim((string) $comment->post_excerpt), 0, 4000);
        $postUrl = null;
        $images = $locals = $seen = [];
        if ($comment->post_image && ($image = $this->validatedDataImage($this->history->image(['local' => $comment->post_image])))) {
            $images[] = $image;
            $locals[] = $comment->post_image;
            $seen[hash('sha256', $image)] = true;
        }
        // Спільне превʼю обрізається до 200 символів; GPT завантажує повний підпис окремо.
        $needsText = $text === '' || mb_strlen($text) >= 200 || str_ends_with($text, '…') || str_ends_with($text, '...');
        $refresh = $images === [] || $needsText;
        $success = ! $refresh;
        if ($refresh && in_array($comment->channel, ['facebook', 'instagram'], true)
            && preg_match('/^[a-zA-Z0-9_-]{1,200}$/D', (string) $comment->post_id)
            && ($token = $comment->connection?->page_access_token)) {
            $fields = $comment->channel === 'instagram'
                ? 'caption,media_type,media_url,thumbnail_url,permalink,children.limit(3){media_type,media_url,thumbnail_url}'
                : 'message,full_picture,permalink_url,attachments.limit(3){media,subattachments.limit(3){media}}';
            $attachmentsTried = false;
            $post = $this->graph((string) $comment->post_id, $fields, $token, $deadline, $unsupported);
            if ($post === null && $unsupported) {
                // Необовʼязкове поле не повинне блокувати базовий підпис і фото допису.
                $minimal = $comment->channel === 'instagram' ? 'caption,media_type,media_url' : 'message,full_picture';
                $post = $this->graph((string) $comment->post_id, $minimal, $token, $deadline);
                if ($comment->channel === 'instagram' && $post !== null) {
                    if (($post['media_type'] ?? '') === 'VIDEO') {
                        $extra = $this->graph((string) $comment->post_id, 'thumbnail_url', $token, $deadline);
                        if ($extra !== null) $post = array_replace($post, $extra);
                    } elseif (($post['media_type'] ?? '') === 'CAROUSEL_ALBUM') {
                        $children = $this->graph($comment->post_id.'/children', 'media_type,media_url,thumbnail_url', $token, $deadline, $unsupportedChildren);
                        if ($children === null && $unsupportedChildren) {
                            $children = $this->graph($comment->post_id.'/children', 'media_type,media_url', $token, $deadline);
                        }
                        if ($children !== null) $post['children'] = $children;
                    }
                } elseif ($comment->channel === 'facebook' && $post === null) {
                    $attachmentsTried = true;
                    $attachments = $this->graph($comment->post_id.'/attachments', 'media,subattachments.limit(3){media}', $token, $deadline);
                    if ($attachments !== null) $post = ['attachments' => $attachments];
                }
            }
            if ($post !== null) {
                $postUrl = self::safePostUrl($post['permalink_url'] ?? $post['permalink'] ?? null);
                $caption = $post[$comment->channel === 'instagram' ? 'caption' : 'message'] ?? null;
                if (is_string($caption) && trim($caption) !== '') $text = mb_substr(trim($caption), 0, 4000);
                $urls = $comment->channel === 'instagram' ? $this->instagramImages($post) : $this->facebookImages($post);
                if ($urls === [] && $images === [] && $comment->channel === 'facebook' && ! $attachmentsTried) {
                    // У Reels full_picture інколи відсутнє: читаємо кадр із вкладень допису.
                    $attachments = $this->graph($comment->post_id.'/attachments', 'media,subattachments.limit(3){media}', $token, $deadline);
                    if ($attachments !== null) $urls = $this->facebookImages(['attachments' => $attachments]);
                }
                $failed = false;
                $attempts = 0;
                foreach (array_unique($urls) as $url) {
                    if (count($images) >= self::MAX_IMAGES || $attempts >= self::MAX_IMAGES) break;
                    $attempts++;
                    if (! ($image = $this->download($url, $deadline))) {
                        $failed = true;
                        continue;
                    }
                    $hash = hash('sha256', $image);
                    if (isset($seen[$hash])) continue;
                    $seen[$hash] = true;
                    $images[] = $image;
                    if ($local = $this->persist($image)) $locals[] = $local;
                }
                $success = $images !== [] && ! $failed && (! $needsText || (is_string($caption) && trim($caption) !== ''));
            }
        }
        // Кеш містить короткі посилання на файли, а не багатомегабайтні base64.
        if (count($locals) === count($images)) {
            Cache::put($key, ['text' => $text, 'locals' => $locals, 'post_url' => $postUrl], now()->addSeconds($success ? 21600 : 60));
        }

        return $this->context($text, $images, $locals, $postUrl);
    }

    public static function safePostUrl(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 2000 || ! filter_var($url, FILTER_VALIDATE_URL)) return null;
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! in_array(strtolower($parts['host'] ?? ''), ['facebook.com', 'www.facebook.com', 'm.facebook.com',
                'instagram.com', 'www.instagram.com', 'fb.watch'], true)
            || preg_match('~^/(?:l\.php|flx/warn|share(?:r(?:\.php)?)?|dialog|login)(?:[/.]|$)~i', rawurldecode($parts['path'] ?? ''))) return null;
        $query = [];
        parse_str($parts['query'] ?? '', $supplied);
        foreach (['id', 'story_fbid', 'fbid', 'v'] as $field) {
            if (isset($supplied[$field]) && is_string($supplied[$field]) && ctype_digit($supplied[$field])) $query[$field] = $supplied[$field];
        }

        return 'https://'.strtolower($parts['host']).($parts['path'] ?? '/').($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    private function context(string $text, array $images, array $paths = [], ?string $postUrl = null): array
    {
        $images = array_slice($images, 0, self::MAX_IMAGES);
        $fingerprint = hash('sha256', json_encode(['text' => $text,
            'images' => array_map(fn ($image) => hash('sha256', $image), $images), 'post_url' => $postUrl], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return ['text' => $text, 'images' => $images, 'image_paths' => array_slice($paths, 0, self::MAX_IMAGES),
            'post_url' => $postUrl, 'fingerprint' => $fingerprint];
    }

    private function graph(string $path, string $fields, #[\SensitiveParameter] string $token, float $deadline, ?bool &$unsupported = null): ?array
    {
        $unsupported = false;
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) return null;
        $version = (string) config('services.meta.graph_version', 'v21.0');
        if (! preg_match('/^v\d+\.\d+$/D', $version)) $version = 'v21.0';
        try {
            $query = ['fields' => $fields];
            if (str_ends_with($path, '/attachments') || str_ends_with($path, '/children')) $query['limit'] = self::MAX_IMAGES;
            $response = Http::withToken($token)->acceptJson()->connectTimeout(min(2, $remaining))
                ->timeout(min(5, $remaining))->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => min(5, $remaining)])
                ->get('https://graph.facebook.com/'.$version.'/'.$path, $query);
            $unsupported = $response->status() >= 400 && $response->status() < 500;
            if (! $response->successful() || (int) $response->header('Content-Length') > self::GRAPH_BYTES) return null;
            $body = $this->readBounded($response->toPsrResponse()->getBody(), self::GRAPH_BYTES, $deadline);
            if ($body === null) return null;
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if ((int) ($data['error']['code'] ?? 0) === 100) $unsupported = true;

            return is_array($data) && ! isset($data['error']) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function instagramImages(array $post): array
    {
        $children = $post['children']['data'] ?? [];
        $nodes = is_array($children) && $children !== [] ? array_slice($children, 0, self::MAX_IMAGES) : [$post];
        $urls = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) continue;
            $url = ($node['media_type'] ?? '') === 'VIDEO'
                ? ($node['thumbnail_url'] ?? null) : ($node['media_url'] ?? $node['thumbnail_url'] ?? null);
            if (is_string($url) && $url !== '') $urls[] = $url;
        }

        return $urls;
    }

    private function facebookImages(array $post): array
    {
        $urls = [];
        if (is_string($post['full_picture'] ?? null) && $post['full_picture'] !== '') $urls[] = $post['full_picture'];
        $attachments = $post['attachments']['data'] ?? [];
        foreach (array_slice(is_array($attachments) ? $attachments : [], 0, self::MAX_IMAGES) as $attachment) {
            if (! is_array($attachment)) continue;
            $url = $attachment['media']['image']['src'] ?? null;
            if (is_string($url) && $url !== '') $urls[] = $url;
            $children = $attachment['subattachments']['data'] ?? [];
            foreach (array_slice(is_array($children) ? $children : [], 0, self::MAX_IMAGES) as $child) {
                $url = is_array($child) ? ($child['media']['image']['src'] ?? null) : null;
                if (is_string($url) && $url !== '') $urls[] = $url;
            }
        }

        return $urls;
    }

    private function download(string $url, float $deadline): ?string
    {
        $parts = filter_var($url, FILTER_VALIDATE_URL) ? parse_url($url) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
            || ! preg_match('/(?:^|\.)(fbcdn\.net|cdninstagram\.com|fbsbx\.com)$/D', strtolower($parts['host'] ?? ''))) return null;
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) return null;
        try {
            $response = Http::connectTimeout(min(2, $remaining))->timeout(min(3, $remaining))
                ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => min(3, $remaining)])->get($url);
            if (! $response->successful() || (int) $response->header('Content-Length') > self::IMAGE_BYTES) return null;
            $bytes = $this->readBounded($response->toPsrResponse()->getBody(), self::IMAGE_BYTES, $deadline);
            return $bytes === null ? null : self::validatedImageData($bytes);
        } catch (\Throwable) {
            return null;
        }
    }

    /** read() може повернути лише мережевий фрагмент, навіть коли запитано весь файл. */
    private function readBounded(StreamInterface $stream, int $limit, float $deadline): ?string
    {
        $bytes = '';
        while (! $stream->eof()) {
            if (microtime(true) >= $deadline) return null;
            $chunk = $stream->read(min(65536, $limit + 1 - strlen($bytes)));
            if ($chunk === '') return $stream->eof() ? $bytes : null;
            $bytes .= $chunk;
            if (strlen($bytes) > $limit) return null;
        }

        return $bytes;
    }

    private function validatedDataImage(?string $image): ?string
    {
        if ($image === null || ! preg_match('~^data:(image/[a-z]+);base64,(.+)$~sD', $image, $match)) return null;
        $bytes = base64_decode($match[2], true);
        if ($bytes === false) return null;
        $validated = self::validatedImageData($bytes);

        return $validated !== null && str_starts_with($validated, 'data:'.$match[1].';') ? $validated : null;
    }

    /** Перевірка вже прочитаного файла без мережі та перекодування. */
    public static function validatedImageData(string $bytes): ?string
    {
        if ($bytes === '' || strlen($bytes) > self::IMAGE_BYTES) return null;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $size = @getimagesizefromstring($bytes);
        if (! isset(self::MIMES[$mime]) || $size === false || $size[0] * $size[1] > 40_000_000) return null;
        // GD відновлює деякі обірвані JPEG, тому кінцевий маркер перевіряємо окремо.
        if ($mime === 'image/jpeg' && ! str_ends_with($bytes, "\xff\xd9")) return null;
        if (function_exists('imagecreatefromstring')) {
            $memory = ini_get('memory_limit');
            if ($memory && $memory !== '-1') {
                $unit = strtolower(substr($memory, -1));
                $limit = (int) $memory * match ($unit) { 'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1 };
                $needed = $size[0] * $size[1] * 5 + strlen($bytes) * 2 + 8 * 1024 * 1024;
                if ($needed > $limit - memory_get_usage(true)) return null;
            }
            $decoded = @imagecreatefromstring($bytes);
            if ($decoded === false) return null;
            unset($decoded);
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function persist(string $image): ?string
    {
        if (! preg_match('~^data:(image/[a-z]+);base64,(.+)$~sD', $image, $match) || ! isset(self::MIMES[$match[1]])) return null;
        $bytes = base64_decode($match[2], true);
        if ($bytes === false) return null;
        $local = 'inbox-context/gpt-comments/'.hash('sha256', $bytes).'.'.self::MIMES[$match[1]];
        try {
            File::ensureDirectoryExists(public_path('inbox-context/gpt-comments'));
            if (! is_file(public_path($local)) && file_put_contents(public_path($local), $bytes, LOCK_EX) === false) return null;

            return $local;
        } catch (\Throwable) {
            return null;
        }
    }
}
