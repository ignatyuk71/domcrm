<?php

namespace App\Services\Ai\Gpt;

/** Текст клієнтові складає сервер: модель обирає намір і товари, але не ціни. */
class CommentReply
{
    public function render(array $decision, array $catalog, string $commentText): array
    {
        $intent = $decision['intent'] ?? 'other';
        if ($intent === 'praise') return $this->result('', null, false, true);
        if ($intent === 'complaint') {
            return $this->result('Доброго дня. Дякуємо, що повідомили про проблему. Ваше звернення потребує уваги менеджера — допоможемо розібратися.', null, true);
        }

        $items = $this->selected($decision, $catalog);
        $group = $this->groupName($items);
        $quote = $intent === 'order' && (($decision['has_color'] ?? false) === true || ($decision['has_size'] ?? false) === true)
            ? $this->quote($commentText) : '';
        $hello = 'Доброго дня! ';
        if ($intent === 'delivery') {
            $next = $items ? 'Підкажіть, що саме хочете уточнити про доставку?' : $this->clarification();

            return $this->result($hello.'Доставляємо Новою Поштою. '.$next, $group);
        }
        if (! $items) return $this->result($hello.$quote.$this->clarification());

        $available = array_values(array_filter($items, fn ($item) => ($item['available'] ?? false) === true));
        $subject = count($items) === 1 ? '«'.$this->plain($items[0]['title'] ?? '', 140).'»' : 'Моделі з допису';
        if (in_array($intent, ['price', 'order', 'availability', 'size'], true) && ! $available) {
            return $this->result($hello.$subject.' зараз немає в наявності. Показати доступні альтернативи?', $group);
        }

        if ($intent === 'price' || $intent === 'order') {
            $price = $this->price($available);
            if ($price === null) {
                return $this->result($hello.$subject.': ціну потрібно уточнити у менеджера. Підкажіть, яка модель або колір вас цікавить?', $group, true);
            }
            $label = count($available) < count($items) ? 'Доступні моделі з допису' : $subject;
            $text = $hello.$label.' — '.$price.'. ';
            if (count($available) < count($items)) $text .= 'Частини обраних моделей зараз немає в наявності. ';
            if ($intent === 'order') {
                $text .= 'Допоможемо оформити замовлення. '.$quote;
                // Неповний вибір спочатку погоджуємо, дані доставки ще не збираємо.
                $text .= count($available) < count($items)
                    ? 'Підкажіть, чи оформлюємо лише доступні моделі, чи підберемо заміну?'
                    : $this->nextSelection($decision, true);
            } else {
                $text .= $this->nextSelection($decision, false);
            }

            return $this->result(trim($text), $group);
        }

        if ($intent === 'availability') {
            $names = count($available) === count($items) ? $subject
                : implode(', ', array_map(fn ($item) => '«'.$this->plain($item['title'] ?? '', 100).'»', $available));
            $text = $hello.$names.' є в наявності. ';
            if (count($available) < count($items)) $text .= 'Решти обраних моделей зараз немає. ';

            return $this->result($text.$this->nextSelection($decision, false), $group);
        }

        if ($intent === 'size') {
            $lines = [];
            foreach ($available as $item) {
                $sizes = array_values(array_filter($item['sizes'] ?? [], fn ($size) => is_string($size) && trim($size) !== ''));
                $title = '«'.$this->plain($item['title'] ?? '', 100).'»';
                $lines[] = $sizes ? $title.' — доступні розміри: '.implode(', ', $sizes).'.'
                    : $title.': у каталозі немає підтвердженої розмірної сітки.';
            }

            return $this->result($hello.implode(' ', $lines).' '.$this->nextSelection($decision, false), $group);
        }

        return $this->result($hello.'Підкажіть, будь ласка, що хочете дізнатися про '.$subject.'?', $group);
    }

    private function selected(array $decision, array $catalog): array
    {
        $ids = $decision['product_ids'] ?? [];
        if (($decision['confidence'] ?? null) !== 'clear' || ! is_array($ids) || ! $ids || count($ids) > 5
            || count(array_filter($ids, fn ($id) => is_int($id) && $id > 0)) !== count($ids)) return [];
        $indexed = [];
        foreach ($catalog as $item) if (is_array($item) && isset($item['id'])) $indexed[$item['id']] = $item;
        $items = [];
        foreach (array_unique($ids) as $id) {
            if (! isset($indexed[$id])) return [];
            $items[] = $indexed[$id];
        }

        return $items;
    }

    private function price(array $items): ?string
    {
        $prices = [];
        try {
            foreach ($items as $item) {
                $cents = Money::cents((string) ($item['price'] ?? ''));
                if ($cents <= 0) return null;
                $prices[] = $cents;
            }
        } catch (\DomainException) {
            return null;
        }
        if (! $prices) return null;
        $min = min($prices);
        $max = max($prices);

        return $min === $max ? Money::display($min).' грн' : Money::display($min).'–'.Money::display($max).' грн';
    }

    private function nextSelection(array $decision, bool $order): string
    {
        $color = ($decision['has_color'] ?? false) === true;
        $size = ($decision['has_size'] ?? false) === true;
        if (! $color && ! $size) return 'Підкажіть, будь ласка, який колір і розмір вас цікавить?';
        if (! $color) return 'Підкажіть, будь ласка, який колір вас цікавить?';
        if (! $size) return 'Підкажіть, будь ласка, який розмір вам потрібен?';

        return $order ? 'Для оформлення напишіть, будь ласка, ПІБ, телефон і відділення або поштомат Нової Пошти.'
            : 'Бажаєте оформити замовлення на цей вибір?';
    }

    private function groupName(array $items): ?string
    {
        $common = null;
        foreach ($items as $item) {
            $groups = [];
            foreach ($item['groups'] ?? [] as $group) {
                if (isset($group['id'], $group['name'])) $groups[$group['id']] = $this->plain($group['name'], 150);
            }
            $common = $common === null ? $groups : array_intersect_key($common, $groups);
        }

        return count($common ?? []) === 1 ? reset($common) : null;
    }

    private function quote(string $comment): string
    {
        $text = $this->plain($comment, 250);

        return $text !== '' ? 'Ви написали під дописом: «'.$text.'». ' : '';
    }

    private function clarification(): string
    {
        return 'Підкажіть, будь ласка, яку саме модель із допису маєте на увазі, або надішліть її фото.';
    }

    private function plain(string $text, int $limit): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));

        return mb_strlen($clean) > $limit ? mb_substr($clean, 0, $limit - 1).'…' : $clean;
    }

    private function result(string $text, ?string $group = null, bool $handoff = false, bool $skip = false): array
    {
        return ['text' => $text, 'matched_group_name' => $group, 'handoff' => $handoff, 'skip' => $skip];
    }
}
