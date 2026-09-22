import { describe, expect, it } from 'vitest';
import { paymentSummary } from './orderPayment';

describe('підсумок оплати замовлення', () => {
  it('показує WayForPay та нульовий залишок для підтвердженої оплати', () => {
    expect(paymentSummary({ payment_status: 'paid', total: 5, payment: { method: 'card', provider: 'wayforpay', paid_amount: '5.00' } }))
      .toMatchObject({ payment_method_label: 'Карткою онлайн · WayForPay', paid_amount: 5, amount_due: 0 });
  });
  it('не вважає вибір WayForPay підтвердженням оплати', () => {
    expect(paymentSummary({ payment_status: 'unpaid', total: 5, payment: { method: 'card', provider: 'wayforpay' } }))
      .toMatchObject({ paid_amount: 0, amount_due: 5 });
  });
  it('зберігає старий спосіб на рахунок і розрахунок передоплати', () => {
    expect(paymentSummary({ total: 500, payment: { method: 'card' } }).payment_method_label).toBe('Оплата на рахунок');
    expect(paymentSummary({ total: 500, prepay_amount: 100 })).toMatchObject({ paid_amount: 100, amount_due: 400 });
  });
  it('враховує статус старих оплачених замовлень без окремої сплаченої суми', () => {
    expect(paymentSummary({ total: 5, payment_status: 'paid' })).toMatchObject({ paid_amount: 5, amount_due: 0 });
  });
  it('рахує залишок із часткової оплати без похибки копійок', () => {
    expect(paymentSummary({ total: 1.1, payment_status: 'prepayment', payment: { paid_amount: 0.3 } }).amount_due).toBe(0.8);
  });
});
