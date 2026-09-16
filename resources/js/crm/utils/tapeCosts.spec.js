import { describe, expect, it } from 'vitest';
import fixtures from '../../../../tests/Fixtures/tape-costs.json';
import { calculateTapeCost, emptyTapeForm } from './tapeCosts';

const input = fixtures[0].input;
describe('Оксамитова стрічка', () => {
  it.each(fixtures)('$name', ({ input, expected }) => { expect(calculateTapeCost(input)).toMatchObject(expected); });
  it('коми підтримуються, доставка не підміняється нулем', () => {
    expect(calculateTapeCost({ ...input, length_m: '100,0000', per_slipper_cm: '75,00' }).unit_cost_uah).toBe(19.2);
    for (const shipping_uah of ['', null, undefined]) {
      const result = calculateTapeCost({ ...input, shipping_uah });
      expect(result).toMatchObject({ total_uah: 1000, shipping_included: false });
      expect(result.breakdown[1]).toMatchObject({ total_uah: null, unit_uah: null });
    }
    expect(calculateTapeCost({ ...input, shipping_uah: '0' }).shipping_included).toBe(true);
  });
  it.each([{ length_m: '' }, { length_m: '0' }, { length_m: '1.5999' }, { length_m: '1e2' }, { length_m: '1.00001' }, { length_m: '1000001' }, { goods_uah: '' }, { goods_uah: '-1' }, { goods_uah: '1.001' }, { goods_uah: '1000001' }, { shipping_uah: '-1' }, { per_slipper_cm: '0' }, { per_slipper_cm: '1001' }, { allowance_cm: '' }, { allowance_cm: '-1' }, { allowance_cm: '0.001' }])('відхиляє некоректне значення %j', changes => {
    expect(calculateTapeCost({ ...input, ...changes })).toBeNull();
  });
  it('новій партії залишає норму витрати, але очищує довжину й суми', () => {
    expect(emptyTapeForm(input)).toMatchObject({ per_slipper_cm: '75', allowance_cm: '5', length_m: '', goods_uah: '', shipping_uah: '' });
    expect(calculateTapeCost(emptyTapeForm(input))).toBeNull();
    expect(calculateTapeCost(emptyTapeForm())).toBeNull();
  });
  it('зміна довжини, запасу чи сум перераховує витрату і вартість', () => {
    expect(calculateTapeCost({ ...input, allowance_cm: '10' })).toMatchObject({ pair_length_m: 1.7, unit_cost_uah: 20.4 });
    expect(calculateTapeCost({ ...input, length_m: '200' }).unit_cost_uah).toBe(9.6);
    expect(calculateTapeCost({ ...input, shipping_uah: '300' }).unit_cost_uah).toBe(20.8);
  });
});
