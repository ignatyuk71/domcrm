import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import OrderPaymentStatusPicker from './OrderPaymentStatusPicker.vue';
import Toast from '@/crm/components/ui/Toast.vue';
import { updateOrderPaymentStatus } from '@/crm/api/orders';

vi.mock('@/crm/api/orders', () => ({ updateOrderPaymentStatus: vi.fn() }));

let wrapper;
const order = () => reactive({
  id: 42, order_number: '42', status_key: 'shipped',
  payment_status: 'unpaid', payment_status_label: 'Не оплачено',
  total: 585, paid_amount: 0, amount_due: 585, currency: 'UAH',
  payment: { method: 'card', paid_amount: null, currency: 'UAH' },
});
const open = (value = order(), props = {}) => {
  wrapper = mount(OrderPaymentStatusPicker, { props: { order: value, ...props }, global: { stubs: { teleport: true, transition: false } } });
  return value;
};

beforeEach(() => vi.clearAllMocks());
afterEach(() => wrapper?.unmount());

describe('зміна статусу оплати з бейджа', () => {
  it('показує наявні статуси й не зберігає їх під час завантаження', async () => {
    open();
    expect(wrapper.findAll('option').map((option) => option.text())).toEqual(['Не оплачено', 'Передоплата', 'Оплачено', 'Повернення']);
    await wrapper.get('select').setValue('unpaid');
    expect(updateOrderPaymentStatus).not.toHaveBeenCalled();
    expect(wrapper.findComponent(Toast).props('show')).toBe(false);
  });

  it('змінює статус і підсумки лише після підтвердження сервера та блокує повторний запит', async () => {
    let finish;
    updateOrderPaymentStatus.mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
    const value = open();
    await wrapper.get('select').setValue('paid');
    expect(wrapper.get('select').attributes('disabled')).toBeDefined();
    expect(value.payment_status).toBe('unpaid');
    expect(value.amount_due).toBe(585);
    await wrapper.get('select').trigger('change');
    expect(updateOrderPaymentStatus).toHaveBeenCalledExactlyOnceWith(42, 'paid');
    finish({ data: { data: { payment_status: 'paid', payment: value.payment } } });
    await flushPromises();
    expect(value).toMatchObject({ payment_status: 'paid', payment_status_label: 'Оплачено', paid_amount: 585, amount_due: 0, status_key: 'shipped' });
    expect(wrapper.get('select').element.value).toBe('paid');
    expect(wrapper.findComponent(Toast).props()).toMatchObject({ show: true, type: 'success' });
  });

  it('після скасування ручної позначки оплати прибирає стару обчислену суму', async () => {
    const value = order();
    Object.assign(value, { payment_status: 'paid', paid_amount: 585, amount_due: 0 });
    open(value);
    updateOrderPaymentStatus.mockResolvedValue({ data: { data: { payment_status: 'unpaid', payment: value.payment } } });
    await wrapper.get('select').setValue('unpaid');
    await flushPromises();
    expect(value).toMatchObject({ payment_status: 'unpaid', paid_amount: 0, amount_due: 585 });
  });

  it('залишає серверний статус і контекст помилки, зберігаючи вибір для повтору', async () => {
    const value = open();
    updateOrderPaymentStatus.mockRejectedValueOnce({ response: { data: { errors: { payment_status: ['Збереження тимчасово недоступне.'] } } } });
    await wrapper.get('select').setValue('paid');
    await flushPromises();
    expect(value.payment_status).toBe('unpaid');
    expect(wrapper.get('select').element.value).toBe('unpaid');
    expect(wrapper.get('select').attributes('aria-invalid')).toBe('true');
    const toast = wrapper.findComponent(Toast);
    expect(toast.props()).toMatchObject({ show: true, type: 'error', actionLabel: 'Повторити' });
    expect(toast.props('messages')).toContain('Збереження тимчасово недоступне.');
    expect(toast.props('messages').join(' ')).toContain('Оплачено');
    updateOrderPaymentStatus.mockResolvedValueOnce({ data: { data: { payment_status: 'paid', payment: value.payment } } });
    toast.vm.$emit('action');
    await flushPromises();
    expect(updateOrderPaymentStatus).toHaveBeenLastCalledWith(42, 'paid');
    expect(value.payment_status).toBe('paid');
    expect(wrapper.get('select').attributes('aria-invalid')).toBeUndefined();
  });

  it('не вважає неповну відповідь успішним збереженням', async () => {
    const value = open();
    updateOrderPaymentStatus.mockResolvedValue({ data: { data: { payment_status: 'paid' } } });
    await wrapper.get('select').setValue('paid');
    await flushPromises();
    expect(value.payment_status).toBe('unpaid');
    expect(wrapper.findComponent(Toast).props('type')).toBe('error');
  });

  it('оновлює актуальний об’єкт замовлення, якщо список перезавантажився під час збереження', async () => {
    let finish;
    updateOrderPaymentStatus.mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
    open();
    await wrapper.get('select').setValue('paid');
    const refreshed = order();
    await wrapper.setProps({ order: refreshed });
    finish({ data: { data: { payment_status: 'paid', payment: refreshed.payment } } });
    await flushPromises();
    expect(refreshed).toMatchObject({ payment_status: 'paid', amount_due: 0 });
  });
});

describe('зміна статусу оплати кнопками', () => {
  it('показує всі чотири статуси з поточним вибором і не надсилає повторний вибір', async () => {
    const value = order();
    value.payment_status = 'prepaid';
    open(value, { variant: 'buttons' });
    expect(wrapper.find('select').exists()).toBe(false);
    expect(wrapper.get('[role="group"]').attributes('aria-label')).toBe('Статус оплати');
    expect(wrapper.findAll('.payment-status-button').map((button) => button.text())).toEqual(['Не оплачено', 'Передоплата', 'Оплачено', 'Повернення']);
    expect(wrapper.get('[data-status="prepayment"]').attributes('aria-pressed')).toBe('true');
    expect(wrapper.get('[data-status="unpaid"]').attributes('aria-pressed')).toBe('false');
    await wrapper.get('[data-status="prepayment"]').trigger('click');
    expect(updateOrderPaymentStatus).not.toHaveBeenCalled();
    expect(wrapper.findComponent(Toast).props('show')).toBe(false);
  });

  it('блокує кнопки та показує індикатор лише на запитаному статусі до підтвердження', async () => {
    let finish;
    updateOrderPaymentStatus.mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
    const value = open(order(), { variant: 'buttons' });
    await wrapper.get('[data-status="paid"]').trigger('click');
    expect(updateOrderPaymentStatus).toHaveBeenCalledExactlyOnceWith(42, 'paid');
    expect(wrapper.findAll('.payment-status-button').every((button) => button.attributes('disabled') !== undefined)).toBe(true);
    expect(wrapper.findAll('.payment-status-button .spinner-border')).toHaveLength(1);
    expect(wrapper.get('[data-status="paid"] .spinner-border').attributes('aria-label')).toBe('Збереження статусу оплати');
    expect(wrapper.get('[data-status="unpaid"]').attributes('aria-pressed')).toBe('true');
    expect(value.payment_status).toBe('unpaid');

    finish({ data: { data: { payment_status: 'paid', payment: value.payment } } });
    await flushPromises();
    expect(value).toMatchObject({ payment_status: 'paid', paid_amount: 585, amount_due: 0 });
    expect(wrapper.get('[data-status="paid"]').attributes('aria-pressed')).toBe('true');
    expect(wrapper.get('[data-status="paid"]').classes()).toContain('is-active');
    expect(wrapper.findAll('.payment-status-button').every((button) => button.attributes('disabled') === undefined)).toBe(true);
    expect(wrapper.find('.payment-status-button .spinner-border').exists()).toBe(false);
  });

  it('зберігає попередній вибір після помилки та повторює запит із повідомлення', async () => {
    const value = open(order(), { variant: 'buttons' });
    updateOrderPaymentStatus.mockRejectedValueOnce({ response: { data: { message: 'Спробуйте пізніше.' } } });
    await wrapper.get('[data-status="refund"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-status="unpaid"]').attributes('aria-pressed')).toBe('true');
    expect(wrapper.get('[data-status="refund"]').attributes('aria-pressed')).toBe('false');
    expect(wrapper.get('[role="group"]').attributes('aria-invalid')).toBe('true');
    const toast = wrapper.findComponent(Toast);
    expect(toast.props()).toMatchObject({ type: 'error', actionLabel: 'Повторити' });
    expect(toast.props('messages').join(' ')).toContain('Повернення');
    expect(toast.props('messages')).toContain('Спробуйте пізніше.');

    updateOrderPaymentStatus.mockResolvedValueOnce({ data: { data: { payment_status: 'refund', payment: value.payment } } });
    toast.vm.$emit('action');
    await flushPromises();
    expect(updateOrderPaymentStatus).toHaveBeenLastCalledWith(42, 'refund');
    expect(wrapper.get('[data-status="refund"]').attributes('aria-pressed')).toBe('true');
    expect(wrapper.get('[role="group"]').attributes('aria-invalid')).toBeUndefined();
  });
});
