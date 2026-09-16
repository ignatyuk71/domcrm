// Та сама геометрія, що й TrapezoidRowLayout: ряди вздовж робочого відрізу, без автоматичного повороту полотна.
export function trapezoidRows(lengthCm, widthCm, cutLengthCm, topCm, bottomCm, heightCm) {
  const scale = 1000000;
  const [length, width, cut, top, bottom, height] = [lengthCm, widthCm, cutLengthCm, topCm, bottomCm, heightCm].map(value => Math.round(value * scale));
  if ([length, width, cut, top, bottom, height].some(value => !Number.isSafeInteger(value) || value <= 0)) return null;
  const maxBase = Math.max(top, bottom);
  const perRow = span => span < maxBase ? 0 : 1 + Math.floor(2 * (span - maxBase) / (top + bottom));
  const rows = Math.floor(width / height), fullCuts = Math.floor(length / cut), remainder = length % cut;
  const piecesPerRow = perRow(cut), remainderPerRow = perRow(remainder);
  const pieces = (fullCuts * piecesPerRow + remainderPerRow) * rows, pairs = Math.floor(pieces / 2);
  const area = lengthCm * widthCm / 10000, pieceArea = (topCm + bottomCm) * heightCm / 20000;
  return {
    method: 'alternating_rows_v1', fabric_length_cm: lengthCm, cut_length_cm: cutLengthCm,
    pieces_per_row: piecesPerRow, rows_per_cut: rows, pieces_per_cut: piecesPerRow * rows,
    full_cuts: fullCuts, remainder_length_cm: remainder / scale,
    remainder_pieces_per_row: remainderPerRow, remainder_pieces: remainderPerRow * rows,
    total_pieces: pieces, pairs, unpaired_pieces: pieces % 2,
    offcut_area_m2: Math.round(Math.max(0, area - pieces * pieceArea) * 1e8) / 1e8,
    offcut_percent: Math.round(Math.max(0, 100 * (1 - pieces * pieceArea / area)) * 1e4) / 1e4,
    consumed_pair_area_m2: pairs ? area / pairs : null,
  };
}
