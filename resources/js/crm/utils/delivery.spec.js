import { describe, it, expect } from 'vitest';
import { isPostomat, filterWarehouses, validateDelivery, mapServerDeliveryErrors } from './delivery.js';

describe('isPostomat', () => {
  it('розпізнає поштомат за назвою', () => {
    expect(isPostomat('Поштомат №123')).toBe(true);
    expect(isPostomat('Відділення №5')).toBe(false);
    expect(isPostomat(null)).toBe(false);
  });
});

describe('filterWarehouses', () => {
  const list = [
    { ref: '1', name: 'Відділення №1' },
    { ref: '2', name: 'Поштомат №2' },
    { ref: '3', name: 'Відділення №3' },
  ];

  it('для поштомата лишає лише поштомати', () => {
    const res = filterWarehouses(list, 'postomat');
    expect(res.map((w) => w.ref)).toEqual(['2']);
  });

  it('для відділення лишає лише відділення', () => {
    const res = filterWarehouses(list, 'warehouse');
    expect(res.map((w) => w.ref)).toEqual(['1', '3']);
  });

  it('порожній список не падає', () => {
    expect(filterWarehouses(null, 'warehouse')).toEqual([]);
  });
});

describe('validateDelivery', () => {
  it('помилка, коли місто вписане, але не обране зі списку', () => {
    const errs = validateDelivery({ city_name: 'Київ', city_ref: '' });
    expect(errs.city_name).toBeTruthy();
  });

  it('немає помилки, коли місто обране (є ref)', () => {
    const errs = validateDelivery({ city_name: 'Київ', city_ref: 'ref-1' });
    expect(errs.city_name).toBeUndefined();
  });

  it('помилка, коли відділення вписане без вибору (не курʼєр)', () => {
    const errs = validateDelivery({ delivery_type: 'warehouse', warehouse_name: 'Відділення №1', warehouse_ref: '' });
    expect(errs.warehouse_name).toBeTruthy();
  });

  it('для курʼєра відділення не вимагається', () => {
    const errs = validateDelivery({ delivery_type: 'courier', warehouse_name: 'щось', warehouse_ref: '' });
    expect(errs.warehouse_name).toBeUndefined();
  });

  it('порожня доставка (чернетка) — без помилок', () => {
    expect(validateDelivery({})).toEqual({});
  });
});

describe('mapServerDeliveryErrors', () => {
  it('знімає префікс delivery. і бере перше повідомлення', () => {
    const res = mapServerDeliveryErrors({
      'delivery.warehouse_name': ['Оберіть відділення зі списку.'],
      'delivery.city_name': ['Оберіть місто зі списку.'],
      'items': ['не чіпаємо це поле'],
    });
    expect(res).toEqual({
      warehouse_name: 'Оберіть відділення зі списку.',
      city_name: 'Оберіть місто зі списку.',
    });
  });

  it('порожній вхід → порожній обʼєкт', () => {
    expect(mapServerDeliveryErrors(null)).toEqual({});
  });
});
