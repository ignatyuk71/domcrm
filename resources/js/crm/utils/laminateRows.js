// Та сама геометрія, що й LaminateRowLayout: основні ряди та поперечні деталі у залишках.
export function laminateRows(lengthCm, widthCm, topCm, bottomCm, heightCm) {
  const scale = 1000000;
  const [length, width, top, bottom, height] = [lengthCm, widthCm, topCm, bottomCm, heightCm].map(value => Math.round(value * scale));
  if ([length, width, top, bottom, height].some(value => !Number.isSafeInteger(value) || value <= 0)) return null;
  const maxBase = Math.max(top, bottom);
  const zone = (key, x, y, w, h, rotated) => {
    const span = rotated ? h : w, across = rotated ? w : h;
    const columns = span < maxBase ? 0 : 1 + Math.floor(2 * (span - maxBase) / (top + bottom));
    const rows = Math.floor(across / height);
    return { key, x_cm: x / scale, y_cm: y / scale, width_cm: w / scale, height_cm: h / scale,
      rotated, pieces_per_row: columns, rows, pieces: columns * rows };
  };
  const main = zone('main', 0, 0, length, width, false);
  const usedLength = main.pieces ? maxBase + Math.floor((main.pieces_per_row - 1) * (top + bottom) / 2) : 0;
  const usedWidth = main.pieces ? main.rows * height : 0;
  main.width_cm = usedLength / scale;
  main.height_cm = usedWidth / scale;
  // Спільний кут не рахуємо двічі; вибираємо один із двох неперетинних поділів залишку.
  const rightFirst = [zone('right', usedLength, 0, length - usedLength, width, true), zone('bottom', 0, usedWidth, usedLength, width - usedWidth, true)];
  const bottomFirst = [zone('right', usedLength, 0, length - usedLength, usedWidth, true), zone('bottom', 0, usedWidth, length, width - usedWidth, true)];
  const count = zones => zones.reduce((sum, row) => sum + row.pieces, 0);
  const extras = count(bottomFirst) > count(rightFirst) ? bottomFirst : rightFirst;
  const rotated = count(extras), pieces = main.pieces + rotated, pairs = Math.floor(pieces / 2);
  const area = lengthCm * widthCm / 10000, pieceArea = (topCm + bottomCm) * heightCm / 20000;
  return {
    method: 'rows_with_rotated_offcuts_v1', fabric_length_cm: lengthCm, cut_length_cm: lengthCm,
    pieces_per_row: main.pieces_per_row, rows_per_cut: main.rows, pieces_per_cut: pieces,
    primary_pieces: main.pieces, rotated_pieces: rotated, zones: [main, ...extras].filter(row => row.pieces > 0),
    total_pieces: pieces, pairs, unpaired_pieces: pieces % 2,
    offcut_area_m2: Math.round(Math.max(0, area - pieces * pieceArea) * 1e8) / 1e8,
    offcut_percent: Math.round(Math.max(0, 100 * (1 - pieces * pieceArea / area)) * 1e4) / 1e4,
    consumed_pair_area_m2: pairs ? area / pairs : null,
  };
}

export function laminatePolygons(layout, top, bottom, height, limit = 24) {
  if (!layout?.pairs) return [];
  const wide = Math.max(top, bottom), narrow = Math.min(top, bottom), inset = (wide - narrow) / 2, step = (wide + narrow) / 2;
  const result = [];
  // Ліміт діє для кожної з трьох зон; навіть великі розкрої не створюють необмежений SVG.
  for (const zone of layout.zones) {
    for (let row = 0; row < Math.min(zone.rows, limit); row++) {
      for (let column = 0; column < Math.min(zone.pieces_per_row, limit); column++) {
        const x = column * step, y = row * height, flipped = column % 2 === 1 && wide !== narrow;
        const local = flipped
          ? [[x + inset, y], [x + inset + narrow, y], [x + wide, y + height], [x, y + height]]
          : [[x, y], [x + wide, y], [x + inset + narrow, y + height], [x + inset, y + height]];
        const points = local.map(([px, py]) => zone.rotated
          ? [zone.x_cm + zone.rows * height - py, zone.y_cm + px]
          : [zone.x_cm + px, zone.y_cm + py]);
        result.push({ zone: zone.key, rotated: zone.rotated, flipped, points });
      }
    }
  }
  return result;
}
