const rangeFormatter = new Intl.DateTimeFormat('uk-UA', { day: 'numeric', month: 'long', year: 'numeric' });
const pluralRules = new Intl.PluralRules('uk-UA');

export function parseDate(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
  const [year, month, day] = value.split('-').map(Number);
  const date = new Date(0);
  date.setFullYear(year, month - 1, day);
  date.setHours(12, 0, 0, 0);
  return year > 0 && date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day ? date : null;
}

export const dateISO = (date) => String(date.getFullYear()).padStart(4, '0') + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');

export function todayInKyiv() {
  const parts = new Intl.DateTimeFormat('en', { timeZone: 'Europe/Kyiv', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
  const part = (type) => parts.find(item => item.type === type).value;
  return `${part('year')}-${part('month')}-${part('day')}`;
}

export function dayCount(from, to) {
  const start = parseDate(from);
  const end = parseDate(to);
  if (!start || !end || from > to) return null;
  // Рахуємо календарні дні: перехід на літній час не скорочує період.
  const ordinal = (date) => {
    const utc = new Date(0);
    utc.setUTCFullYear(date.getFullYear(), date.getMonth(), date.getDate());
    return utc.getTime() / 86400000;
  };
  return ordinal(end) - ordinal(start) + 1;
}

export function daysLabel(count) {
  if (count == null) return '';
  const ending = { one: 'день', few: 'дні', many: 'днів', other: 'дня' }[pluralRules.select(count)];
  return `${count} ${ending}`;
}

export function formatDateRange(from, to) {
  return dayCount(from, to) == null ? 'Обрати період' : rangeFormatter.formatRange(parseDate(from), parseDate(to));
}

export function datePresets(today = todayInKyiv()) {
  const end = parseDate(today);
  const week = new Date(end);
  week.setDate(week.getDate() - 6);
  const month = new Date(end);
  month.setDate(1);
  const previousEnd = new Date(month);
  previousEnd.setDate(0);
  const previousStart = new Date(previousEnd);
  previousStart.setDate(1);
  return [
    { key: 'today', label: 'Сьогодні', from: today, to: today },
    { key: '7days', label: '7 днів', from: dateISO(week), to: today },
    { key: 'month', label: 'Цей місяць', from: dateISO(month), to: today },
    { key: 'previous_month', label: 'Минулий місяць', from: dateISO(previousStart), to: dateISO(previousEnd) },
  ];
}
