<?php

namespace App\Services\Ai;

use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Product;
use App\Services\Meta\MetaSendService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

    /** Скільки останніх фото клієнта передаємо моделі як картинки (vision). */
    private const MAX_VISION_IMAGES = 2;

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

            // Цикл tool-ів: Claude може кілька разів заглянути в базу, потім відповідає.
            for ($loop = 0; $loop <= self::MAX_TOOL_LOOPS; $loop++) {
                $payload = [
                    'model' => $model,
                    'max_tokens' => 700,
                    // NB: параметр temperature НЕ передаємо — Opus 4.8 його не приймає
                    // («temperature is deprecated for this model» → відповідь падала).
                    // Проти вигадок працюють промпт-правила (forced grounding, анти-вигадка).
                    'system' => $systemBlocks,
                    'messages' => $messages,
                    'tools' => $this->toolDefinitions(),
                ];
                // Вичерпав ліміт заглядань — зобовʼязаний відповісти текстом.
                if ($loop === self::MAX_TOOL_LOOPS) {
                    $payload['tool_choice'] = ['type' => 'none'];
                }

                $r = Http::timeout(40)->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ])->post('https://api.anthropic.com/v1/messages', $payload);

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
                $tt = trim($this->stripPhotoPlaceholder(collect($content)->where('type', 'text')->pluck('text')->implode("\n")));
                if ($tt !== '') {
                    $turnTexts[] = $tt;
                }

                if ($r->json('stop_reason') !== 'tool_use') {
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
                foreach ($content as $block) {
                    if (($block['type'] ?? '') !== 'tool_use') {
                        continue;
                    }
                    $toolsCalled[] = ['tool' => $block['name'], 'input' => $block['input'] ?? []];
                    $out = $this->runTool($block['name'], (array) ($block['input'] ?? []), $conversation);
                    $results[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content' => json_encode($out, JSON_UNESCAPED_UNICODE),
                    ];
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

    /** Виконати інструмент. Назовні йдуть ЛИШЕ безпечні поля (без собівартости й службового). */
    private function runTool(string $name, array $input, InboxConversation $conversation): array
    {
        try {
            return match ($name) {
                'get_product' => $this->toolGetProduct((int) ($input['product_id'] ?? 0)),
                'send_photos' => $this->toolSendPhotos($conversation, (array) ($input['photo_ids'] ?? [])),
                'ask_delivery_details' => $this->toolAskDeliveryDetails($conversation),
                'send_payment_details' => $this->toolSendPaymentDetails($conversation),
                'request_iban' => $this->toolRequestIban($conversation),
                'escalate_to_manager' => $this->toolEscalateToManager($conversation),
                'complete_order' => $this->toolCompleteOrder($conversation, $input),
                default => ['помилка' => 'Невідомий інструмент'],
            };
        } catch (\Throwable $e) {
            Log::warning('AI tool failed', ['tool' => $name, 'error' => $e->getMessage()]);
            return ['помилка' => 'Не вдалося виконати інструмент'];
        }
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

    /**
     * Зацікавлений клієнт → статус «В роботі», щоб лід не загубився.
     * Тригер — бот показав фото / уточнив деталі / почав збір доставки.
     * Піднімаємо ЛИШЕ з дефолтного «Новий» (або без статусу): не перетираємо
     * ручний статус оператора і вже виставлені ai_order / iban_needed.
     */
    private function maybeMarkInProgress(InboxConversation $conversation, array $toolsCalled): void
    {
        $engaged = collect($toolsCalled)->contains(
            fn ($t) => in_array($t['tool'] ?? '', ['send_photos', 'get_product', 'ask_delivery_details'], true)
        );
        if (!$engaged) {
            return;
        }

        $newId = \App\Models\ChatStatus::where('code', 'new')->value('id');
        if ($conversation->chat_status_id !== null && $conversation->chat_status_id !== $newId) {
            return;
        }

        $inProgress = \App\Models\ChatStatus::firstOrCreate(
            ['code' => 'in_progress'],
            ['name' => 'В роботі', 'icon' => 'bi-chat-dots', 'color' => '#f59f00', 'sort_order' => 2]
        );
        $conversation->update(['chat_status_id' => $inProgress->id]);
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

    /** Надіслати клієнту форму-шаблон для збору даних доставки. */
    public function toolAskDeliveryDetails(InboxConversation $conversation): array
    {
        $ok = $this->sendBotMessage($conversation, self::orderTexts()['delivery_template']);

        return $ok
            ? ['готово' => 'Форму даних доставки надіслано клієнту. Дочекайся ПІБ, телефон і адресу, потім спитай спосіб оплати. Не друкуй цю форму ще раз.']
            : ['помилка' => 'Не вдалося надіслати форму'];
    }

    /** Надіслати реквізити оплати на карту: текст + номер картки ОКРЕМИМ повідомленням. */
    public function toolSendPaymentDetails(InboxConversation $conversation): array
    {
        $t = self::orderTexts();

        // Захист від повтору: якщо номер картки вже надсилали в цій розмові — не дублюємо.
        if ($conversation->messages()->where('direction', 'out')->where('text', $t['card_number'])->exists()) {
            return ['готово' => 'Реквізити цьому клієнту вже надіслані раніше — не дублюй. Чекай скрін/PDF оплати, потім зафіксуй замовлення через complete_order.'];
        }

        $ok1 = $this->sendBotMessage($conversation, $t['card_intro']);
        $ok2 = $ok1 && $this->sendBotMessage($conversation, $t['card_number']);

        if (!$ok1 || !$ok2) {
            return ['помилка' => 'Не вдалося надіслати реквізити'];
        }

        return ['готово' => 'Реквізити надіслано клієнту двома повідомленнями (текст + номер картки окремо). НЕ дублюй номер картки. Чекай скрін/PDF оплати, потім зафіксуй замовлення через complete_order.'];
    }

    /** Клієнт просить повні реквізити / IBAN → пауза + бокова позначка для працівника. */
    public function toolRequestIban(InboxConversation $conversation): array
    {
        $status = \App\Models\ChatStatus::firstOrCreate(
            ['code' => 'iban_needed'],
            ['name' => 'Потрібен IBAN — у працівника', 'icon' => '🏦', 'color' => '#dc2626', 'sort_order' => 95]
        );

        $conversation->update([
            'chat_status_id' => $status->id,
            'ai_order_needs_iban' => true,
            'ai_paused_until' => now()->addMinutes(60), // бот мовчить, поки працівник дасть IBAN
        ]);

        return ['далі' => 'Напиши клієнту, що повні реквізити (IBAN) найближчим часом надішле наш працівник, і більше нічого не питай.'];
    }

    /** Бот не впевнений → пауза + червона мітка «Потрібна увага» менеджеру. */
    public function toolEscalateToManager(InboxConversation $conversation): array
    {
        $status = \App\Models\ChatStatus::firstOrCreate(
            ['code' => 'needs_human'],
            ['name' => 'Потрібна увага', 'icon' => '🔴', 'color' => '#dc2626', 'sort_order' => 0]
        );

        $conversation->update([
            'chat_status_id' => $status->id,
            'ai_paused_until' => now()->addHours(12), // мовчимо, поки менеджер не розбереться
        ]);

        return ['далі' => 'Більше нічого не пиши й не вгадуй — систему вже сповіщено, менеджер передивиться чат.'];
    }

    /**
     * Зафіксувати замовлення: структуровані позиції + дані доставки + оплата,
     * позначка «Замовлення від ШІ», самовимкнення агента — далі оформлює людина.
     */
    public function toolCompleteOrder(InboxConversation $conversation, array $input): array
    {
        $status = \App\Models\ChatStatus::firstOrCreate(
            ['code' => 'ai_order'],
            ['name' => 'Замовлення від ШІ', 'icon' => '🤖', 'color' => '#7c3aed', 'sort_order' => 90]
        );

        // Нормалізуємо позиції (до 5 пар).
        $items = [];
        foreach (array_slice((array) ($input['items'] ?? []), 0, 5) as $it) {
            $title = trim((string) ($it['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $items[] = [
                'title' => $title,
                'color' => trim((string) ($it['color'] ?? '')) ?: null,
                'size' => trim((string) ($it['size'] ?? '')) ?: null,
                'qty' => max(1, (int) ($it['qty'] ?? 1)),
                'price' => isset($it['price']) ? round((float) $it['price']) : null,
            ];
        }

        // Людський підсумок одним рядком (для списку діалогів / сумісності).
        $summary = implode('; ', array_map(function ($i) {
            $line = trim(implode(' ', array_filter([$i['title'], $i['color'], $i['size']])));
            return $line . ($i['qty'] > 1 ? " ×{$i['qty']}" : '');
        }, $items));
        $summary = mb_substr($summary, 0, 250); // не впертись у VARCHAR(255)

        $conversation->update([
            'chat_status_id' => $status->id,
            'ai_enabled' => false, // бот замовкає — далі оформлює людина
            'ai_order_items' => $items ?: null,
            'ai_order_summary' => $summary ?: (trim((string) ($input['summary'] ?? '')) ?: null),
            'ai_order_customer_name' => trim((string) ($input['customer_name'] ?? '')) ?: null,
            'ai_order_phone' => trim((string) ($input['phone'] ?? '')) ?: null,
            'ai_order_address' => trim((string) ($input['address'] ?? '')) ?: null,
            'ai_order_payment' => trim((string) ($input['payment'] ?? '')) ?: null,
            'ai_order_needs_iban' => false,
            'ai_order_handled_at' => null, // нове — чекає менеджера
        ]);

        Log::info('AI: замовлення зафіксовано', [
            'conv' => $conversation->id,
            'items' => count($items),
            'payment' => $input['payment'] ?? '',
            'phone' => $input['phone'] ?? '',
        ]);

        return [
            'готово' => 'Замовлення зафіксовано і показано менеджеру. Бот вимкнено в цій розмові.',
            'далі' => 'Система сама надішле клієнту фінальне підтвердження — більше нічого не пиши.',
        ];
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

        $r = Http::timeout(30)->withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => AiSetting::global()->model ?: 'claude-sonnet-4-6',
            'max_tokens' => 200,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $transcript]],
        ]);

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

    /**
     * Надіслати клієнту фото з галереї ШІ (до 3). Система сама фільтрує
     * нещодавно відправлені, щоб агент не спамив тим самим колажем.
     */
    public function toolSendPhotos(InboxConversation $conversation, array $photoIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $photoIds)));
        $extra = count($ids) > 3 ? 'Ліміт 3 фото за виклик — надіслано перші 3.' : null;
        $ids = array_slice($ids, 0, 3);

        if (empty($ids)) {
            return ['помилка' => 'Не передано жодного номера фото'];
        }

        $conversation->loadMissing(['connection', 'contact']);

        // URL-и з останніх вихідних повідомлень — захист від дублювання.
        $recentUrls = $conversation->messages()
            ->where('direction', 'out')
            ->orderByDesc('id')
            ->limit(10)
            ->pluck('attachments')
            ->filter()
            ->flatten(1)
            ->pluck('url')
            ->all();

        $result = [];
        foreach ($ids as $id) {
            $photo = AiPhoto::find($id);
            if (!$photo) {
                $result["фото №{$id}"] = 'не знайдено — такого номера немає';
                continue;
            }

            $url = $photo->url();
            if (in_array($url, $recentUrls, true)) {
                $result["фото №{$id}"] = 'щойно надсилалося цьому клієнту — пропущено, не дублюй';
                continue;
            }

            $sent = $this->send->sendAttachment($conversation->connection, $conversation->contact->external_id, 'image', $url);
            if (!($sent['ok'] ?? false)) {
                $result["фото №{$id}"] = 'помилка відправки';
                continue;
            }

            $this->persistOutgoing([
                'inbox_conversation_id' => $conversation->id,
                'direction' => 'out',
                'sender' => 'ai',
                'external_message_id' => $sent['message_id'] ?? null,
                'text' => null,
                'attachments' => [['type' => 'image', 'url' => $url]],
                'sent_at' => now(),
            ]);
            $conversation->update([
                'last_message_at' => now(),
                'last_message_text' => '[фото]',
                'last_message_direction' => 'out',
            ]);

            $result["фото №{$id}"] = 'надіслано';
        }

        if ($extra) {
            $result['увага'] = $extra;
        }

        return $result;
    }

    private function toolGetProduct(int $id): array
    {
        $p = Product::with(['category:id,name', 'color:id,name', 'variants' => fn ($q) => $q->where('is_active', true)])->find($id);
        if (!$p) {
            return ['помилка' => 'Товар не знайдено'];
        }

        return array_merge($this->productSummary($p, $this->photoInfoFor([$p->id])), [
            'опис' => $this->scrub($p->description ?: null),
            'розміри' => $p->variants->map(fn ($v) => [
                'розмір' => $v->size,
                'залишок' => (int) $v->stock_qty,
                'наявність' => $v->stock_qty > 0 ? 'є' : 'немає',
            ])->values()->all(),
        ]);
    }

    /** Безпечний підсумок товару. Делегує CatalogBuilder. */
    private function productSummary(Product $p, array $photoInfo = []): array
    {
        return $this->catalogBuilder->productSummary($p, $photoInfo);
    }

    /** Мапа товар → його фото з галереї ШІ. Делегує CatalogBuilder. */
    private function photoInfoFor(array $productIds): array
    {
        return $this->catalogBuilder->photoInfoFor($productIds);
    }

    /**
     * Історія діалогу → формат Claude Messages API.
     * in → user, out → assistant; сусідні однакові ролі зливаються; починається з user.
     */
    private function buildHistory(InboxConversation $conversation, int $limit = 20): array
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
                $text = $this->stripPhotoPlaceholder((string) $this->scrub($text));
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
                    }
                }
                if ($blocks && $text === '') {
                    $text = '(зображення вище — роздивись і знайди відповідник у каталозі)';
                }
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

        return $messages;
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

        return $label !== '' ? "(надіслала клієнту фото товарів: {$label})" : '';
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
        return collect($m->attachments ?? [])
            ->filter(fn ($a) => !empty($a['url']) && str_contains((string) ($a['type'] ?? ''), 'image'))
            ->pluck('url')
            ->values()
            ->all();
    }

    /** Чи повідомлення — лише голосове/аудіо (без тексту й без картинки). */
    private function isVoiceOnly(InboxMessage $m): bool
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

    /**
     * Чи збігається фото клієнта з фото нашої галереї? Делегує ImageMatcher
     * (лишено тут для стабільного публічного API: джоба AiReplyToComment кличе цей метод).
     */
    public function matchGalleryPhoto(string $bytes): ?AiPhoto
    {
        return $this->imageMatcher->matchGalleryPhoto($bytes);
    }

    /** Завантажити картинку клієнта. Делегує ImageMatcher. */
    private function fetchImage(string $url): ?array
    {
        return $this->imageMatcher->fetchImage($url);
    }
}
