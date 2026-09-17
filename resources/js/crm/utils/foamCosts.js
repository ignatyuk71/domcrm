import { decimalInput } from './soleCosts';
import { calculateSheetAreaCost, emptyCardboardForm } from './cardboardCosts';

export const foamFields = ['sheet_price_usd', 'usd_rate', 'shipping_uah', 'sheet_length_cm', 'sheet_width_cm', 'blank_length_cm', 'blank_width_cm'];

export function calculateFoamCost(form) {
  const price = decimalInput(form.sheet_price_usd), rate = decimalInput(form.usd_rate);
  if (!/^\d+(?:\.\d{1,2})?$/.test(price) || Number(price) > 1000) return null;
  if (!/^\d+(?:\.\d{1,4})?$/.test(rate) || Number(rate) < 0.0001 || Number(rate) > 1000) return null;
  // Спершу переводимо ціну одного листа у копійки за вказаним курсом.
  const sheetUah = Math.floor((Math.round(Number(price) * 100) * Math.round(Number(rate) * 10000) + 5000) / 10000) / 100;
  const calculation = calculateSheetAreaCost({ ...form, quantity: '1', goods_uah: sheetUah.toFixed(2) });
  return calculation ? { ...calculation, purchase_sheet_uah: sheetUah } : null;
}

export function emptyFoamForm(previous = {}) {
  const { quantity, goods_uah, ...geometry } = emptyCardboardForm(previous);
  return { ...geometry, name: 'Поролон-вставка 5 мм', sheet_price_usd: '', usd_rate: '' };
}
