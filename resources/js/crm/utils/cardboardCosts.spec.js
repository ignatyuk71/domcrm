import { describe, expect, it } from 'vitest';
import { calculateCardboardCost, emptyCardboardForm } from './cardboardCosts';

const inputs = { quantity: '20', goods_uah: '3600', shipping_uah: '', sheet_length_cm: '120', sheet_width_cm: '80', blank_length_cm: '25', blank_width_cm: '9' };
describe('Картон за площею заготовок', () => {
  it('рахує лист, м² і дві заготовки без проміжного округлення', () => {
    expect(calculateCardboardCost(inputs)).toEqual({ total_uah: 3600, sheet_area_m2: 0.96, blank_area_m2: 0.0225, pair_area_m2: 0.045, total_area_m2: 19.2, sheet_cost_uah: 180, square_metre_cost_uah: 187.5, unit_cost_uah: 8.4375, shipping_included: false });
  });
  it('додає вказану доставку й підтримує десяткову кому', () => {
    expect(calculateCardboardCost({ ...inputs, shipping_uah: '240,00', sheet_width_cm: '80,00' })).toMatchObject({ total_uah: 3840, unit_cost_uah: 9, shipping_included: true });
    expect(calculateCardboardCost({ ...inputs, shipping_uah: '0' }).shipping_included).toBe(true);
    expect(calculateCardboardCost({ ...inputs, shipping_uah: null }).shipping_included).toBe(false);
  });
  it('не округлює ціну м² до копійок перед множенням на площу', () => {
    expect(calculateCardboardCost({ ...inputs, goods_uah: '3601' }).unit_cost_uah).toBe(8.439844);
  });
  it.each([{ quantity: '0' }, { quantity: '1.5' }, { goods_uah: '' }, { goods_uah: '-1' }, { goods_uah: '1.234' }, { goods_uah: '1e3' }, { goods_uah: '1000001' }, { shipping_uah: '-1' }, { sheet_length_cm: '0' }, { sheet_width_cm: '1001' }, { blank_width_cm: '121' }, { blank_length_cm: '121' }, { blank_length_cm: '1.234' }])('не рахує некоректні поля %j', change => {
    expect(calculateCardboardCost({ ...inputs, ...change })).toBeNull();
  });
  it('допускає заготовку, яка поміщається після повороту', () => {
    expect(calculateCardboardCost({ ...inputs, blank_length_cm: '70', blank_width_cm: '100' })).not.toBeNull();
  });
  it('нова партія переносить лише розміри, а не вартість закупівлі', () => {
    const fresh = emptyCardboardForm(inputs);
    expect(fresh).toMatchObject({ goods_uah: '', quantity: '', shipping_uah: '', sheet_length_cm: '120', blank_width_cm: '9' });
    expect(calculateCardboardCost(fresh)).toBeNull();
    expect(calculateCardboardCost(emptyCardboardForm())).toBeNull();
  });
});
