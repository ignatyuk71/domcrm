import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import OrdersTable from './OrdersTable.vue';
import Toast from '@/crm/components/ui/Toast.vue';
import { fetchFiscalAvailability } from '@/crm/api/fiscal';

vi.mock('@/crm/api/fiscal', () => ({ fetchFiscalAvailability: vi.fn() }));
const opened = { available: true, shift_status: 'OPENED', code: null, message: '' };
const closed = { available: false, shift_status: 'CLOSED', code: 'fiscal_shift_closed', message: 'Каса закрита' };
const order = (id, extra = {}) => ({ id, client: 'Тест', status_key: 'shipped', total: 399, currency: 'UAH', items: [], tags: [], ...extra });
let wrapper;
beforeEach(() => { vi.useFakeTimers(); vi.clearAllMocks(); });
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.useRealTimers(); });

describe('віджет фіскалізації у таблиці', () => {
  it('блокує наявну quick-подію й показує одну підказку каси без окремих запитів на рядок', async () => {
    fetchFiscalAvailability.mockResolvedValue({ data: closed });
    wrapper = shallowMount(OrdersTable, { props: { orders: [order(1), order(2)], expandedRows: new Set() } });
    await flushPromises();
    const widgets = wrapper.findAll('.widget-empty');
    expect(widgets).toHaveLength(2);
    expect(wrapper.findAllComponents(Toast)).toHaveLength(1);
    expect(fetchFiscalAvailability).toHaveBeenCalledTimes(1);
    for (const widget of widgets) {
      expect(widget.attributes('aria-disabled')).toBe('true');
      expect(widget.attributes('title')).toBe('Каса закрита');
      await widget.trigger('click');
    }
    expect(wrapper.emitted('quick-fiscalize')).toBeUndefined();
    fetchFiscalAvailability.mockResolvedValue({ data: opened });
    window.dispatchEvent(new Event('focus'));
    await flushPromises();
    expect(widgets[0].attributes('aria-disabled')).toBe('false');
    await widgets[0].trigger('click');
    expect(wrapper.emitted('quick-fiscalize')).toHaveLength(1);
    expect(wrapper.emitted('quick-fiscalize')[0][0].id).toBe(1);
  });

  it('не блокує відкриття успішного чека, коли каса закрита', async () => {
    fetchFiscalAvailability.mockResolvedValue({ data: closed });
    wrapper = shallowMount(OrdersTable, { props: {
      orders: [order(1, { latestFiscalReceipt: { status: 'success', type: 'sell', check_link: 'https://check.example/one' } })],
      expandedRows: new Set(),
    } });
    await flushPromises();
    const link = wrapper.get('.fiscal-widget');
    expect(link.element.tagName).toBe('A');
    expect(link.attributes('href')).toBe('https://check.example/one');
    expect(link.attributes('aria-disabled')).toBeUndefined();
  });
});
