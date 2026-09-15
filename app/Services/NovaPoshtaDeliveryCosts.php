<?php

namespace App\Services;

use App\Models\OrderDelivery;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NovaPoshtaDeliveryCosts
{
    public function store(OrderDelivery $delivery, array $row, CarbonInterface $checkedAt): bool
    {
        $number = (string) ($row['Number'] ?? $row['IntDocNumber'] ?? $row['DocumentNumber'] ?? '');
        if ($delivery->carrier !== 'nova_poshta' || $number === '' || $number !== trim((string) $delivery->ttn)) {
            return false;
        }

        $status = (string) ($row['StatusCode'] ?? '');
        if (! ctype_digit($status) || (int) $status <= 0) {
            return false;
        }
        $unavailable = in_array((int) $status, [2, 3], true);
        $payer = strtolower((string) ($row['PayerType'] ?? ''));
        $payer = in_array($payer, ['sender', 'recipient', 'thirdperson'], true) ? $payer : null;

        // Cost — оголошена вартість товару. Для доставки використовуємо ЛИШЕ DocumentCost.
        // Умова по ТТН не дозволяє прикріпити стару відповідь до перевипущеної накладної.
        return DB::table('order_deliveries')->where('id', $delivery->id)
            ->where('carrier', 'nova_poshta')->where('ttn', $delivery->ttn)->update([
                'np_document_cost' => $unavailable ? null : $this->money($row['DocumentCost'] ?? null),
                'np_payer_type' => $unavailable ? null : $payer,
                'np_cost_ttn' => $number,
                'np_cost_status_code' => $status,
                'np_document_date' => $unavailable ? null : $this->documentDate($row['DateCreated'] ?? null),
                'np_cost_checked_at' => $checkedAt,
                'np_cost_attempted_at' => $checkedAt,
            ]) > 0;
    }

    private function money(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $value = trim((string) $value);
        if (! preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole.'.'.str_pad($fraction, 2, '0');
    }

    private function documentDate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        foreach (['d-m-Y H:i:s', 'Y-m-d H:i:s', 'd.m.Y H:i:s'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value, config('app.timezone'));
                if ($date && $date->format($format) === $value) {
                    return $date->format('Y-m-d H:i:s');
                }
            } catch (\Throwable) {
                // Неповну дату не замінюємо поточним днем синхронізації.
            }
        }

        return null;
    }
}
