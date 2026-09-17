import { describe, expect, it } from 'vitest';
import { insoleInRectangle, insoleLength, insoleWidth, insolePath } from './cardboardInsole';
import { laminateRows, laminatePolygons } from './laminateRows';

describe('Умовна устілка всередині заготовки', () => {
  it('має довжину 26 см і найбільшу ширину 10 см, а не ширину прямокутника', () => {
    const polygon = { rotated: false, points: [[0, 0], [26, 0], [26, 11], [0, 11]] };
    expect([insoleLength, insoleWidth]).toEqual([26, 10]);
    expect(insoleInRectangle(polygon).matrix).toEqual([1, 0, 0, 1, 0, 0.5]);
    expect(insolePath).toContain('26 4.6');
    expect(insolePath).toContain('20.5 0');
    expect(insolePath).toContain('20 10');
  });
  it.each([[150, 100, 26, 11], [150, 100, 26, 10], [120, 80, 25, 9], [100, 200, 30, 15], [1, 1, 0.01, 0.02]])('контури не виходять за межі %j × %j та повертаються разом із заготовками', (length, width, a, b) => {
    const layout = laminateRows(length, width, a, a, b), before = JSON.stringify(layout);
    const polygons = laminatePolygons(layout, a, a, b);
    expect(polygons.length).toBeGreaterThan(0);
    polygons.forEach((polygon, index) => {
      const outline = insoleInRectangle(polygon, index);
      const [m0, m1, m2, m3, m4, m5] = outline.matrix;
      const xs = polygon.points.map(point => point[0]), ys = polygon.points.map(point => point[1]);
      for (const [x, y] of [[0, 0], [26, 0], [26, 10], [0, 10]]) {
        const px = m0*x + m2*y + m4, py = m1*x + m3*y + m5;
        expect(px).toBeGreaterThanOrEqual(Math.min(...xs) - 1e-8);
        expect(px).toBeLessThanOrEqual(Math.max(...xs) + 1e-8);
        expect(py).toBeGreaterThanOrEqual(Math.min(...ys) - 1e-8);
        expect(py).toBeLessThanOrEqual(Math.max(...ys) + 1e-8);
      }
      expect(outline.mirrored).toBe(index % 2 === 1);
      expect(outline.scale).toBeLessThanOrEqual(1);
    });
    expect(JSON.stringify(layout)).toBe(before);
  });
  it('не розтягує устілку, якщо прямокутник більший за контур', () => {
    const polygon = { rotated: true, points: [[10, 20], [10, 50], [25, 50], [25, 20]] };
    expect(insoleInRectangle(polygon).matrix).toEqual([0, 1, -1, 0, 22.5, 22]);
    expect(insoleInRectangle(polygon, 1).matrix).toEqual([0, 1, 1, 0, 12.5, 22]);
  });
});
