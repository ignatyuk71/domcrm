import { describe, expect, it } from 'vitest';
import { summarizeCostParts } from './costSummary';

const entry = amount => ({ name: 'Синтетична партія', calculation: { unit_cost_uah: amount, total_uah: 99999 } });
describe('Підсумок однієї пари', () => {
  it('додає по одній ціні матеріалів та дві різні деталі полотна без подвійного поролону', () => {
    const result = summarizeCostParts({ soles: entry(30), cardboard: entry(4), foam: entry(2), fur: entry(15), tape: entry(6), laminate: { calculation: { unit_cost_uah: null, total_uah: 300, insole_pair_cost_uah: 9, upper_pair_cost_uah: 3 } } });
    expect(result.total).toBe(69); expect(result.known).toBe(7);
    expect(result.missing.map(row => row.key)).toEqual(['thread', 'labor']);
  });
  it('не перетворює відсутні або невалідні значення на нулі', () => {
    expect(summarizeCostParts().total).toBeNull();
    for (const value of [null, undefined, '', '12', NaN, Infinity, -1]) expect(summarizeCostParts({ soles: entry(value) }).total).toBeNull();
    expect(summarizeCostParts({ soles: entry(0) }).total).toBe(0);
  });
  it('складає неокруглені рядки і позначає невалідну чернетку', () => {
    const result = summarizeCostParts({ soles: entry(1.004), tape: entry(1.004), foam: { calculation: null, dirty: true } });
    expect(result.total).toBe(2.008); expect(result.dirty).toBe(true); expect(result.known).toBe(2);
  });
});
