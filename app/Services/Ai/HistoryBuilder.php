<?php

namespace App\Services\Ai;

use App\Models\AiPhoto;
use App\Models\InboxConversation;
use App\Models\InboxMessage;

/**
 * Побудова історії діалогу у формат Claude Messages API: текст, vision
 * (картинки клієнта/сторіс/пост), примітки контексту та вкладень.
 * Винесено з AiAgentService. Чистку слів робить TextScrubber, збіг фото — ImageMatcher.
 */
class HistoryBuilder
{
    /** Скільки останніх фото клієнта передаємо моделі як картинки (vision). */
    private const MAX_VISION_IMAGES = 2;

    public function __construct(
        private TextScrubber $scrubber,
        private ImageMatcher $imageMatcher,
    ) {
    }

    private function scrub(?string $text): ?string
    {
        return $this->scrubber->scrub($text);
    }

    private function stripPhotoPlaceholder(?string $text): string
    {
        return $this->scrubber->stripPhotoPlaceholder($text);
    }

    private function matchGalleryPhoto(string $bytes): ?AiPhoto
    {
        return $this->imageMatcher->matchGalleryPhoto($bytes);
    }

    private function fetchImage(string $url): ?array
    {
        return $this->imageMatcher->fetchImage($url);
    }

    /**
     * Історія діалогу → формат Claude Messages API.
     * in → user, out → assistant; сусідні однакові ролі зливаються; починається з user.
     */
    public function buildHistory(InboxConversation $conversation, int $limit = 20): array
    {
        $items = $conversation->messages()
            // «Скинути памʼять ШІ»: усе до мітки для агента не існує.
            ->when($conversation->ai_context_after_id, fn ($q) => $q->where('id', '>', $conversation->ai_context_after_id))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        // Vision: 2 НАЙСВІЖІШІ повідомлення з картинками (фото клієнта АБО
        // сторіс/пост, на які він відповів) ідуть у модель як зображення.
        $visionMessageIds = $items
            ->filter(fn ($m) => $m->direction === 'in' && ($this->clientImageUrls($m) !== [] || $this->contextImageUrl($m)))
            ->sortByDesc('id')
            ->take(self::MAX_VISION_IMAGES)
            ->pluck('id')
            ->all();

        // Цитати «у відповідь на повідомлення»: mid → процитоване повідомлення (одним запитом).
        $quotedMids = $items->pluck('context.mid')->filter()->unique()->values();
        $quoted = $quotedMids->isEmpty()
            ? collect()
            : $conversation->messages()->whereIn('external_message_id', $quotedMids)->get()->keyBy('external_message_id');

        $messages = [];
        foreach ($items as $m) {
            $role = $m->direction === 'in' ? 'user' : 'assistant';
            $text = trim((string) $m->text);

            // Власні минулі репліки агента чистимо від службових слів і від
            // плейсхолдерів фото, щоб модель не вчилась друкувати їх текстом.
            if ($role === 'assistant') {
                // + чистка markdown/галюцинацій: щоб модель не копіювала власні
                // старі артефакти з історії (Sonnet вчиться сам у себе).
                $text = $this->scrubber->stripModelArtifacts($this->stripPhotoPlaceholder((string) $this->scrub($text)));
            }

            $blocks = [];
            if ($role === 'user' && in_array($m->id, $visionMessageIds, true)) {
                // Контекст (сторіс/пост) — першим: це «про що мова».
                $urls = array_slice(array_merge(
                    array_filter([$this->contextImageUrl($m)]),
                    $this->clientImageUrls($m)
                ), 0, 2);
                foreach ($urls as $url) {
                    $img = $this->fetchImage($url);
                    if (!$img) {
                        continue;
                    }
                    $blocks[] = [
                        'type' => 'image',
                        'source' => ['type' => 'base64', 'media_type' => $img['mime'], 'data' => base64_encode($img['bytes'])],
                    ];
                    // Скрін нашого ж фото / наш пост → точний товар, без вгадування.
                    if ($match = $this->matchGalleryPhoto($img['bytes'])) {
                        $list = $match->products
                            ->map(fn ($p) => '#' . $p->id . ' ' . $this->scrub($p->title) . ' — ' . round((float) $p->sale_price) . ' грн')
                            ->implode('; ');
                        if ($list !== '') {
                            $blocks[] = [
                                'type' => 'text',
                                'text' => "(система: це фото збігається з фото №{$match->id} НАШОЇ галереї. На ньому наші товари: {$list}. Точний збіг — відповідай саме по них, не вгадуй.)",
                            ];
                        }
                    } else {
                        // Скріншот/власний знімок клієнта: зір на кольорах бреше
                        // (кейс Ірини: капучино назвала пудровим) → колір не визначаємо.
                        $blocks[] = [
                            'type' => 'text',
                            'text' => '(система: точного збігу цього фото з нашою галереєю НЕМАЄ — це може бути скріншот або власний знімок клієнта. КОЛІР із фото НЕ визначай і НЕ називай як факт. Визнач лише тип (домашні/вуличні/дитячі), назви ціну групи, надішли КОЛАЖ через send_photos і запитай, який саме колір цікавить — клієнт сам покаже на колажі.)',
                        ];
                    }
                }
                $hasImages = collect($blocks)->contains(fn ($b) => ($b['type'] ?? '') === 'image');
                if ($hasImages && $text === '') {
                    $text = '(зображення вище — роздивись і знайди відповідник у каталозі)';
                }
            }

            // Рілс/відео переглядати не вміємо: чесно скажи це клієнту, а не
            // «фото не долучилося» (кейс соні 05.07 — переслала рілс).
            if ($role === 'user' && $this->hasVideoAttachment($m)) {
                $blocks[] = [
                    'type' => 'text',
                    'text' => '(система: клієнт надіслав ВІДЕО або РІЛС — ти НЕ можеш переглядати відео. НЕ кажи «фото не долучилося». Чесно скажи, що відео переглянути не можеш, і попроси надіслати фото чи скріншот — або уточни модель/колір словами.)',
                ];
            }

            // Примітка про контекст: на ЩО відповів клієнт.
            if ($role === 'user' && ($c = $m->context)) {
                $note = '';
                if (($c['type'] ?? '') === 'reply') {
                    $q = $quoted[$c['mid'] ?? ''] ?? null;
                    $qt = trim((string) ($q?->text ?? ''));
                    if ($qt !== '') {
                        $note = '(у відповідь на повідомлення: «' . mb_substr($qt, 0, 140) . '»)';
                    } elseif ($q && ($lbl = $this->photoProductsLabel($q)) !== '') {
                        // Відповідь на наше фото/колаж → бот знає, ЩО саме на ньому.
                        $note = "(у відповідь на наше фото; на ньому товари: {$lbl})";
                    } elseif ($q && !empty($q->attachments)) {
                        $note = '(у відповідь на фото в цій розмові)';
                    } else {
                        $note = '(у відповідь на одне з попередніх повідомлень)';
                    }
                } elseif (($c['type'] ?? '') === 'story') {
                    $note = '(клієнт відповів на нашу СТОРІС' . (in_array($m->id, $visionMessageIds, true) ? ' — її зображення вище, визнач по ньому товар' : '') . ')';
                } elseif (($c['type'] ?? '') === 'share') {
                    $note = '(клієнт переслав наш ПОСТ' . (in_array($m->id, $visionMessageIds, true) ? ' — його зображення вище, визнач по ньому товар' : '') . ')';
                }
                if ($note !== '') {
                    $text = trim($note . "\n" . $text);
                }
            }

            if ($text === '' && empty($blocks)) {
                if (empty($m->attachments)) {
                    continue;
                }
                // Фото в історії НЕ позначаємо токеном-плейсхолдером: модель починала
                // друкувати його як текст замість виклику send_photos.
                if ($role === 'user') {
                    $text = $this->clientAttachmentNote($m);
                } else {
                    // Власні надіслані фото — службова примітка З ТОВАРАМИ:
                    // інакше бот не памʼятає, що показував, і «ціна цих?» губиться.
                    $text = $this->sentPhotosNote($m);
                    if ($text === '') {
                        continue;
                    }
                }
            }

            if ($text !== '') {
                $blocks[] = ['type' => 'text', 'text' => $text];
            }

            if (!empty($messages) && $messages[count($messages) - 1]['role'] === $role) {
                $prev = $messages[count($messages) - 1]['content'];
                $messages[count($messages) - 1]['content'] = array_merge(
                    is_array($prev) ? $prev : [['type' => 'text', 'text' => $prev]],
                    $blocks
                );
            } else {
                // Чисто текстове повідомлення лишаємо рядком (компактніше в логах/кеші).
                $messages[] = [
                    'role' => $role,
                    'content' => (count($blocks) === 1 && $blocks[0]['type'] === 'text') ? $blocks[0]['text'] : $blocks,
                ];
            }
        }

        // Перше повідомлення має бути від user
        while (!empty($messages) && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        // Годинник для моделі: без нього вона писала «Доброго дня!» о 22:26 і
        // обіцяла «відправимо сьогодні» після 12:00 (кейси 05.07). Кладемо в
        // ОСТАННЄ user-повідомлення (некешована частина — кеш промпту цілий).
        if (!empty($messages)) {
            $last = count($messages) - 1;
            if ($messages[$last]['role'] === 'user') {
                $note = ['type' => 'text', 'text' => self::clockNote()];
                $prev = $messages[$last]['content'];
                $messages[$last]['content'] = array_merge(
                    is_array($prev) ? $prev : [['type' => 'text', 'text' => $prev]],
                    [$note]
                );
            }
        }

        return $messages;
    }

    /** Поточні день і час словами — щоб привітання і «сьогодні» були чесними. */
    public static function clockNote(): string
    {
        $now = now();
        $days = ['неділя', 'понеділок', 'вівторок', 'середа', 'четвер', "п'ятниця", 'субота'];
        $part = match (true) {
            $now->hour >= 6 && $now->hour < 11 => 'ранок',
            $now->hour >= 11 && $now->hour < 17 => 'день',
            $now->hour >= 17 && $now->hour < 23 => 'вечір',
            default => 'ніч',
        };

        return sprintf(
            '(система: зараз %s, %s, %s — %s. Вітайся відповідно до часу доби. «Відправимо сьогодні» обіцяй лише якщо ще НЕ минуло 12:00 і сьогодні не неділя — інакше чесно кажи, що відправка наступного робочого дня.)',
            $days[$now->dayOfWeek],
            $now->format('d.m.Y'),
            $now->format('H:i'),
            $part
        );
    }

    /** Кеш фото галереї з товарами (на час одного запиту). */
    private ?\Illuminate\Support\Collection $galleryPhotosMemo = null;

    private function galleryPhotos(): \Illuminate\Support\Collection
    {
        return $this->galleryPhotosMemo ??= AiPhoto::with('products:products.id,title,sale_price')->get();
    }

    /**
     * Службова примітка про надіслані клієнту фото: які САМЕ товари він побачив.
     * Без неї модель не памʼятає, що показувала, і «яка ціна цих?» втрачає сенс.
     */
    /** Які товари зображені на фото повідомлення (по нашій галереї). '' якщо не наші фото. */
    private function photoProductsLabel(InboxMessage $m): string
    {
        $labels = [];
        foreach ($m->attachments ?? [] as $a) {
            $url = (string) ($a['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $photo = $this->galleryPhotos()->first(fn (AiPhoto $p) => str_contains($url, $p->path));
            if (!$photo) {
                continue;
            }
            $list = $photo->products
                ->map(fn ($p) => '#' . $p->id . ' ' . $this->scrub($p->title) . ' — ' . round((float) $p->sale_price) . ' грн')
                ->implode('; ');
            $labels[] = $list !== '' ? $list : "фото №{$photo->id}";
        }

        return implode(' | ', array_unique($labels));
    }

    private function sentPhotosNote(InboxMessage $m): string
    {
        $label = $this->photoProductsLabel($m);

        // СИСТЕМНИЙ голос, а не «мова бота»: коли нотатка звучала від першої особи
        // («надіслала клієнту фото…»), модель бачила її як власний текст і почала
        // КОПІЮВАТИ у відповіді замість реально викликати send_photos (кейс Тані 04.07).
        return $label !== '' ? "(система: ти надіслала клієнту фото: {$label})" : '';
    }

    /** Картинка контексту (сторіс/пост, на які відповів клієнт): локальна копія або віддалена. */
    private function contextImageUrl(InboxMessage $m): ?string
    {
        $c = $m->context;
        if (!$c || !in_array($c['type'] ?? '', ['story', 'share'], true)) {
            return null;
        }

        return !empty($c['local']) ? url($c['local']) : ($c['url'] ?? null);
    }

    /** URL-и картинок клієнта з повідомлення (лише вхідні зображення). */
    private function clientImageUrls(InboxMessage $m): array
    {
        // Не лише type=image: пересланий пост (ig_post/share/media_share) несе
        // картинку поста — раніше відсіювався, і бот казав «фото не долучилося»
        // (кейс Оксани К. 05.07). Відео/рілси сюди не беремо (там url не картинка);
        // сміття відсіє fetchImage — він валідує байти. Локальна копія — перша
        // (посилання Meta протухають).
        return collect($m->attachments ?? [])
            ->filter(fn ($a) => (!empty($a['url']) || !empty($a['local']))
                && preg_match('/image|ig_post|media_share|share|story/i', (string) ($a['type'] ?? '')))
            ->map(fn ($a) => !empty($a['local']) ? url($a['local']) : $a['url'])
            ->values()
            ->all();
    }

    /** Чи є у повідомленні відео/рілс (переглядати не вміємо — чесно кажемо). */
    private function hasVideoAttachment(InboxMessage $m): bool
    {
        return collect($m->attachments ?? [])
            ->contains(fn ($a) => preg_match('/reel|video/i', (string) ($a['type'] ?? '')));
    }

    /** Чи повідомлення — лише голосове/аудіо (без тексту й без картинки). */
    public function isVoiceOnly(InboxMessage $m): bool
    {
        if (trim((string) $m->text) !== '') {
            return false;
        }
        $atts = collect($m->attachments ?? []);
        if ($atts->isEmpty()) {
            return false;
        }
        $hasAudio = $atts->contains(fn ($a) => str_contains((string) ($a['type'] ?? ''), 'audio'));
        $hasImage = $atts->contains(fn ($a) => str_contains((string) ($a['type'] ?? ''), 'image'));

        return $hasAudio && !$hasImage;
    }

    /** Підпис вкладення клієнта в історії — за типом, щоб аудіо/відео не звати «фото». */
    private function clientAttachmentNote(InboxMessage $m): string
    {
        $types = collect($m->attachments ?? [])->map(fn ($a) => (string) ($a['type'] ?? ''));

        if ($types->contains(fn ($t) => str_contains($t, 'image'))) {
            return '(клієнт надіслав фото)';
        }
        if ($types->contains(fn ($t) => str_contains($t, 'audio'))) {
            return '(клієнт надіслав голосове повідомлення — прослухати його неможливо)';
        }
        if ($types->contains(fn ($t) => str_contains($t, 'video'))) {
            return '(клієнт надіслав відео — переглянути його неможливо)';
        }

        return '(клієнт надіслав файл)';
    }
}
