import { describe, it, expect, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('@/crm/api/customers', () => ({
  getCustomer: vi.fn(),
}));

import { getCustomer } from '@/crm/api/customers';
import CustomerOrderHistory from './CustomerOrderHistory.vue';

describe('CustomerOrderHistory', () => {
  it('рендерить замовлення зі шляху data.data.recent_orders (баг із порожньою історією)', async () => {
    getCustomer.mockResolvedValue({
      data: {
        data: {
          recent_orders: [
            { id: 1, order_number: '1001', items_sum_total: 500, currency: 'UAH', items: [] },
            { id: 2, order_number: '1002', items_sum_total: 300, currency: 'UAH', items: [] },
          ],
        },
      },
    });

    const wrapper = mount(CustomerOrderHistory, { props: { customerId: 42 } });
    await flushPromises();

    expect(wrapper.findAll('.order-card')).toHaveLength(2);
  });

  it('показує порожній стан, коли замовлень немає', async () => {
    getCustomer.mockResolvedValue({ data: { data: { recent_orders: [] } } });

    const wrapper = mount(CustomerOrderHistory, { props: { customerId: 42 } });
    await flushPromises();

    expect(wrapper.findAll('.order-card')).toHaveLength(0);
  });
});
