import { describe, expect, it } from 'vitest';
import { calculateFurCost, emptyFurForm, activeFurFields } from './furCosts';

const inputs = { fabric_length: '10', length_unit: 'metre', fabric_width_cm: '200', top_width_cm: '20', bottom_width_cm: '10', height_cm: '10', goods_cny: '100', china_shipping_cny: '10', commission_percent: '10', international_shipping_usd: '10', ukraine_shipping_uah: '', other_costs_uah: '0', cny_rate: '6', usd_rate: '40' };
describe('Собівартість хутра', () => {
  it('контрольний відріз дає 110 деталей, 55 пар, 10,91 за деталь і 21,82 на пару з обрізками', () => {
    const example = { ...inputs, purchase_source: 'ukraine', goods_uah: '1200', fabric_length: '1', fabric_width_cm: '180', top_width_cm: '20', bottom_width_cm: '13', height_cm: '8' };
    expect(calculateFurCost(example)).toMatchObject({ piece_cost_uah: 10.909091, unit_cost_uah: 21.818182, layout: { pieces_per_row: 5, rows_per_cut: 22, total_pieces: 110, pairs: 55, offcut_area_m2: 0.348 } });
    expect(calculateFurCost({ ...example, height_cm: '9' })).toMatchObject({ unit_cost_uah: 24, layout: { total_pieces: 100, pairs: 50 } });
    expect(calculateFurCost({ ...example, fabric_length: '1.19' })).toMatchObject({ unit_cost_uah: 21.818182, layout: { total_pieces: 110, remainder_length_cm: 19, remainder_pieces: 0 } });
    expect(calculateFurCost({ ...example, fabric_length: '1.25' })).toMatchObject({ unit_cost_uah: 18.181818, layout: { total_pieces: 132, remainder_pieces: 22, pairs: 66 } });
    expect(calculateFurCost({ ...example, fabric_length: '2', length_unit: 'yard' })).toMatchObject({ unit_cost_uah: 12.121212, layout: { full_cuts: 1, total_pieces: 198, pairs: 99, remainder_length_cm: 82.88 } });
    expect(calculateFurCost({ ...example, fabric_width_cm: '8' })).toMatchObject({ unit_cost_uah: 600, layout: { total_pieces: 5, pairs: 2, unpaired_pieces: 1 } });
    expect(calculateFurCost({ ...example, fabric_length: '0.2', fabric_width_cm: '8' })).toBeNull();
    expect(emptyFurForm(example).cut_length_cm).toBe('100');
    expect(emptyFurForm({ ...example, cut_length_cm: '200' }).cut_length_cm).toBe('200');
  });
  it('українська закупівля не додає приховані китайські суми та курси', () => {
    const form = { ...inputs, purchase_source: 'ukraine', goods_uah: '3000,00', ukraine_shipping_uah: '100,00', other_costs_uah: '20' };
    const result = calculateFurCost(form);
    expect(result).toMatchObject({ total_uah: 3120, unit_cost_uah: 5.2, linear_metre_cost_uah: 312, square_metre_cost_uah: 156 });
    expect(result.breakdown).toHaveLength(3); expect(result).not.toHaveProperty('commission_cny');
    expect(activeFurFields(form)).toContain('goods_uah'); expect(activeFurFields(form)).not.toContain('goods_cny');
    expect(activeFurFields(inputs)).not.toContain('goods_uah');
  });
  it('місцеві суми складає в копійках, доставка залишається невідомою до введення', () => {
    const local = { ...inputs, purchase_source: 'ukraine', goods_uah: '3000', cny_rate: '', usd_rate: '' };
    expect(calculateFurCost(local)).toMatchObject({ total_uah: 3000, unit_cost_uah: 5, ukraine_shipping_included: false });
    expect(calculateFurCost({ ...local, ukraine_shipping_uah: '0' }).ukraine_shipping_included).toBe(true);
    expect(calculateFurCost({ ...local, length_unit: 'yard' }).unit_cost_uah).toBe(5.555556);
    expect(calculateFurCost({ ...local, goods_uah: '0.10', other_costs_uah: '0.20' }).total_uah).toBe(0.3);
  });
  it.each(['', '-1', '1.001', '1e2', '1000001'])('не підміняє некоректну українську суму %s нулем', goods_uah => {
    expect(calculateFurCost({ ...inputs, purchase_source: 'ukraine', goods_uah })).toBeNull();
  });
  it('зберігає обране джерело для нової партії, але не стару оплату', () => {
    expect(emptyFurForm({ purchase_source: 'ukraine', goods_uah: '3000' })).toMatchObject({ purchase_source: 'ukraine', length_unit: 'metre', goods_uah: '', ukraine_shipping_uah: '' });
    expect(calculateFurCost({ ...inputs, purchase_source: 'unknown' })).toBeNull();
  });
  it('дві трапеції, не прямокутники; комісія включає доставку Китаєм', () => {
    const result = calculateFurCost(inputs);
    expect(result).toMatchObject({ total_uah: 1126, commission_cny: 11, total_cny: 121, length_metres: 10, total_area_m2: 20, pair_area_m2: 0.03, linear_metre_cost_uah: 112.6, square_metre_cost_uah: 56.3, unit_cost_uah: 1.876667, ukraine_shipping_included: false });
    expect(result.breakdown[0]).toMatchObject({ label: 'Хутро', total_uah: 600, unit_uah: 1 });
  });
  it('ярд не дорівнює метру, дробову довжину не обрізає', () => {
    const result = calculateFurCost({ ...inputs, length_unit: 'yard' });
    expect(result.length_metres).toBeCloseTo(9.144, 8);
    expect(result.unit_cost_uah).toBe(2.085185);
    expect(calculateFurCost({ ...inputs, fabric_length: '10,1250', length_unit: 'yard' }).length_metres).toBeCloseTo(10.125 * 0.9144, 8);
  });
  it('враховує лише відому місцеву доставку та інші витрати', () => {
    expect(calculateFurCost({ ...inputs, ukraine_shipping_uah: '100,00', other_costs_uah: '20' })).toMatchObject({ total_uah: 1246, unit_cost_uah: 2.076667, ukraine_shipping_included: true });
    expect(calculateFurCost({ ...inputs, ukraine_shipping_uah: '0' }).ukraine_shipping_included).toBe(true);
  });
  it('комісію й конвертацію округлює як сервер, ділить на цілі пари', () => {
    expect(calculateFurCost({ ...inputs, goods_cny: '0.05', china_shipping_cny: '0', international_shipping_usd: '0', cny_rate: '7.1234' })).toMatchObject({ total_uah: 0.43, commission_cny: 0.01, unit_cost_uah: 0.000717 });
  });
  it.each([{ fabric_length: '0' }, { fabric_length: '' }, { fabric_length: '1.00001' }, { fabric_length: '1e2' }, { length_unit: 'cm' }, { fabric_width_cm: '9' }, { top_width_cm: '-1' }, { bottom_width_cm: '0' }, { height_cm: '0' }, { fabric_length: '0.05' }, { goods_cny: '1.001' }, { usd_rate: '0' }, { ukraine_shipping_uah: '-1' }, { cut_length_cm: '' }, { cut_length_cm: '0' }, { cut_length_cm: '100.001' }, { cut_length_cm: '19' }])('відхиляє некоректні параметри %j', change => {
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
