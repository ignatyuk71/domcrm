import { describe, expect, it } from 'vitest';
import { calculateLaminateCost, emptyLaminateForm, laminateFields } from './laminateCosts';

const inputs = { cut_width_cm: '100', plush_price_metre_uah: '120', plush_width_cm: '200', plush_shipping_metre_uah: '', web_roll_price_uah: '800', web_roll_length_m: '40', web_width_cm: '100', web_shipping_roll_uah: '', foam_sheet_price_usd: '4', usd_rate: '40', foam_sheet_length_cm: '200', foam_sheet_width_cm: '100', foam_shipping_sheet_uah: '', insole_length_cm: '25', insole_width_cm: '10', upper_top_cm: '20', upper_bottom_cm: '10', upper_height_cm: '10' };
describe('Склеєне полотно', () => {
  it('100 × 150 см: окремі ряди прямокутників і трапецій, а не сума їх площ', () => {
    const example = { ...inputs, cut_width_cm: '150', insole_length_cm: '27', insole_width_cm: '11.5', upper_top_cm: '20', upper_bottom_cm: '13', upper_height_cm: '7' };
    expect(calculateLaminateCost(example)).toMatchObject({ linear_metre_cost_uah: 240, unit_cost_uah: null, insole_pair_cost_uah: 10.909091, upper_pair_cost_uah: 4,
      insole_layout: { pieces_per_row: 3, rows_per_cut: 13, primary_pieces: 39, rotated_pieces: 5, total_pieces: 44, pairs: 22, unpaired_pieces: 0 }, upper_layout: { pieces_per_row: 5, rows_per_cut: 21, primary_pieces: 105, rotated_pieces: 16, total_pieces: 121, pairs: 60, unpaired_pieces: 1 } });
    expect(calculateLaminateCost({ ...example, upper_height_cm: '8' })).toMatchObject({ insole_pair_cost_uah: 10.909091, upper_pair_cost_uah: 4.897959, upper_layout: { total_pieces: 98 } });
    expect(calculateLaminateCost({ ...example, cut_width_cm: '100' })).toMatchObject({ linear_metre_cost_uah: 160, insole_pair_cost_uah: 12.307692, upper_pair_cost_uah: 4 });
    expect(calculateLaminateCost({ ...example, insole_length_cm: '101' })).toMatchObject({ insole_layout: { primary_pieces: 0, rotated_pieces: 8, pairs: 4 }, insole_pair_cost_uah: 60 });
    expect(calculateLaminateCost({ ...example, cut_width_cm: '100', insole_length_cm: '101' }).insole_pair_cost_uah).toBeNull();
    expect(emptyLaminateForm(example).cut_width_cm).toBe('150');
  });
  it.each([undefined, null, ''])('невідома ширина %s не підміняється шириною одного шару', cut_width_cm => {
    expect(calculateLaminateCost({ ...inputs, cut_width_cm })).toMatchObject({ square_metre_cost_uah: 160, linear_metre_cost_uah: null, insole_layout: null, upper_layout: null, insole_pair_cost_uah: null, upper_pair_cost_uah: null, unit_cost_uah: null });
  });
  it.each(['0', '-1', '100.001', '1e2', '1001'])('відхиляє некоректну ширину розкрою %s', cut_width_cm => {
    expect(calculateLaminateCost({ ...inputs, cut_width_cm })).toBeNull();
  });
  it('рахує по два прямокутники й трапеції, три шари лише один раз', () => {
    const result = calculateLaminateCost(inputs);
    expect(result).toMatchObject({ total_uah: 160, square_metre_cost_uah: 160, unit_cost_uah: null, linear_metre_cost_uah: 160, insole_pair_area_m2: 0.05, upper_pair_area_m2: 0.03, insole_pair_cost_uah: 8, upper_pair_cost_uah: 5.333333, foam_sheet_uah: 160 });
    expect(result).not.toHaveProperty('pair_area_m2');
    expect(result.breakdown.map(row => row.square_metre_cost_uah)).toEqual([60, 20, 80]);
    expect(result.breakdown.map(row => row.linear_metre_cost_uah)).toEqual([60, 20, 80]);
    expect(result.breakdown.every(row => !('unit_cost_uah' in row))).toBe(true);
    expect(result.breakdown.every(row => !row.shipping_included)).toBe(true);
  });
  it('розподіляє доставку на метр плюшу, рулон павутинки й лист поролону', () => {
    expect(calculateLaminateCost({ ...inputs, plush_shipping_metre_uah: '20,00', web_shipping_roll_uah: '400', foam_shipping_sheet_uah: '20' })).toMatchObject({ square_metre_cost_uah: 190, linear_metre_cost_uah: 190, insole_pair_cost_uah: 9.5, upper_pair_cost_uah: 6.333333 });
    expect(calculateLaminateCost({ ...inputs, plush_shipping_metre_uah: '0' }).breakdown[0].shipping_included).toBe(true);
    expect(calculateLaminateCost({ ...inputs, plush_shipping_metre_uah: null }).breakdown[0].shipping_included).toBe(false);
  });
  it('округлює валюту в копійках, але не ціну м² перед множенням', () => {
    expect(calculateLaminateCost({ ...inputs, foam_sheet_price_usd: '0,05', usd_rate: '7,1234' })).toMatchObject({ foam_sheet_uah: 0.36, insole_pair_cost_uah: 4.009, upper_pair_cost_uah: 2.672667 });
    const result = calculateLaminateCost({ ...inputs, plush_width_cm: '150', web_roll_length_m: '37,1250', insole_width_cm: '10,25' });
    expect(result.insole_pair_cost_uah).toBeCloseTo((120 / 1.5 + 800 / 37.125 + 160 / 2) / 18, 6);
    expect(result.upper_pair_cost_uah).toBeCloseTo((120 / 1.5 + 800 / 37.125 + 160 / 2) / 30, 6);
  });
  it.each([{ plush_price_metre_uah: '' }, { plush_price_metre_uah: '-1' }, { plush_price_metre_uah: '1.001' }, { plush_price_metre_uah: '1000001' }, { plush_width_cm: '0' }, { plush_width_cm: '5' }, { web_roll_length_m: '0' }, { web_roll_length_m: '1e2' }, { web_roll_length_m: '1.00001' }, { web_roll_length_m: '0.01' }, { web_width_cm: '1001' }, { foam_sheet_width_cm: '5' }, { foam_sheet_price_usd: '1001' }, { usd_rate: '0' }, { usd_rate: '1.00001' }, { insole_width_cm: '-1' }, { upper_top_cm: '0' }, { upper_height_cm: '0' }, { plush_shipping_metre_uah: '-1' }])('відхиляє некоректні поля %j', change => {
    expect(calculateLaminateCost({ ...inputs, ...change })).toBeNull();
  });
  it('дозволяє повернути прямокутник у листі, але не губить висоту верху', () => {
    expect(calculateLaminateCost({ ...inputs, foam_sheet_length_cm: '20', foam_sheet_width_cm: '30' })).not.toBeNull();
    expect(calculateLaminateCost({ ...inputs, upper_height_cm: '300', upper_top_cm: '300', upper_bottom_cm: '250' })).toBeNull();
  });
  it('нова розцінка лишає розміри, очищує ціни, доставку й курс', () => {
    const fresh = emptyLaminateForm(inputs);
    expect(fresh).toMatchObject({ plush_width_cm: '200', web_roll_length_m: '40', insole_width_cm: '10', plush_price_metre_uah: '', usd_rate: '', web_roll_price_uah: '', foam_sheet_price_usd: '', plush_shipping_metre_uah: '' });
    expect(calculateLaminateCost(fresh)).toBeNull(); expect(calculateLaminateCost(emptyLaminateForm())).toBeNull();
  });
  it('не допускає переповнення грошової колонки', () => {
    const small = Object.fromEntries(laminateFields.filter(field => field.endsWith('_cm')).map(field => [field, '0.01']));
    expect(calculateLaminateCost({ ...inputs, ...small, web_roll_length_m: '0.0001', foam_sheet_price_usd: '1000', usd_rate: '1000' })).toBeNull();
  });
});
