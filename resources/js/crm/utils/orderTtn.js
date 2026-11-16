const allowedStatusCodes = new Set([
  'confirmed', 'reserved', 'packing', 'packed', 'shipped', 'delivered', 'delivered_paid',
]);

// Дозволяємо лише відомі погоджені етапи; порядок та ID статусів не визначають доступ.
export function canCreateTtn(order) {
  return allowedStatusCodes.has(order?.status_key);
}

export function ttnStatusWarning(order) {
  const name = typeof order?.status === 'string' && order.status.trim() ? order.status.trim() : 'Не визначено';
  return `Неможливо створити ТТН у статусі «${name}». Спочатку змініть статус замовлення на «Підтверджено».`;
}

export function ttnErrorMessages(payload, fallback = 'Не вдалося створити ТТН. Спробуйте ще раз.') {
  const messages = [];
  const collect = (value) => {
    if (typeof value === 'string' && value.trim()) messages.push(value);
    else if (Array.isArray(value)) value.forEach(collect);
    else if (value && typeof value === 'object') Object.values(value).forEach(collect);
  };
  collect(payload?.message);
  collect(payload?.errors);
  return messages.length ? [...new Set(messages)] : [fallback];
}
