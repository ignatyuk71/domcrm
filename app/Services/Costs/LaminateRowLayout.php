<?php

namespace App\Services\Costs;

class LaminateRowLayout
{
    public function calculate(float $lengthCm, float $widthCm, float $topCm, float $bottomCm, float $heightCm): array
    {
        // Основні ряди зберігаємо; поперечні додаємо лише у неперетинні прямокутні залишки.
        $scale = 1000000;
        [$length, $width, $top, $bottom, $height] = array_map(fn ($value) => (int) round($value * $scale), [$lengthCm, $widthCm, $topCm, $bottomCm, $heightCm]);
        $maxBase = max($top, $bottom);
        $zone = function ($key, $x, $y, $w, $h, $rotated) use ($scale, $top, $bottom, $height, $maxBase) {
            $span = $rotated ? $h : $w;
            $across = $rotated ? $w : $h;
            $columns = $span < $maxBase ? 0 : 1 + intdiv(2 * ($span - $maxBase), $top + $bottom);
            $rows = intdiv($across, $height);

            return ['key' => $key, 'x_cm' => $x / $scale, 'y_cm' => $y / $scale, 'width_cm' => $w / $scale, 'height_cm' => $h / $scale,
                'rotated' => $rotated, 'pieces_per_row' => $columns, 'rows' => $rows, 'pieces' => $columns * $rows];
        };
        $main = $zone('main', 0, 0, $length, $width, false);
        $usedLength = $main['pieces'] ? $maxBase + intdiv(($main['pieces_per_row'] - 1) * ($top + $bottom), 2) : 0;
        $usedWidth = $main['pieces'] ? $main['rows'] * $height : 0;
        $main['width_cm'] = $usedLength / $scale;
        $main['height_cm'] = $usedWidth / $scale;
        // Кут залишку належить лише одній смузі. Обираємо кращий із двох поділів, не глобальний оптимум.
        $rightFirst = [$zone('right', $usedLength, 0, $length - $usedLength, $width, true), $zone('bottom', 0, $usedWidth, $usedLength, $width - $usedWidth, true)];
        $bottomFirst = [$zone('right', $usedLength, 0, $length - $usedLength, $usedWidth, true), $zone('bottom', 0, $usedWidth, $length, $width - $usedWidth, true)];
        $count = fn ($zones) => array_sum(array_column($zones, 'pieces'));
        $extras = $count($bottomFirst) > $count($rightFirst) ? $bottomFirst : $rightFirst;
        $rotated = $count($extras);
        $pieces = $main['pieces'] + $rotated;
        $pairs = intdiv($pieces, 2);
        $area = $lengthCm * $widthCm / 10000;
        $pieceArea = ($topCm + $bottomCm) * $heightCm / 20000;

        return [
            'method' => 'rows_with_rotated_offcuts_v1', 'fabric_length_cm' => $lengthCm, 'cut_length_cm' => $lengthCm,
            'pieces_per_row' => $main['pieces_per_row'], 'rows_per_cut' => $main['rows'], 'pieces_per_cut' => $pieces,
            'primary_pieces' => $main['pieces'], 'rotated_pieces' => $rotated,
            'zones' => array_values(array_filter([$main, ...$extras], fn ($row) => $row['pieces'] > 0)),
            'total_pieces' => $pieces, 'pairs' => $pairs, 'unpaired_pieces' => $pieces % 2,
            'offcut_area_m2' => round(max(0, $area - $pieces * $pieceArea), 8),
            'offcut_percent' => round(max(0, 100 * (1 - $pieces * $pieceArea / $area)), 4),
            'consumed_pair_area_m2' => $pairs ? $area / $pairs : null,
        ];
    }
}
