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
