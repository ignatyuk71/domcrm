export const statusLabels = {
  new: 'Новий',
  confirmed: 'Підтверджено',
  pending: 'Очікує підтвердження',
  in_process: 'В обробці',
  packed: 'Упаковане',
  delivered: 'Доставлене',
  returned: 'Повернене',
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
  pending: '#fcd5b5',
  in_process: '#c7f2d4',
  in_progress: '#c7f2d4',
  confirmed: '#b2ecf7',
  packed: '#e4e6e9',
  shipped: '#dcd9ff',
  delivered: '#c6f1d4',
  cancelled: '#e4e6e9',
  canceled: '#e4e6e9',
  returned: '#ffd6d6',
};

export function getStatusClass(status) {
  const map = {
    new: 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
    in_process: 'bg-success-subtle text-success-emphasis border-success-subtle',
    confirmed: 'bg-info-subtle text-info-emphasis border-info-subtle',
    in_progress: 'bg-primary-subtle text-primary-emphasis border-primary-subtle',
    in_work: 'bg-primary-subtle text-primary-emphasis border-primary-subtle',
    pending: 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
    packed: 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle',
    delivered: 'bg-success-subtle text-success-emphasis border-success-subtle',
    completed: 'bg-success-subtle text-success-emphasis border-success-subtle',
    done: 'bg-success-subtle text-success-emphasis border-success-subtle',
    cancelled: 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle',
    canceled: 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle',
    shipped: 'bg-indigo-subtle text-indigo-emphasis border-indigo-subtle',
    returned: 'bg-danger-subtle text-danger-emphasis border-danger-subtle',
  };
  return map[status] || 'bg-light text-dark border-light-subtle';
}

function buildStatusStyle(color) {
  const normalized = String(color).trim();
  const hexMatch = normalized.match(/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/);

  if (hexMatch) {
    const hex = hexMatch[1];
    const fullHex = hex.length === 3 ? hex.split('').map((c) => c + c).join('') : hex;
    const base = `#${fullHex}`;

    return {
      color: base,
      backgroundColor: `${base}1F`,
      borderColor: `${base}40`,
    };
  }

  return {
    color: normalized,
    backgroundColor: normalized,
    borderColor: normalized,
  };
}

export function getStatusStyle(order) {
  if (order?.status_color) {
    return buildStatusStyle(order.status_color);
  }

  const color = statusColorMap[order?.status_key];
  if (!color) return {};

  return {
    backgroundColor: color,
    borderColor: color,
    color: '#0f172a',
  };
}

export function getStatusIcon(status) {
  const map = {
    new: 'bi-circle',
    confirmed: 'bi-check-circle',
    pending: 'bi-hourglass-split',
    packed: 'bi-box-seam',
    delivered: 'bi-bag-check',
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

export function formatDate(value) {
  if (!value) return '';
  try {
    return new Date(value).toLocaleString('uk-UA', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch {
    return value;
  }
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
