<?php

namespace App\Services\Costs;

class FoamCostCalculator
{
    public const FIELDS = [
        'sheet_price_usd' => 2, 'usd_rate' => 4, 'shipping_uah' => 2,
        'sheet_length_cm' => 2, 'sheet_width_cm' => 2, 'blank_length_cm' => 2, 'blank_width_cm' => 2,
    ];

    public function __construct(private CardboardCostCalculator $sheetCalculator) {}

    public function normalize(array $data): array
    {
        $inputs = [];
        foreach (self::FIELDS as $field => $precision) {
            $inputs[$field] = $field === 'shipping_uah' && ($data[$field] ?? null) === null
                ? null : number_format((float) $data[$field], $precision, '.', '');
        }

        return $inputs;
    }

    public function calculate(int $quantity, array $inputs): array
    {
        $priceCents = (int) round((float) $inputs['sheet_price_usd'] * 100);
        $rateUnits = (int) round((float) $inputs['usd_rate'] * 10000);
        $sheetUah = intdiv($priceCents * $rateUnits + 5000, 10000) / 100;

        // Це розцінка одного листа, не вигадана закупівля чи складське надходження.
        // Нова розкладка картону не змінює погоджений розрахунок вставки за площею.
        return $this->sheetCalculator->calculateByArea(1, $inputs + ['goods_uah' => number_format($sheetUah, 2, '.', '')])
            + ['purchase_sheet_uah' => $sheetUah];
    }
}
