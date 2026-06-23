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

// Валідація доставки ДО збереження. Доставка обовʼязкова: місто має бути ОБРАНЕ
// зі списку (є ref), а для відділення/поштомата — обране відділення; для курʼєра — вулиця.
export function validateDelivery(delivery) {
  const d = delivery || {};
  const errs = {};

  // Місто: має бути обране зі списку (city_ref). Розрізняємо «вписано, але не обрано» і «порожнє».
  if (!d.city_ref) {
    errs.city_name = (d.city_name || '').trim()
      ? 'Оберіть місто зі списку підказок.'
      : 'Вкажіть місто доставки.';
  }

  if (d.delivery_type === 'courier') {
    // Курʼєр: потрібна вулиця (мʼяко — достатньо введеного тексту, бо не всі вулиці є в базі НП).
    if (!(d.street_name || '').trim()) {
      errs.street_name = 'Вкажіть вулицю доставки.';
    }
  } else {
    // Відділення / Поштомат: має бути обране зі списку (warehouse_ref).
    if (!d.warehouse_ref) {
      errs.warehouse_name = (d.warehouse_name || '').trim()
        ? 'Оберіть відділення або поштомат зі списку.'
        : 'Оберіть відділення або поштомат.';
    }
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
