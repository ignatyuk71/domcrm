<?php

namespace App\Services\Meta;

use App\Models\InboxMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Докачує медіа вкладень переписки до себе в public/inbox-media —
 * посилання Meta (lookaside/scontent) тимчасові, і без локальної копії
 * фото/голосові у старих переписках з часом «протухають».
 *
 * Оригінальний url НІКОЛИ не видаляється: local лише додається поруч,
 * фронт віддає local, якщо він є, інакше — віддалений url.
 */
class InboxMediaStore
{
    private const MAX_BYTES = 30 * 1024 * 1024;

    /**
     * Білий список типів: лише те, що безпечно віддавати з нашого домену
     * (жодних svg/html — це вектор XSS зі стороннього файлу).
     */
    private const EXT_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/aac' => 'aac',
        'audio/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'application/pdf' => 'pdf',
    ];

    /**
     * Пройтись по вкладеннях повідомлення і докачати ті, що ще без локальної копії.
     * Ідемпотентно: local/dead повторно не чіпаємо, свої ж файли не качаємо.
     */
    public function persistMessageAttachments(InboxMessage $message): void
    {
        $attachments = $message->attachments ?? [];
        $changed = false;

        foreach ($attachments as $i => $att) {
            if (!empty($att['local']) || !empty($att['dead'])) {
                continue;
            }
            $url = $att['url'] ?? null;
            if (!$url || !str_starts_with($url, 'http')) {
                continue;
            }
            // Наші власні файли (inbox-uploads, каталог) качати нема сенсу.
            if (str_starts_with($url, rtrim((string) config('app.url'), '/'))) {
                continue;
            }

            $result = $this->download($url);
            if ($result === null) {
                continue; // тимчасова помилка — заплановане inbox:persist-media доспробує
            }
            $attachments[$i] = array_merge($att, $result);
            $changed = true;
        }

        if ($changed) {
            $message->update(['attachments' => $attachments]);
        }
    }

    /**
     * Скачати аватарку контакта до себе в public/inbox-avatars.
     * Файл фіксований на контакт (перезаписується при тижневому оновленні,
     * тека не росте). Повертає відносний шлях або null при невдачі.
     *
     * Нащо: браузер клієнта тягнув аватарки напряму з cdninstagram.com —
     * їх ріжуть блокувальники/протухання підпису. Зі свого домену — стабільно.
     */
    public function downloadAvatar(string $url, int $contactId): ?string
    {
        try {
            $r = Http::timeout(10)->get($url);
            if (!$r->successful()) {
                return null;
            }
            $mime = trim(explode(';', (string) $r->header('Content-Type'))[0]);
            if (!str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
                return null;
            }
            $body = $r->body();
            if ($body === '' || strlen($body) > 5 * 1024 * 1024) {
                return null;
            }

            $dir = public_path('inbox-avatars');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $ext = self::EXT_BY_MIME[$mime] ?? 'jpg';
            $name = 'c' . $contactId . '.' . $ext;
            if (@file_put_contents($dir . '/' . $name, $body) === false) {
                return null;
            }

            // ?v= — щоб браузер не тримав стару картинку після оновлення файла.
            return 'inbox-avatars/' . $name . '?v=' . substr(md5($body), 0, 8);
        } catch (\Throwable $e) {
            Log::info('Inbox: не вдалося скачати аватарку', ['contact' => $contactId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Скачати один файл.
     *  - ['local' => 'inbox-media/….jpg'] — успіх;
     *  - ['dead' => true] — качати безглуздо (посилання мертве або тип поза білим списком);
     *  - null — тимчасова помилка (мережа/5xx), варто спробувати пізніше.
     */
    public function download(string $url): ?array
    {
        try {
            $r = Http::timeout(15)->get($url);

            if ($r->clientError()) {
                return ['dead' => true]; // протухло назавжди — не довбемось
            }
            if (!$r->successful()) {
                return null;
            }

            $mime = trim(explode(';', (string) $r->header('Content-Type'))[0]);
            $ext = self::EXT_BY_MIME[$mime] ?? null;
            $body = $r->body();

            if (!$ext || $body === '' || strlen($body) > self::MAX_BYTES) {
                return ['dead' => true];
            }

            $dir = public_path('inbox-media');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $name = Str::random(28) . '.' . $ext;
            if (@file_put_contents($dir . '/' . $name, $body) === false) {
                Log::warning('Inbox media: не можу записати файл', ['dir' => $dir]);
                return null;
            }

            return ['local' => 'inbox-media/' . $name];
        } catch (\Throwable $e) {
            Log::info('Inbox media: не вдалося докачати вкладення', ['error' => $e->getMessage()]);
            return null;
        }
    }
}