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

            // Правила короткі: думає САМ Claude. Він бачить повний каталог і вирішує,
            // що підходить клієнту — без нашого пошуку по ключових словах.
            $rules = trim(($store->system_prompt ?: "Ти ввічливий співробітник магазину «{$conversation->connection->page_name}»."))
                . "\n\nБазові правила (інструкція вище має пріоритет, якщо вони суперечать): "
                . "відповідай стисло, до 4–5 речень; якщо інструкція не визначає мову — українською. "
                . "Нижче — ПОВНИЙ каталог магазину з живими даними з бази. Сам вирішуй, що підходить клієнту, "
                . "за ЗМІСТОМ його слів. Ціни, розміри й наявність бери ЛИШЕ з каталогу — не вигадуй і не "
                . "приписуй товарам властивостей, яких немає в назві чи описі. Чого в каталозі немає — чесно "
                . "скажи й одразу запропонуй найближче з того, що Є, не видаючи одне за інше. "
                . "На КОЖНЕ товарне питання звіряйся з каталогом заново: твої минулі відповіді в розмові могли "
                . "застаріти, і якщо вони суперечать каталогу — правда в каталозі. Давай ПОВНУ картину: якщо "
                . "запиту відповідають кілька груп чи цін — покажи всі підходящі варіанти з цінами, не звужуй "
                . "до однієї лінійки. "
                . "СУВОРА ЗАБОРОНА: слова Halluci, Luxury, назви груп і SKU — внутрішні позначення магазину, "
                . "клієнт їх не знає. НІКОЛИ не пиши їх клієнту — навіть якщо вони стоять у НАЗВІ товару в "
                . "каталозі, і навіть якщо вже звучали в цій розмові (твої минулі повідомлення — не виправдання). "
                . "Перекладай назву в просту мову: тип (домашні/вуличні/дитячі) + матеріал (пухнасті, з хутра) + "
                . "колір + ціна. Замість «Капці Luxury чорні — 500 грн» кажи «домашні пухнасті капці у чорному — "
                . "500 грн». Артикул називай лише якщо клієнт сам ним користується. "
                . "Деталі (матеріал, підошва, стелька, догляд, маломірність) — виклич get_product і відповідай "
                . "з опису; «уточню в менеджера» — лише якщо і там немає. "
                . "Максимум ОДНЕ коротке уточнення на тему: клієнт відповів або повторив — покажи конкретні "
                . "варіанти, не перепитуй. "
                . "Розміри в каталозі діапазонами: клієнт каже «38» → це 38-39, перевір наявність і підтверди "
                . "(«Вам підійде 38-39?»). Довжину в сантиметрах сам не конвертуй — спитай звичний розмір або "
                . "передай менеджеру. "
                . "\n\nФото: у товарів в каталозі є номери «фото» (ракурси цього кольору) і «колаж» (спільне фото "
                . "групи). Надсилай їх інструментом send_photos (до 3 за виклик), клієнт отримує картинки в чат. "
                . "Показуй щедро, як жива продавчиня, не одне фото: огляд/«що є» → колаж(і) плюс пара кольорів; "
                . "конкретний колір → усі його ракурси; прямо просять фото → обовʼязково надішли. Немає номерів — "
                . "фото не існує: не обіцяй і не вставляй посилань у текст. Спершу фото, потім короткий текст.";

            $systemBlocks = [
                ['type' => 'text', 'text' => $rules],
                [
                    'type' => 'text',
                    'text' => "КАТАЛОГ МАГАЗИНУ (повний, актуальний на зараз):\n" . $this->buildCatalog(),
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ];

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
     * Повний каталог для системного промпта: один рядок на товар, живі дані.
     * Рішення «що підходить клієнту» приймає Claude, а не пошук по словах.
     */
    public function buildCatalog(): string
    {
        $products = Product::query()
            ->where('is_active', true)
            ->with(['category:id,name', 'color:id,name', 'variants' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('category_id')
            ->orderBy('title')
            ->limit(300)
            ->get();

        if ($products->isEmpty()) {
            return '(каталог порожній)';
        }

        $photoInfo = $this->photoInfoFor($products->pluck('id')->all());

        return $products->map(function (Product $p) use ($photoInfo) {
            $sizes = $p->variants->filter(fn ($v) => $v->stock_qty > 0)->pluck('size')->implode(',');
            $i = $photoInfo[$p->id] ?? [];

            $parts = array_filter([
                '#' . $p->id,
                $p->title,
                $p->category?->name,
                $p->color?->name,
                'SKU ' . $p->sku,
                round((float) $p->sale_price) . ' грн',
                $sizes !== '' ? "розміри: {$sizes}" : 'НЕМАЄ в наявності',
                !empty($i['фото']) ? 'фото:[' . implode(',', $i['фото']) . ']' : null,
                !empty($i['колаж']) ? 'колаж:' . $i['колаж'] : null,
                !empty($i['група']) ? 'група: ' . $i['група'] : null,
            ], fn ($x) => $x !== null && $x !== '');

            return implode(' | ', $parts);
        })->implode("\n");
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
