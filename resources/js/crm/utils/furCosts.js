import { calculateSoleCost, costFields, decimalInput } from './soleCosts';

export const furGeometry = { fabric_length: 4, fabric_width_cm: 2, top_width_cm: 2, bottom_width_cm: 2, height_cm: 2 };
export const furFields = [...Object.keys(costFields), ...Object.keys(furGeometry)];
const round = value => Math.round(value * 1000000) / 1000000;

export function calculateFurCost(form) {
  if (!['yard', 'metre'].includes(form.length_unit)) return null;
  for (const [key, precision] of Object.entries(furGeometry)) {
    const value = decimalInput(form[key]);
    if (!new RegExp(`^\\d+(?:\\.\\d{1,${precision}})?$`).test(value) || Number(value) < (key === 'fabric_length' ? 0.0001 : 0.01) || Number(value) > (key === 'fabric_length' ? 1000000 : 1000)) return null;
  }
  const values = Object.fromEntries(Object.keys(furGeometry).map(key => [key, Number(decimalInput(form[key]))]));
  const lengthMetres = values.fabric_length * (form.length_unit === 'yard' ? 0.9144 : 1);
  if (Math.max(values.top_width_cm, values.bottom_width_cm) > values.fabric_width_cm || values.height_cm > lengthMetres * 100) return null;
  const deliveryKnown = decimalInput(form.ukraine_shipping_uah) !== '';
  const purchase = calculateSoleCost({ ...form, quantity: '1', ukraine_shipping_uah: deliveryKnown ? form.ukraine_shipping_uah : '0' });
  if (!purchase) return null;
  const area = lengthMetres * values.fabric_width_cm / 100;
  // Для двох трапецій множник 2 скорочується зі знаменником формули їх площі.
  const pairArea = (values.top_width_cm + values.bottom_width_cm) * values.height_cm / 10000;
  const share = pairArea / area;
  const pairCost = round(purchase.total_uah * share);
  if (pairCost >= 10000000000) return null;
  return {
    ...purchase, unit_cost_uah: pairCost,
    breakdown: purchase.breakdown.map(row => ({ ...row, label: row.key === 'goods' ? 'Хутро' : row.label, unit_uah: round(row.total_uah * share) })),
    length_metres: lengthMetres, total_area_m2: area, pair_area_m2: pairArea,
    linear_metre_cost_uah: round(purchase.total_uah / lengthMetres), square_metre_cost_uah: round(purchase.total_uah / area),
    ukraine_shipping_included: deliveryKnown,
  };
}

export const emptyFurForm = (previous = {}) => ({
  name: 'Нова партія хутра', purchased_on: '', note: '', fabric_length: '', length_unit: previous.length_unit || 'yard',
  ...Object.fromEntries(Object.keys(furGeometry).filter(key => key !== 'fabric_length').map(key => [key, previous[key] ?? ''])),
  goods_cny: '', china_shipping_cny: '', commission_percent: '', international_shipping_usd: '',
  ukraine_shipping_uah: '', other_costs_uah: '0', cny_rate: '', usd_rate: '',
});
