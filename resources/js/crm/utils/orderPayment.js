// Окремий підсумок оплати: фіскальні суми й передоплата зберігають власне значення.
export function paymentSummary(order, total = order.total) {
  const payment = (Array.isArray(order.payment) ? order.payment[0] : order.payment) || {};
  const method = payment.method || order.payment_method || '';
  const provider = String(payment.provider || order.payment_provider || '').toLowerCase();
  const labels = { cod: 'Накладений платіж', card: 'Оплата на рахунок', transfer: 'Оплата на рахунок', cashless: 'Оплата на рахунок', prepay: 'Часткова передоплата', cash: 'Готівка' };
  const amount = Math.max(0, Number(total) || 0);
  const recorded = payment.paid_amount ?? order.paid_amount;
  const prepay = Number(order.prepay_amount ?? payment.prepay_amount ?? payment.prepayment ?? 0) || 0;
  const paid = order.payment_status === 'refund' ? 0 : Math.max(0,
    recorded != null ? Number(recorded) || 0 : order.payment_status === 'paid' ? amount : prepay,
  );
  return {
    payment_method: method,
    payment_provider: provider,
    payment_method_label: provider === 'wayforpay' || method === 'wayforpay'
      ? 'Карткою онлайн · WayForPay' : labels[method] || method || '—',
    paid_amount: paid,
    amount_due: Math.max(0, Math.round((amount - paid) * 100) / 100),
    payment_transaction_id: payment.transaction_id || '',
    payment_paid_at: payment.paid_at || '',
  };
}
