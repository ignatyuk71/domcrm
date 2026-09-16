<?php

namespace App\Services\Costs;

class LaminateCostCalculator
{
    public const FIELDS = [
        'plush_price_metre_uah' => 2, 'plush_width_cm' => 2, 'plush_shipping_metre_uah' => 2,
        'web_roll_price_uah' => 2, 'web_roll_length_m' => 4, 'web_width_cm' => 2, 'web_shipping_roll_uah' => 2,
        'foam_sheet_price_usd' => 2, 'usd_rate' => 4, 'foam_sheet_length_cm' => 2, 'foam_sheet_width_cm' => 2, 'foam_shipping_sheet_uah' => 2,
        'insole_length_cm' => 2, 'insole_width_cm' => 2, 'upper_top_cm' => 2, 'upper_bottom_cm' => 2, 'upper_height_cm' => 2,
        'cut_width_cm' => 2,
    ];

    public const SHIPPING = ['plush_shipping_metre_uah', 'web_shipping_roll_uah', 'foam_shipping_sheet_uah'];

    public function __construct(private LaminateRowLayout $rowLayout) {}

    public function normalize(array $data): array
    {
        $inputs = [];
        foreach (self::FIELDS as $field => $precision) {
            $inputs[$field] = in_array($field, [...self::SHIPPING, 'cut_width_cm'], true) && ($data[$field] ?? null) === null
                ? null : number_format((float) $data[$field], $precision, '.', '');
        }

        return $inputs;
    }

    public function calculate(int $quantity, array $inputs): array
    {
        $cents = fn ($field) => (int) round((float) ($inputs[$field] ?? 0) * 100);
        // Спочатку переводимо один лист поролону у копійки; ціну м² далі не округлюємо.
        $foamCents = intdiv($cents('foam_sheet_price_usd') * (int) round((float) $inputs['usd_rate'] * 10000) + 5000, 10000);
        $layers = [
            ['key' => 'plush', 'label' => 'Плюш вельбо', 'cost' => ($cents('plush_price_metre_uah') + $cents('plush_shipping_metre_uah')) / 100, 'area' => (float) $inputs['plush_width_cm'] / 100, 'shipping' => 'plush_shipping_metre_uah'],
            ['key' => 'web', 'label' => 'Клейова павутинка', 'cost' => ($cents('web_roll_price_uah') + $cents('web_shipping_roll_uah')) / 100, 'area' => (float) $inputs['web_roll_length_m'] * (float) $inputs['web_width_cm'] / 100, 'shipping' => 'web_shipping_roll_uah'],
            ['key' => 'foam', 'label' => 'Поролон 5 мм у полотні', 'cost' => ($foamCents + $cents('foam_shipping_sheet_uah')) / 100, 'area' => (float) $inputs['foam_sheet_length_cm'] * (float) $inputs['foam_sheet_width_cm'] / 10000, 'shipping' => 'foam_shipping_sheet_uah'],
        ];
        $insoleArea = 2 * (float) $inputs['insole_length_cm'] * (float) $inputs['insole_width_cm'] / 10000;
        $upperArea = ((float) $inputs['upper_top_cm'] + (float) $inputs['upper_bottom_cm']) * (float) $inputs['upper_height_cm'] / 10000;
        $squareMetreCost = array_sum(array_map(fn ($row) => $row['cost'] / $row['area'], $layers));
        $layouts = $this->layouts($inputs);
        $metreArea = ($inputs['cut_width_cm'] ?? null) === null ? null : (float) $inputs['cut_width_cm'] / 100;
        $metreCost = $metreArea === null ? null : $squareMetreCost * $metreArea;
        $partCost = fn ($layout) => ($layout['pairs'] ?? 0) > 0 ? round($metreCost / $layout['pairs'], 6) : null;

        return [
            // У спільній таблиці total_uah — розцінка 1 м², не сума закупівлі трьох різних упаковок.
            'total_uah' => round($squareMetreCost, 2), 'square_metre_cost_uah' => round($squareMetreCost, 6),
            'method' => 'separate_metre_rows_v2', 'unit_cost_uah' => null,
            'linear_metre_cost_uah' => $metreCost === null ? null : round($metreCost, 6), 'cut_area_m2' => $metreArea,
            'insole_layout' => $layouts['insole'], 'upper_layout' => $layouts['upper'],
            'insole_pair_area_m2' => $insoleArea, 'upper_pair_area_m2' => $upperArea,
            'insole_pair_cost_uah' => $partCost($layouts['insole']), 'upper_pair_cost_uah' => $partCost($layouts['upper']),
            'foam_sheet_uah' => $foamCents / 100,
            'breakdown' => array_map(fn ($row) => [
                'key' => $row['key'], 'label' => $row['label'], 'purchase_unit_uah' => $row['cost'], 'purchase_area_m2' => $row['area'],
                'square_metre_cost_uah' => round($row['cost'] / $row['area'], 6),
                'linear_metre_cost_uah' => $metreArea === null ? null : round($row['cost'] / $row['area'] * $metreArea, 6),
                'shipping_included' => $inputs[$row['shipping']] !== null,
            ], $layers),
        ];
    }

    public function layouts(array $data): array
    {
        // Ширина склеєного полотна невідома зі самих розмірів окремих шарів.
        if (($data['cut_width_cm'] ?? null) === null) {
            return ['insole' => null, 'upper' => null];
        }
        $width = (float) $data['cut_width_cm'];

        return [
            // Обидва варіанти окремі; у залишках кожного дозволено поворот деталей на 90°.
            'insole' => $this->rowLayout->calculate(100, $width, (float) $data['insole_length_cm'], (float) $data['insole_length_cm'], (float) $data['insole_width_cm']),
            'upper' => $this->rowLayout->calculate(100, $width, (float) $data['upper_top_cm'], (float) $data['upper_bottom_cm'], (float) $data['upper_height_cm']),
        ];
    }

    public function geometryFits(array $data): bool
    {
        $sheets = [[(float) $data['web_roll_length_m'] * 100, (float) $data['web_width_cm']], [(float) $data['foam_sheet_length_cm'], (float) $data['foam_sheet_width_cm']]];
        foreach ([[(float) $data['insole_length_cm'], (float) $data['insole_width_cm']], [max((float) $data['upper_top_cm'], (float) $data['upper_bottom_cm']), (float) $data['upper_height_cm']]] as $blank) {
            sort($blank);
            if ($blank[0] > (float) $data['plush_width_cm']) {
                return false;
            }
            foreach ($sheets as $sheet) {
                sort($sheet);
                if ($blank[0] > $sheet[0] || $blank[1] > $sheet[1]) {
                    return false;
                }
            }
        }

        return true;
    }
}
