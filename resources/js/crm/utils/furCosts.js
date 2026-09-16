import { calculateSoleCost, costFields, decimalInput } from './soleCosts';
import { trapezoidRows } from './trapezoidRows';

export const furGeometry = { fabric_length: 4, fabric_width_cm: 2, cut_length_cm: 2, top_width_cm: 2, bottom_width_cm: 2, height_cm: 2 };
export const furFields = [...Object.keys(costFields), ...Object.keys(furGeometry)];
export const localFurFields = ['goods_uah', 'ukraine_shipping_uah', 'other_costs_uah', ...Object.keys(furGeometry)];
export const activeFurFields = form => form.purchase_source === 'ukraine' ? localFurFields : furFields;
const round = value => Math.round(value * 1000000) / 1000000;

export function calculateFurCost(form) {
  form = { cut_length_cm: '100', ...form };
  if (!['china', 'ukraine'].includes(form.purchase_source ?? 'china')) return null;
  if (!['yard', 'metre'].includes(form.length_unit)) return null;
  for (const [key, precision] of Object.entries(furGeometry)) {
    const value = decimalInput(form[key]);
    if (!new RegExp(`^\\d+(?:\\.\\d{1,${precision}})?$`).test(value) || Number(value) < (key === 'fabric_length' ? 0.0001 : 0.01) || Number(value) > (key === 'fabric_length' ? 1000000 : 1000)) return null;
  }
  const values = Object.fromEntries(Object.keys(furGeometry).map(key => [key, Number(decimalInput(form[key]))]));
  const lengthMetres = values.fabric_length * (form.length_unit === 'yard' ? 0.9144 : 1);
  const layout = trapezoidRows(lengthMetres * 100, values.fabric_width_cm, values.cut_length_cm, values.top_width_cm, values.bottom_width_cm, values.height_cm);
  if (!layout?.pairs) return null;
  const deliveryKnown = decimalInput(form.ukraine_shipping_uah) !== '';
  const purchaseInputs = { ...form, ukraine_shipping_uah: deliveryKnown ? form.ukraine_shipping_uah : '0' };
  const purchase = form.purchase_source === 'ukraine' ? localPurchase(purchaseInputs) : calculateSoleCost({ ...purchaseInputs, quantity: '1' });
  if (!purchase) return null;
  const area = lengthMetres * values.fabric_width_cm / 100;
  // Для двох трапецій множник 2 скорочується зі знаменником формули їх площі.
  const pairArea = (values.top_width_cm + values.bottom_width_cm) * values.height_cm / 10000;
  const pairCost = round(purchase.total_uah / layout.pairs);
  if (pairCost >= 10000000000) return null;
  return {
    ...purchase, unit_cost_uah: pairCost, piece_cost_uah: round(purchase.total_uah / layout.total_pieces), layout,
    breakdown: purchase.breakdown.map(row => ({ ...row, label: row.key === 'goods' ? 'Хутро' : row.label, unit_uah: round(row.total_uah / layout.pairs) })),
    length_metres: lengthMetres, total_area_m2: area, pair_area_m2: pairArea,
    linear_metre_cost_uah: round(purchase.total_uah / lengthMetres), square_metre_cost_uah: round(purchase.total_uah / area),
    ukraine_shipping_included: deliveryKnown,
  };
}

function localPurchase(form) {
  const rows = [['goods_uah', 'goods', 'Хутро'], ['ukraine_shipping_uah', 'ukraine_shipping', 'Доставка по Україні'], ['other_costs_uah', 'other_costs', 'Інші витрати']];
  const amounts = {};
  for (const [field] of rows) {
    const value = decimalInput(form[field]);
    if (!/^\d+(?:\.\d{1,2})?$/.test(value) || Number(value) > 1000000) return null;
    const [whole, fraction = ''] = value.split('.');
    amounts[field] = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
  }
  return {
    total_uah: Object.values(amounts).reduce((sum, amount) => sum + amount, 0) / 100,
    breakdown: rows.map(([field, key, label]) => ({ key, label, total_uah: amounts[field] / 100 })),
  };
}

export const emptyFurForm = (previous = {}) => ({
  name: 'Нова партія хутра', purchased_on: '', note: '', purchase_source: previous.purchase_source || 'china',
  fabric_length: '', length_unit: previous.length_unit || (previous.purchase_source === 'ukraine' ? 'metre' : 'yard'), goods_uah: '',
  ...Object.fromEntries(Object.keys(furGeometry).filter(key => key !== 'fabric_length').map(key => [key, previous[key] ?? (key === 'cut_length_cm' ? '100' : '')])),
  goods_cny: '', china_shipping_cny: '', commission_percent: '', international_shipping_usd: '',
  ukraine_shipping_uah: '', other_costs_uah: '0', cny_rate: '', usd_rate: '',
});
