// Вартість кожної складової вже розрахована на пару відповідним калькулятором.
const parts = [
  ['soles', 'soles', 'Підошва', 'box-seam', 'unit_cost_uah'],
  ['cardboard', 'cardboard', 'Картон', 'file-earmark', 'unit_cost_uah'],
  ['foam', 'foam', 'Поролон-вставка', 'square', 'unit_cost_uah'],
  ['laminate-insole', 'laminate', 'Плюш + поролон · устілка', 'layers', 'insole_pair_cost_uah'],
  ['laminate-upper', 'laminate', 'Плюш + поролон · верх', 'layers', 'upper_pair_cost_uah'],
  ['fur', 'fur', 'Хутро', 'scissors', 'unit_cost_uah'],
  ['tape', 'tape', 'Окантовка', 'bounding-box', 'unit_cost_uah'],
  ['thread', 'thread', 'Нитки', 'bezier', 'unit_cost_uah'],
  ['labor', 'labor', 'Робота', 'tools', 'unit_cost_uah'],
];

export function summarizeCostParts(components = {}, profile = 'sewn') {
  const selected = profile === 'outdoor' ? parts.filter(([, component]) => ['soles', 'fur', 'labor'].includes(component)) : parts;
  const rows = selected.map(([key, component, label, icon, field]) => {
    const entry = components[component];
    const value = entry?.calculation?.[field];
    // null, порожній рядок і невалідне число не є безкоштовним матеріалом.
    const amount = typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : null;
    return { key, component, label: profile === 'outdoor' && component === 'soles' ? 'Підошва + верх' : label, icon, amount, name: entry?.name || '', dirty: !!entry?.dirty };
  });
  const known = rows.filter(row => row.amount !== null);
  // Складаємо мікрогривні, а не округлені до копійок рядки.
  const total = known.length ? known.reduce((sum, row) => sum + Math.round(row.amount * 1e6), 0) / 1e6 : null;
  return { rows, total, known: known.length, missing: rows.filter(row => row.amount === null), dirty: rows.some(row => row.dirty) };
}
