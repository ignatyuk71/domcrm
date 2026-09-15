import { describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('vue3-apexcharts', () => ({ default: { name: 'ApexChart', props: ['options', 'series'], template: '<div class="chart-stub" />' } }));
vi.mock('@/crm/services/salesAnalyticsApi', () => ({ fetchSalesAnalytics: vi.fn() }));

import ShippingReturnsOverview from './ShippingReturnsOverview.vue';
import SalesAnalyticsPage from './SalesAnalyticsPage.vue';
import { fetchSalesAnalytics } from '@/crm/services/salesAnalyticsApi';

const returns = () => ({
  currency: 'UAH', cost_basis: 'fixed_estimate', estimated_cost_per_return: 100,
  totals: { returned: 3, received: 7, completed: 10, return_rate: 30, estimated_cost: 300, average_estimated_cost: 100 },
  trend: { dates: ['2026-09-01', '2026-09-02', '2026-09-03'], returned: [1, 2, null], estimated_cost: [100, 200, null] },
  quality: { undated_shipments: 0, missing_tracking_orders: 0 },
});

describe('Повернення посилок', () => {
  it('показує один графік та окремий блок із цифрами, формулою й позначкою оцінки', () => {
    const wrapper = mount(ShippingReturnsOverview, { props: { data: returns() } });
    expect(wrapper.findAll('.shipping-returns > article')).toHaveLength(2);
    expect(wrapper.findAllComponents({ name: 'ApexChart' })).toHaveLength(1);
    expect(wrapper.find('.returns-metrics').text()).toContain('30%');
    expect(wrapper.find('.estimated-total').text().replace(/\s/g, '')).toContain('≈300');
    expect(wrapper.find('.estimated-total').text()).toContain('3 ×');
    expect(wrapper.text()).toContain('Орієнтовні витрати');
    expect(wrapper.text()).toContain('не фактичний тариф з API');
    expect(wrapper.text()).toContain('не віднімається від виручки Checkbox');
    expect(wrapper.find('.returns-warning').exists()).toBe(false);
    const chart = wrapper.findComponent({ name: 'ApexChart' });
    expect(chart.props('series')[0].data).toEqual([1, 2, null]);
    expect(chart.props('options').stroke.curve).toBe('monotoneCubic');
    expect(chart.props('options').tooltip.y.formatter(2, { dataPointIndex: 1 })).toContain('200');
    wrapper.unmount();
  });

  it('не вигадує відсоток без бази та пояснює неповні історичні дані', () => {
    const data = returns();
    Object.assign(data.totals, { returned: 0, received: 0, completed: 0, return_rate: null, estimated_cost: 0, average_estimated_cost: null });
    data.quality = { undated_shipments: 2, missing_tracking_orders: 1 };
    const wrapper = mount(ShippingReturnsOverview, { props: { data } });
    expect(wrapper.find('.returns-metrics').text()).toContain('—');
    expect(wrapper.find('.returns-metrics').text()).not.toContain('0%');
    expect(wrapper.find('.empty-note').exists()).toBe(true);
    expect(wrapper.find('.returns-warning').text()).toContain('без дати статусу — 2');
    expect(wrapper.find('.returns-warning').text()).toContain('без ТТН — 1');
    expect(wrapper.find('.average-cost').text()).toContain('—');
    wrapper.unmount();
  });

  it('оновлює блок разом зі спільними фільтрами та не залишає старі суми', async () => {
    window.history.replaceState({}, '', '/analytics?date_from=2026-09-01&date_to=2026-09-15&source_id=2');
    fetchSalesAnalytics.mockResolvedValue({ data: { fiscal: {}, shipping_returns: returns(), meta: { currency: 'UAH' }, filters: {} } });
    const wrapper = mount(SalesAnalyticsPage);
    await flushPromises();
    expect(wrapper.findComponent(ShippingReturnsOverview).props('data').totals.returned).toBe(3);
    expect(fetchSalesAnalytics).toHaveBeenLastCalledWith(expect.objectContaining({ fiscal_only: 1, source_id: '2' }));
    const empty = returns();
    empty.totals.returned = 0;
    empty.totals.estimated_cost = 0;
    fetchSalesAnalytics.mockResolvedValue({ data: { fiscal: {}, shipping_returns: empty, meta: {}, filters: {} } });
    await wrapper.find('.btn-refresh').trigger('click');
    await flushPromises();
    expect(wrapper.findComponent(ShippingReturnsOverview).props('data').totals.estimated_cost).toBe(0);
    wrapper.unmount();
  });
});
