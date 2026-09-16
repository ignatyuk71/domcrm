<?php

namespace App\Services\Costs;

class SoleCostCalculator
{
    public const FIELDS = [
        'goods_cny' => 2, 'china_shipping_cny' => 2, 'commission_percent' => 2,
        'international_shipping_usd' => 2, 'ukraine_shipping_uah' => 2,
        'other_costs_uah' => 2, 'cny_rate' => 4, 'usd_rate' => 4,
    ];

    public function normalize(array $data): array
    {
        $inputs = [];
        foreach (self::FIELDS as $field => $precision) {
            $inputs[$field] = number_format((float) $data[$field], $precision, '.', '');
        }

        return $inputs;
    }

    public function calculate(int $quantity, array $inputs): array
    {
        $units = [];
        foreach (self::FIELDS as $field => $precision) {
            $units[$field] = $this->minor($inputs[$field], $precision);
        }
        // Рахуємо в цілих копійках; комісія на товар і китайську доставку округлюється до феня.
        $commission = $this->divide(($units['goods_cny'] + $units['china_shipping_cny']) * $units['commission_percent'], 10000);
        $rows = [
            ['key' => 'goods', 'label' => 'Підошва', 'minor' => $this->divide($units['goods_cny'] * $units['cny_rate'], 10000)],
            ['key' => 'china_shipping', 'label' => 'Доставка по Китаю', 'minor' => $this->divide($units['china_shipping_cny'] * $units['cny_rate'], 10000)],
            ['key' => 'commission', 'label' => 'Комісія за викуп', 'minor' => $this->divide($commission * $units['cny_rate'], 10000)],
            ['key' => 'international_shipping', 'label' => 'Доставка в Україну з митним оформленням', 'minor' => $this->divide($units['international_shipping_usd'] * $units['usd_rate'], 10000)],
            ['key' => 'ukraine_shipping', 'label' => 'Доставка по Україні', 'minor' => $units['ukraine_shipping_uah']],
            ['key' => 'other_costs', 'label' => 'Інші витрати', 'minor' => $units['other_costs_uah']],
        ];
        $total = array_sum(array_column($rows, 'minor'));

        return [
            'commission_cny' => $commission / 100,
            'total_cny' => ($units['goods_cny'] + $units['china_shipping_cny'] + $commission) / 100,
            'total_uah' => $total / 100,
            'unit_cost_uah' => round($total / 100 / $quantity, 6),
            'breakdown' => array_map(fn ($row) => ['key' => $row['key'], 'label' => $row['label'], 'total_uah' => $row['minor'] / 100, 'unit_uah' => round($row['minor'] / 100 / $quantity, 6)], $rows),
        ];
    }

    private function minor(string $value, int $precision): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * (10 ** $precision) + (int) str_pad($fraction, $precision, '0');
    }

    private function divide(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
