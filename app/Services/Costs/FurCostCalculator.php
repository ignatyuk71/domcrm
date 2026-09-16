<?php

namespace App\Services\Costs;

class FurCostCalculator
{
    public const GEOMETRY = ['fabric_length' => 4, 'fabric_width_cm' => 2, 'top_width_cm' => 2, 'bottom_width_cm' => 2, 'height_cm' => 2];

    public const LOCAL_FIELDS = ['goods_uah', 'ukraine_shipping_uah', 'other_costs_uah'];

    public function __construct(private SoleCostCalculator $purchaseCalculator) {}

    public function normalize(array $data): array
    {
        // Старі записи без джерела залишаються китайськими закупівлями.
        $source = $data['purchase_source'] ?? 'china';
        $inputs = $source === 'ukraine'
            ? array_combine(self::LOCAL_FIELDS, array_map(fn ($field) => number_format((float) ($data[$field] ?? 0), 2, '.', ''), self::LOCAL_FIELDS))
            : $this->purchaseCalculator->normalize(array_replace($data, ['ukraine_shipping_uah' => $data['ukraine_shipping_uah'] ?? 0]));
        $inputs['purchase_source'] = $source;
        $inputs['ukraine_shipping_uah'] = ($data['ukraine_shipping_uah'] ?? null) === null ? null : $inputs['ukraine_shipping_uah'];
        foreach (self::GEOMETRY as $field => $precision) {
            $inputs[$field] = number_format((float) $data[$field], $precision, '.', '');
        }
        $inputs['length_unit'] = $data['length_unit'];

        return $inputs;
    }

    public function calculate(int $quantity, array $inputs): array
    {
        if (($inputs['purchase_source'] ?? 'china') === 'ukraine') {
            $result = $this->localPurchase($inputs);
        } else {
            $purchase = $this->purchaseCalculator->normalize(array_replace($inputs, ['ukraine_shipping_uah' => $inputs['ukraine_shipping_uah'] ?? 0]));
            $result = $this->purchaseCalculator->calculate(1, $purchase);
        }
        // Ярд — рівно 0,9144 м. Площа двох щільно розкладених трапецій, не двох прямокутників.
        $lengthMetres = (float) $inputs['fabric_length'] * ($inputs['length_unit'] === 'yard' ? 0.9144 : 1);
        $area = $lengthMetres * (float) $inputs['fabric_width_cm'] / 100;
        $pairArea = ((float) $inputs['top_width_cm'] + (float) $inputs['bottom_width_cm']) * (float) $inputs['height_cm'] / 10000;
        $share = $pairArea / $area;
        $result['unit_cost_uah'] = round($result['total_uah'] * $share, 6);
        $result['breakdown'] = array_map(function ($row) use ($share) {
            return array_replace($row, ['label' => $row['key'] === 'goods' ? 'Хутро' : $row['label'], 'unit_uah' => round($row['total_uah'] * $share, 6)]);
        }, $result['breakdown']);

        return $result + [
            'length_metres' => $lengthMetres, 'total_area_m2' => $area, 'pair_area_m2' => $pairArea,
            'linear_metre_cost_uah' => round($result['total_uah'] / $lengthMetres, 6),
            'square_metre_cost_uah' => round($result['total_uah'] / $area, 6),
            'ukraine_shipping_included' => $inputs['ukraine_shipping_uah'] !== null,
        ];
    }

    private function localPurchase(array $inputs): array
    {
        $rows = [];
        foreach (['goods_uah' => ['goods', 'Хутро'], 'ukraine_shipping_uah' => ['ukraine_shipping', 'Доставка по Україні'], 'other_costs_uah' => ['other_costs', 'Інші витрати']] as $field => [$key, $label]) {
            // Суми нормалізовані до двох знаків; складаємо цілі копійки.
            $minor = (int) str_replace('.', '', $inputs[$field] ?? '0.00');
            $rows[] = ['key' => $key, 'label' => $label, 'minor' => $minor];
        }

        return [
            'total_uah' => array_sum(array_column($rows, 'minor')) / 100,
            'breakdown' => array_map(fn ($row) => ['key' => $row['key'], 'label' => $row['label'], 'total_uah' => $row['minor'] / 100], $rows),
        ];
    }
}
