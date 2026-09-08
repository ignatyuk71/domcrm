import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import axios from 'axios';
import OrderShowPage from './OrderShowPage.vue';
import OrderDetails from '@/crm/components/orders/list/OrderDetails.vue';
import { getOrder } from '@/crm/api/orders';
import { fetchStatuses } from '@/crm/api/statuses';
import { fetchTags } from '@/crm/api/tags';

vi.mock('@/crm/api/orders', () => ({
  getOrder: vi.fn(),
  updateOrderTags: vi.fn(),
  updateOrderStatus: vi.fn(),
  updateOrderComment: vi.fn(),
}));
vi.mock('@/crm/api/statuses', () => ({ fetchStatuses: vi.fn() }));
vi.mock('@/crm/api/tags', () => ({ fetchTags: vi.fn() }));

let wrapper;

beforeEach(() => {
  vi.clearAllMocks();
  fetchStatuses.mockResolvedValue({ data: { data: [] } });
  fetchTags.mockResolvedValue({ data: { data: [] } });
  getOrder.mockResolvedValue({
    data: {
      data: {
        id: 5980,
        status: 'delivered',
        status_ref: { id: 6, code: 'delivered', name: 'У відділенні', color: '#f59e0b', icon: 'bi-geo-alt' },
        status_changed_at: '2026-09-07T09:00:00Z',
        delivery: {
          ttn: '20450000000001',
          delivery_status_label: 'Прибув у відділення',
          delivery_status_code: 'at_warehouse',
          delivery_status_color: '#f59e0b',
          delivery_status_icon: 'bi-building',
          delivery_status_description: 'Посилка у відділенні',
          active_warehouse_status: { entered_at: '2026-09-07T08:00:00Z' },
        },
      },
    },
  });
});

afterEach(() => {
  wrapper?.unmount();
  wrapper = null;
  vi.restoreAllMocks();
});

describe('оновлення доставки у перегляді замовлення', () => {
  it('оновлює статус замовлення разом із доставкою у картці та деталях без перезавантаження', async () => {
    const post = vi.spyOn(axios, 'post').mockResolvedValue({
      data: {
        delivery_status_label: 'Отримано',
        delivery_status_code: 'received',
        delivery_status_color: '#16a34a',
        delivery_status_icon: 'bi-check-circle-fill',
        delivery_status_updated_at: '2026-09-08T12:00:00Z',
        delivery_status_description: null,
        last_tracked_at: '2026-09-08T12:00:00Z',
        warehouse_entered_at: null,
        order_status: {
          id: 11,
          code: 'delivered_paid',
          name: 'Завершено',
          color: '#16a34a',
          icon: 'bi-check-all',
          status_changed_at: '2026-09-08T12:00:00Z',
        },
      },
    });
    wrapper = shallowMount(OrderShowPage, { props: { initialOrderId: 5980 } });
    await flushPromises();
    const details = wrapper.findComponent(OrderDetails);
    expect(details.props('order').status_key).toBe('delivered');
    expect(details.props('order').delivery_status_description).toBe('Посилка у відділенні');
    expect(details.props('order').delivery_status_entered_at).toBe('2026-09-07T08:00:00Z');

    details.vm.$emit('refresh-delivery');
    await flushPromises();

    expect(post).toHaveBeenCalledTimes(1);
    expect(post.mock.calls[0][0]).toBe('/orders/5980/track-delivery');
    expect(details.props('order')).toMatchObject({
      delivery_status: 'Отримано',
      delivery_status_code: 'received',
      delivery_status_color: '#16a34a',
      delivery_status_icon: 'bi-check-circle-fill',
      delivery_status_updated_at: '2026-09-08T12:00:00Z',
      delivery_status_description: '',
      last_tracked_at: '2026-09-08T12:00:00Z',
      delivery_status_entered_at: '',
      status_id: 11,
      status_key: 'delivered_paid',
      status: 'Завершено',
      status_color: '#16a34a',
      status_icon: 'bi-check-all',
      status_changed_at: '2026-09-08T12:00:00Z',
      refreshingDelivery: false,
    });
    expect(wrapper.find('.status-chip').text()).toContain('Завершено');
    expect(getOrder).toHaveBeenCalledTimes(1);
  });
});
