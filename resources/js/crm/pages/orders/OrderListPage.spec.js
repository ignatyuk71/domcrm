import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import axios from 'axios';
import OrderListPage from './OrderListPage.vue';
import OrdersTable from '@/crm/components/orders/list/OrdersTable.vue';
import { listOrders } from '@/crm/api/orders';
import { fetchStatuses } from '@/crm/api/statuses';

vi.mock('@/crm/api/orders', () => ({
  listOrders: vi.fn(),
  deleteOrder: vi.fn(),
  updateOrderTags: vi.fn(),
  updateOrderStatus: vi.fn(),
  updateOrderComment: vi.fn(),
}));
vi.mock('@/crm/api/statuses', () => ({ fetchStatuses: vi.fn() }));

let wrapper;

beforeEach(() => {
  vi.clearAllMocks();
  fetchStatuses.mockResolvedValue({ data: { data: [] } });
  listOrders.mockResolvedValue({
    data: {
      data: [{
        id: 5980,
        status: 'shipped',
        status_ref: { id: 5, code: 'shipped', name: 'Відправлено', color: '#0ea5e9', icon: 'bi-truck' },
        status_changed_at: '2026-09-07T09:00:00Z',
        delivery: {
          ttn: '20450000000001',
          delivery_status_label: 'У дорозі',
          delivery_status_code: 'in_transit',
          delivery_status_color: '#0ea5e9',
          delivery_status_icon: 'bi-truck',
          delivery_status_description: 'Попередній опис перевізника',
          last_tracked_at: '2026-09-07T09:00:00Z',
        },
      }],
    },
  });
});

afterEach(() => {
  wrapper?.unmount();
  wrapper = null;
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('оновлення доставки у списку замовлень', () => {
  it('показує галочку в рядку лише після успішного копіювання та не розгортає замовлення', async () => {
    let finishCopy;
    const writeText = vi.fn(() => new Promise((resolve) => { finishCopy = resolve; }));
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    wrapper = shallowMount(OrderListPage, { global: { stubs: { OrdersTable: false } } });
    await flushPromises();

    const button = wrapper.get('.ttn-row');
    await button.trigger('click');
    expect(writeText).toHaveBeenCalledWith('20450000000001');
    expect(button.find('.bi-check-lg').exists()).toBe(false);
    expect(wrapper.findComponent(OrdersTable).props('expandedRows').size).toBe(0);

    finishCopy();
    await flushPromises();
    expect(button.find('.ttn-copy-icon.is-copied .bi-check-lg').exists()).toBe(true);
    expect(button.attributes('title')).toBe('ТТН скопійовано');
    expect(button.find('.bi-copy').exists()).toBe(false);
    expect(listOrders).toHaveBeenCalledTimes(1);
  });

  it('передає таблиці нові статуси, колір та іконку без повторного завантаження списку', async () => {
    const post = vi.spyOn(axios, 'post').mockResolvedValue({
      data: {
        delivery_status_label: 'Прибув у відділення',
        delivery_status_code: 'at_warehouse',
        delivery_status_color: '#f59e0b',
        delivery_status_icon: 'bi-building',
        delivery_status_updated_at: '2026-09-08T12:00:00Z',
        delivery_status_description: 'Посилка готова до видачі',
        last_tracked_at: '2026-09-08T12:00:00Z',
        warehouse_entered_at: '2026-09-08T10:00:00Z',
        order_status: {
          id: 6,
          code: 'delivered',
          name: 'У відділенні',
          color: '#f59e0b',
          icon: 'bi-geo-alt',
          status_changed_at: '2026-09-08T12:00:00Z',
        },
      },
    });
    wrapper = shallowMount(OrderListPage);
    await flushPromises();
    const table = wrapper.findComponent(OrdersTable);
    expect(table.props('orders')[0].delivery_status_color).toBe('#0ea5e9');
    expect(table.props('orders')[0].delivery_status_description).toBe('Попередній опис перевізника');
    expect(table.props('orders')[0].last_tracked_at).toBe('2026-09-07T09:00:00Z');

    table.vm.$emit('refresh-delivery', table.props('orders')[0]);
    await flushPromises();

    expect(post).toHaveBeenCalledTimes(1);
    expect(post.mock.calls[0][0]).toBe('/orders/5980/track-delivery');
    expect(table.props('orders')[0]).toMatchObject({
      delivery_status: 'Прибув у відділення',
      delivery_status_code: 'at_warehouse',
      delivery_status_color: '#f59e0b',
      delivery_status_icon: 'bi-building',
      delivery_status_updated_at: '2026-09-08T12:00:00Z',
      delivery_status_description: 'Посилка готова до видачі',
      last_tracked_at: '2026-09-08T12:00:00Z',
      delivery_status_entered_at: '2026-09-08T10:00:00Z',
      status_id: 6,
      status_key: 'delivered',
      status: 'У відділенні',
      status_color: '#f59e0b',
      status_icon: 'bi-geo-alt',
      status_changed_at: '2026-09-08T12:00:00Z',
      refreshingDelivery: false,
    });
    expect(listOrders).toHaveBeenCalledTimes(1);
  });
});
