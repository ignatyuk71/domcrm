import { describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('vue3-apexcharts', () => ({ default: { name: 'ApexChart', props: ['options', 'series'], template: '<div class="chart-stub" />' } }));
vi.mock('@/crm/services/salesAnalyticsApi', () => ({ fetchSalesAnalytics: vi.fn() }));

import FiscalSalesOverview from './FiscalSalesOverview.vue';
import SalesAnalyticsPage from './SalesAnalyticsPage.vue';
import { fetchSalesAnalytics } from '@/crm/services/salesAnalyticsApi';

const fiscal = () => ({
  kpis: { revenue: { value: 173517, delta: 4 }, receipts: { value: 316, delta: -2 }, average_check: { value: 549.10, delta: null } },
  totals: { sales: 174017, refunds: 500, revenue: 173517, receipts: 316, refund_receipts: 1 },
  trend: { dates: ['2026-09-01', '2026-09-02', '2026-09-03'], sales: [174017, 0, null], refunds: [0, 500, null], revenue: [174017, -500, null], cash: [0, 0, null], cashless: [174017, -500, null], other: [0, 0, null], receipts: [316, 0, null], average_check: [550.69, 0, null] },
  quality: { fallback_date_receipts: 0, unknown_payment_receipts: 0 },
});

describe('Фіскальна аналітика', () => {
  it('показує три показники та графіки за чеками з точністю до копійок', () => {
    const wrapper = mount(FiscalSalesOverview, { props: { fiscal: fiscal() } });
    const cards = wrapper.findAll('.fiscal-kpi');
    expect(cards).toHaveLength(3);
    expect(cards[0].text().replace(/\s/g, '')).toContain('173517,00');
    expect(cards[1].text()).toContain('316');
    expect(cards[2].text().replace(/\s/g, '')).toContain('549,10');
    expect(wrapper.findAllComponents({ name: 'ApexChart' })).toHaveLength(3);
    expect(wrapper.text()).toContain('Виручка — не прибуток');
    wrapper.unmount();
  });

  it('не приховує від’ємну виручку, майбутні дні чи невідомий спосіб оплати', () => {
    const data = fiscal();
    data.trend.other = [100, 0, null];
    data.quality.unknown_payment_receipts = 1;
    data.quality.fallback_date_receipts = 2;
    const wrapper = mount(FiscalSalesOverview, { props: { fiscal: data } });
    const chart = wrapper.findComponent({ name: 'ApexChart' });
    expect(chart.props('series')).toEqual([
      { name: 'Продажі', type: 'column', data: [174017, 0, null] },
      { name: 'Повернення', type: 'column', data: [0, -500, null] },
      { name: 'Виручка після повернень', type: 'line', data: [174017, -500, null] },
    ]);
    expect(chart.props('options').chart.type).toBe('line');
    expect(chart.props('options').chart.stacked).toBe(false);
    expect(chart.props('options').stroke.width).toEqual([0, 0, 2.8]);
    expect(chart.props('options').yaxis.min).toBeUndefined();
    expect(chart.props('options').annotations.yaxis[0].y).toBe(0);
    expect(wrapper.text()).toContain('Для 2 чеків немає дати');
    expect(wrapper.text()).toContain('Для 1 чеків спосіб оплати');
    expect(wrapper.text()).not.toContain('Інше / невідомо');
    expect(data.trend.refunds).toEqual([0, 500, null]);
    wrapper.unmount();
  });

  it('показує чеки стовпчиками, середній чек лінією та не змінює копійки при поверненні', () => {
    const data = fiscal();
    data.trend.sales = [500.01, 0, null];
    data.trend.refunds = [125.5, 200.01, null];
    data.trend.revenue = [374.51, -200.01, null];
    const wrapper = mount(FiscalSalesOverview, { props: { fiscal: data } });
    const charts = wrapper.findAllComponents({ name: 'ApexChart' });
    const [sales, refunds, net] = charts[0].props('series');
    expect(sales.data).toEqual([500.01, 0, null]);
    expect(refunds.data).toEqual([-125.5, -200.01, null]);
    expect(net.data).toEqual([374.51, -200.01, null]);
    expect(charts[1].props('options').chart.type).toBe('bar');
    expect(charts[2].props('options').chart.type).toBe('line');
    wrapper.unmount();
  });

  it('відрізняє порожній період від непідтримуваної валюти', () => {
    const wrapper = mount(FiscalSalesOverview, { props: { fiscal: { totals: {}, trend: {} }, currency: 'PLN' } });
    expect(wrapper.text()).toContain('Виберіть UAH');
    expect(wrapper.text()).toContain('фіскальних чеків немає');
    expect(wrapper.findComponent({ name: 'ApexChart' }).props('series')).toHaveLength(3);
    wrapper.unmount();
  });

  it('залишає тільки чеки та ігнорує старі фільтри зі збереженого посилання', async () => {
    window.history.replaceState({}, '', '/analytics?scope=all&status_id=8&payment_status=refund&currency=PLN&page=3');
    fetchSalesAnalytics.mockResolvedValue({ data: {
      fiscal: fiscal(), meta: { currency: 'UAH', date_from: '2026-09-01', date_to: '2026-09-15' },
      kpis: { revenue: { value: 240000 }, returns: { count: 30, rate: 30 } },
      filters: { currencies: ['UAH', 'PLN'] },
    } });
    const wrapper = mount(SalesAnalyticsPage);
    await flushPromises();
    expect(wrapper.find('.operational-section').exists()).toBe(false);
    expect(wrapper.find('.audit-card').exists()).toBe(false);
    expect(wrapper.find('.btn-export').exists()).toBe(false);
    expect(wrapper.find('.currency-field').exists()).toBe(false);
    expect(wrapper.find('.fiscal-overview').text().replace(/\s/g, '')).not.toContain('240000');
    expect(wrapper.text()).not.toContain('30%');
    expect(wrapper.text()).not.toContain('операційний блок');
    expect(fetchSalesAnalytics).toHaveBeenLastCalledWith(expect.objectContaining({ currency: 'UAH', fiscal_only: 1 }));
    expect(fetchSalesAnalytics.mock.lastCall[0]).not.toHaveProperty('scope');
    expect(fetchSalesAnalytics.mock.lastCall[0]).not.toHaveProperty('status_id');
    expect(window.location.search).not.toContain('payment_status');
    expect(wrapper.findComponent(FiscalSalesOverview).props('currency')).toBe('UAH');
    wrapper.unmount();
  });

  it('зберігає фільтри чеків і дозволяє оновити дані без кешу', async () => {
    window.history.replaceState({}, '', '/analytics?date_from=2026-09-01&date_to=2026-09-14&sale_type=retail&source_id=2&manager_id=3');
    fetchSalesAnalytics.mockResolvedValue({ data: { fiscal: fiscal(), meta: { currency: 'UAH' }, filters: {} } });
    const wrapper = mount(SalesAnalyticsPage);
    await flushPromises();
    expect(fetchSalesAnalytics).toHaveBeenLastCalledWith({ date_from: '2026-09-01', date_to: '2026-09-14', sale_type: 'retail', source_id: '2', manager_id: '3', currency: 'UAH', fiscal_only: 1 });
    await wrapper.find('.btn-refresh').trigger('click');
    await flushPromises();
    expect(fetchSalesAnalytics).toHaveBeenLastCalledWith(expect.objectContaining({ fresh: 1, source_id: '2', fiscal_only: 1 }));
    wrapper.unmount();
  });
});
