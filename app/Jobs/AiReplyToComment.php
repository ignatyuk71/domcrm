<?php

namespace App\Jobs;

use App\Models\AiPhotoGroup;
use App\Models\AiPostLine;
use App\Models\AiSetting;
use App\Models\InboxComment;
use App\Services\Ai\AiAgentService;
use App\Services\Meta\MetaSendService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Автоворонка коментарів: новий коментар → ОДНА приватна відповідь у директ.
 * Лінійку поста визначає (один раз на пост, далі кеш ai_post_lines):
 *   1) відбиток картинки поста по галереї (безкоштовно, точно);
 *   2) ШІ читає текст + фото поста і називає лінійку;
 *   3) не зрозумів → шаблонний відкривач.
 * Відповідає ЛИШЕ на коментарі, що прийшли ПІСЛЯ ввімкнення рубильника.
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
        // Тільки НОВІ коментарі — ті, що прийшли після ввімкнення рубильника.
        // Старі (до ввімкнення) лишаються в вкладці для ручної відповіді.
        $enabledAt = $cfg['enabled_at'] ?? null;
        if ($enabledAt && $comment->created_at && $comment->created_at->lt(\Carbon\Carbon::parse($enabledAt))) {
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

            // Антиспам: цій людині вже пішов DM на комент за останні 10 годин
            // (кілька коментів поспіль чи на різні пости) → повторно не бомбимо,
            // комент лишається у вкладці для ручної відповіді. Комент «через
            // місяць» працює як завжди — вікно давно мине.
            $recentDm = InboxComment::where('meta_connection_id', $comment->meta_connection_id)
                ->where('from_id', $comment->from_id)
                ->where('id', '!=', $comment->id)
                ->where('status', 'dm_sent')
                ->where('updated_at', '>=', now()->subHours(10))
                ->exists();
            if ($recentDm) {
                $comment->update(['status' => 'dm_skipped']);
                return;
            }

            $group = $this->resolveLine($comment);
            $text = self::replyText($group, $cfg);

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

    /** Визначити лінійку поста: кеш → відбиток → ШІ. null = не розпізнано. */
    private function resolveLine(InboxComment $comment): ?AiPhotoGroup
    {
        // 0) Кеш: пост уже класифіковано (першим коментарем)
        $cached = AiPostLine::where('post_id', $comment->post_id)->first();
        if ($cached) {
            return $cached->group;
        }

        // 1) Відбиток картинки поста по галереї — безкоштовно і точно
        if ($comment->post_image && is_file(public_path($comment->post_image))) {
            $photo = app(AiAgentService::class)->matchGalleryPhoto((string) file_get_contents(public_path($comment->post_image)));
            if ($photo && $photo->group) {
                AiPostLine::create(['post_id' => $comment->post_id, 'ai_photo_group_id' => $photo->group->id, 'source' => 'hash']);

                return $photo->group;
            }
        }

        // 2) ШІ читає пост (текст + фото) і називає лінійку
        [$group, $definitive] = $this->classifyWithAi($comment);
        if ($definitive) {
            AiPostLine::create(['post_id' => $comment->post_id, 'ai_photo_group_id' => $group?->id, 'source' => $group ? 'ai' : 'none']);
        }

        return $group;
    }

    /**
     * Класифікація постів через Claude: повертає [група|null, чи відповідь остаточна].
     * Не остаточна (помилка API/нема ключа) — не кешуємо, спрацює відкривач.
     * @return array{0: ?AiPhotoGroup, 1: bool}
     */
    private function classifyWithAi(InboxComment $comment): array
    {
        $groups = AiPhotoGroup::orderBy('id')->get();
        if ($groups->isEmpty()) {
            return [null, true];
        }

        $apiKey = AiSetting::global()->api_key;
        if (!$apiKey) {
            return [null, false];
        }

        $list = $groups->map(fn ($g) => '- ' . $g->name)->implode("\n");
        $content = [];
        if ($comment->post_image && is_file(public_path($comment->post_image))) {
            $bytes = (string) file_get_contents(public_path($comment->post_image));
            $mime = match (strtolower(pathinfo($comment->post_image, PATHINFO_EXTENSION))) {
                'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', default => 'image/jpeg',
            };
            if (strlen($bytes) < 4 * 1024 * 1024) {
                $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]];
            }
        }
        $content[] = ['type' => 'text', 'text' => "Текст поста: " . mb_substr((string) ($comment->post_excerpt ?: '(немає)'), 0, 400)];

        try {
            $r = Http::timeout(30)->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => AiSetting::global()->model ?: 'claude-sonnet-4-6',
                'max_tokens' => 30,
                'system' => "Це пост магазину тапочок. Визнач, про яку лінійку йдеться, за текстом і фото.\nЛінійки:\n{$list}\nВідповідай ЛИШЕ точною назвою лінійки зі списку або словом «невідомо». Нічого більше.",
                'messages' => [['role' => 'user', 'content' => $content]],
            ]);

            if (!$r->successful()) {
                Log::warning('AI post classify error', ['post' => $comment->post_id, 'body' => mb_substr($r->body(), 0, 200)]);
                return [null, false];
            }

            $answer = trim((string) collect($r->json('content') ?? [])->where('type', 'text')->pluck('text')->implode(' '));
            $matched = $groups->first(fn ($g) => $answer !== '' && mb_stripos($answer, $g->name) !== false);

            return [$matched, true];
        } catch (\Throwable $e) {
            Log::warning('AI post classify failed', ['post' => $comment->post_id, 'error' => $e->getMessage()]);
            return [null, false];
        }
    }

    /** Текст директа: відома лінійка → шаблон з живою ціною; ні → відкривач. */
    public static function replyText(?AiPhotoGroup $group, array $cfg): string
    {
        if ($group) {
            $prices = $group->products()->pluck('sale_price')->filter();
            $price = $prices->isNotEmpty() ? round((float) $prices->min()) : null;
            $name = mb_strtolower(trim(preg_replace('/\s*\(.*?\)\s*/u', ' ', $group->name)));

            return "Доброго дня! 💛 Дякуємо за ваш коментар під нашим дописом 🙂 Це наші {$name}" . ($price ? " — {$price} грн" : '')
                . ". Підкажіть, який колір і розмір вас цікавить — надішлю фото й перевірю наявність 🙂";
        }

        $opener = trim((string) ($cfg['opener'] ?? ''));

        return $opener !== '' ? $opener
            : 'Доброго дня! 💛 Дякуємо за ваш коментар під нашим дописом 🙂 Підкажіть, які саме тапулі вас цікавлять — домашні, вуличні чи дитячі? Підберу варіанти, покажу фото й ціни 🙂';
    }
}
