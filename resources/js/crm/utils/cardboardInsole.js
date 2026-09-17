// Умовний контур для ілюстрації, не виробниче лекало й не основа собівартості.
export const insoleLength = 26;
export const insoleWidth = 10;
export const insolePath = 'M 0 5 C 0 3.1 0.9 2 3.2 2 C 6 2 8.8 3.1 11.5 2.7 C 14 2.4 16 0 20.5 0 C 24 0 26 2 26 4.6 C 26 7.7 23.2 10 20 10 C 16.5 10 13.8 8.3 11 8 C 8.6 7.7 5.8 8.3 3.3 8 C 1.1 8 0 6.9 0 5 Z';

export function insoleInRectangle(polygon, index = 0) {
  const xs = polygon.points.map(point => point[0]), ys = polygon.points.map(point => point[1]);
  const x = Math.min(...xs), y = Math.min(...ys), width = Math.max(...xs) - x, height = Math.max(...ys) - y;
  const scale = Math.min(1, (polygon.rotated ? height : width) / insoleLength, (polygon.rotated ? width : height) / insoleWidth);
  // Контур лише вписуємо в наявну заготовку: її розміри та розкладку не змінюємо.
  const left = x + (width - (polygon.rotated ? insoleWidth : insoleLength) * scale) / 2;
  const top = y + (height - (polygon.rotated ? insoleLength : insoleWidth) * scale) / 2;
  const mirrored = index % 2 === 1;
  const matrix = polygon.rotated
    ? [0, scale, mirrored ? scale : -scale, 0, left + (mirrored ? 0 : insoleWidth * scale), top]
    : [scale, 0, 0, mirrored ? -scale : scale, left, top + (mirrored ? insoleWidth * scale : 0)];
  return { transform: `matrix(${matrix.join(' ')})`, matrix, scale, mirrored };
}
