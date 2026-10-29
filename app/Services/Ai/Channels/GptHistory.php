<?php

namespace App\Services\Ai\Channels;

use App\Models\{AiPhoto, InboxConversation};
use Illuminate\Support\Facades\Http;

/** Незалежна історія GPT: лише текст і перевірені зображення, без чужих tool-викликів. */
class GptHistory
{
    public function build(InboxConversation $conversation): array
    {
        $rows = $conversation->messages()->when($conversation->ai_context_after_id,
            fn ($q, $id) => $q->where('id', '>', $id))->latest('id')->limit(20)->get()->reverse();
        $input = [['role' => 'user', 'content' => \App\Services\Ai\Gpt\ConversationNotes::clockNote()]];
        // Останнє фото (зокрема квитанція) завжди має пріоритет над давніми зображеннями.
        $visionIds = $rows->filter(fn ($m) => $m->direction === 'in' && (collect($m->attachments ?? [])->contains('type', 'image')
            || ! empty($m->context['url']) || ! empty($m->context['local'])))->take(-2)->pluck('id')->all();
        $images = 0;
        foreach ($rows as $message) {
            $incoming = $message->direction === 'in';
            $content = [];
            $text = trim((string) $message->text);
            if (! $incoming && ($photos = $this->photoNote($message->attachments ?? [])) !== '') $text = trim($text."\n".$photos);
            if ($incoming && ($message->context['type'] ?? '') === 'reply') {
                $quoted = $conversation->messages()->where('external_message_id', $message->context['mid'] ?? '')->first();
                if ($quoted) $text = '(Відповідь на повідомлення: '.mb_substr((string) $quoted->text, 0, 1000).' '.$this->photoNote($quoted->attachments ?? []).")\n".$text;
            }
            if ($incoming && collect($message->attachments ?? [])->contains(fn ($a) => in_array($a['type'] ?? '', ['video', 'reel'], true))) {
                $text .= "\n(Клієнт надіслав відео. Перегляд відео недоступний; попроси фото чи уточнення словами.)";
            }
            if ($text !== '') $content[] = ['type' => 'input_text', 'text' => mb_substr($text, 0, 6000)];
            if ($incoming && in_array($message->id, $visionIds, true)) {
                $attachments = $message->attachments ?? [];
                $context = $message->context ?? [];
                if (! empty($context['local']) || ! empty($context['url'])) $attachments[] = $context + ['type' => 'image'];
                foreach (array_slice($attachments, 0, 3) as $attachment) {
                    if (($attachment['type'] ?? null) !== 'image' || $images >= 6) continue;
                    if ($data = $this->image($attachment)) {
                        $content[] = ['type' => 'input_image', 'image_url' => $data];
                        $images++;
                    }
                }
            }
            if ($content) $input[] = ['role' => $incoming ? 'user' : 'assistant', 'content' => $content];
        }
        return $input;
    }

    private function photoNote(array $attachments): string
    {
        $labels = [];
        foreach ($attachments as $attachment) {
            if (($attachment['type'] ?? '') !== 'image') continue;
            $photo = ! empty($attachment['ai_photo_id']) ? AiPhoto::find($attachment['ai_photo_id']) : null;
            if (! $photo && ! empty($attachment['url'])) {
                $path = ltrim((string) parse_url($attachment['url'], PHP_URL_PATH), '/');
                if (str_starts_with($path, 'ai-gallery/')) $photo = AiPhoto::where('path', $path)->first();
            }
            if (! $photo) continue;
            $products = $photo->products()->where('is_active', true)->get(['products.id', 'title']);
            if ($products->isEmpty()) continue;
            $labels[] = ($photo->purpose === 'size_guide' ? 'Пояснення розміру' : 'Фото товарів').' №'.$photo->id.': '
                .$products->map(fn ($p) => '#'.$p->id.' '.strip_tags($p->title))->implode(', ');
        }
        return $labels ? '(Магазин надіслав: '.implode('; ', $labels).')' : '';
    }

    public function image(array $attachment): ?string
    {
        $bytes = null;
        $relative = ltrim((string) ($attachment['local'] ?? ''), '/');
        if ($relative === '') {
            $url = (string) ($attachment['url'] ?? '');
            if (str_starts_with($url, rtrim(url('/'), '/').'/')) $relative = substr($url, strlen(rtrim(url('/'), '/')) + 1);
        }
        if ($relative !== '' && preg_match('~^(inbox-media|inbox-context|inbox-uploads|ai-gallery)/[a-zA-Z0-9_./-]+$~D', $relative) && ! str_contains($relative, '..')) {
            $base = realpath(public_path(explode('/', $relative)[0]));
            $path = realpath(public_path($relative));
            if ($base && $path && str_starts_with($path, $base.DIRECTORY_SEPARATOR) && is_file($path) && filesize($path) <= 4 * 1024 * 1024) {
                $bytes = file_get_contents($path);
            }
        }
        if ($bytes === null) {
            $url = (string) ($attachment['url'] ?? '');
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            // Завантаження лише з CDN Meta; редиректи та довільні адреси заборонені.
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_PORT)
                || parse_url($url, PHP_URL_USER) || ! preg_match('/(?:^|\.)(fbcdn\.net|cdninstagram\.com|fbsbx\.com)$/D', $host)) return null;
            try {
                $response = Http::timeout(10)->withOptions(['allow_redirects' => false, 'stream' => true])->get($url);
                if (! $response->successful()) return null;
                $bytes = $response->toPsrResponse()->getBody()->read(4 * 1024 * 1024 + 1);
            } catch (\Throwable) { return null; }
        }
        if (! is_string($bytes) || strlen($bytes) > 4 * 1024 * 1024) return null;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return null;
        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
