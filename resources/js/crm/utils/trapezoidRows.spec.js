import { describe, expect, it } from 'vitest';
import fixtures from '../../../../tests/Fixtures/trapezoid-rows.json';
import { trapezoidRows } from './trapezoidRows';

describe('Цілі ряди перевернутих трапецій', () => {
  it.each(fixtures)('$name', ({ input, expected }) => {
    const result = trapezoidRows(...input);
    expect(result).toMatchObject(expected);
    expect(result.offcut_area_m2).toBeGreaterThanOrEqual(0);
    expect(result.offcut_percent).toBeLessThanOrEqual(100);
  });
  it('не ділить на нуль і не приймає нечислові розміри', () => {
    expect(trapezoidRows(100, 180, 0, 20, 13, 8)).toBeNull();
    expect(trapezoidRows(100, 180, 100, NaN, 13, 8)).toBeNull();
  });
});
