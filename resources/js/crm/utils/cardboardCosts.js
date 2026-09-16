import { decimalInput } from './soleCosts';

export const cardboardFields = ['goods_uah', 'shipping_uah', 'sheet_length_cm', 'sheet_width_cm', 'blank_length_cm', 'blank_width_cm'];
const rounded = value => Math.round(value * 1000000) / 1000000;

export function calculateCardboardCost(form) {
  if (!/^\d+$/.test(String(form.quantity)) || Number(form.quantity) < 1 || Number(form.quantity) > 10000000) return null;
  const inputs = {};
  for (const field of cardboardFields) {
    const value = decimalInput(form[field]);
    if (field === 'shipping_uah' && value === '') { inputs[field] = null; continue; }
    const dimension = field.endsWith('_cm');
    if (!/^\d+(?:\.\d{1,2})?$/.test(value) || Number(value) < (dimension ? 0.01 : 0) || Number(value) > (dimension ? 1000 : 1000000)) return null;
    inputs[field] = Number(value);
  }
  const sheet = [inputs.sheet_length_cm, inputs.sheet_width_cm].sort((a, b) => a - b);
  const blank = [inputs.blank_length_cm, inputs.blank_width_cm].sort((a, b) => a - b);
  if (blank.some((side, i) => side > sheet[i])) return null;
  const total = (Math.round(inputs.goods_uah * 100) + Math.round((inputs.shipping_uah ?? 0) * 100)) / 100;
  const sheetArea = inputs.sheet_length_cm * inputs.sheet_width_cm / 10000;
  const blankArea = inputs.blank_length_cm * inputs.blank_width_cm / 10000;
  // Дві заготовки на пару; ціну м² округлюємо лише для показу, не перед множенням.
  const squareMetreCost = total / Number(form.quantity) / sheetArea;
  return {
    total_uah: total, sheet_area_m2: sheetArea, blank_area_m2: blankArea, pair_area_m2: blankArea * 2,
    total_area_m2: sheetArea * Number(form.quantity), sheet_cost_uah: rounded(total / Number(form.quantity)),
    square_metre_cost_uah: rounded(squareMetreCost), unit_cost_uah: rounded(squareMetreCost * blankArea * 2),
    shipping_included: inputs.shipping_uah !== null,
  };
}

export const emptyCardboardForm = (previous = {}) => ({
  name: 'Нова партія картону', purchased_on: '', note: '', quantity: '', goods_uah: '', shipping_uah: '',
  ...Object.fromEntries(cardboardFields.filter(field => field.endsWith('_cm')).map(field => [field, previous[field] ?? ''])),
});
