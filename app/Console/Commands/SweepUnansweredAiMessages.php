<?php

namespace App\Console\Commands;

use App\Models\AiSetting;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Services\Ai\AiAgentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Страховка ШІ для шаред-хостингу: фонову «думалку» (dispatchAfterResponse)
 * сервер може мовчки вбити — тоді вхідне повідомлення лишається без реакції
 * назавжди. Ця команда раз на тік крона підбирає такі повідомлення і
 * проганяє їх через агента. Всі скіп-перевірки (оператор уже відповів,
 * клієнт дописав, ШІ вимкнено) робить сам respond().
 */
class SweepUnansweredAiMessages extends Command
{
    protected $signature = 'ai:sweep {--max=10 : Скільки повідомлень обробити за один прохід}';

    protected $description = 'Добрати вхідні повідомлення, які лишилися без реакції ШІ (вбитий фоновий процес)';

    public function handle(AiAgentService $ai): int
    {
        $messages = InboxMessage::query()
            ->where('direction', 'in')
            // даємо швидкому шляху 90с дожити; старше 12 год не воскрешаємо
            ->whereBetween('created_at', [now()->subHours(12), now()->subSeconds(90)])
            ->whereNotExists(function ($q) {
                // Термінальний результат (відповіли / свідомо пропустили) — не чіпаємо.
                // skipped_schedule (вікно ще закрите) і error (збій) НЕ термінальні:
                // schedule добираємо коли відкриється вікно, error — повторюємо.
                $q->select(DB::raw(1))
                    ->from('ai_runs')
                    ->whereColumn('ai_runs.inbox_message_id', 'inbox_messages.id')
                    ->whereNotIn('ai_runs.status', ['skipped_schedule', 'error']);
            })
            // але не довбимо вічно: максимум 3 невдалі (error) спроби на повідомлення
            ->whereRaw('(select count(*) from ai_runs r where r.inbox_message_id = inbox_messages.id and r.status = ?) < ?', ['error', 3])
            ->whereHas('conversation', fn ($q) => $q->where('ai_enabled', true))
            ->orderBy('id')
            ->limit((int) $this->option('max'))
            ->get();

        foreach ($messages as $msg) {
            $conversation = $msg->conversation;
            if (!$conversation) {
                continue;
            }

            Log::info('AI sweep: підбираю пропущене повідомлення', ['conv' => $conversation->id, 'msg' => $msg->id]);
            $run = $ai->respond($conversation, $msg->id);
            $this->line("msg #{$msg->id} (conv #{$conversation->id}): {$run->status}");
        }

        // Коментарі, чию автовідповідь вбив хостинг: джоба сама перевіряє
        // налаштування/тригери і не відповість двічі (замок + статус).
        $comments = \App\Models\InboxComment::query()
            ->where('status', 'new')
            ->whereBetween('created_at', [now()->subHours(12), now()->subSeconds(90)])
            ->orderBy('id')
            ->limit((int) $this->option('max'))
            ->get();

        foreach ($comments as $comment) {
            (new \App\Jobs\AiReplyToComment($comment->id))->handle(app(\App\Services\Meta\MetaSendService::class));
            $this->line("comment #{$comment->id}: " . $comment->fresh()->status);
        }

        // Фоллоу-ап мовчазним лідам: ми відповіли, клієнт мовчить — ОДИН теплий пінг.
        $followed = 0;
        $fuHours = (int) (AiSetting::global()->follow_up_hours ?? 0);
        if ($fuHours > 0) {
            $leads = InboxConversation::query()
                ->whereNull('follow_up_sent_at')
                ->where('ai_enabled', true)
                ->where('last_message_direction', 'out')              // ми написали останні
                ->where(fn ($q) => $q->whereNull('ai_paused_until')->orWhere('ai_paused_until', '<=', now()))
                ->whereBetween('last_message_at', [now()->subHours(20), now()->subHours($fuHours)])
                ->whereHas('messages', fn ($q) => $q->where('direction', 'in')) // клієнт писав у DM → вікно 24г відкрите
                ->orderBy('last_message_at')
                ->limit((int) $this->option('max'))
                ->get();

            foreach ($leads as $conv) {
                // Поза робочим вікном магазину / магазин вимкнено — без нічних пінгів.
                $store = AiSetting::where('meta_connection_id', $conv->meta_connection_id)->first();
                if (!$store || !$store->enabled || !AiAgentService::scheduleAllows($store->schedule)) {
                    continue;
                }

                // Клієнт уже чемно ВІДМОВИВСЯ («ні, дякую», «нет спасибо», «поки що ні»)
                // → «чи вдалося визначитися?» після цього — спам (кейси Оксани К. і соні
                // 05.07). Пінг одноразовий — просто списуємо його без відправки.
                $lastIn = $conv->messages()->where('direction', 'in')->latest('id')->first();
                if ($lastIn && self::looksLikeRefusal((string) $lastIn->text)) {
                    $conv->update(['follow_up_sent_at' => now()]);
                    $this->line("follow-up conv #{$conv->id}: пропущено (клієнт відмовився)");
                    continue;
                }

                $ai->sendFollowUp($conv);
                $followed++;
                $this->line("follow-up conv #{$conv->id}");
            }
        }

        $this->info('Оброблено: ' . $messages->count() . ' повідомлень, ' . $comments->count() . ' коментарів, ' . $followed . ' фоллоу-апів');

        return self::SUCCESS;
    }

    /**
     * Останнє повідомлення клієнта схоже на відмову. Свідомо обережний список:
     * краще пропустити зайвий пінг, ніж дістати людину, яка сказала «ні».
     */
    public static function looksLikeRefusal(string $text): bool
    {
        return (bool) preg_match(
            '/(?<!\p{L})(ні|нет|не\s+(?:треба|потрібно|потрібні|буду|хочу|надо|нужно|цікавить|интересует)|передумал\p{L}*|відмовл\p{L}*)(?!\p{L})/iu',
            mb_strtolower($text)
        );
    }
}
