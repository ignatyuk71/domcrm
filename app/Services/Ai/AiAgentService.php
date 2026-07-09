<?php

namespace App\Services\Ai;

use App\Models\AiPhoto;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Services\Meta\MetaSendService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Агент відповідає на вхідні: бачить ПОВНИЙ живий каталог у системному промпті
 * і сам вирішує, що підходить клієнту (жодного пошуку по ключових словах).
 * Інструменти: get_product (опис/деталі) і send_photos (фото з галереї ШІ).
 * Собівартість і службові поля назовні не виходять.
 */
class AiAgentService
{
    private const MAX_TOOL_LOOPS = 5;

    /**
     * Переробка «сказав = зробив»: модель написала про надсилання фото, але
     * send_photos не викликала — чернетку клієнту не шлемо, повертаємо в API.
     */
    private const PHOTO_REPAIR_NOTE = '(система: у чернетці вище ти написала про надсилання фото, але інструмент send_photos НЕ викликала — клієнт фото НЕ отримав, і цієї чернетки він НЕ бачив. Сформуй відповідь заново: якщо показуєш фото — обовʼязково виклич send_photos з photo_ids з каталогу; службові позначки в дужках не пиши.)';

    /**
     * Захист від дубля після send_photos: текст усіх кроків склеюється в одне
     * повідомлення, тому після фото модель НЕ має переказувати вже написане
     * (кейс Валентини: «сірого немає» двічі різними словами).
     */
    private const PHOTOS_SENT_NOTE = '(система: фото вже надіслані клієнту. Увесь текст, який ти написала ДО виклику інструмента, ТЕЖ буде надіслано клієнту — одним повідомленням після фото. НЕ повторюй і НЕ перефразовуй його. Якщо відповідь уже повна — заверши хід БЕЗ додаткового тексту; якщо тексту ще не було — напиши повну відповідь зараз.)';

    /**
     * Жодне фото з виклику реально не пішло (дубль пропущено або помилка відправки).
     * Стара нотатка «фото вже надіслані» тут БРЕХАЛА моделі → клієнт отримував
     * «Ось детальні фото 🙂» без жодного фото (кейс Olena Lykova 09.07 13:31).
     */
    private const PHOTOS_SKIPPED_NOTE = '(система: УВАГА — жодне фото ЗАРАЗ НЕ пішло клієнту: це повтор уже надісланих фото або помилка відправки (див. результат інструмента). Текст, який ти написала ДО виклику, все одно буде надіслано клієнту. Якщо в ньому ти обіцяла показати фото — допиши зараз ОДИН короткий чесний рядок: для повтору — «Це ті самі фото, що я надсилала вище 🙂», для помилки — вибачся і опиши товар словами. НЕ повторюй попередній текст і НЕ обіцяй нових фото.)';

    /**
     * Чи дозволяє графік працювати зараз. Делегує AiSchedule (лишено тут для
     * стабільного публічного API: команда й тести кличуть AiAgentService::scheduleAllows).
     */
    public static function scheduleAllows(?array $schedule, ?\Carbon\Carbon $at = null): bool
    {
        return AiSchedule::allows($schedule, $at);
    }

    /** Прибрати службові слова з тексту для моделі. Делегує TextScrubber. */
    private function scrub(?string $text): ?string
    {
        return $this->scrubber->scrub($text);
    }

    /**
     * Вирізати плейсхолдер фото («[зображення]» тощо). Делегує TextScrubber.
     */
    private function stripPhotoPlaceholder(?string $text): string
    {
        return $this->scrubber->stripPhotoPlaceholder($text);
    }

    public function __construct(
        private MetaSendService $send,
        private TextScrubber $scrubber,
        private ImageMatcher $imageMatcher,
        private CatalogBuilder $catalogBuilder,
        private PromptBuilder $promptBuilder,
        private OutgoingMessageWriter $outgoing,
        private HistoryBuilder $history,
        private AgentTools $tools,
        private ClaudeClient $claude,
    ) {
    }

    public function respond(InboxConversation $conversation, int $triggerMessageId): AiRun
    {
        $t0 = microtime(true);
        $toolsCalled = [];
        $finish = function (string $status, ?string $error = null, int $in = 0, int $out = 0) use ($conversation, $triggerMessageId, $t0, &$toolsCalled) {
            return AiRun::create([
                'inbox_conversation_id' => $conversation->id,
                'inbox_message_id' => $triggerMessageId,
                'status' => $status,
                'error' => $error,
                'tools_called' => $toolsCalled ?: null,
                'tokens_in' => $in,
                'tokens_out' => $out,
                'duration_ms' => (int) ((microtime(true) - $t0) * 1000),
            ]);
        };

        // Замок на повідомлення: швидкий шлях і крон-страховка не повинні
        // відповісти одночасно. Не взяли замок — хтось уже обробляє.
        $lock = Cache::lock('ai-respond-msg-' . $triggerMessageId, 300);
        if (!$lock->get()) {
            return $finish('skipped_in_progress');
        }

        try {
            $conversation->loadMissing(['connection', 'contact']);
            if (!$conversation->connection || !$conversation->contact) {
                return $finish('skipped_no_connection');
            }
            if (!$conversation->ai_enabled) {
                return $finish('skipped_conversation_off');
            }

            $global = AiSetting::global();
            $apiKey = $global->api_key;
            if (!$apiKey) {
                return $finish('skipped_no_key');
            }

            $store = AiSetting::where('meta_connection_id', $conversation->meta_connection_id)->first();
            if (!$store || !$store->enabled) {
                return $finish('skipped_store_off');
            }

            // Графік: поза вікном роботи агент мовчить. Такий скіп НЕ вважається
            // «оброблено» — щойно вікно відкриється, крон-добирач підхопить
            // невідповіджені повідомлення (якщо людина так і не відповіла).
            if (!self::scheduleAllows($store->schedule)) {
                return $finish('skipped_schedule');
            }

            // Липка пауза: оператор написав у цю розмову → ШІ відступає на N годин.
            if ($conversation->ai_paused_until && now()->lt($conversation->ai_paused_until)) {
                return $finish('skipped_operator_pause');
            }

            // Актуальність: відповідаємо лише якщо це досі ОСТАННЄ повідомлення і воно вхідне.
            // Якщо клієнт встиг написати ще — відповість джоба новішого повідомлення (з повним контекстом).
            // Якщо вже відповів оператор — мовчимо. ВЛАСНІ повідомлення бота (надіслані
            // фото) новизною НЕ вважаємо — інакше вони «застарювали» питання клієнта,
            // що прийшло посеред відправлення фото, і бот мовчав (узгоджено з пізньою перевіркою).
            $last = $conversation->messages()->where('sender', '!=', 'ai')->orderByDesc('id')->first();
            if (!$last || $last->id !== $triggerMessageId || $last->direction !== 'in') {
                return $finish('skipped_stale');
            }

            // Голосові/аудіо ми не опрацьовуємо — одразу чемна відповідь, без виклику моделі.
            if ($this->isVoiceOnly($last)) {
                $this->sendBotMessage($conversation, self::orderTexts()['voice_reject']);

                return $finish('replied_voice');
            }

            $messages = $this->buildHistory($conversation);
            if (empty($messages)) {
                return $finish('skipped_empty_history');
            }

            $systemBlocks = $this->buildSystemPrompt($conversation, $store);

            $model = $global->model ?: 'claude-sonnet-4-6';
            $tokensIn = 0;
            $tokensOut = 0;
            $content = [];
            $turnTexts = [];
            $photoClaim = false; // модель ЗАЯВЛЯЛА в тексті, що надсилає фото
            $repairs = 0;        // переробок «сказав=зробив» — не більше однієї

            // Цикл tool-ів: Claude може кілька разів заглянути в базу, потім відповідає.
            for ($loop = 0; $loop <= self::MAX_TOOL_LOOPS; $loop++) {
                $payload = [
                    'model' => $model,
                    'max_tokens' => 700,
                    // NB: параметр temperature НЕ передаємо — Opus 4.8 його не приймає
                    // («temperature is deprecated for this model» → відповідь падала).
                    // Проти вигадок працюють промпт-правила (forced grounding, анти-вигадка).
                    //
                    // thinking ВИМКНЕНО явно: Sonnet 5 інакше вмикає adaptive thinking
                    // за замовчуванням і зʼїдає весь бюджет max_tokens на роздуми →
                    // порожня відповідь (кейс 06.07 «Які ще у вас є?»). Наш бот працює
                    // за жорстким сценарієм, міркувань не потребує; для Opus/Haiku це
                    // штатний режим (нічого не змінює). Fable 5 thinking вимкнути НЕ
                    // дозволяє — якщо колись перейдемо, цей рядок прибрати.
                    'thinking' => ['type' => 'disabled'],
                    'system' => $systemBlocks,
                    'messages' => $messages,
                    'tools' => $this->toolDefinitions(),
                ];
                // Вичерпав ліміт заглядань — зобовʼязаний відповісти текстом.
                if ($loop === self::MAX_TOOL_LOOPS) {
                    $payload['tool_choice'] = ['type' => 'none'];
                }

                $r = $this->claude->messages($apiKey, $payload, 40);

                // Вхідні = свіжі + записані в кеш + прочитані з кешу (інакше статистика бреше при кешуванні).
                $tokensIn += (int) $r->json('usage.input_tokens', 0)
                    + (int) $r->json('usage.cache_creation_input_tokens', 0)
                    + (int) $r->json('usage.cache_read_input_tokens', 0);
                $tokensOut += (int) $r->json('usage.output_tokens', 0);

                if (!$r->successful()) {
                    Log::warning('AI: Claude API error', ['conv' => $conversation->id, 'body' => mb_substr($r->body(), 0, 300)]);
                    return $finish('error', 'Claude: ' . ($r->json('error.message') ?? ('HTTP ' . $r->status())), $tokensIn, $tokensOut);
                }

                $content = $r->json('content') ?? [];

                // Текст КОЖНОГО кроку (а не лише останнього). Коли модель пише
                // привітання+ціну РАЗОМ із викликом send_photos — цей текст на кроці
                // tool_use, і раніше ГУБИВСЯ: слався лише фінальний куций рядок.
                $rawStep = collect($content)->where('type', 'text')->pluck('text')->implode("\n");
                if ($this->scrubber->mentionsSendingPhotos($rawStep)) {
                    $photoClaim = true;
                }
                $tt = trim($this->scrubber->stripModelArtifacts($this->stripPhotoPlaceholder($rawStep)));
                if ($tt !== '') {
                    $turnTexts[] = $tt;
                }

                if ($r->json('stop_reason') !== 'tool_use') {
                    // «Сказав = зробив»: модель писала про надсилання фото, але send_photos
                    // за весь хід не викликала (кейс Тані: «(надіслала клієнту фото товару…)»
                    // текстом і нуль фото). Чернетку клієнту НЕ шлемо — повертаємо в API
                    // на переробку, щоб модель САМА викликала інструмент. Одна спроба.
                    $sentPhotos = collect($toolsCalled)->contains(fn ($t) => ($t['tool'] ?? '') === 'send_photos');
                    if ($photoClaim && !$sentPhotos && $repairs === 0 && $loop < self::MAX_TOOL_LOOPS) {
                        $repairs++;
                        $draft = trim(implode("\n\n", $turnTexts)) !== '' ? implode("\n\n", $turnTexts) : $rawStep;
                        $messages[] = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $draft !== '' ? $draft : '(порожня чернетка)']]];
                        $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => self::PHOTO_REPAIR_NOTE]]];
                        $turnTexts = [];
                        $photoClaim = false;
                        Log::info('AI: photo claim without send_photos — повертаю на переробку', ['conv' => $conversation->id]);
                        continue;
                    }
                    break;
                }

                // Порожній вхід інструмента {} декодується PHP як [] — Claude
                // відхиляє його при повторній відправці («Input should be an object»).
                // Приводимо input КОЖНОГО tool_use до обʼєкта → серіалізується як {}.
                foreach ($content as $ci => $blk) {
                    if (($blk['type'] ?? '') === 'tool_use') {
                        $content[$ci]['input'] = (object) ($blk['input'] ?? []);
                    }
                }

                // Виконуємо всі запити в базу з цього кроку і віддаємо результати назад.
                $messages[] = ['role' => 'assistant', 'content' => $content];
                $results = [];
                $photoToolCalled = false;
                $photoActuallySent = false;
                foreach ($content as $block) {
                    if (($block['type'] ?? '') !== 'tool_use') {
                        continue;
                    }
                    $toolsCalled[] = ['tool' => $block['name'], 'input' => $block['input'] ?? []];
                    $out = $this->runTool($block['name'], (array) ($block['input'] ?? []), $conversation);
                    if (($block['name'] ?? '') === 'send_photos') {
                        $photoToolCalled = true;
                        // toolSendPhotos повертає постатусно: 'надіслано' | 'пропущено…' | 'помилка…'.
                        $photoActuallySent = $photoActuallySent || in_array('надіслано', $out, true);
                    }
                    $results[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content' => json_encode($out, JSON_UNESCAPED_UNICODE),
                    ];
                }
                // Після send_photos нагадуємо: текст кроків склеїться в одне
                // повідомлення — не переказуй себе (захист від дубля). Нотатка
                // каже ПРАВДУ: якщо дедуп/помилка пропустили ВСІ фото — модель
                // мусить чесно виправитись, а не лишати «Ось фото…» без фото.
                if ($photoToolCalled) {
                    $results[] = ['type' => 'text', 'text' => $photoActuallySent ? self::PHOTOS_SENT_NOTE : self::PHOTOS_SKIPPED_NOTE];
                }
                $messages[] = ['role' => 'user', 'content' => $results];
            }

            // Текст УСІХ кроків разом (привітання+ціна з кроку tool_use + фінал),
            // без точних повторів. Плейсхолдери фото вже вирізані покроково вище.
            $text = implode("\n\n", array_values(array_unique($turnTexts)));
            // Після фіксації замовлення завжди шлемо ТОЧНИЙ фінальний текст із конфігу —
            // не покладаємось на модель (гарантія дослівності + захист від порожньої відповіді).
            if (collect($toolsCalled)->contains(fn ($t) => ($t['tool'] ?? '') === 'complete_order')) {
                // Після оформлення — ВИКЛЮЧНО канонічний фінал, без власної балаканини
                // моделі (інакше виходив дубль «Дякую! Оформлюю… Замовлення оформлено!…
                // Дякуємо за замовлення…»). Клієнт бачить лише один чистий фінал.
                $text = self::orderTexts()['final_message'];
            } elseif (collect($toolsCalled)->contains(fn ($t) => ($t['tool'] ?? '') === 'escalate_to_manager')) {
                $text = self::orderTexts()['handover'];
            }
            if ($text === '') {
                return $finish('error', 'Порожня відповідь моделі', $tokensIn, $tokensOut);
            }
            $text = mb_substr($text, 0, 1900); // ліміт Send API — 2000 символів

            // Клієнт (або оператор) міг написати, ПОКИ Claude думав — тоді цю відповідь
            // викидаємо, нова джоба відповість з урахуванням дописаного. Власні
            // повідомлення агента (надіслані фото) новизною НЕ вважаються.
            $newerForeign = $conversation->messages()
                ->where('id', '>', $triggerMessageId)
                ->where('sender', '!=', 'ai')
                ->exists();
            if ($newerForeign) {
                return $finish('skipped_stale_late', null, $tokensIn, $tokensOut);
            }

            $sent = $this->send->sendText($conversation->connection, $conversation->contact->external_id, $text);
            if (!($sent['ok'] ?? false)) {
                return $finish('error', 'Send API: ' . ($sent['error'] ?? 'невідома помилка'), $tokensIn, $tokensOut);
            }

            $this->persistOutgoing([
                'inbox_conversation_id' => $conversation->id,
                'direction' => 'out',
                'sender' => 'ai',
                'external_message_id' => $sent['message_id'] ?? null,
                'text' => $text,
                'sent_at' => now(),
            ]);

            $conversation->update([
                'last_message_at' => now(),
                'last_message_text' => mb_substr($text, 0, 480),
                'last_message_direction' => 'out',
            ]);

            // Клієнт зацікавився товаром (бот показав фото / уточнив деталі / почав
            // оформлення) → піднімаємо статус у «В роботі», щоб лід не загубився.
            $this->maybeMarkInProgress($conversation, $toolsCalled);

            return $finish('replied', null, $tokensIn, $tokensOut);
        } catch (\Throwable $e) {
            Log::error('AI: respond failed', ['conv' => $conversation->id, 'error' => $e->getMessage()]);
            return $finish('error', mb_substr($e->getMessage(), 0, 500));
        } finally {
            $lock->release();
        }
    }

    /**
     * Системний промпт для Claude. Делегує PromptBuilder (лишено тут для
     * стабільного публічного API: respond і тести кличуть цей метод).
     */
    public function buildSystemPrompt(InboxConversation $conversation, ?AiSetting $store = null): array
    {
        return $this->promptBuilder->buildSystemPrompt($conversation, $store);
    }

    /** Описи інструментів для Claude. Делегує PromptBuilder. */
    private function toolDefinitions(): array
    {
        return $this->promptBuilder->toolDefinitions();
    }

    /** Виконати інструмент. Делегує AgentTools. */
    private function runTool(string $name, array $input, InboxConversation $conversation): array
    {
        return $this->tools->runTool($name, $input, $conversation);
    }

    /**
     * Повний каталог для системного промпта. Делегує CatalogBuilder (лишено тут
     * для стабільного публічного API: buildSystemPrompt і тести кличуть цей метод).
     */
    public function buildCatalog(): string
    {
        return $this->catalogBuilder->buildCatalog();
    }

    /**
     * Тексти й реквізити флоу замовлення. Делегує OrderTexts (лишено тут для
     * стабільного публічного API: тести кличуть AiAgentService::orderTexts).
     */
    public static function orderTexts(): array
    {
        return OrderTexts::all();
    }

    /** Один теплий пінг мовчазному ліду (ставить прапор, щоб не повторювати). */
    public function sendFollowUp(InboxConversation $conversation): bool
    {
        $ok = $this->sendBotMessage($conversation, self::orderTexts()['follow_up']);
        // Позначаємо ЗАВЖДИ (навіть при невдачі) — щоб не довбити повторно.
        $conversation->update(['follow_up_sent_at' => now()]);

        return $ok;
    }

    /** Зацікавлений клієнт → статус «В роботі». Делегує AgentTools. */
    private function maybeMarkInProgress(InboxConversation $conversation, array $toolsCalled): void
    {
        $this->tools->maybeMarkInProgress($conversation, $toolsCalled);
    }

    /** Надіслати клієнту текст від імені бота. Делегує OutgoingMessageWriter. */
    private function sendBotMessage(InboxConversation $conversation, string $text): bool
    {
        return $this->outgoing->sendBotMessage($conversation, $text);
    }

    /** Ідемпотентний запис вихідного повідомлення. Делегує OutgoingMessageWriter. */
    private function persistOutgoing(array $attrs): void
    {
        $this->outgoing->persistOutgoing($attrs);
    }

    /** Надіслати клієнту форму-шаблон для збору даних доставки. Делегує AgentTools. */
    public function toolAskDeliveryDetails(InboxConversation $conversation): array
    {
        return $this->tools->toolAskDeliveryDetails($conversation);
    }

    /** Надіслати реквізити оплати: картка + IBAN ФОП разом (клієнт обирає). Делегує AgentTools. */
    public function toolSendPaymentDetails(InboxConversation $conversation, array $input = []): array
    {
        return $this->tools->toolSendPaymentDetails($conversation, $input);
    }

    /** Бот не впевнений → ескалація менеджеру. Делегує AgentTools. */
    public function toolEscalateToManager(InboxConversation $conversation): array
    {
        return $this->tools->toolEscalateToManager($conversation);
    }

    /** Зафіксувати замовлення. Делегує AgentTools. */
    public function toolCompleteOrder(InboxConversation $conversation, array $input): array
    {
        return $this->tools->toolCompleteOrder($conversation, $input);
    }

    /**
     * Стислий підсумок розмови для панелі менеджера («Про що тут»).
     * Окремий легкий виклик (без інструментів і каталогу), результат
     * кешуємо в розмові — генеруємо лише на вимогу (кнопка), не автоматично.
     */
    public function summarizeConversation(InboxConversation $conversation): ?string
    {
        $apiKey = AiSetting::global()->api_key;
        if (!$apiKey) {
            return null;
        }

        $transcript = $conversation->messages()
            ->orderBy('id')
            ->get(['direction', 'text'])
            ->map(function ($m) {
                $text = trim((string) $m->text);
                return $text === '' ? null : (($m->direction === 'in' ? 'Клієнт' : 'Магазин') . ': ' . $text);
            })
            ->filter()
            ->take(-40)
            ->implode("\n");

        if ($transcript === '') {
            return null;
        }

        $system = 'Ти помічник продавця тапочок. Стисло, 1–2 короткі речення українською, підсумуй цей чат: '
            . 'що клієнт хоче (модель/колір/розмір) і про що домовились (оплата, статус). '
            . 'Лише суть, без вступів і звертань. Якщо конкретики ще нема — напиши, що саме питав клієнт.';

        $r = $this->claude->messages($apiKey, [
            'model' => AiSetting::global()->model ?: 'claude-sonnet-4-6',
            'max_tokens' => 200,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $transcript]],
        ], 30);

        if (!$r->successful()) {
            Log::warning('AI summary failed', ['conv' => $conversation->id, 'body' => mb_substr($r->body(), 0, 200)]);
            return null;
        }

        $text = '';
        foreach ($r->json('content') ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        $text = trim((string) $this->scrub(trim($text)));
        if ($text === '') {
            return null;
        }

        $conversation->update(['ai_summary' => $text, 'ai_summary_at' => now()]);

        return $text;
    }

    /** Надіслати клієнту фото з галереї ШІ. Делегує AgentTools. */
    public function toolSendPhotos(InboxConversation $conversation, array $photoIds): array
    {
        return $this->tools->toolSendPhotos($conversation, $photoIds);
    }

    /** Історія діалогу → формат Claude Messages API. Делегує HistoryBuilder. */
    private function buildHistory(InboxConversation $conversation, int $limit = 20): array
    {
        return $this->history->buildHistory($conversation, $limit);
    }

    /** Чи повідомлення — лише голосове/аудіо. Делегує HistoryBuilder. */
    private function isVoiceOnly(InboxMessage $m): bool
    {
        return $this->history->isVoiceOnly($m);
    }

    /**
     * Чи збігається фото клієнта з фото нашої галереї? Делегує ImageMatcher
     * (лишено тут для стабільного публічного API: джоба AiReplyToComment кличе цей метод).
     */
    public function matchGalleryPhoto(string $bytes): ?AiPhoto
    {
        return $this->imageMatcher->matchGalleryPhoto($bytes);
    }
}
