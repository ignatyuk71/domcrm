export const statusLabels = {
  new: 'Новий',
  confirmed: 'Підтверджено',
  reserved: 'Бронювання',
  pending: 'Очікує підтвердження',
  in_process: 'В обробці',
  packing: 'Упакування',
  packed: 'Запаковано',
  delivered: 'У відділенні',
  delivered_paid: 'Завершено',
  returned: 'Повернення',
  in_progress: 'В роботі',
  done: 'Готово',
  completed: 'Виконано',
  cancelled: 'Скасовано',
  shipped: 'Відправлено',
};

export const paymentLabels = {
  paid: 'Оплачено',
  unpaid: 'Не оплачено',
  prepaid: 'Передоплата',
  prepayment: 'Передоплата',
  refund: 'Повернення',
};

export const statusColorMap = {
  new: '#fbbf24',
  pending: '#fbbf24',
  in_process: '#3b82f6',
  in_progress: '#3b82f6',
  in_work: '#3b82f6',
  confirmed: '#a855f7',
  reserved: '#0f766e',
  packing: '#78716c',
  packed: '#f97316',
  shipped: '#0ea5e9',
  delivered: '#f59e0b',
  delivered_paid: '#16a34a',
  completed: '#16a34a',
  done: '#16a34a',
  cancelled: '#1f2937',
  canceled: '#1f2937',
  returned: '#ef4444',
};

const deliveryColorMap = {
  created: '#78716c',
  deleted: '#1f2937',
  in_transit: '#0ea5e9',
  at_warehouse: '#f59e0b',
  received: '#16a34a',
  received_money: '#16a34a',
  cod_on_way: '#16a34a',
  refusal: '#ef4444',
};

function normalizeHex(color) {
  const hex = String(color || '').trim().match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i)?.[1];
  if (!hex) return null;
  return `#${hex.length === 3 ? hex.split('').map((c) => c + c).join('') : hex}`.toLowerCase();
}

const badgeStyles = new Map();
const toHex = (rgb) => `#${rgb.map((value) => value.toString(16).padStart(2, '0')).join('')}`;
function luminance(rgb) {
  const channels = rgb.map((value) => {
    const channel = value / 255;
    return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
  });
  return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
}

function buildStatusStyle(base) {
  if (badgeStyles.has(base)) return badgeStyles.get(base);
  const rgb = base.slice(1).match(/../g).map((channel) => Number.parseInt(channel, 16));
  const background = rgb.map((channel) => Math.round(255 * 0.88 + channel * 0.12));
  let text = [...rgb];
  // Світлий фон однаковий на всіх поверхнях, а текст має достатній контраст.
  while ((luminance(background) + 0.05) / (luminance(text) + 0.05) < 4.5) {
    text = text.map((channel) => Math.floor(channel * 0.9));
  }
  const style = {
    color: toHex(text),
    backgroundColor: toHex(background),
    borderColor: `${base}40`,
  };
  badgeStyles.set(base, style);
  return style;
}

export function getStatusStyle(order) {
  return buildStatusStyle(normalizeHex(order?.status_color) || normalizeHex(statusColorMap[order?.status_key]) || '#6b7280');
}

export function getDeliveryStatusStyle(order) {
  return buildStatusStyle(normalizeHex(order?.delivery_status_color) || normalizeHex(deliveryColorMap[order?.delivery_status_code]) || '#6b7280');
}

export function getStatusIcon(status) {
  const map = {
    new: 'bi-circle',
    in_process: 'bi-telephone',
    confirmed: 'bi-person-check-fill',
    reserved: 'bi-calendar2-check',
    pending: 'bi-hourglass-split',
    packing: 'bi-qr-code',
    packed: 'bi-box-seam',
    delivered: 'bi-geo-alt',
    delivered_paid: 'bi-check-all',
    returned: 'bi-arrow-counterclockwise',
    in_progress: 'bi-hourglass-split',
    in_work: 'bi-hourglass-split',
    done: 'bi-check2-circle',
    completed: 'bi-check2-circle',
    canceled: 'bi-x-circle',
    cancelled: 'bi-x-circle',
    shipped: 'bi-truck',
  };
  return map[status] || 'bi-dot';
}

export function getPaymentClass(status) {
  const map = {
    paid: 'bg-success-subtle text-success-emphasis border-success-subtle',
    unpaid: 'bg-danger-subtle text-danger-emphasis border-danger-subtle',
    prepayment: 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
    refund: 'bg-dark-subtle text-dark-emphasis border-dark-subtle',
  };
  return map[status] || 'bg-light text-dark border-light-subtle';
}

export function getPaymentIcon(status) {
  const map = {
    paid: 'bi-check-circle',
    unpaid: 'bi-x-circle',
    prepayment: 'bi-cash-stack',
    refund: 'bi-arrow-counterclockwise',
  };
  return map[status] || 'bi-dot';
}

export function formatCurrency(value, currency = 'UAH') {
  return Number(value ?? 0).toLocaleString('uk-UA', { style: 'currency', currency });
}

export const DISPLAY_TIME_ZONE = 'Europe/Kyiv';

function parseDate(value) {
  if (!value) return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function formatStatusDuration(value, now = Date.now()) {
  const startedAt = parseDate(value);
  if (!startedAt || !Number.isFinite(now)) return '';

  const minutes = Math.max(0, Math.floor((now - startedAt.getTime()) / 60_000));
  if (minutes === 0) return 'Щойно у статусі';
  if (minutes < 60) return `${minutes} хв у статусі`;

  const hours = Math.floor(minutes / 60);
  if (hours < 24) {
    const remainder = minutes % 60;
    return `${hours} год${remainder ? ` ${remainder} хв` : ''} у статусі`;
  }

  const days = Math.floor(hours / 24);
  const remainder = hours % 24;
  return `${days} дн${remainder ? ` ${remainder} год` : ''} у статусі`;
}

export function formatDate(value) {
  const date = parseDate(value);
  if (!date) return value || '';

  return date.toLocaleString('uk-UA', {
    timeZone: DISPLAY_TIME_ZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export function formatTime(value) {
  const date = parseDate(value);
  if (!date) return value || '';

  return date.toLocaleTimeString('uk-UA', {
    timeZone: DISPLAY_TIME_ZONE,
    hour: '2-digit',
    minute: '2-digit',
  });
}

export function getDisplayHour(value) {
  const date = parseDate(value);
  if (!date) return null;

  const hour = new Intl.DateTimeFormat('uk-UA', {
    timeZone: DISPLAY_TIME_ZONE,
    hour: '2-digit',
    hourCycle: 'h23',
  }).format(date);

  return Number.parseInt(hour, 10);
}

export function buildPhotoUrl(path) {
  if (!path) return '';
  if (path.startsWith('http')) return path;
  let clean = path.replace(/^\//, '');
  if (clean.startsWith('public/')) {
    clean = clean.replace(/^public\//, '');
  }
  const urlPath = clean.startsWith('storage/') ? `/${clean}` : `/storage/${clean}`;
  return urlPath;
}
