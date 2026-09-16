import { describe, expect, it } from 'vitest';
import { calculateFurCost, emptyFurForm } from './furCosts';

const inputs = { fabric_length: '10', length_unit: 'metre', fabric_width_cm: '200', top_width_cm: '20', bottom_width_cm: '10', height_cm: '10', goods_cny: '100', china_shipping_cny: '10', commission_percent: '10', international_shipping_usd: '10', ukraine_shipping_uah: '', other_costs_uah: '0', cny_rate: '6', usd_rate: '40' };
describe('Собівартість хутра', () => {
  it('дві трапеції, не прямокутники; комісія включає доставку Китаєм', () => {
    const result = calculateFurCost(inputs);
    expect(result).toMatchObject({ total_uah: 1126, commission_cny: 11, total_cny: 121, length_metres: 10, total_area_m2: 20, pair_area_m2: 0.03, linear_metre_cost_uah: 112.6, square_metre_cost_uah: 56.3, unit_cost_uah: 1.689, ukraine_shipping_included: false });
    expect(result.breakdown[0]).toMatchObject({ label: 'Хутро', total_uah: 600, unit_uah: 0.9 });
  });
  it('ярд не дорівнює метру, дробову довжину не обрізає', () => {
    const result = calculateFurCost({ ...inputs, length_unit: 'yard' });
    expect(result.length_metres).toBeCloseTo(9.144, 8);
    expect(result.unit_cost_uah).toBe(1.847113);
    expect(calculateFurCost({ ...inputs, fabric_length: '10,1250', length_unit: 'yard' }).length_metres).toBeCloseTo(10.125 * 0.9144, 8);
  });
  it('враховує лише відому місцеву доставку та інші витрати', () => {
    expect(calculateFurCost({ ...inputs, ukraine_shipping_uah: '100,00', other_costs_uah: '20' })).toMatchObject({ total_uah: 1246, unit_cost_uah: 1.869, ukraine_shipping_included: true });
    expect(calculateFurCost({ ...inputs, ukraine_shipping_uah: '0' }).ukraine_shipping_included).toBe(true);
  });
  it('комісію й конвертацію округлює як сервер, не округлює м² перед множенням', () => {
    expect(calculateFurCost({ ...inputs, goods_cny: '0.05', china_shipping_cny: '0', international_shipping_usd: '0', cny_rate: '7.1234' })).toMatchObject({ total_uah: 0.43, commission_cny: 0.01, unit_cost_uah: 0.000645 });
  });
  it.each([{ fabric_length: '0' }, { fabric_length: '' }, { fabric_length: '1.00001' }, { fabric_length: '1e2' }, { length_unit: 'cm' }, { fabric_width_cm: '15' }, { top_width_cm: '-1' }, { bottom_width_cm: '0' }, { height_cm: '0' }, { fabric_length: '0.05' }, { goods_cny: '1.001' }, { usd_rate: '0' }, { ukraine_shipping_uah: '-1' }])('відхиляє некоректні параметри %j', change => {
    expect(calculateFurCost({ ...inputs, ...change })).toBeNull();
  });
  it('нова партія лишає одиницю та геометрію, очищує суми, довжину й курси', () => {
    const fresh = emptyFurForm(inputs);
    expect(fresh).toMatchObject({ length_unit: 'metre', top_width_cm: '20', fabric_width_cm: '200', fabric_length: '', goods_cny: '', cny_rate: '', usd_rate: '', commission_percent: '' });
    expect(calculateFurCost(fresh)).toBeNull(); expect(calculateFurCost(emptyFurForm())).toBeNull();
  });
  it('не пропускає переповнення серверної точності ціни на пару', () => {
    expect(calculateFurCost({ ...inputs, fabric_length: '1', fabric_width_cm: '1', top_width_cm: '1', bottom_width_cm: '1', height_cm: '100', goods_cny: '1000000', china_shipping_cny: '1000000', commission_percent: '100', international_shipping_usd: '1000000', ukraine_shipping_uah: '1000000', other_costs_uah: '1000000', cny_rate: '1000', usd_rate: '1000' })).toBeNull();
  });
});
