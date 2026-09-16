import { decimalInput } from './soleCosts';

export const tapePrecision = { length_m: 4, goods_uah: 2, shipping_uah: 2, per_slipper_cm: 2, allowance_cm: 2 };
export const tapeFields = Object.keys(tapePrecision);
const round = value => Math.round(value * 1e6) / 1e6;

export function calculateTapeCost(form) {
  const values = {};
  for (const [field, precision] of Object.entries(tapePrecision)) {
    const value = decimalInput(form[field]);
    if (field === 'shipping_uah' && value === '') { values[field] = null; continue; }
    const min = field === 'length_m' ? 0.0001 : field === 'per_slipper_cm' ? 0.01 : 0;
    if (!new RegExp(`^\\d+(?:\\.\\d{1,${precision}})?$`).test(value) || Number(value) < min || Number(value) > (field.endsWith('_cm') ? 1000 : 1000000)) return null;
    values[field] = Number(value);
  }
  // Одна одиниця — 0,0001 м або 0,01 см, тому залишок не втрачається через похибку floor.
  const length = Math.round(values.length_m * 10000);
  const slipper = Math.round(values.per_slipper_cm * 100) + Math.round(values.allowance_cm * 100), pair = slipper * 2;
  const goods = Math.round(values.goods_uah * 100), shipping = Math.round((values.shipping_uah ?? 0) * 100), total = (goods + shipping) / 100;
  if (length < pair) return null;
  return {
    total_uah: total, metre_cost_uah: round(total * 10000 / length),
    unit_cost_uah: round(total * pair / length), slipper_cost_uah: round(total * slipper / length),
    slipper_length_cm: slipper / 100, pair_length_m: pair / 10000,
    whole_pairs: Math.floor(length / pair), remaining_length_m: (length % pair) / 10000,
    shipping_included: values.shipping_uah !== null,
    breakdown: [
      { key: 'goods', label: 'Стрічка з комісією за викуп', total_uah: goods / 100, unit_uah: round(goods / 100 * pair / length) },
      { key: 'shipping', label: 'Доставка всієї партії', total_uah: values.shipping_uah === null ? null : shipping / 100, unit_uah: values.shipping_uah === null ? null : round(shipping / 100 * pair / length) },
    ],
  };
}

export const emptyTapeForm = (previous = {}) => ({
  name: 'Оксамитова стрічка · нова партія', purchased_on: '', note: '', length_m: '', goods_uah: '', shipping_uah: '',
  per_slipper_cm: previous.per_slipper_cm ?? '', allowance_cm: previous.allowance_cm ?? '',
});
