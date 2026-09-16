import { describe, expect, it } from 'vitest';
import { calculateFoamCost, emptyFoamForm } from './foamCosts';

const inputs = { sheet_price_usd: '4.50', usd_rate: '40', shipping_uah: '', sheet_length_cm: '120', sheet_width_cm: '200', blank_length_cm: '25', blank_width_cm: '10' };
describe('Окремі поролонові вставки', () => {
  it('рахує дві вставки за ціною одного листа без кількості закупівлі', () => {
    expect(calculateFoamCost(inputs)).toMatchObject({ purchase_sheet_uah: 180, sheet_cost_uah: 180, sheet_area_m2: 2.4, square_metre_cost_uah: 75, pair_area_m2: 0.05, unit_cost_uah: 3.75, shipping_included: false });
  });
  it('змінює курс і додає лише вказану доставку на один лист', () => {
    expect(calculateFoamCost({ ...inputs, usd_rate: '41' }).unit_cost_uah).toBe(3.84375);
    expect(calculateFoamCost({ ...inputs, sheet_price_usd: '4,50', usd_rate: '40,0000', shipping_uah: '12,00' })).toMatchObject({ sheet_cost_uah: 192, unit_cost_uah: 4, shipping_included: true });
    expect(calculateFoamCost({ ...inputs, shipping_uah: '0' }).shipping_included).toBe(true);
  });
  it('валютну конвертацію округлює до копійки як сервер', () => {
    expect(calculateFoamCost({ ...inputs, sheet_price_usd: '0.01', usd_rate: '1.5000' })).toMatchObject({ purchase_sheet_uah: 0.02, unit_cost_uah: 0.000417 });
  });
  it.each([{ sheet_price_usd: '' }, { sheet_price_usd: '-1' }, { sheet_price_usd: '1.001' }, { sheet_price_usd: '1e3' }, { sheet_price_usd: '1001' }, { usd_rate: '' }, { usd_rate: '0' }, { usd_rate: '1e2' }, { usd_rate: '1001' }, { usd_rate: '1.00001' }, { sheet_width_cm: '0' }, { blank_length_cm: '201' }, { shipping_uah: '-1' }])('відхиляє помилкові значення %j', change => {
    expect(calculateFoamCost({ ...inputs, ...change })).toBeNull();
  });
  it('нова розцінка переносить лише геометрію, очищує старі ціну та курс', () => {
    const fresh = emptyFoamForm(inputs);
    expect(fresh).toMatchObject({ sheet_price_usd: '', usd_rate: '', shipping_uah: '', blank_width_cm: '10', sheet_length_cm: '120' });
    expect(fresh).not.toHaveProperty('quantity'); expect(fresh).not.toHaveProperty('goods_uah');
    expect(calculateFoamCost(fresh)).toBeNull(); expect(calculateFoamCost(emptyFoamForm())).toBeNull();
  });
});
