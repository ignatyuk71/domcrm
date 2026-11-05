<?php

namespace App\Services\Ai\Gpt;

use Illuminate\Support\Facades\Validator;

/** Чернетка, суми та перевірки оформлення незалежні від текстових відповідей GPT. */
final class CheckoutSession
{
    public array $messages = [];
    public array $messageCheckpoints = [];
    public bool $completed = false;
    public bool $escalated = false;
    private array $deliveryBefore;
    private bool $paymentRequestInvalidated = false;

    public function __construct(private CheckoutPricing $pricing, public ?array $data = null, private array $receipt = [])
    {
        $this->deliveryBefore = [
            'delivery_requested' => $data['delivery_requested'] ?? null,
            'payment_request' => $data['payment_request'] ?? null,
        ];
        if (! empty($receipt['foot_length_cm'])) {
            $this->data ??= ['status' => 'draft', 'items' => []];
            $this->data['sizing'] = ['foot_length_cm' => $receipt['foot_length_cm']];
        }
    }

    /** Підготовка тексту ще не підтверджує його фактичне надсилання. */
    public function deliveryState(): array
    {
        return ['before' => $this->deliveryBefore, 'checkpoints' => $this->messageCheckpoints,
            'invalidated_payment_request' => $this->paymentRequestInvalidated];
    }

    public function run(string $name, array $args): array
    {
        try {
            if ($this->completed) {
                return ['готово' => 'Цю заявку вже завершено. Не змінюй її повторними викликами в тому самому ході.', 'order' => $this->data];
            }
            if ($name !== 'update_order' && $args !== []) {
                throw new \DomainException('Цей інструмент не приймає параметрів. Спочатку збережи товари й дані через update_order.');
            }

            return match ($name) {
                'update_order' => $this->update($args),
                'ask_delivery_details' => $this->deliveryForm(),
                'send_payment_details' => $this->paymentDetails(),
                'complete_order' => $this->complete(),
                'escalate_to_manager' => $this->escalate(),
                default => ['помилка' => 'Невідомий інструмент'],
            };
        } catch (\DomainException $e) {
            return ['помилка' => $e->getMessage(), 'order' => $this->data];
        }
    }

    private function update(array $args): array
    {
        $rules = [
            'items' => 'present|nullable|array|min:1|max:5',
            'customer_name' => 'present|nullable|string|max:200', 'phone' => 'present|nullable|string|max:100',
            'address' => 'present|nullable|string|max:2000', 'payment' => 'present|nullable|in:cod,full,deposit',
            'confirm_total' => 'required|boolean',
        ];
        if (array_diff(array_keys($args), array_keys($rules)) || Validator::make($args, $rules)->fails()) {
            throw new \DomainException('Некоректні дані заявки. Невідомі дані передавай як null; спосіб оплати — cod, full або deposit.');
        }
        if ($args['phone'] !== null && ! preg_match('/^\d{9,15}$/D', preg_replace('/\D/', '', $args['phone']))) {
            throw new \DomainException('Уточни номер телефону: потрібно від 9 до 15 цифр. Решта даних збережена.');
        }
        $candidate = $this->data ?? ['status' => 'draft', 'items' => []];
        foreach (['customer_name', 'phone', 'address', 'payment'] as $field) {
            if ($args[$field] !== null) {
                $candidate[$field] = trim($args[$field]);
            }
        }
        $items = $args['items'] ?? $this->pricing->inputItems($candidate['items']);
        $quote = $this->pricing->quote($items);
        $sameSelection = $this->data && $this->selection($quote['items']) === $this->selection($this->data['items'] ?? []);
        $priceChanged = $sameSelection && $this->prices($quote['items']) !== $this->prices($this->data['items']);
        $candidate = array_merge($candidate, $quote);
        $this->calculate($candidate);
        if ($priceChanged) {
            $candidate['needs_confirmation'] = true;
            $candidate['quote_updated_turn_id'] = $this->receipt['turn_id'] ?? null;
        } elseif (($candidate['needs_confirmation'] ?? false) && $args['confirm_total']
            && isset($this->receipt['turn_id']) && $this->receipt['turn_id'] !== ($candidate['quote_updated_turn_id'] ?? null)) {
            unset($candidate['needs_confirmation'], $candidate['quote_updated_turn_id']);
        }
        if (! $sameSelection) {
            unset($candidate['needs_confirmation'], $candidate['quote_updated_turn_id']);
        }
        if (! $this->data || $this->signature($candidate) !== $this->signature($this->data)) {
            if (isset($candidate['payment_request'])) $this->paymentRequestInvalidated = true;
            unset($candidate['payment_request'], $candidate['receipt']);
        }
        $candidate['status'] = 'draft';
        unset($candidate['finished_at']);
        $this->data = $candidate;

        return ['order' => $this->data, 'missing' => $this->missing(),
            'примітка' => ($this->data['needs_confirmation'] ?? false) ? 'Ціна змінилася. Повідом нову суму й отримай підтвердження покупця наступним повідомленням.' : 'Суми й наявність перевірені сервером.'];
    }

    private function deliveryForm(): array
    {
        $this->data ??= ['status' => 'draft', 'items' => []];
        if (! empty($this->data['delivery_requested'])) {
            return ['готово' => 'Форму доставки вже надіслано. Збережи отримані дані через update_order; запитуй лише відсутнє.'];
        }
        $this->data['delivery_requested'] = true;
        $template = OrderTexts::all()['delivery_template'];
        if (isset($this->data['total']) && Money::cents($this->data['total']) <= 10000) {
            $template = str_replace(', повністю на картку/ФОП або 100 грн передоплати, решта при отриманні',
                ' або повністю на картку/ФОП', $template);
        }
        $this->messages[] = $template;
        $this->messageCheckpoints[array_key_last($this->messages)] = ['delivery_requested' => true];

        return ['готово' => 'Форму доставки надіслано. Збережи отримані дані через update_order. Запитуй лише відсутнє; надану адресу приймай без зайвих уточнень.'];
    }

    private function paymentDetails(): array
    {
        $this->refresh();
        $this->requireConfirmedTotal();
        if (! in_array($this->data['payment'] ?? null, ['full', 'deposit'], true)) {
            throw new \DomainException('Уточни спосіб оплати: повна оплата чи 100 грн передоплати. Для cod переказ не потрібний.');
        }
        $signature = $this->signature($this->data);
        $previous = $this->data['payment_request'] ?? null;
        $sameRequest = ($previous['signature'] ?? null) === $signature;
        // Старі записи без sent уже вважаються повністю доставленими.
        if ($sameRequest && ($previous['sent'] ?? true) === true) {
            return ['готово' => 'Актуальні реквізити вже надіслані. Очікуй квитанцію, не дублюй повідомлення.', 'order' => $this->data];
        }
        $texts = OrderTexts::all();
        $payNow = Money::cents($this->data['pay_now']);
        $condition = $this->data['payment'] === 'deposit'
            ? 'Передоплата — '.Money::display($payNow).' грн. Решта '.Money::display(Money::cents($this->data['pay_on_delivery'])).' грн — при отриманні.'
            : 'Повна оплата — '.Money::display($payNow).' грн.';
        $messages = [$condition."\n\n".$texts['card_intro'], $texts['card_number'],
            sprintf($texts['iban_intro'], Money::display($payNow)), $texts['iban_number']];
        $count = count($messages);
        // Незавершений некоректний курсор не може сам підтвердити доставку.
        $sent = $sameRequest ? max(0, min($count - 1, (int) ($previous['messages_sent'] ?? 0))) : 0;
        $request = ['signature' => $signature,
            'turn_id' => $sameRequest ? ($previous['turn_id'] ?? null) : ($this->receipt['turn_id'] ?? null),
            'sent' => false, 'messages_sent' => $sent, 'message_count' => $count];
        for ($index = $sent; $index < $count; $index++) {
            $this->messages[] = $messages[$index];
            $request['messages_sent'] = $index + 1;
            $request['sent'] = $index + 1 === $count;
            $this->messageCheckpoints[array_key_last($this->messages)] = ['payment_request' => $request];
        }
        $this->data['payment_request'] = $request;

        return ['готово' => 'Картку й IBAN надіслано окремими повідомленнями з точною сумою. Не дублюй реквізити; очікуй квитанцію.', 'order' => $this->data];
    }

    private function complete(): array
    {
        $this->refresh();
        $this->requireConfirmedTotal();
        if ($missing = $this->missing()) {
            return ['помилка' => 'Попроси лише відсутні дані.', 'missing' => $missing, 'order' => $this->data];
        }
        if ($this->data['payment'] !== 'cod') {
            $request = $this->data['payment_request'] ?? null;
            if (! $request || $request['signature'] !== $this->signature($this->data)) {
                throw new \DomainException('Спочатку надішли актуальні реквізити через send_payment_details й отримай квитанцію.');
            }
            if (($request['sent'] ?? true) !== true) {
                throw new \DomainException('Реквізити надіслано не повністю. Продовж надсилання через send_payment_details й отримай квитанцію.');
            }
            $savedReceipt = $this->data['receipt'] ?? null;
            $resumeCompletion = ! empty($this->data['completion_pending'])
                && ($savedReceipt['status'] ?? null) === 'provided_unverified'
                && isset($savedReceipt['turn_id']) && $savedReceipt['turn_id'] !== $request['turn_id'];
            if (! $resumeCompletion) {
                if (empty($this->receipt['has_image']) || ! isset($this->receipt['turn_id']) || $request['turn_id'] === $this->receipt['turn_id']) {
                    throw new \DomainException('Очікуй фото квитанції в новому повідомленні після надсилання реквізитів. Текстове «оплатив» не замінює квитанцію.');
                }
                $this->data['receipt'] = ['turn_id' => $this->receipt['turn_id'], 'status' => 'provided_unverified'];
            }
        }
        $this->data['status'] = 'ready_for_manager';
        unset($this->data['completion_pending']);
        $this->data['finished_at'] = now()->toIso8601String();
        $this->completed = true;

        return ['готово' => 'Заявку зібрано й показано власнику. Система додасть фінальний текст магазину.', 'order' => $this->data];
    }

    /** Повторна перевірка після останнього API-виклику, перед збереженням результату. */
    public function verifyCompletion(): void
    {
        if (! $this->completed) {
            return;
        }
        $quote = $this->pricing->quote($this->pricing->inputItems($this->data['items']));
        if ($this->prices($quote['items']) !== $this->prices($this->data['items'])) {
            throw new \DomainException('Ціна змінилася під час оформлення. Онови підсумок і отримай нове підтвердження.');
        }
    }

    private function refresh(): void
    {
        if (empty($this->data['items'])) {
            throw new \DomainException('Спочатку збережи вибрані товари через update_order.');
        }
        $quote = $this->pricing->quote($this->pricing->inputItems($this->data['items']));
        $changed = $this->prices($quote['items']) !== $this->prices($this->data['items']);
        $candidate = array_merge($this->data, $quote);
        $this->calculate($candidate);
        $this->data = $candidate;
        if ($changed) {
            if (isset($this->data['payment_request'])) $this->paymentRequestInvalidated = true;
            $this->data['needs_confirmation'] = true;
            $this->data['quote_updated_turn_id'] = $this->receipt['turn_id'] ?? null;
            $this->data['status'] = 'draft';
            unset($this->data['payment_request'], $this->data['receipt'], $this->data['finished_at']);
        }
    }

    private function calculate(array &$data): void
    {
        $total = Money::cents($data['total']);
        $payment = $data['payment'] ?? null;
        if ($payment === 'deposit' && $total <= 10000) {
            throw new \DomainException('Сума замовлення не перевищує 100 грн. Запропонуй повну оплату або оплату при отриманні.');
        }
        $now = match ($payment) { 'full' => $total, 'deposit' => 10000, default => 0 };
        $data['pay_now'] = $payment === null ? null : Money::decimal($now);
        $data['pay_on_delivery'] = $payment === null ? null : Money::decimal($total - $now);
    }

    private function requireConfirmedTotal(): void
    {
        if ($this->data['needs_confirmation'] ?? false) {
            throw new \DomainException('Ціна змінилася. Повідом нову суму й дочекайся підтвердження покупця; у наступному ході виклич update_order з confirm_total=true.');
        }
    }

    private function missing(): array
    {
        return array_values(array_filter(['customer_name', 'phone', 'address', 'payment'], fn ($field) => empty($this->data[$field])));
    }

    private function signature(array $data): string
    {
        return hash('sha256', json_encode([$data['items'] ?? [], $data['payment'] ?? null], JSON_THROW_ON_ERROR));
    }

    private function selection(array $items): array
    {
        $selection = array_map(fn ($item) => [$item['product_id'] ?? null, $item['size'] ?? null, $item['qty']], $items);
        sort($selection);

        return $selection;
    }

    private function prices(array $items): array
    {
        $prices = array_map(fn ($item) => [$item['product_id'] ?? null, $item['size'] ?? null, $item['price'] ?? null], $items);
        sort($prices);

        return $prices;
    }

    private function escalate(): array
    {
        if (empty($this->receipt['handoff_requested']) && (! empty($this->receipt['foot_length_cm']) || ! empty($this->receipt['consultation_request']))) {
            throw new \DomainException('Це звичайна консультація про вибір товару або розміру. Використай наявні факти й відомі сантиметри або постав одне уточнення; не передавай підбір менеджеру.');
        }
        $this->data = array_merge($this->data ?? [], ['status' => 'needs_human']);
        unset($this->data['paused_until']);
        $this->escalated = true;

        return ['далі' => 'Більше нічого не пиши й не вгадуй — систему вже сповіщено, менеджер передивиться чат.'];
    }
}
