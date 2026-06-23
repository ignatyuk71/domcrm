// Валідація замовлення ПЕРЕД збереженням (до вікна підтвердження).
// Підсвічуємо конкретні поля + збираємо повідомлення для toast.
import { validateDelivery } from './delivery.js';

// Клієнт: імʼя і телефон обовʼязкові; email НЕ обовʼязковий, але якщо введено — має містити «@».
export function validateCustomer(customer) {
  const c = customer || {};
  const errs = {};

  const name = `${c.first_name || ''} ${c.last_name || ''}`.trim();
  if (!name) {
    errs.first_name = 'Вкажіть імʼя клієнта.';
  }

  const phone = String(c.phone || '').trim();
  const digits = phone.replace(/\D/g, '');
  if (!phone) {
    errs.phone = 'Вкажіть номер телефону.';
  } else if (digits.length < 10) {
    errs.phone = 'Невірний формат номера телефону.';
  }

  const email = String(c.email || '').trim();
  if (email && !email.includes('@')) {
    errs.email = 'Email має містити «@» (або залиште поле порожнім).';
  }

  return errs;
}

// Кошик: має бути хоча б один товар.
export function validateItems(items) {
  return Array.isArray(items) && items.length > 0 ? '' : 'Додайте хоча б один товар у кошик.';
}

// Повна перевірка форми замовлення. Повертає помилки по блоках + плаский список повідомлень.
export function validateOrder(form) {
  const f = form || {};
  const customer = validateCustomer(f.customer);
  const delivery = validateDelivery(f.delivery);
  const items = validateItems(f.items);

  const messages = [
    ...Object.values(customer),
    ...Object.values(delivery),
    ...(items ? [items] : []),
  ];

  return { customer, delivery, items, messages };
}
