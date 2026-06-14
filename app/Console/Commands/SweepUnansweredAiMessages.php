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
                // skipped_schedule ≠ оброблено: вікно відкрилось → добираємо.
                $q->select(DB::raw(1))
                    ->from('ai_runs')
                    ->whereColumn('ai_runs.inbox_message_id', 'inbox_messages.id')
                    ->where('ai_runs.status', '!=', 'skipped_schedule');
            })
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
                $ai->sendFollowUp($conv);
                $followed++;
                $this->line("follow-up conv #{$conv->id}");
            }
        }

        $this->info('Оброблено: ' . $messages->count() . ' повідомлень, ' . $comments->count() . ' коментарів, ' . $followed . ' фоллоу-апів');

        return self::SUCCESS;
    }
}
