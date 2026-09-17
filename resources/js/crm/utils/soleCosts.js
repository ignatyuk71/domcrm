export const costFields = {
  goods_cny: 2, china_shipping_cny: 2, commission_percent: 2,
  international_shipping_usd: 2, ukraine_shipping_uah: 2, other_costs_uah: 2,
  cny_rate: 4, usd_rate: 4,
};
export const optionalCostFields = { upper_shipping_usd: 2 };
export const decimalInput = value => String(value ?? '').trim().replace(',', '.');
const divide = (numerator, denominator) => Math.floor((numerator + Math.floor(denominator / 2)) / denominator);
const roundUnit = value => Math.round(value * 1000000) / 1000000;
const minor = (value, precision) => {
  const [whole, fraction = ''] = value.split('.');
  return Number(whole) * 10 ** precision + Number(fraction.padEnd(precision, '0'));
};

export function calculateSoleCost(form) {
  if (!/^\d+$/.test(String(form.quantity)) || Number(form.quantity) < 1 || Number(form.quantity) > 10000000) return null;
  const quantity = Number(form.quantity), units = {};
  for (const [field, precision] of Object.entries({ ...costFields, ...optionalCostFields })) {
    const value = decimalInput(form[field] ?? (field in optionalCostFields ? '0' : ''));
    const max = field.endsWith('_rate') ? 1000 : field === 'commission_percent' ? 100 : 1000000;
    if (!new RegExp(`^\\d+(?:\\.\\d{1,${precision}})?$`).test(value) || Number(value) > max || (field.endsWith('_rate') && Number(value) < 0.0001)) return null;
    units[field] = minor(value, precision);
  }
  // Межі полів утримують добутки в Number.MAX_SAFE_INTEGER; округлення відповідає серверу.
  const commission = divide((units.goods_cny + units.china_shipping_cny) * units.commission_percent, 10000);
  const rows = [
    ['goods', 'Підошва', divide(units.goods_cny * units.cny_rate, 10000)],
    ['china_shipping', 'Доставка по Китаю', divide(units.china_shipping_cny * units.cny_rate, 10000)],
    ['commission', 'Комісія за викуп', divide(commission * units.cny_rate, 10000)],
    ['international_shipping', 'Доставка в Україну з митним оформленням', divide(units.international_shipping_usd * units.usd_rate, 10000)],
    ['ukraine_shipping', 'Доставка по Україні', units.ukraine_shipping_uah],
    ['other_costs', 'Інші витрати', units.other_costs_uah],
  ];
  if (units.upper_shipping_usd > 0) rows.push(['upper_shipping', 'Окрема доставка верху в Україну', divide(units.upper_shipping_usd * units.usd_rate, 10000)]);
  const total = rows.reduce((sum, row) => sum + row[2], 0);
  return {
    total_uah: total / 100, unit_cost_uah: roundUnit(total / 100 / quantity),
    commission_cny: commission / 100,
    total_cny: (units.goods_cny + units.china_shipping_cny + commission) / 100,
    breakdown: rows.map(([key, label, amount]) => ({ key, label, total_uah: amount / 100, unit_uah: roundUnit(amount / 100 / quantity) })),
  };
}

export const emptyCostForm = () => ({
  name: 'Нова партія підошви', purchased_on: '', quantity: '', note: '',
  goods_cny: '', china_shipping_cny: '0', commission_percent: '10', international_shipping_usd: '0',
  upper_shipping_usd: '0', ukraine_shipping_uah: '0', other_costs_uah: '0', cny_rate: '', usd_rate: '',
});
