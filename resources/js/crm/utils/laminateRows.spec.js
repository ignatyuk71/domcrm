import { describe, expect, it } from 'vitest';
import fixtures from '../../../../tests/Fixtures/laminate-rows.json';
import { laminateRows, laminatePolygons } from './laminateRows';

// Перевіряємо перетин опуклих деталей; дотик меж допустимий.
function overlaps(a, b) {
  for (const polygon of [a, b]) {
    for (let i = 0; i < polygon.length; i++) {
      const p = polygon[i], q = polygon[(i + 1) % polygon.length], axis = [p[1] - q[1], q[0] - p[0]];
      const projections = [a, b].map(points => points.map(([x, y]) => x * axis[0] + y * axis[1]));
      if (Math.min(Math.max(...projections[0]), Math.max(...projections[1])) - Math.max(Math.min(...projections[0]), Math.min(...projections[1])) <= 1e-7) return false;
    }
  }
  return true;
}

describe('Розкрій полотна із поперечними деталями у залишках', () => {
  it.each(fixtures)('$name', ({ input, expected }) => {
    const result = laminateRows(...input);
    expect(result).toMatchObject(expected);
    expect(result.zones.reduce((sum, zone) => sum + zone.pieces, 0)).toBe(result.total_pieces);
    expect(result.offcut_area_m2).toBeGreaterThanOrEqual(0);
    expect(result.offcut_percent).toBeLessThanOrEqual(100);
    const polygons = laminatePolygons(result, ...input.slice(2), 200);
    if (result.pairs && result.total_pieces <= 200) {
      expect(polygons).toHaveLength(result.total_pieces);
      expect(polygons.filter(polygon => polygon.rotated)).toHaveLength(result.rotated_pieces);
      for (const [i, polygon] of polygons.entries()) {
        for (const [x, y] of polygon.points) {
          expect(x).toBeGreaterThanOrEqual(-1e-7); expect(x).toBeLessThanOrEqual(input[0] + 1e-7);
          expect(y).toBeGreaterThanOrEqual(-1e-7); expect(y).toBeLessThanOrEqual(input[1] + 1e-7);
        }
        const area = Math.abs(polygon.points.reduce((sum, p, j, points) => { const q = points[(j + 1) % points.length]; return sum + p[0] * q[1] - q[0] * p[1]; }, 0)) / 2;
        expect(area).toBeCloseTo((input[2] + input[3]) * input[4] / 2, 6);
        for (const other of polygons.slice(i + 1)) expect(overlaps(polygon.points, other.points)).toBe(false);
      }
    }
  });
  it('обмежує SVG, але не математичний вихід', () => {
    const result = laminateRows(100, 1000, 0.01, 0.01, 0.01);
    expect(result.total_pieces).toBe(1000000000);
    expect(laminatePolygons(result, 0.01, 0.01, 0.01)).toHaveLength(576);
  });
  it.each([0, -1, NaN, Infinity])('не обчислює некоректний розмір %s', dimension => {
    expect(laminateRows(100, 150, 20, 13, dimension)).toBeNull();
    expect(laminateRows(100, dimension, 20, 13, 7)).toBeNull();
  });
});
