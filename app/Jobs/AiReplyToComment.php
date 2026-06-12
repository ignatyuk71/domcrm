<?php

namespace App\Jobs;

use App\Models\AiPhotoGroup;
use App\Models\AiSetting;
use App\Models\InboxComment;
use App\Services\Meta\MetaSendService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Автоворонка коментарів: клієнт пише «Ціна?» під постом → ШІ шле ОДНУ
 * приватну відповідь у директ (ліміт Meta). Лінійку визначає з ТЕКСТУ поста
 * (власник пише її в підписі), не впізнав — чесний відкривач-питання.
 * Колажі й ціни полетять у звичайній розмові, щойно людина відповість.
 */
class AiReplyToComment
{
    use Dispatchable;

    public function __construct(public int $commentId)
    {
    }

    public function handle(MetaSendService $send): void
    {
        $comment = InboxComment::with('connection')->find($this->commentId);
        if (!$comment || $comment->status !== 'new' || !$comment->connection) {
            return;
        }

        $cfg = AiSetting::global()->comment_settings ?? [];
        if (!($cfg['enabled'] ?? false)) {
            return;
        }
        if (!($cfg[$comment->channel] ?? true)) {
            return; // канал вимкнено (напр. Instagram off)
        }
        if (($cfg['mode'] ?? 'keywords') === 'keywords' && !self::matchesKeywords((string) $comment->text, (string) ($cfg['keywords'] ?? ''))) {
            return;
        }

        // Замок: вебхук-джоба і крон-добирач не повинні відповісти двічі.
        $lock = Cache::lock('ai-comment-' . $comment->id, 300);
        if (!$lock->get()) {
            return;
        }

        try {
            $comment->refresh();
            if ($comment->status !== 'new') {
                return;
            }

            [$group, $text] = self::buildReply($comment, $cfg);

            $res = $send->sendPrivateReply($comment->connection, $comment->comment_id, $text);
            if (!($res['ok'] ?? false)) {
                $comment->update(['status' => 'dm_failed']);
                Log::warning('AI comment funnel: приватна відповідь не пішла', ['comment' => $comment->id, 'error' => $res['error'] ?? '']);
                return;
            }

            $comment->update([
                'status' => 'dm_sent',
                'dm_message_id' => $res['message_id'] ?? null,
                'matched_group_name' => $group?->name,
            ]);
        } catch (\Throwable $e) {
            Log::error('AI comment funnel failed', ['comment' => $this->commentId, 'error' => $e->getMessage()]);
        } finally {
            $lock->release();
        }
    }

    /** Чи містить коментар тригер-слово (регістронезалежно, по підрядку). */
    public static function matchesKeywords(string $text, string $keywords): bool
    {
        $text = mb_strtolower($text);
        foreach (preg_split('/[,;\n]+/u', mb_strtolower($keywords)) as $kw) {
            $kw = trim($kw);
            if ($kw !== '' && str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    /** Класи призначення: маркер у тексті → маркер у назві групи. */
    private const PURPOSE_STEMS = ['вулиц', 'вулич', 'домашн', 'дитяч', 'чолов', 'жіноч'];

    /**
     * Визначити лінійку з ТЕКСТУ поста за словом ПРИЗНАЧЕННЯ (власник пише його
     * в підписі: «домашні», «вуличні», «дитячі»…). Слова типу «пухнасті/тапочки»
     * не розрізняють — у нас усе пухнасте. Немає однозначного маркера → відкривач.
     * @return array{0: ?AiPhotoGroup, 1: string}
     */
    public static function buildReply(InboxComment $comment, array $cfg): array
    {
        $haystack = mb_strtolower(trim(($comment->post_excerpt ?? '') . ' ' . ($comment->text ?? '')));

        // Які класи призначення згадані в тексті (вулич/вулиц — одне і те ж)
        $norm = fn (string $s) => $s === 'вулич' ? 'вулиц' : $s;
        $textClasses = [];
        foreach (self::PURPOSE_STEMS as $stem) {
            if (str_contains($haystack, $stem)) {
                $textClasses[$norm($stem)] = true;
            }
        }

        $best = null;
        if (count($textClasses) === 1) {
            $class = array_key_first($textClasses);
            $matched = AiPhotoGroup::with('products:id,sale_price')->get()
                ->filter(function (AiPhotoGroup $g) use ($class, $norm) {
                    $name = mb_strtolower($g->name);
                    foreach (self::PURPOSE_STEMS as $stem) {
                        if ($norm($stem) === $class && str_contains($name, $stem)) {
                            return true;
                        }
                    }
                    return false;
                })
                ->values();
            if ($matched->count() === 1) {
                $best = $matched->first();
            }
        }

        if ($best) {
            $prices = $best->products->pluck('sale_price')->filter();
            $price = $prices->isNotEmpty() ? round((float) $prices->min()) : null;
            $name = mb_strtolower(trim(preg_replace('/\s*\(.*?\)\s*/u', ' ', $best->name)));
            $text = "Доброго дня! 💛 Це наші {$name}" . ($price ? " — {$price} грн" : '')
                . ". Підкажіть, який колір і розмір вас цікавить — надішлю фото й перевірю наявність 🙂";

            return [$best, $text];
        }

        $opener = trim((string) ($cfg['opener'] ?? ''));
        if ($opener === '') {
            $opener = 'Доброго дня! 💛 Підкажіть, які саме тапулі вас цікавлять — домашні, вуличні чи дитячі? Підберу варіанти, покажу фото й ціни 🙂';
        }

        return [null, $opener];
    }
}
