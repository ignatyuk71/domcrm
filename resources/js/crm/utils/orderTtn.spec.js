import { describe, expect, it } from 'vitest';
import { canCreateTtn, ttnErrorMessages, ttnStatusWarning } from './orderTtn';

describe('доступ до створення ТТН за статусом', () => {
  it.each(['confirmed', 'reserved', 'packing', 'packed', 'shipped', 'delivered', 'delivered_paid'])
    ('дозволяє створення у статусі %s', (status_key) => {
      expect(canCreateTtn({ status_key })).toBe(true);
    });

  it.each(['new', 'in_process', 'returned', 'cancelled', 'canceled', 'custom_status', '', null, undefined])
    ('блокує створення у статусі %s', (status_key) => {
      expect(canCreateTtn({ status_key })).toBe(false);
    });

  it('не визначає доступ за ID, назвою або відсутнім замовленням', () => {
    expect(canCreateTtn({ status_id: 999, status: 'Підтверджено' })).toBe(false);
    expect(canCreateTtn(null)).toBe(false);
    expect(canCreateTtn()).toBe(false);
  });
});

describe('повідомлення про недоступність ТТН', () => {
  it('називає поточний статус і потрібну дію погодженим текстом', () => {
    expect(ttnStatusWarning({ status: 'В обробці' })).toBe(
      'Неможливо створити ТТН у статусі «В обробці». Спочатку змініть статус замовлення на «Підтверджено».',
    );
  });

  it.each([undefined, null, {}, { status: '' }, { status: '  ' }, { status: 3 }])
    ('чесно позначає невизначений статус для %j', (order) => {
      expect(ttnStatusWarning(order)).toBe(
        'Неможливо створити ТТН у статусі «Не визначено». Спочатку змініть статус замовлення на «Підтверджено».',
      );
    });
});

describe('збереження серверних помилок створення ТТН', () => {
  it('зберігає дослівний текст повідомлення та вкладених помилок без дублів', () => {
    const message = 'Неможливо створити ТТН у статусі «Новий».';
    const address = '  Не вказано номер будинку.  ';
    const phone = 'Телефон отримувача некоректний: +380.';
    expect(ttnErrorMessages({
      message,
      errors: { address: [address, message], recipient: { phone: [phone] }, empty: ['', '  ', null, 422] },
    })).toEqual([message, address, phone]);
  });

  it('повертає повідомлення навіть без загального message', () => {
    expect(ttnErrorMessages({ errors: ['Помилка Нової пошти.'] })).toEqual(['Помилка Нової пошти.']);
  });

  it.each([undefined, null, {}, { message: '', errors: [] }])
    ('дає зрозумілий fallback, якщо сервер не надав тексту: %j', (payload) => {
      expect(ttnErrorMessages(payload)).toEqual(['Не вдалося створити ТТН. Спробуйте ще раз.']);
    });

  it('використовує переданий fallback для помилки запиту', () => {
    expect(ttnErrorMessages(undefined, 'З’єднання перервано.')).toEqual(['З’єднання перервано.']);
  });
});
