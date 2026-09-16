import { decimalInput } from './soleCosts';

export const laminatePrecision = {
  plush_price_metre_uah: 2, plush_width_cm: 2, plush_shipping_metre_uah: 2,
  web_roll_price_uah: 2, web_roll_length_m: 4, web_width_cm: 2, web_shipping_roll_uah: 2,
  foam_sheet_price_usd: 2, usd_rate: 4, foam_sheet_length_cm: 2, foam_sheet_width_cm: 2, foam_shipping_sheet_uah: 2,
  insole_length_cm: 2, insole_width_cm: 2, upper_top_cm: 2, upper_bottom_cm: 2, upper_height_cm: 2,
};
export const laminateFields = Object.keys(laminatePrecision);
export const laminateShipping = ['plush_shipping_metre_uah', 'web_shipping_roll_uah', 'foam_shipping_sheet_uah'];
const round = value => Math.round(value * 1000000) / 1000000;

export function calculateLaminateCost(form) {
  const values = {};
  for (const [field, precision] of Object.entries(laminatePrecision)) {
    const value = decimalInput(form[field]);
    if (laminateShipping.includes(field) && value === '') { values[field] = null; continue; }
    const dimension = field.endsWith('_cm');
    const min = dimension ? 0.01 : ['usd_rate', 'web_roll_length_m'].includes(field) ? 0.0001 : 0;
    const max = dimension || ['usd_rate', 'foam_sheet_price_usd'].includes(field) ? 1000 : 1000000;
    if (!new RegExp(`^\\d+(?:\\.\\d{1,${precision}})?$`).test(value) || Number(value) < min || Number(value) > max) return null;
    values[field] = Number(value);
  }
  const sheets = [[values.web_roll_length_m * 100, values.web_width_cm], [values.foam_sheet_length_cm, values.foam_sheet_width_cm]];
  for (const blank of [[values.insole_length_cm, values.insole_width_cm], [Math.max(values.upper_top_cm, values.upper_bottom_cm), values.upper_height_cm]]) {
    blank.sort((a, b) => a - b);
    if (blank[0] > values.plush_width_cm) return null;
    for (const sheet of sheets) {
      sheet.sort((a, b) => a - b);
      if (blank.some((side, i) => side > sheet[i])) return null;
    }
  }
  const cents = field => Math.round((values[field] ?? 0) * 100);
  const foamCents = Math.floor((cents('foam_sheet_price_usd') * Math.round(values.usd_rate * 10000) + 5000) / 10000);
  const layers = [
    { key: 'plush', label: 'Плюш вельбо', cost: (cents('plush_price_metre_uah') + cents('plush_shipping_metre_uah')) / 100, area: values.plush_width_cm / 100, shipping: 'plush_shipping_metre_uah' },
    { key: 'web', label: 'Клейова павутинка', cost: (cents('web_roll_price_uah') + cents('web_shipping_roll_uah')) / 100, area: values.web_roll_length_m * values.web_width_cm / 100, shipping: 'web_shipping_roll_uah' },
    { key: 'foam', label: 'Поролон 5 мм у полотні', cost: (foamCents + cents('foam_shipping_sheet_uah')) / 100, area: values.foam_sheet_length_cm * values.foam_sheet_width_cm / 10000, shipping: 'foam_shipping_sheet_uah' },
  ];
  const insoleArea = 2 * values.insole_length_cm * values.insole_width_cm / 10000;
  const upperArea = (values.upper_top_cm + values.upper_bottom_cm) * values.upper_height_cm / 10000;
  const pairArea = insoleArea + upperArea;
  const squareMetreCost = layers.reduce((sum, row) => sum + row.cost / row.area, 0);
  const pairCost = round(squareMetreCost * pairArea);
  if (Math.round(squareMetreCost * 100) / 100 >= 1000000000000 || pairCost >= 10000000000) return null;
  // Ціну м² округлюємо для показу, а не перед множенням на площу заготовок.
  return {
    total_uah: Math.round(squareMetreCost * 100) / 100, square_metre_cost_uah: round(squareMetreCost), unit_cost_uah: pairCost,
    pair_area_m2: pairArea, insole_pair_area_m2: insoleArea, upper_pair_area_m2: upperArea,
    insole_pair_cost_uah: round(squareMetreCost * insoleArea), upper_pair_cost_uah: round(squareMetreCost * upperArea), foam_sheet_uah: foamCents / 100,
    breakdown: layers.map(row => ({ key: row.key, label: row.label, purchase_unit_uah: row.cost, purchase_area_m2: row.area,
      square_metre_cost_uah: round(row.cost / row.area), unit_cost_uah: round(row.cost / row.area * pairArea), shipping_included: values[row.shipping] !== null })),
  };
}

export const emptyLaminateForm = (previous = {}) => ({
  name: 'Склеєне полотно · новий розрахунок', purchased_on: '', note: '',
  ...Object.fromEntries(laminateFields.map(field => [field, field.endsWith('_cm') || field === 'web_roll_length_m' ? previous[field] ?? '' : ''])),
});
