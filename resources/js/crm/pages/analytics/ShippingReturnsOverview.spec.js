import { describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('vue3-apexcharts', () => ({ default: { name: 'ApexChart', props: ['options', 'series'], template: '<div class="chart-stub" />' } }));
vi.mock('@/crm/services/salesAnalyticsApi', () => ({ fetchSalesAnalytics: vi.fn() }));

import ShippingReturnsOverview from './ShippingReturnsOverview.vue';
import SalesAnalyticsPage from './SalesAnalyticsPage.vue';
import { fetchSalesAnalytics } from '@/crm/services/salesAnalyticsApi';

const returns = () => ({
  currency: 'UAH', cost_basis: 'api_with_fallback', cost_source: 'nova_poshta.DocumentCost', estimated_cost_per_return: 100,
  totals: { returned: 3, received: 7, completed: 10, return_rate: 30, priced: 2, estimated_shipments: 1, api_cost: 96.25, estimated_cost: 100, total_cost: 196.25, average_cost: 65.42 },
  trend: { dates: ['2026-09-01', '2026-09-02', '2026-09-03'], returned: [1, 2, null], total_cost: [96.25, 100, null], estimated_cost: [0, 100, null], estimated_shipments: [0, 1, null] },
  quality: { undated_shipments: 0, missing_tracking_orders: 0 },
});

describe('Повернення посилок', () => {
  it('відокремлює API-суми від оцінки та показує денні витрати у графіку', () => {
    const wrapper = mount(ShippingReturnsOverview, { props: { data: returns() } });
    expect(wrapper.findAll('.shipping-returns > article')).toHaveLength(2);
    expect(wrapper.findAllComponents({ name: 'ApexChart' })).toHaveLength(1);
    expect(wrapper.find('.returns-metrics').text()).toContain('30%');
    expect(wrapper.find('.estimated-total').text().replace(/\s/g, '')).toContain('≈196,25');
    expect(wrapper.find('.estimated-total').text()).toContain('Без ціни: 1 ×');
    expect(wrapper.find('.estimated-total').text()).toContain('96,25');
    expect(wrapper.text()).toContain('Частина витрат — оцінка');
    expect(wrapper.text()).toContain('Окрема зворотна накладна поки не врахована');
    expect(wrapper.text()).toContain('не віднімається від виручки Checkbox');
    expect(wrapper.find('.returns-warning').exists()).toBe(false);
    const chart = wrapper.findComponent({ name: 'ApexChart' });
    expect(chart.props('series')[0].data).toEqual([1, 2, null]);
    expect(chart.props('options').stroke.curve).toBe('monotoneCubic');
    const tooltip = chart.props('options').tooltip.y.formatter;
    expect(tooltip(2, { dataPointIndex: 1 })).toContain('≈ 100,00');
    expect(tooltip(2, { dataPointIndex: 1 })).toContain('без ціни: 1');
    expect(tooltip(1, { dataPointIndex: 0 })).toContain('96,25');
    expect(tooltip(1, { dataPointIndex: 0 })).not.toContain('≈');
    expect(tooltip(null, { dataPointIndex: 2 })).toBe('Немає даних');
    wrapper.unmount();
  });

  it('не позначає відомі API-ціни, зокрема нуль, як оцінку', async () => {
    const data = returns();
    Object.assign(data.totals, { priced: 3, estimated_shipments: 0, estimated_cost: 0, total_cost: 96.25, average_cost: 32.08 });
    data.trend.total_cost = [96.25, 0, null];
    data.trend.estimated_shipments = [0, 0, null];
    const wrapper = mount(ShippingReturnsOverview, { props: { data } });
    expect(wrapper.find('.estimate-badge').text()).toBe('Вартість за API НП');
    expect(wrapper.find('.estimated-total strong').text()).toContain('96,25');
    expect(wrapper.find('.estimated-total').text()).not.toContain('≈');
    expect(wrapper.find('.estimated-total').text()).not.toContain('Без ціни');
    expect(wrapper.find('.average-cost').text()).toContain('32,08');
    const tooltip = wrapper.findComponent({ name: 'ApexChart' }).props('options').tooltip.y.formatter;
    expect(tooltip(2, { dataPointIndex: 1 })).toContain('витрати 0,00');
    const zero = { ...data, totals: { ...data.totals, total_cost: 0, api_cost: 0, average_cost: 0 } };
    await wrapper.setProps({ data: zero });
    expect(wrapper.find('.estimated-total strong').text()).toContain('0,00');
    expect(wrapper.find('.average-cost').text()).not.toContain('≈');
    wrapper.unmount();
  });

  it('не вигадує відсоток без бази та пояснює неповні історичні дані', () => {
    const data = returns();
    Object.assign(data.totals, { returned: 0, received: 0, completed: 0, return_rate: null, priced: 0, estimated_shipments: 0, api_cost: 0, total_cost: 0, estimated_cost: 0, average_cost: null });
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
    empty.totals.total_cost = 0;
    fetchSalesAnalytics.mockResolvedValue({ data: { fiscal: {}, shipping_returns: empty, meta: {}, filters: {} } });
    await wrapper.find('.btn-refresh').trigger('click');
    await flushPromises();
    expect(wrapper.findComponent(ShippingReturnsOverview).props('data').totals.total_cost).toBe(0);
    wrapper.unmount();
  });
});
