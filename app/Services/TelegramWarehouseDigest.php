<?php

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;

class TelegramWarehouseDigest
{
    public function messages(CarbonImmutable $now): array
    {
        $today = $now->setTimezone('Europe/Kyiv')->startOfDay();
        // День прибуття — перший. Межі переводимо в часовий пояс зберігання БД.
        $start = $today->subDays(6)->setTimezone(config('app.timezone'));
        $end = $today->subDays(3)->setTimezone(config('app.timezone'));
        $orders = Order::query()
            ->whereNotIn('status', ['completed', 'done', 'returned', 'cancelled', 'canceled', 'delivered_paid'])
            ->whereHas('delivery', fn ($q) => $q->where('carrier', 'nova_poshta')
                ->where('delivery_status_code', 'at_warehouse')->whereNotNull('ttn')->where('ttn', '!=', '')
                ->where('last_tracked_at', '>=', $now->subMinutes(30)->setTimezone(config('app.timezone')))
                ->whereHas('activeWarehouseStatus', fn ($h) => $h->where('entered_at', '>=', $start)->where('entered_at', '<', $end)))
            ->with(['customer', 'items', 'delivery.activeWarehouseStatus'])->get()
            ->sortBy(fn ($order) => $order->delivery->activeWarehouseStatus->entered_at->timestamp);

        if ($orders->isEmpty()) {
            return [];
        }
        $header = '📞 <b>Передзвонити клієнтам · '.$today->format('d.m.Y')."</b>\nЗамовлень: ".$orders->count();
        $parts = [];
        $body = $header;
        foreach ($orders as $order) {
            $entered = CarbonImmutable::instance($order->delivery->activeWarehouseStatus->entered_at)->setTimezone('Europe/Kyiv')->startOfDay();
            $day = (int) $entered->diffInDays($today) + 1;
            $name = $order->customer?->full_name ?: $order->delivery->recipient_name ?: 'Клієнт';
            $phone = $order->customer?->phone ?: $order->delivery->recipient_phone;
            $phone = preg_replace('/\D/', '', $phone ?? '');
            if (strlen($phone) === 10 && str_starts_with($phone, '0')) {
                $phone = '38'.$phone;
            }
            $items = $order->items->map(fn ($item) => implode(', ', array_filter([$item->product_title ?: 'Товар', $item->color, $item->size])).' — '.$item->qty.' шт.')->implode("\n");
            $url = route('orders.show', $order->id);
            $block = '<b>№'.$this->escape($order->order_number ?: $order->id, 60).' · '.$this->escape($name, 120)."</b>\n"
                .($day >= 6 ? '🔴' : '🟡')." <b>{$day}-й день зберігання</b>\n"
                .'📞 '.($phone ? '+'.$phone : 'Не вказано')."\n"
                .'📦 ТТН: '.$this->escape($order->delivery->ttn, 40)."\n"
                .'🛍 '.$this->escape($items ?: 'Товари не вказані', 900)."\n"
                .'<a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">Замовлення ↗</a>';
            // Розділяємо лише між замовленнями, з запасом до ліміту Telegram.
            $separator = "\n\n━━━━━━━━━━━━━━\n\n";
            if (mb_strlen($body.$separator.$block) > 3500) {
                $parts[] = $body;
                $body = $header;
            }
            $body .= $separator.$block;
        }
        $parts[] = $body;
        $total = count($parts);
        return array_map(fn ($part, $index) => $total > 1 ? 'Частина '.($index + 1).'/'.$total."\n".$part : $part, $parts, array_keys($parts));
    }

    private function escape(string|int $value, int $limit): string
    {
        // Екрануємо назви товарів та клієнтів перед HTML Telegram.
        $text = mb_strimwidth((string) $value, 0, $limit, '…');
        while (strlen(mb_convert_encoding(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), 'UTF-16LE', 'UTF-8')) / 2 > $limit) {
            $text = mb_substr($text, 0, max(0, mb_strlen($text) - 10));
        }
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
