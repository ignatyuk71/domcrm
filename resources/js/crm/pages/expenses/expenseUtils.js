export const money = (value, currency = 'UAH') => new Intl.NumberFormat('uk-UA', { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number(value || 0));
export const shortDate = value => value ? new Intl.DateTimeFormat('uk-UA', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date(`${value.slice(0, 10)}T12:00:00`)) : '—';
// Порівняння сум не залежить від похибки чисел із рухомою комою.
export function minor(value) {
  const match = String(value ?? '').replace(',', '.').match(/^(\d+)(?:\.(\d{1,2}))?$/);
  return match ? BigInt(match[1]) * 100n + BigInt((match[2] || '').padEnd(2, '0')) : null;
}
export function requestKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
  const hex = [...bytes].map(byte => byte.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
