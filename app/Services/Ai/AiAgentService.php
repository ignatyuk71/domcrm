<?php

namespace App\Services\Ai;

use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Category;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Product;
use App\Services\Meta\MetaSendService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Фаза 3: агент відповідає на вхідні, користуючись інструкцією магазину,
 * історією діалогу та «руками» — живими запитами в базу товарів
 * (пошук + картка товару). Собівартість і службові поля назовні не виходять.
 */
class AiAgentService
{
    private const MAX_TOOL_LOOPS = 5;
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

            $categories = Category::orderBy('name')->pluck('name')->implode(', ');

            $system = trim(($store->system_prompt ?: "Ти ввічливий співробітник магазину «{$conversation->connection->page_name}»."))
                . "\n\nБазові правила (інструкція вище має пріоритет, якщо вони суперечать): "
                . "відповідай стисло, до 4–5 речень. Якщо інструкція не визначає мову — відповідай українською. "
                . "\n\nУ тебе є інструменти пошуку по БАЗІ ТОВАРІВ магазину — використовуй їх щоразу, "
                . "коли клієнт питає про товар, ціну, колір, розмір або наявність. "
                . "Ціни, розміри й наявність називай ЛИШЕ з результатів інструментів — ніколи не вигадуй. "
                . "Дані в базі змінюються: перед КОЖНИМ переліком товарів чи цін викликай search_products заново, "
                . "навіть якщо схожий список уже був у розмові — НЕ передруковуй зі своїх старих повідомлень. "
                . "Питання про матеріал, підошву, стельку, догляд чи маломірність — це є в ОПИСІ товару: "
                . "спочатку виклич get_product потрібної моделі і відповідай з опису; "
                . "«уточню в менеджера» кажи ЛИШЕ якщо в описі відповіді справді немає. "
                . "Якщо інструмент нічого не знайшов — скажи, що уточниш у менеджера. "
                . "\n\nПоведінка продавця: максимум ОДНЕ коротке уточнення на тему. Якщо клієнт відповів на уточнення "
                . "або повторив запит — БІЛЬШЕ НЕ перепитуй, одразу покажи конкретні товари з бази (назва, ціна, наявність). "
                . "Краще показати влучний список і спитати «який подобається?», ніж ставити ще одне абстрактне питання. "
                . "\n\nРозміри: в базі розміри взуття йдуть діапазонами (напр. 36-37, 38-39, 40-41). "
                . "Якщо клієнт називає один розмір (напр. «38») — сам визнач діапазон, куди він входить "
                . "(38 → 38-39, 41 → 40-41), перевір його наявність і запропонуй, обовʼязково уточнивши: "
                . "«Вам підійде 38-39?». Якщо клієнт пише довжину стопи в сантиметрах — не підбирай розмір сам: "
                . "попроси його звичний розмір взуття або скажи, що точні заміри устілки підкаже менеджер. "
                . "\n\nПризначення взуття визначай СУВОРО за назвою і категорією товару: «для вулиці»/«вуличні» — "
                . "носять надвір; «домашні», «чуні», «тапочки» — лише для дому. Просять «на вулицю» — пропонуй "
                . "тільки вуличні моделі; немає підходящих вуличних — чесно скажи, що немає, і запропонуй уточнити "
                . "в менеджера. НІКОЛИ не приписуй товару властивостей, яких немає в його назві чи описі. "
                . "Сезон так само: пухнасті/хутряні/теплі/з овчини — це ЗИМОВІ й теплі моделі, вони НЕ літні і не "
                . "«легкі». Просять літні/легкі — пропонуй лише товари, де про літо чи легкість прямо сказано в "
                . "назві/категорії/описі; таких немає — чесно скажи, що літніх немає, і НЕ підсовуй теплі як заміну. "
                . "Якщо пошук позначив збіг як ЧАСТКОВИЙ — список засмічений: спершу відфільтруй його сам. "
                . "\n\nФото: у товарів з пошуку є поля «фото» (СПИСОК фото саме цього кольору — різні ракурси), "
                . "«колаж» (колаж, де цей товар серед інших), «колажі_групи» (всі колажі лінійки) і «група». "
                . "Інструмент send_photos надсилає клієнту фото за номерами (до 3 за виклик), вони доходять як "
                . "картинки в чат. Показуй щедро, як жива продавчиня — НЕ одне фото, а до 3 за раз. Правила: "
                . "клієнт питає загально, просить показати що є чи фото без конкретики → надішли «колажі_групи», "
                . "і якщо колаж один — докинь до 3 фото кольорів у наявності (найперші «фото» різних товарів), "
                . "потім запитай, який колір цікавить; клієнт назвав конкретний колір чи модель → надішли ВСІ його "
                . "«фото» одним викликом (до 3 ракурсів), а якщо «фото» немає — його «колаж»; клієнт прямо просить "
                . "фото («можна фото», «покажіть») → обовʼязково надішли. "
                . "Якщо всі поля null — фото не існує: не вигадуй, не обіцяй і не вставляй жодних посилань у "
                . "текст; на пряме прохання скажи, що фото надішле менеджер. Спершу фото, потім короткий текст. "
                . ($categories !== '' ? "\n\nКатегорії магазину: {$categories}." : '');

            $model = $global->model ?: 'claude-sonnet-4-6';
            $tokensIn = 0;
            $tokensOut = 0;
            $content = [];

            // Цикл tool-ів: Claude може кілька разів заглянути в базу, потім відповідає.
            for ($loop = 0; $loop <= self::MAX_TOOL_LOOPS; $loop++) {
                $payload = [
                    'model' => $model,
                    'max_tokens' => 700,
                    'system' => $system,
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

                $tokensIn += (int) $r->json('usage.input_tokens', 0);
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
            $text = trim($text);
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
        }
    }

    /** Описи інструментів для Claude. */
    private function toolDefinitions(): array
    {
        return [
            [
                'name' => 'search_products',
                'description' => 'Пошук товарів магазину за словами клієнта: назва, колір, категорія або артикул (SKU). Повертає до 8 товарів з ціною та наявністю.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Слова пошуку, напр.: "рожеві капці", "дитячі", "піжама", "6026"',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_product',
                'description' => 'Повна картка товару за його id: усі розміри з реальними залишками, ціна та ОПИС з деталями (матеріал, хутро, підошва, стельки в см, догляд/прання, маломірність). Викликай для питань про характеристики конкретної моделі.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'description' => 'id товару з результатів search_products'],
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
                'search_products' => $this->toolSearchProducts((string) ($input['query'] ?? '')),
                'get_product' => $this->toolGetProduct((int) ($input['product_id'] ?? 0)),
                'send_photos' => $this->toolSendPhotos($conversation, (array) ($input['photo_ids'] ?? [])),
                default => ['помилка' => 'Невідомий інструмент'],
            };
        } catch (\Throwable $e) {
            Log::warning('AI tool failed', ['tool' => $name, 'error' => $e->getMessage()]);
            return ['помилка' => 'Не вдалося виконати пошук'];
        }
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

    public function toolSearchProducts(string $query): array
    {
        $words = collect(preg_split('/[\s,.\/]+/u', mb_strtolower(trim($query))))
            ->filter(fn ($w) => mb_strlen($w) >= 2)
            // грубий «стем», щоб ловити різні закінчення: вуличні/вулиці → «вули», рожеві/рожевий → «рож»
            ->map(function ($w) {
                $n = mb_strlen($w);
                if ($n >= 6) {
                    return mb_substr($w, 0, $n - 3);
                }
                if ($n === 5) {
                    return mb_substr($w, 0, 3);
                }
                return $w;
            })
            ->take(5)
            ->values();

        if ($words->isEmpty()) {
            return ['знайдено' => 0, 'товари' => []];
        }

        $build = function ($mode, int $limit = 8) use ($words) {
            return Product::query()
                ->with(['category:id,name', 'color:id,name', 'variants:id,product_id,size,stock_qty,is_active'])
                ->where(function ($q) use ($words, $mode) {
                    foreach ($words as $w) {
                        $clause = function ($sub) use ($w) {
                            $sub->where('title', 'like', "%{$w}%")
                                ->orWhere('sku', 'like', "%{$w}%")
                                ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$w}%"))
                                ->orWhereHas('color', fn ($c) => $c->where('name', 'like', "%{$w}%"));
                        };
                        $mode === 'and' ? $q->where($clause) : $q->orWhere($clause);
                    }
                })
                ->limit($limit)
                ->get();
        };

        // Точний пошук: до 21, щоб помітити обрізання (у магазині буває 15+ кольорів однієї моделі)
        $found = $build('and', 21);
        $truncated = $found->count() > 20;
        $found = $found->take(20);
        $partialMatch = false;

        if ($found->isEmpty()) {
            $partialMatch = true;
            // Мʼякший пошук: беремо ширше і ранжуємо за кількістю збігів слів,
            // щоб «капці для вулиці» стояли вище за випадкові збіги.
            $pool = $build('or', 60)
                ->map(function (Product $p) use ($words) {
                    $hay = mb_strtolower($p->title . ' ' . ($p->category?->name ?? '') . ' ' . ($p->color?->name ?? '') . ' ' . $p->sku);
                    $p->setAttribute('search_score', $words->filter(fn ($w) => str_contains($hay, $w))->count());
                    return $p;
                })
                ->sortByDesc('search_score');
            $truncated = $truncated || $pool->count() > 20;
            $found = $pool->take(20)->values();
        }

        $photoInfo = $this->photoInfoFor($found->pluck('id')->all());

        $res = [
            'знайдено' => $found->count(),
            'збіг' => $partialMatch
                ? 'ЧАСТКОВИЙ: точних збігів немає, список зібраний за окремими словами і може містити нерелевантні товари. Відбери лише ті, що СПРАВДІ відповідають запиту (сезон, призначення, матеріал); якщо таких немає — чесно скажи клієнту, що немає.'
                : 'точний',
            'товари' => $found->map(fn (Product $p) => $this->productSummary($p, $photoInfo))->values()->all(),
        ];
        if ($truncated) {
            $res['увага'] = 'Показано перші 20 — є ще збіги, уточни запит (колір/категорія), і не кажи «всі», бо список неповний.';
        }

        return $res;
    }

    private function toolGetProduct(int $id): array
    {
        $p = Product::with(['category:id,name', 'color:id,name', 'variants' => fn ($q) => $q->where('is_active', true)])->find($id);
        if (!$p) {
            return ['помилка' => 'Товар не знайдено'];
        }

        return array_merge($this->productSummary($p, $this->photoInfoFor([$p->id])), [
            'опис' => $p->description ?: null,
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
            'назва' => $p->title,
            'sku' => $p->sku,
            'ціна' => round((float) $p->sale_price) . ' грн',
            'категорія' => $p->category?->name,
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
                    'група' => $group->name,
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
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $messages = [];
        foreach ($items as $m) {
            $role = $m->direction === 'in' ? 'user' : 'assistant';
            $text = trim((string) $m->text);
            if ($text === '') {
                $text = !empty($m->attachments) ? '[зображення]' : '';
            }
            if ($text === '') {
                continue;
            }

            if (!empty($messages) && $messages[count($messages) - 1]['role'] === $role) {
                $messages[count($messages) - 1]['content'] .= "\n" . $text;
            } else {
                $messages[] = ['role' => $role, 'content' => $text];
            }
        }

        // Перше повідомлення має бути від user
        while (!empty($messages) && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        return $messages;
    }
}
