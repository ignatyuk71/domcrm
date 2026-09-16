import { describe, expect, it } from 'vitest';
import { calculateSoleCost, emptyCostForm } from './soleCosts';

const exampleInputs = { quantity: '100', goods_cny: '200', china_shipping_cny: '10', commission_percent: '10', international_shipping_usd: '100', ukraine_shipping_uah: '500', other_costs_uah: '0', cny_rate: '6', usd_rate: '40' };
describe('Собівартість підошви', () => {
  it('відтворює погоджені суми без хутра та подвійного митного оформлення', () => {
    const result = calculateSoleCost(exampleInputs);
    expect(result.total_uah).toBe(5886);
    expect(result.unit_cost_uah).toBe(58.86);
    expect(result.commission_cny).toBe(21);
    expect(result.total_cny).toBe(231);
    expect(result.breakdown.map(row => row.total_uah)).toEqual([1200, 60, 126, 4000, 500, 0]);
  });
  it('приймає кому й одразу перераховує інші курси та кількість', () => {
    const result = calculateSoleCost({ ...exampleInputs, cny_rate: '6,00', usd_rate: '41', quantity: '50' });
    expect(result.total_uah).toBe(5986);
    expect(result.unit_cost_uah).toBeCloseTo(119.72, 6);
  });
  it.each([{ quantity: '0' }, { quantity: '1.5' }, { goods_cny: '-1' }, { goods_cny: '1.123' }, { goods_cny: '1e4' }, { usd_rate: '0' }, { cny_rate: '1.12345' }, { commission_percent: '101' }, { international_shipping_usd: '1000001' }])('не рахує некоректний набір %j', change => {
    expect(calculateSoleCost({ ...exampleInputs, ...change })).toBeNull();
  });
  it('не видає порожню нову партію за нульову собівартість', () => expect(calculateSoleCost(emptyCostForm())).toBeNull());
  it('округлює комісію та перерахунок у копійках як сервер', () => {
    const result = calculateSoleCost({ ...exampleInputs, quantity: '3', goods_cny: '0.05', china_shipping_cny: '0', cny_rate: '7.1234', international_shipping_usd: '0', ukraine_shipping_uah: '0' });
    expect(result.commission_cny).toBe(0.01);
    expect(result.total_uah).toBe(0.43);
  });
});
