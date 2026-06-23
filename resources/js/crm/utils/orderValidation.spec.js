import { describe, it, expect } from 'vitest';
import { validateCustomer, validateItems, validateOrder } from './orderValidation.js';

describe('validateCustomer', () => {
  it('вимагає імʼя і телефон', () => {
    const e = validateCustomer({});
    expect(e.first_name).toBeTruthy();
    expect(e.phone).toBeTruthy();
  });

  it('повний валідний клієнт — без помилок', () => {
    expect(validateCustomer({ first_name: 'Іван', phone: '0961234567', email: 'a@b.com' })).toEqual({});
  });

  it('короткий номер — помилка формату', () => {
    expect(validateCustomer({ first_name: 'Іван', phone: '12345' }).phone).toBeTruthy();
  });

  it('email необовʼязковий (порожній — ок)', () => {
    expect(validateCustomer({ first_name: 'Іван', phone: '0961234567', email: '' }).email).toBeUndefined();
  });

  it('email без «@» — помилка', () => {
    expect(validateCustomer({ first_name: 'Іван', phone: '0961234567', email: 'bademail' }).email).toBeTruthy();
  });
});

describe('validateItems', () => {
  it('порожній кошик — помилка', () => {
    expect(validateItems([])).toBeTruthy();
    expect(validateItems(null)).toBeTruthy();
  });
  it('є товари — без помилки', () => {
    expect(validateItems([{ sku: 'x' }])).toBe('');
  });
});

describe('validateOrder', () => {
  it('збирає повідомлення по всіх блоках', () => {
    const res = validateOrder({
      customer: {},
      items: [],
      delivery: { city_name: 'Київ', city_ref: '' },
    });
    expect(res.messages.length).toBeGreaterThan(0);
    expect(res.customer.phone).toBeTruthy();
    expect(res.delivery.city_name).toBeTruthy();
    expect(res.items).toBeTruthy();
  });

  it('валідне замовлення — без повідомлень', () => {
    const res = validateOrder({
      customer: { first_name: 'Іван', phone: '0961234567' },
      items: [{ sku: 'x', qty: 1, price: 100 }],
      delivery: { delivery_type: 'warehouse', city_name: 'Київ', city_ref: 'c1', warehouse_name: 'Відділення №1', warehouse_ref: 'w1' },
    });
    expect(res.messages).toEqual([]);
  });
});
