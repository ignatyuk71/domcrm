<?php

namespace App\Services\Ai;

use App\Models\AiPhoto;
use App\Models\InboxConversation;
use App\Models\Product;
use App\Services\Meta\MetaSendService;
use Illuminate\Support\Facades\Log;

/**
 * Інструменти агента (tool-use) і службові дії розмови: картка товару, фото,
 * форма доставки, реквізити, ескалація, фіксація замовлення, статус «В роботі».
 * Винесено з AiAgentService.
 */
class AgentTools
{
    public function __construct(
        private OutgoingMessageWriter $outgoing,
        private CatalogBuilder $catalog,
        private TextScrubber $scrubber,
        private MetaSendService $send,
    ) {
    }

    private function sendBotMessage(InboxConversation $conversation, string $text): bool
    {
        return $this->outgoing->sendBotMessage($conversation, $text);
    }

    private function persistOutgoing(array $attrs): void
    {
        $this->outgoing->persistOutgoing($attrs);
    }

    private function scrub(?string $text): ?string
    {
        return $this->scrubber->scrub($text);
    }

    private function productSummary(Product $p, array $photoInfo = []): array
    {
        return $this->catalog->productSummary($p, $photoInfo);
    }

    private function photoInfoFor(array $productIds): array
    {
        return $this->catalog->photoInfoFor($productIds);
    }

    /** Виконати інструмент. Назовні йдуть ЛИШЕ безпечні поля (без собівартости й службового). */
    public function runTool(string $name, array $input, InboxConversation $conversation): array
    {
        try {
            return match ($name) {
                'get_product' => $this->toolGetProduct((int) ($input['product_id'] ?? 0)),
                'send_photos' => $this->toolSendPhotos($conversation, (array) ($input['photo_ids'] ?? [])),
                'ask_delivery_details' => $this->toolAskDeliveryDetails($conversation),
                'send_payment_details' => $this->toolSendPaymentDetails($conversation),
                'send_iban_details' => $this->toolSendIbanDetails($conversation, $input),
                'escalate_to_manager' => $this->toolEscalateToManager($conversation),
                'complete_order' => $this->toolCompleteOrder($conversation, $input),
                default => ['помилка' => 'Невідомий інструмент'],
            };
        } catch (\Throwable $e) {
            Log::warning('AI tool failed', ['tool' => $name, 'error' => $e->getMessage()]);
            return ['помилка' => 'Не вдалося виконати інструмент'];
        }
    }

    /** Надіслати клієнту форму-шаблон для збору даних доставки. */
    public function toolAskDeliveryDetails(InboxConversation $conversation): array
    {
        $ok = $this->sendBotMessage($conversation, OrderTexts::all()['delivery_template']);

        return $ok
            ? ['готово' => 'Форму даних доставки надіслано клієнту. Дочекайся ПІБ, телефон і адресу, потім спитай спосіб оплати. Не друкуй цю форму ще раз.']
            : ['помилка' => 'Не вдалося надіслати форму'];
    }

    /** Надіслати реквізити оплати на карту: текст + номер картки ОКРЕМИМ повідомленням. */
    public function toolSendPaymentDetails(InboxConversation $conversation): array
    {
        $t = OrderTexts::all();

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

    /** Клієнт хоче оплату за рахунком ФОП → бот сам шле рахунок (із сумою) + IBAN окремим повідомленням. */
    public function toolSendIbanDetails(InboxConversation $conversation, array $input): array
    {
        $t = OrderTexts::all();
        $amount = max(0, (int) round((float) ($input['amount'] ?? 0)));

        if ($amount <= 0) {
            return ['помилка' => 'Передай суму замовлення (amount) у грн — без неї рахунок не сформувати.'];
        }

        // Захист від повтору: якщо IBAN уже надсилали в цій розмові — не дублюємо.
        if ($conversation->messages()->where('direction', 'out')->where('text', $t['iban_number'])->exists()) {
            return ['готово' => 'Рахунок ФОП цьому клієнту вже надіслано раніше — не дублюй. Чекай квитанцію/скрін оплати, потім зафіксуй замовлення через complete_order (payment: «на рахунок ФОП»).'];
        }

        $ok1 = $this->sendBotMessage($conversation, sprintf($t['iban_intro'], $amount));
        $ok2 = $ok1 && $this->sendBotMessage($conversation, $t['iban_number']);

        if (!$ok1 || !$ok2) {
            return ['помилка' => 'Не вдалося надіслати рахунок ФОП'];
        }

        return ['готово' => 'Рахунок ФОП надіслано двома повідомленнями (реквізити з сумою + IBAN окремо). НЕ друкуй IBAN сам. Чекай квитанцію/скрін оплати, потім зафіксуй замовлення через complete_order (payment: «на рахунок ФОП»).'];
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

    /**
     * Зацікавлений клієнт → статус «В роботі», щоб лід не загубився.
     * Тригер — бот показав фото / уточнив деталі / почав збір доставки.
     * Піднімаємо ЛИШЕ з дефолтного «Новий» (або без статусу): не перетираємо
     * ручний статус оператора і вже виставлені ai_order / iban_needed.
     */
    public function maybeMarkInProgress(InboxConversation $conversation, array $toolsCalled): void
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
}
