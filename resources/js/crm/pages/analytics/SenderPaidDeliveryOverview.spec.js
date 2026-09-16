import { describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
vi.mock('vue3-apexcharts', () => ({ default: { template: '<div />' } }));
vi.mock('@/crm/services/salesAnalyticsApi', () => ({ fetchSalesAnalytics: vi.fn() }));
import SenderPaidDeliveryOverview from './SenderPaidDeliveryOverview.vue';
import SalesAnalyticsPage from './SalesAnalyticsPage.vue';
import DateRangePicker from '@/crm/components/ui/DateRangePicker.vue';
import { fetchSalesAnalytics } from '@/crm/services/salesAnalyticsApi';

const data = () => ({
  totals: { known_cost: 96.25, sent: 2, shipments: 2, priced: 1, unknown_cost: 1 }, quality: {},
  rows: { current_page: 1, last_page: 2, total: 11, data: [
    { order_id: 12, order_number: '6072', order_url: '/orders/12/edit', ttn: '20451532364466', cost: 96.25, document_date: '2026-09-10 12:00:00', date_verified: true, payer_verified: true, is_sent: true, status: 'Отримано', checked_at: '2026-09-15 12:30:00' },
    { order_id: 13, order_number: '6073', order_url: '/orders/13/edit', ttn: '20451532364467', cost: null, is_sent: true },
  ] },
});

describe('Доставка за наш рахунок', () => {
  it('показує точні копійки з API, посилання та невідому суму без підстановки 100 грн', async () => {
    const wrapper = mount(SenderPaidDeliveryOverview, { props: { data: data() } });
    expect(wrapper.find('.sender-kpis').text().replace(/\s/g, '')).toContain('96,25');
    expect(wrapper.find('.sender-table a').attributes('href')).toBe('/orders/12/edit');
    expect(wrapper.find('.missing-cost').text()).toBe('Сума не отримана');
    expect(wrapper.text()).not.toContain('100 грн');
    await wrapper.findAll('.sender-pagination button')[1].trigger('click');
    expect(wrapper.emitted('page')).toEqual([[2]]);
    await wrapper.setProps({ loading: true });
    expect(wrapper.findAll('.sender-pagination button')[1].attributes('disabled')).toBeDefined();
    wrapper.unmount();
  });

  it('розрізняє нульову ціну НП і відсутню суму', async () => {
    const report = data();
    report.totals.known_cost = null;
    report.rows.data[0].cost = 0;
    const wrapper = mount(SenderPaidDeliveryOverview, { props: { data: report } });
    expect(wrapper.find('.sender-kpis strong').text()).toBe('Сума не отримана');
    expect(wrapper.findAll('.sender-table tbody tr')[0].text()).toContain('0,00');
    wrapper.unmount();
  });

  it('передає сторінку зі спільними фільтрами та скидає її при зміні періоду', async () => {
    window.history.replaceState({}, '', '/analytics?source_id=2');
    fetchSalesAnalytics.mockResolvedValue({ data: { fiscal: {}, sender_delivery: data(), meta: {}, filters: {} } });
    const wrapper = mount(SalesAnalyticsPage);
    await flushPromises();
    wrapper.findComponent(SenderPaidDeliveryOverview).vm.$emit('page', 2);
    await flushPromises();
    expect(fetchSalesAnalytics).toHaveBeenLastCalledWith(expect.objectContaining({ sender_delivery_page: 2, source_id: '2' }));
    wrapper.findComponent(DateRangePicker).vm.$emit('apply', { from: '2026-09-15', to: '2026-09-15' });
    await flushPromises();
    expect(fetchSalesAnalytics.mock.lastCall[0]).not.toHaveProperty('sender_delivery_page');
    wrapper.unmount();
  });
});
