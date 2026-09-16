<?php

namespace App\Services\Costs;

class TapeCostCalculator
{
    public const FIELDS = ['length_m' => 4, 'goods_uah' => 2, 'shipping_uah' => 2, 'per_slipper_cm' => 2, 'allowance_cm' => 2];

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
        // 0,0001 м = 0,01 см: спільні цілі одиниці для точної витрати й залишку.
        $length = (int) round((float) $inputs['length_m'] * 10000);
        $slipper = (int) round((float) $inputs['per_slipper_cm'] * 100) + (int) round((float) $inputs['allowance_cm'] * 100);
        $pair = 2 * $slipper;
        $goods = (int) round((float) $inputs['goods_uah'] * 100);
        $shipping = (int) round((float) ($inputs['shipping_uah'] ?? 0) * 100);
        $total = ($goods + $shipping) / 100;
        $metreCost = $total * 10000 / $length;

        return [
            'total_uah' => $total, 'metre_cost_uah' => round($metreCost, 6),
            // Ціну метра не округлюємо перед множенням на витрату.
            'unit_cost_uah' => round($total * $pair / $length, 6), 'slipper_cost_uah' => round($total * $slipper / $length, 6),
            'slipper_length_cm' => $slipper / 100, 'pair_length_m' => $pair / 10000,
            'whole_pairs' => intdiv($length, $pair), 'remaining_length_m' => ($length % $pair) / 10000,
            'shipping_included' => $inputs['shipping_uah'] !== null,
            'breakdown' => [
                ['key' => 'goods', 'label' => 'Стрічка з комісією за викуп', 'total_uah' => $goods / 100, 'unit_uah' => round($goods / 100 * $pair / $length, 6)],
                ['key' => 'shipping', 'label' => 'Доставка всієї партії', 'total_uah' => $inputs['shipping_uah'] === null ? null : $shipping / 100, 'unit_uah' => $inputs['shipping_uah'] === null ? null : round($shipping / 100 * $pair / $length, 6)],
            ],
        ];
    }
}
