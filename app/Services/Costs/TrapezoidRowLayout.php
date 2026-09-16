<?php

namespace App\Services\Costs;

class TrapezoidRowLayout
{
    public function calculate(float $lengthCm, float $widthCm, float $cutLengthCm, float $topCm, float $bottomCm, float $heightCm): array
    {
        // Цілі мікроодиниці сантиметра зберігають точність навіть після переведення ярдів.
        $scale = 1000000;
        [$length, $width, $cut, $top, $bottom, $height] = array_map(fn ($value) => (int) round($value * $scale), [$lengthCm, $widthCm, $cutLengthCm, $topCm, $bottomCm, $heightCm]);
        $maxBase = max($top, $bottom);
        // Перша деталь займає більшу основу, кожна перевернута сусідня додає півсуми основ.
        $perRow = fn ($span) => $span < $maxBase ? 0 : 1 + intdiv(2 * ($span - $maxBase), $top + $bottom);
        $rows = intdiv($width, $height);
        $fullCuts = intdiv($length, $cut);
        $remainder = $length % $cut;
        $piecesPerRow = $perRow($cut);
        $remainderPerRow = $perRow($remainder);
        $pieces = ($fullCuts * $piecesPerRow + $remainderPerRow) * $rows;
        $pairs = intdiv($pieces, 2);
        $area = $lengthCm * $widthCm / 10000;
        $pieceArea = ($topCm + $bottomCm) * $heightCm / 20000;

        return [
            'method' => 'alternating_rows_v1', 'fabric_length_cm' => $lengthCm, 'cut_length_cm' => $cutLengthCm,
            'pieces_per_row' => $piecesPerRow, 'rows_per_cut' => $rows, 'pieces_per_cut' => $piecesPerRow * $rows,
            'full_cuts' => $fullCuts, 'remainder_length_cm' => $remainder / $scale,
            'remainder_pieces_per_row' => $remainderPerRow, 'remainder_pieces' => $remainderPerRow * $rows,
            'total_pieces' => $pieces, 'pairs' => $pairs, 'unpaired_pieces' => $pieces % 2,
            'offcut_area_m2' => round(max(0, $area - $pieces * $pieceArea), 8),
            'offcut_percent' => round(max(0, 100 * (1 - $pieces * $pieceArea / $area)), 4),
            'consumed_pair_area_m2' => $pairs ? $area / $pairs : null,
        ];
    }
}
