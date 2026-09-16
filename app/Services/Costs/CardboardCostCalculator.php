<?php

namespace App\Services\Costs;

class CardboardCostCalculator
{
    public const FIELDS = ['goods_uah', 'shipping_uah', 'sheet_length_cm', 'sheet_width_cm', 'blank_length_cm', 'blank_width_cm'];

    public function normalize(array $data): array
    {
        $inputs = [];
        foreach (self::FIELDS as $field) {
            $inputs[$field] = $field === 'shipping_uah' && ($data[$field] ?? null) === null
                ? null : number_format((float) $data[$field], 2, '.', '');
        }

        return $inputs;
    }

    public function calculate(int $quantity, array $inputs): array
    {
        // Суми складаємо в копійках; проміжні ціни площі не округлюємо.
        $total = ((int) round((float) $inputs['goods_uah'] * 100) + (int) round((float) ($inputs['shipping_uah'] ?? 0) * 100)) / 100;
        $sheetArea = (float) $inputs['sheet_length_cm'] * (float) $inputs['sheet_width_cm'] / 10000;
        $blankArea = (float) $inputs['blank_length_cm'] * (float) $inputs['blank_width_cm'] / 10000;
        $squareMetreCost = $total / $quantity / $sheetArea;

        return [
            'total_uah' => $total,
            'sheet_area_m2' => $sheetArea, 'blank_area_m2' => $blankArea, 'pair_area_m2' => $blankArea * 2,
            'total_area_m2' => $sheetArea * $quantity,
            'sheet_cost_uah' => round($total / $quantity, 6),
            'square_metre_cost_uah' => round($squareMetreCost, 6),
            'unit_cost_uah' => round($squareMetreCost * $blankArea * 2, 6),
            'shipping_included' => $inputs['shipping_uah'] !== null,
        ];
    }
}
