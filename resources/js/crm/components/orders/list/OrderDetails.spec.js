import { expect, it } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import OrderDetails from './OrderDetails.vue';
import FiscalBlock from './FiscalBlock.vue';

it('відображає сплачену суму окремо й залишає фіскальні параметри незмінними', () => {
  const wrapper = shallowMount(OrderDetails, { props: { order: {
    id: 6527, order_number: '6527', external_id: '4987', total: 5, currency: 'UAH',
    items: [], tags: [], payment_status: 'paid', payment_status_label: 'Оплачено',
    payment_method_label: 'Карткою онлайн · WayForPay', paid_amount: 5, prepay_amount: 0,
    payment_transaction_id: 'txn-test',
  } } });
  expect(wrapper.text()).toContain('На сайті: #4987');
  expect(wrapper.text()).toContain('Карткою онлайн · WayForPay');
  expect(wrapper.get('.calc-group').text()).toContain('Сплачено');
  expect(wrapper.get('.total-box').text()).toContain('0,00');
  expect(wrapper.findComponent(FiscalBlock).props()).toMatchObject({ prepayAmount: 0, totalAmount: 5 });
  wrapper.unmount();
});

it.each(['new', 'in_process', 'returned', 'cancelled', 'unknown', undefined])('у деталях блокує створення ТТН для %s', async (code) => {
  const wrapper = shallowMount(OrderDetails, { props: { order: {
    id: 5980, status_key: code, status: 'В обробці', items: [], tags: [],
  } } });
  const button = wrapper.get('.btn-create-ttn');
  expect(button.attributes('aria-disabled')).toBe('true');
  expect(button.attributes('disabled')).toBeUndefined();
  expect(button.classes()).toContain('ttn-unavailable');
  await button.trigger('click');
  expect(wrapper.emitted('ttn-blocked')).toHaveLength(1);
  expect(wrapper.emitted('generate-ttn')).toBeUndefined();
  wrapper.unmount();
});

it.each(['confirmed', 'reserved', 'packing', 'packed', 'shipped', 'delivered', 'delivered_paid'])('у деталях дозволяє створення ТТН для %s', async (code) => {
  const wrapper = shallowMount(OrderDetails, { props: { order: {
    id: 5980, status_key: code, status: 'Підтверджено', items: [], tags: [],
  } } });
  expect(wrapper.get('.btn-create-ttn').attributes('aria-disabled')).toBe('false');
  await wrapper.get('.btn-create-ttn').trigger('click');
  expect(wrapper.emitted('generate-ttn')).toHaveLength(1);
  expect(wrapper.emitted('ttn-blocked')).toBeUndefined();
  wrapper.unmount();
});

it('зберігає копіювання, друк та анулювання вже наявної ТТН у статусі Новий', async () => {
  const wrapper = shallowMount(OrderDetails, { props: { order: {
    id: 5980, status_key: 'new', status: 'Новий', items: [], tags: [], ttn: '20450000000001',
  } } });
  await wrapper.get('.btn-copy').trigger('click');
  const actions = wrapper.findAll('.btn-action-light');
  await actions[0].trigger('click');
  await actions[1].trigger('click');
  expect(wrapper.emitted('copy-ttn')).toHaveLength(1);
  expect(wrapper.emitted('print-ttn')).toHaveLength(1);
  expect(wrapper.emitted('cancel-ttn')).toHaveLength(1);
  expect(wrapper.find('.btn-create-ttn').exists()).toBe(false);
  wrapper.unmount();
});
