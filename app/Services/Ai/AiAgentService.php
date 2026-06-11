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
     * Внутрішні назви лінійок (вигадані для CRM, клієнти їх не знають).
     * Вирізаються з УСЬОГО, що бачить модель: каталог, картки, історія.
     * Модель не може сказати слово, якого ніколи не бачила.
     */
    private const INTERNAL_WORDS = ['halluci', 'luxury'];

    /** Прибрати службові слова з тексту для моделі. */
    private function scrub(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        $clean = preg_replace('/(?:' . implode('|', self::INTERNAL_WORDS) . ')/iu', '', $text);
        return trim(preg_replace('/[ \t]{2,}/u', ' ', $clean));
    }

    /**
     * Вирізати плейсхолдер фото («[зображення]» тощо). Фото шле лише send_photos;
     * якщо модель надрукувала позначку текстом — клієнт побачив би сміття.
     * Чистимо і вихідний текст, і історію (щоб модель не копіювала позначку далі).
     */
    private function stripPhotoPlaceholder(?string $text): string
    {
        $clean = preg_replace('/\[\s*(?:зображення|фото|image|photo|картинк[аи])\s*\]/iu', '', (string) $text);
        return trim(preg_replace('/\n{3,}/u', "\n\n", (string) $clean));
    }
    public function __construct(private MetaSendService $send)
    {
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

            // Актуальність: відповідаємо лише якщо це досі ОСТАННЄ повідомлення і воно вхідне.
            // Якщо клієнт встиг написати ще — відповість джоба новішого повідомлення (з повним контекстом).
            // Якщо вже відповів оператор — мовчимо.
            $last = $conversation->messages()->orderByDesc('id')->first();
            if (!$last || $last->id !== $triggerMessageId || $last->direction !== 'in') {
                return $finish('skipped_stale');
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

            // Цикл tool-ів: Claude може кілька разів заглянути в базу, потім відповідає.
            for ($loop = 0; $loop <= self::MAX_TOOL_LOOPS; $loop++) {
                $payload = [
                    'model' => $model,
                    'max_tokens' => 700,
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

                if ($r->json('stop_reason') !== 'tool_use') {
                    break;
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

            $text = collect($content)
                ->where('type', 'text')
                ->pluck('text')
                ->implode("\n");
            // Страхувальник: якщо модель усе ж надрукувала плейсхолдер фото —
            // вирізаємо, бо текстом фото не надсилається (його шле send_photos).
            $text = $this->stripPhotoPlaceholder($text);
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

            InboxMessage::create([
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

            return $finish('replied', null, $tokensIn, $tokensOut);
        } catch (\Throwable $e) {
            Log::error('AI: respond failed', ['conv' => $conversation->id, 'error' => $e->getMessage()]);
            return $finish('error', mb_substr($e->getMessage(), 0, 500));
        } finally {
            $lock->release();
        }
    }

    /**
     * Системний промпт для Claude: короткі правила + ПОВНИЙ каталог (кешований).
     * Винесено окремо, щоб тести й дрон-прогони били 1:1 у бойовий промпт.
     */
    public function buildSystemPrompt(InboxConversation $conversation, ?AiSetting $store = null): array
    {
        $store ??= AiSetting::where('meta_connection_id', $conversation->meta_connection_id)->first();
        $storePrompt = $store?->system_prompt;
        $pageName = $conversation->connection?->page_name ?? 'магазин';

        $rules = trim(($storePrompt ?: "Ти ввічливий співробітник магазину «{$pageName}»."))
            . "\n\nБазові правила (інструкція вище має пріоритет, якщо вони суперечать): "
            . "відповідай стисло, до 4–5 речень; якщо інструкція не визначає мову — українською. "
            . "Нижче — ПОВНИЙ каталог магазину з живими даними з бази, структурований по ЛІНІЯХ (моделях): "
            . "заголовок «═ ЛІНІЯ ═», під ним «Від магазину» — знання продавчині про цю модель (маломірність, "
            . "відмінності від інших ліній, матеріал, догляд, як впізнати на фото) — спирайся на них як на "
            . "факти й відповідай ними на питання клієнта; далі кольори лінії. Блок «ІНШІ ТОВАРИ» — товари "
            . "поза лініями. Сам вирішуй, що підходить клієнту, за ЗМІСТОМ його слів. Ціни, розміри й "
            . "наявність бери ЛИШЕ з каталогу — не вигадуй і не приписуй товарам властивостей, яких немає "
            . "в назві чи описах. Чого в каталозі немає — чесно скажи й одразу запропонуй найближче з того, "
            . "що Є, не видаючи одне за інше. "
            . "Якщо клієнт називає лише РОЗМІР — перевір його по ВСІХ лініях і покажи, в яких моделях він Є; "
            . "не кажи «немає», якщо розмір закінчився лише в одній лінії, а в іншій доступний. "
            . "На КОЖНЕ товарне питання звіряйся з каталогом заново: твої минулі відповіді в розмові могли "
            . "застаріти, і якщо вони суперечать каталогу — правда в каталозі. Давай ПОВНУ картину: якщо "
            . "запиту відповідають кілька моделей чи цін — покажи всі підходящі варіанти з цінами, не звужуй "
            . "до однієї. "
            . "ВАЖЛИВО про матеріал: пухнасті, пушисті, «з хутра», «з хутром», «з міхом», «з мехом», "
            . "«з пушком», мохнаті, ворсисті — це СИНОНІМИ, усе означає хутряні тапки. У НАС ВЕСЬ асортимент "
            . "хутряний, тому БУДЬ-ЯКУ нашу модель можна назвати пушистою — і клієнт має право називати "
            . "пушистими будь-які. ЗАБОРОНЕНО виправляти клієнта чи сперечатися про слова (типу «це не "
            . "пушисті, а з хутра») — слова «(пушисті)» чи «(хутряні)» в назвах категорій це внутрішні ярлики "
            . "ліній, а НЕ різний матеріал. Лінії розрізняй тільки за призначенням (домашні/вуличні/дитячі) "
            . "та ціною. На загальне «пушисті»/«з хутра» показуй варіанти з УСІХ хутряних категорій з цінами "
            . "(і одразу шли їхні фото, де є) — навіть якщо щойно перелічувала лінійки. Вибором конкретної "
            . "лінійки вважай лише конкретику: ціну («ті, що по 380»), колір, розмір або «перші/другі». "
            . "Клієнту описуй товар простими словами: тип (домашні/вуличні/дитячі) + матеріал (пухнасті, "
            . "з хутра) + колір + ціна, напр. «домашні пухнасті капці у чорному — 500 грн». Жодних службових "
            . "кодів чи назв лінійок. Артикул називай лише якщо клієнт сам ним користується. "
            . "Деталі (матеріал, підошва, стелька, догляд, маломірність) — виклич get_product і відповідай "
            . "з опису; «уточню в менеджера» — лише якщо і там немає. "
            . "ЗАЛІЗНЕ ПРАВИЛО: на пряме питання клієнта (ціна? є в наявності? який розмір є?) відповідай "
            . "У ПЕРШОМУ Ж реченні — конкретною цифрою/фактом, а вже потім уточнюй своє. Питає ціну, а "
            . "підходить кілька моделей → назви ціни ВСІХ одразу (напр. «380, 500 і вуличні 530 грн»). "
            . "Ніколи не відповідай питанням на питання і не ігноруй питання про ціну. "
            . "Максимум ОДНЕ коротке уточнення на тему: клієнт відповів або повторив — покажи конкретні "
            . "варіанти, не перепитуй. "
            . "Розміри в каталозі діапазонами: клієнт каже «38» → це 38-39, перевір наявність і підтверди "
            . "(«Вам підійде 38-39?»). Довжину в сантиметрах сам не конвертуй — спитай звичний розмір або "
            . "передай менеджеру. "
            . "Фото ВІД клієнта: якщо в повідомленні є картинка — уважно роздивись її: тип (домашні/вуличні/"
            . "дитячі), колір, хутро, і знайди відповідник у каталозі за виглядом. Впізнав — назви товар "
            . "своїми словами, ціну й наявні розміри, надішли наші фото цього товару. Якщо поруч із фото є "
            . "позначка «(система: це фото збігається з …)» — це ТОЧНИЙ збіг з нашою галереєю: відповідай "
            . "строго по вказаних товарах, не переглядай. Без позначки і не впевнений — скажи, що бачиш "
            . "(«схожі на наші домашні пухнасті…») і постав ОДНЕ уточнення; нічого не вигадуй. "
            . "\n\nФото: у товарів в каталозі є номери «фото» (ракурси цього кольору) і «колаж» (спільне фото "
            . "групи). Надсилай їх інструментом send_photos (до 3 за виклик), клієнт отримує картинки в чат. "
            . "Ти — як жива продавчиня: товар ПОКАЗУЮТЬ, а не описують словами. НІКОЛИ не питай «показати "
            . "фото?» чи «який колір показати?» — спершу ПОКАЖИ, потім запитуй про колір/розмір. "
            . "ОБОВʼЯЗКОВО викликай send_photos у ЦІЙ ЖЕ відповіді, коли: 1) клієнт назвав тип або групу "
            . "(вуличні, домашні, пушисті, дитячі…) чи питає «що є», «які кольори» → одразу шли колаж групи "
            . "плюс 1-2 фото кольорів; 2) клієнт назвав конкретний колір → шли всі його ракурси; 3) клієнт "
            . "прямо просить фото → завжди. Без фото відповідай ЛИШЕ якщо у відповідних товарів немає жодного "
            . "номера фото — тоді чесно скажи, що фото надішле менеджер, і не вставляй посилань у текст. "
            . "КРИТИЧНО: фото надсилається ВИКЛЮЧНО викликом інструмента send_photos. НІКОЛИ не пиши в "
            . "тексті відповіді «[зображення]», «[фото]», «[image]» чи будь-які подібні позначки в дужках — "
            . "це НЕ надсилає фото, клієнт побачить лише дивний текст. Хочеш показати фото — виклич send_photos.";

        return [
            ['type' => 'text', 'text' => $rules],
            [
                'type' => 'text',
                'text' => "КАТАЛОГ МАГАЗИНУ (повний, актуальний на зараз):\n" . $this->buildCatalog(),
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ];
    }

    /** Описи інструментів для Claude. */
    private function toolDefinitions(): array
    {
        return [
            [
                'name' => 'get_product',
                'description' => 'Повна картка товару за його id: усі розміри з реальними залишками, ціна та ОПИС з деталями (матеріал, хутро, підошва, стельки в см, догляд/прання, маломірність). Викликай для питань про характеристики конкретної моделі.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'description' => 'id товару з каталогу (число після #)'],
                    ],
                    'required' => ['product_id'],
                ],
            ],
            [
                'name' => 'send_photos',
                'description' => 'Надіслати клієнту фото за номерами з полів «фото» і «колаж» результатів пошуку. До 3 за виклик. Колаж — для огляду групи, фото кольору — коли клієнт обрав конкретний колір.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'photo_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Номери фото, напр. [11] або [13, 14]',
                        ],
                    ],
                    'required' => ['photo_ids'],
                ],
            ],
        ];
    }

    /** Виконати інструмент. Назовні йдуть ЛИШЕ безпечні поля (без собівартости й службового). */
    private function runTool(string $name, array $input, InboxConversation $conversation): array
    {
        try {
            return match ($name) {
                'get_product' => $this->toolGetProduct((int) ($input['product_id'] ?? 0)),
                'send_photos' => $this->toolSendPhotos($conversation, (array) ($input['photo_ids'] ?? [])),
                default => ['помилка' => 'Невідомий інструмент'],
            };
        } catch (\Throwable $e) {
            Log::warning('AI tool failed', ['tool' => $name, 'error' => $e->getMessage()]);
            return ['помилка' => 'Не вдалося виконати інструмент'];
        }
    }

    /**
     * Повний каталог для системного промпта, структурований по ЛІНІЯХ (вітрина):
     * заголовок лінії → «Від магазину» (знання продавчині з ai_description) →
     * кольори лінії з живими цінами/розмірами/фото. Товари поза лініями — внизу
     * блоком «ІНШІ ТОВАРИ». Цифри завжди з бази, слова — від власника.
     */
    public function buildCatalog(): string
    {
        $products = Product::query()
            ->where('is_active', true)
            ->with(['category:id,name', 'color:id,name', 'variants' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('category_id')
            ->orderBy('title')
            ->limit(300)
            ->get()
            ->keyBy('id');

        if ($products->isEmpty()) {
            return '(каталог порожній)';
        }

        $photoInfo = $this->photoInfoFor($products->keys()->all());

        $line = function (Product $p) use ($photoInfo): string {
            $sizes = $p->variants->filter(fn ($v) => $v->stock_qty > 0)->pluck('size')->implode(',');
            $i = $photoInfo[$p->id] ?? [];

            $parts = array_filter([
                '#' . $p->id,
                $this->scrub($p->title),
                $this->scrub($p->category?->name),
                $p->color?->name,
                'SKU ' . $p->sku,
                round((float) $p->sale_price) . ' грн',
                $sizes !== '' ? "розміри: {$sizes}" : 'НЕМАЄ в наявності',
                !empty($i['фото']) ? 'фото:[' . implode(',', $i['фото']) . ']' : null,
                !empty($i['колаж']) ? 'колаж:' . $i['колаж'] : null,
            ], fn ($x) => $x !== null && $x !== '');

            return implode(' | ', $parts);
        };

        $sections = [];
        $used = [];

        $groups = AiPhotoGroup::with(['products:products.id', 'photos.products:products.id'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($groups as $g) {
            $ids = $g->products->pluck('id')
                ->filter(fn ($id) => $products->has($id) && !isset($used[$id]))
                ->values();
            if ($ids->isEmpty()) {
                continue;
            }

            $head = '═ ЛІНІЯ: ' . $this->scrub($g->name) . ' ═';
            $collages = $g->photos->filter(fn (AiPhoto $p) => $p->products->count() >= 2)->pluck('id')->all();
            if ($collages) {
                $head .= "\nКолажі лінії: [" . implode(',', $collages) . ']';
            }
            if (trim((string) $g->ai_description) !== '') {
                $head .= "\nВід магазину: " . trim((string) $this->scrub($g->ai_description));
            }

            $sections[] = $head . "\n" . $ids->map(fn ($id) => '  ' . $line($products[$id]))->implode("\n");
            foreach ($ids as $id) {
                $used[$id] = true;
            }
        }

        $rest = $products->keys()->reject(fn ($id) => isset($used[$id]))->values();
        if ($rest->isNotEmpty()) {
            $sections[] = "═ ІНШІ ТОВАРИ (поза лініями, без опису від магазину) ═\n"
                . $rest->map(fn ($id) => '  ' . $line($products[$id]))->implode("\n");
        }

        return implode("\n\n", $sections);
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

            InboxMessage::create([
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

    /** Безпечний підсумок товару: БЕЗ собівартости, ваги і службових полів. */
    private function productSummary(Product $p, array $photoInfo = []): array
    {
        $inStockSizes = $p->variants
            ->filter(fn ($v) => $v->is_active !== false && $v->stock_qty > 0)
            ->pluck('size')
            ->implode(', ');

        $info = $photoInfo[$p->id] ?? [];

        return [
            'id' => $p->id,
            'назва' => $this->scrub($p->title),
            'sku' => $p->sku,
            'ціна' => round((float) $p->sale_price) . ' грн',
            'категорія' => $this->scrub($p->category?->name),
            'колір' => $p->color?->name,
            'наявність' => $inStockSizes !== '' ? 'є в наявності' : 'немає в наявності',
            'розміри_в_наявності' => $inStockSizes ?: null,
            'група' => $info['група'] ?? null,
            'фото' => $info['фото'] ?? null,
            'колаж' => $info['колаж'] ?? null,
            'колажі_групи' => $info['колажі_групи'] ?? null,
        ];
    }

    /**
     * Мапа товар → його фото з галереї ШІ: «фото» (де він один),
     * «колаж» (перше спільне фото групи), «група» (назва лінійки).
     */
    private function photoInfoFor(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $groups = AiPhotoGroup::query()
            ->whereHas('products', fn ($q) => $q->whereIn('products.id', $productIds))
            ->with(['products:products.id', 'photos.products:products.id'])
            ->get();

        $map = [];
        foreach ($groups as $group) {
            $collages = $group->photos->filter(fn (AiPhoto $p) => $p->products->count() >= 2)->values();
            $collageIds = $collages->pluck('id')->all();

            foreach ($group->products as $product) {
                if (!in_array($product->id, $productIds) || isset($map[$product->id])) {
                    continue;
                }

                // ВСІ фото цього кольору (різні ракурси), по порядку.
                $own = $group->photos
                    ->filter(fn (AiPhoto $p) => $p->products->count() === 1 && (int) $p->products->first()->id === (int) $product->id)
                    ->pluck('id')
                    ->values()
                    ->all();

                // Колаж саме з цим товаром; як нема — перший колаж групи.
                $ownCollage = $collages->first(fn (AiPhoto $p) => $p->products->contains('id', $product->id));

                $map[$product->id] = [
                    'група' => $this->scrub($group->name),
                    'фото' => $own ?: null,
                    'колаж' => $ownCollage?->id ?? ($collages->first()?->id),
                    'колажі_групи' => $collageIds ?: null,
                ];
            }
        }

        return $map;
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

        // Vision: 2 НАЙСВІЖІШІ фото клієнта передаємо моделі як картинки —
        // вона бачить, що на них, і звіряє з каталогом. Старіші — лише текстом.
        $visionMessageIds = $items
            ->filter(fn ($m) => $m->direction === 'in' && $this->clientImageUrls($m) !== [])
            ->sortByDesc('id')
            ->take(self::MAX_VISION_IMAGES)
            ->pluck('id')
            ->all();

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
                foreach (array_slice($this->clientImageUrls($m), 0, 2) as $url) {
                    $img = $this->fetchImage($url);
                    if (!$img) {
                        continue;
                    }
                    $blocks[] = [
                        'type' => 'image',
                        'source' => ['type' => 'base64', 'media_type' => $img['mime'], 'data' => base64_encode($img['bytes'])],
                    ];
                    // Скрін нашого ж фото → точний товар, без вгадування.
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
                    $text = '(фото від клієнта вище — роздивись і знайди відповідник у каталозі)';
                }
            }

            if ($text === '' && empty($blocks)) {
                if (empty($m->attachments)) {
                    continue;
                }
                // Фото в історії НЕ позначаємо токеном-плейсхолдером: модель починала
                // друкувати його як текст замість виклику send_photos.
                if ($role === 'user') {
                    $text = '(клієнт надіслав фото)';
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
    private function sentPhotosNote(InboxMessage $m): string
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

        return $labels
            ? '(надіслала клієнту фото товарів: ' . implode(' | ', array_unique($labels)) . ')'
            : '';
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

    /**
     * dHash 8x8 (16 hex-символів) — перцептивний відбиток картинки.
     * Стійкий до стиснення/ресайзу, яким Meta мне скріни клієнтів.
     */
    private function imageHash(string $bytes): ?string
    {
        try {
            $img = @imagecreatefromstring($bytes);
            if (!$img) {
                return null;
            }
            $small = imagescale($img, 9, 8);
            imagedestroy($img);
            if (!$small) {
                return null;
            }

            $bits = '';
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 9; $x++) {
                    $c = imagecolorat($small, $x, $y);
                    $gray[$x] = (($c >> 16 & 0xFF) * 299 + ($c >> 8 & 0xFF) * 587 + ($c & 0xFF) * 114) / 1000;
                    if ($x > 0) {
                        $bits .= $gray[$x - 1] > $gray[$x] ? '1' : '0';
                    }
                }
            }
            imagedestroy($small);

            $hex = '';
            foreach (str_split($bits, 4) as $nibble) {
                $hex .= dechex(bindec($nibble));
            }

            return $hex;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Відстань Геммінга між двома hex-відбитками (менше = схожіші). */
    private function hashDistance(string $a, string $b): int
    {
        $d = 0;
        for ($i = 0; $i < min(strlen($a), strlen($b)); $i++) {
            $d += substr_count(str_pad(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), 4, '0', STR_PAD_LEFT), '1');
        }

        return $d;
    }

    /**
     * Чи збігається фото клієнта з фото нашої галереї? (скрін нашого фото —
     * найчастіший випадок). Збіг = точний товар, модель не вгадує.
     * Відбитки галереї рахуються ліниво й кешуються в БД.
     */
    private function matchGalleryPhoto(string $bytes): ?AiPhoto
    {
        $hash = $this->imageHash($bytes);
        if (!$hash) {
            return null;
        }

        $best = null;
        $bestDist = 11; // поріг: ≤10 з 64 біт — впевнений збіг

        foreach (AiPhoto::with('products.color:id,name')->get() as $photo) {
            if (!$photo->phash) {
                $file = public_path($photo->path);
                if (!is_file($file)) {
                    continue;
                }
                $ph = $this->imageHash((string) file_get_contents($file));
                if (!$ph) {
                    continue;
                }
                $photo->forceFill(['phash' => $ph])->save();
            }
            $dist = $this->hashDistance($hash, $photo->phash);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $photo;
            }
        }

        return $best;
    }

    /**
     * Завантажити картинку клієнта (посилання Meta тимчасові, тягнемо одразу).
     * Не вдалося (протухло/завелике/не картинка) — null, історія деградує в текст.
     * @return array{mime: string, bytes: string}|null
     */
    private function fetchImage(string $url): ?array
    {
        try {
            $r = Http::timeout(10)->get($url);
            if (!$r->successful()) {
                return null;
            }
            $mime = trim(explode(';', (string) $r->header('Content-Type'))[0]) ?: 'image/jpeg';
            if (!str_starts_with($mime, 'image/')) {
                return null;
            }
            $body = $r->body();
            if (strlen($body) > 4 * 1024 * 1024) { // ліміт API ~5MB на картинку, з запасом
                return null;
            }

            return ['mime' => $mime, 'bytes' => $body];
        } catch (\Throwable $e) {
            Log::warning('AI vision: не вдалося завантажити фото клієнта', ['url' => mb_substr($url, 0, 120), 'error' => $e->getMessage()]);
            return null;
        }
    }
}
