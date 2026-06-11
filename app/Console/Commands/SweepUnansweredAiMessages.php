<?php

namespace App\Console\Commands;

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
                $q->select(DB::raw(1))
                    ->from('ai_runs')
                    ->whereColumn('ai_runs.inbox_message_id', 'inbox_messages.id');
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

        $this->info('Оброблено: ' . $messages->count());

        return self::SUCCESS;
    }
}
