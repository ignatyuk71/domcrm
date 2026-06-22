// Чисті утиліти доставки — винесено з компонентів, щоб не дублювати й покрити тестами.

// У назві поштоматів Нова Пошта містить слово "Поштомат".
export function isPostomat(name) {
  return String(name || '').includes('Поштомат');
}

// Фільтр складів за типом доставки: поштомати окремо від відділень.
export function filterWarehouses(warehouses, deliveryType) {
  const wantPostomat = deliveryType === 'postomat';
  return (warehouses || []).filter((wh) => (wantPostomat ? isPostomat(wh.name) : !isPostomat(wh.name)));
}

// Валідація вибору доставки ДО збереження: текст вписано, але не обрано зі списку (ref порожній).
export function validateDelivery(delivery) {
  const d = delivery || {};
  const errs = {};
  if ((d.city_name || '').trim() && !d.city_ref) {
    errs.city_name = 'Оберіть місто зі списку підказок.';
  }
  if (d.delivery_type !== 'courier' && (d.warehouse_name || '').trim() && !d.warehouse_ref) {
    errs.warehouse_name = 'Оберіть відділення або поштомат зі списку.';
  }
  return errs;
}

// Перетворення 422-помилок Laravel (delivery.warehouse_name → warehouse_name).
export function mapServerDeliveryErrors(errors) {
  return Object.fromEntries(
    Object.entries(errors || {})
      .filter(([key]) => key.startsWith('delivery.'))
      .map(([key, val]) => [key.replace('delivery.', ''), Array.isArray(val) ? val[0] : val]),
  );
}
