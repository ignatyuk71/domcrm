<?php

namespace App\Services\Ai\Gpt;

use App\Models\InboxConversation;
use App\Models\InboxMessage;

/** Привітання й обірвані службові слова не потребують передачі менеджеру. */
final class ConversationOpening
{
    public const RULE = "■ УТОЧНЕННЯ ЗАПИТУ (ОБОВ'ЯЗКОВЕ ПРАВИЛО). Привітання, перевірка зв’язку, друкарська помилка та незавершена фраза на кшталт «Привіт», «А до», «А як», «Ви тут?» не є питанням, для якого бракує даних магазину. Привітайся, якщо ще не віталася, і постав одне коротке уточнення без обіцянки щось перевірити. Якщо зміст запиту неясний — спершу попроси клієнта дописати або уточнити його. Не викликай escalate_to_manager і не став чат на паузу лише через нечіткий текст. Цей виняток має пріоритет над загальними правилами передачі незрозумілих запитів, включно з інструкцією магазину. Передавай менеджеру, коли клієнт просить людину, має скаргу/повернення або вже поставив змістовне питання, для якого справді немає перевірених даних; після уточнення також можеш передати нерозв’язане змістовне питання.";

    public const TOOL_RULE = ' Привітання й незавершена фраза не є підставою для цього виклику. Якщо запит нечіткий — спершу постав одне коротке уточнення й залиш чат активним.';

    /** Число після нашого запитання про стопу — відповідь у сантиметрах. */
    public static function footLength(InboxConversation $conversation, InboxMessage $message): ?string
    {
        if (! preg_match('/^\s*(\d{1,2}(?:[.,]\d{1,2})?)\s*(?:см|cm|сантиметр\p{L}*)?\s*$/iu', (string) $message->text, $match)) return null;
        $lastText = $conversation->messages()->where('direction', 'out')->whereNotNull('text')->where('text', '!=', '')
            ->where('text', '!=', OrderTexts::all()['handover'])->latest('id')->value('text');
        if (! preg_match('/(?:довжин\p{L}*|скільки|який).*(?:стоп|устіл|сантиметр|см)/iu', (string) $lastText)) return null;

        return str_replace(',', '.', $match[1]);
    }

    public static function reply(InboxConversation $conversation, InboxMessage $message): ?string
    {
        if ($message->attachments || $message->context) {
            return null;
        }
        $text = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(trim((string) $message->text))));
        if ($text === '' || mb_strlen($text) > 100) {
            return null;
        }
        $greeting = '(?:привіт(?:ик)?|вітаю|добрий (?:день|вечір|ранок)|доброго (?:дня|вечора|ранку)|здравствуйте|здрастуйте|добрый (?:день|вечер)|hello|hi|hey)';
        $remainder = trim(preg_replace('/^'.$greeting.'(?:\s+|$)/u', '', $text));
        $contactCheck = preg_match('/^(?:алло|ау|агов|ви тут|є хто|є хтось|хто тут)$/u', $remainder);
        $fragment = preg_match('/^(?:(?:а|і|й|та|але|ну|до|від|на|за|у|в|з|із|зі|про|по|для|або|щодо|як|якщо)(?:\s+|$))+$/u', $remainder);
        if ($remainder !== '' && ! $contactCheck && ! $fragment) {
            return null;
        }

        // Перевіряємо початок розмови, щоб не повторювати привітання після відновлення.
        $greeted = $conversation->messages()->where('direction', 'out')->oldest('id')->limit(20)->pluck('text')
            ->contains(fn ($text) => preg_match('/(?:привіт|вітаю|доброго (?:дня|вечора|ранку)|добрий (?:день|вечір|ранок))/iu', (string) $text));
        $hello = $greeted ? '' : match (true) {
            now()->hour >= 6 && now()->hour < 17 => 'Доброго дня! ',
            now()->hour >= 17 && now()->hour < 23 => 'Доброго вечора! ',
            default => 'Доброї ночі! ',
        };

        return $hello.($remainder === '' ? 'Підкажіть, що вас цікавить 🙂' : 'Уточніть, будь ласка, що саме вас цікавить 🙂');
    }
}
