import { describe, expect, it } from 'vitest';
import { calculateCardboardCost, emptyCardboardForm } from './cardboardCosts';

const inputs = { quantity: '20', goods_uah: '3600', shipping_uah: '', sheet_length_cm: '120', sheet_width_cm: '80', blank_length_cm: '25', blank_width_cm: '9' };
describe('Картон за розкладкою цілих деталей', () => {
  it('розподіляє ціну листа на повні пари, враховуючи поперечні деталі', () => {
    expect(calculateCardboardCost(inputs)).toMatchObject({ total_uah: 3600, sheet_area_m2: 0.96, blank_area_m2: 0.0225, pair_area_m2: 0.045, total_area_m2: 19.2, sheet_cost_uah: 180, square_metre_cost_uah: 187.5, unit_cost_uah: 9.473684, shipping_included: false,
      method: 'sheet_rows_v1', layout: { primary_pieces: 32, rotated_pieces: 6, total_pieces: 38, pairs: 19 } });
  });
  it('додає вказану доставку й підтримує десяткову кому', () => {
    expect(calculateCardboardCost({ ...inputs, shipping_uah: '240,00', sheet_width_cm: '80,00' })).toMatchObject({ total_uah: 3840, unit_cost_uah: 10.105263, shipping_included: true });
    expect(calculateCardboardCost({ ...inputs, shipping_uah: '0' }).shipping_included).toBe(true);
    expect(calculateCardboardCost({ ...inputs, shipping_uah: null }).shipping_included).toBe(false);
  });
  it('не округлює ціну листа перед діленням на пари', () => {
    expect(calculateCardboardCost({ ...inputs, goods_uah: '3601' }).unit_cost_uah).toBe(9.476316);
    expect(calculateCardboardCost({ ...inputs, quantity: '7', goods_uah: '100' }).unit_cost_uah).toBe(0.75188);
  });
  it.each([{ quantity: '0' }, { quantity: '1.5' }, { goods_uah: '' }, { goods_uah: '-1' }, { goods_uah: '1.234' }, { goods_uah: '1e3' }, { goods_uah: '1000001' }, { shipping_uah: '-1' }, { sheet_length_cm: '0' }, { sheet_width_cm: '1001' }, { blank_width_cm: '121' }, { blank_length_cm: '121' }, { blank_length_cm: '1.234' }])('не рахує некоректні поля %j', change => {
    expect(calculateCardboardCost({ ...inputs, ...change })).toBeNull();
  });
  it('допускає заготовку, яка поміщається після повороту', () => {
    expect(calculateCardboardCost({ ...inputs, sheet_length_cm: '200', blank_length_cm: '70', blank_width_cm: '100' })).toMatchObject({ layout: { primary_pieces: 0, rotated_pieces: 2, pairs: 1 }, unit_cost_uah: 180 });
  });
  it('перераховує вихід при зміні розмірів і не переносить непарну деталь на інший лист', () => {
    const sheet = { ...inputs, sheet_length_cm: '150', sheet_width_cm: '100', blank_length_cm: '26', blank_width_cm: '11' };
    expect(calculateCardboardCost(sheet)).toMatchObject({ layout: { primary_pieces: 45, rotated_pieces: 3, total_pieces: 48, pairs: 24 }, unit_cost_uah: 7.5 });
    expect(calculateCardboardCost({ ...sheet, blank_width_cm: '10' })).toMatchObject({ layout: { total_pieces: 56, pairs: 28 }, unit_cost_uah: 6.428571 });
    expect(calculateCardboardCost({ ...sheet, blank_width_cm: '12' })).toMatchObject({ layout: { total_pieces: 43, pairs: 21, unpaired_pieces: 1 }, unit_cost_uah: 8.571429 });
  });
  it('одна деталь не створює нульову ціну повної пари', () => {
    expect(calculateCardboardCost({ ...inputs, blank_length_cm: '70', blank_width_cm: '100' })).toMatchObject({ layout: { pairs: 0 }, unit_cost_uah: null });
  });
  it('нова партія переносить лише розміри, а не вартість закупівлі', () => {
    const fresh = emptyCardboardForm(inputs);
    expect(fresh).toMatchObject({ goods_uah: '', quantity: '', shipping_uah: '', sheet_length_cm: '120', blank_width_cm: '9' });
    expect(calculateCardboardCost(fresh)).toBeNull();
    expect(calculateCardboardCost(emptyCardboardForm())).toBeNull();
  });
});
