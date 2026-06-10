<?php

namespace App\Services\Ai;

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
                . "Якщо інструмент нічого не знайшов — скажи, що уточниш у менеджера. "
                . "\n\nРозміри: в базі розміри взуття йдуть діапазонами (напр. 36-37, 38-39, 40-41). "
                . "Якщо клієнт називає один розмір (напр. «38») — сам визнач діапазон, куди він входить "
                . "(38 → 38-39, 41 → 40-41), перевір його наявність і запропонуй, обовʼязково уточнивши: "
                . "«Вам підійде 38-39?». Якщо клієнт пише довжину стопи в сантиметрах — не підбирай розмір сам: "
                . "попроси його звичний розмір взуття або скажи, що точні заміри устілки підкаже менеджер. "
                . "\n\nПризначення взуття визначай СУВОРО за назвою і категорією товару: «для вулиці»/«вуличні» — "
                . "носять надвір; «домашні», «чуні», «тапочки» — лише для дому. Просять «на вулицю» — пропонуй "
                . "тільки вуличні моделі; немає підходящих вуличних — чесно скажи, що немає, і запропонуй уточнити "
                . "в менеджера. НІКОЛИ не приписуй товару властивостей, яких немає в його назві чи описі. "
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
                    $out = $this->runTool($block['name'], (array) ($block['input'] ?? []));
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

            // Клієнт міг дописати, ПОКИ Claude думав — тоді цю відповідь викидаємо,
            // нова джоба відповість з урахуванням дописаного.
            $lastNow = $conversation->messages()->orderByDesc('id')->value('id');
            if ($lastNow !== $triggerMessageId) {
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
                'description' => 'Повна картка товару за його id: усі розміри з реальними залишками, ціна, опис.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'description' => 'id товару з результатів search_products'],
                    ],
                    'required' => ['product_id'],
                ],
            ],
        ];
    }

    /** Виконати інструмент. Назовні йдуть ЛИШЕ безпечні поля (без собівартости й службового). */
    private function runTool(string $name, array $input): array
    {
        try {
            return match ($name) {
                'search_products' => $this->toolSearchProducts((string) ($input['query'] ?? '')),
                'get_product' => $this->toolGetProduct((int) ($input['product_id'] ?? 0)),
                default => ['помилка' => 'Невідомий інструмент'],
            };
        } catch (\Throwable $e) {
            Log::warning('AI tool failed', ['tool' => $name, 'error' => $e->getMessage()]);
            return ['помилка' => 'Не вдалося виконати пошук'];
        }
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

        if ($found->isEmpty()) {
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

        $res = [
            'знайдено' => $found->count(),
            'товари' => $found->map(fn (Product $p) => $this->productSummary($p))->values()->all(),
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

        return array_merge($this->productSummary($p), [
            'опис' => $p->description ?: null,
            'розміри' => $p->variants->map(fn ($v) => [
                'розмір' => $v->size,
                'залишок' => (int) $v->stock_qty,
                'наявність' => $v->stock_qty > 0 ? 'є' : 'немає',
            ])->values()->all(),
        ]);
    }

    /** Безпечний підсумок товару: БЕЗ собівартости, ваги і службових полів. */
    private function productSummary(Product $p): array
    {
        $inStockSizes = $p->variants
            ->filter(fn ($v) => $v->is_active !== false && $v->stock_qty > 0)
            ->pluck('size')
            ->implode(', ');

        return [
            'id' => $p->id,
            'назва' => $p->title,
            'sku' => $p->sku,
            'ціна' => round((float) $p->sale_price) . ' грн',
            'категорія' => $p->category?->name,
            'колір' => $p->color?->name,
            'наявність' => $inStockSizes !== '' ? 'є в наявності' : 'немає в наявності',
            'розміри_в_наявності' => $inStockSizes ?: null,
        ];
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
