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
  trend: { dates: ['2026-09-01', '2026-09-02', '2026-09-03'], revenue: [174017, -500, null], cash: [0, 0, null], cashless: [174017, -500, null], other: [0, 0, null], receipts: [316, 0, null], average_check: [550.69, 0, null] },
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
    expect(chart.props('series')[0].data).toEqual([174017, -500, null]);
    expect(chart.props('series')[3].name).toBe('Інше / невідомо');
    expect(chart.props('options').yaxis.min).toBeUndefined();
    expect(wrapper.text()).toContain('Для 2 чеків немає дати');
    expect(wrapper.text()).toContain('Для 1 чеків спосіб оплати');
    wrapper.unmount();
  });

  it('відрізняє порожній період від непідтримуваної валюти', () => {
    const wrapper = mount(FiscalSalesOverview, { props: { fiscal: { totals: {}, trend: {} }, currency: 'PLN' } });
    expect(wrapper.text()).toContain('Виберіть UAH');
    expect(wrapper.text()).toContain('фіскальних чеків немає');
    expect(wrapper.findComponent({ name: 'ApexChart' }).props('series')).toHaveLength(3);
    wrapper.unmount();
  });

  it('відокремлює замовлення та не підміняє валюту даних незастосованим фільтром', async () => {
    window.history.replaceState({}, '', '/analytics');
    fetchSalesAnalytics.mockResolvedValue({ data: {
      fiscal: fiscal(), meta: { currency: 'UAH', date_from: '2026-09-01', date_to: '2026-09-15' },
      kpis: { revenue: { value: 240000 }, returns: { count: 30, rate: 30 } },
      filters: { currencies: ['UAH', 'PLN'] },
    } });
    const wrapper = mount(SalesAnalyticsPage);
    await flushPromises();
    expect(wrapper.find('.operational-section').attributes('open')).toBeUndefined();
    expect(wrapper.find('.operational-section summary').text()).toContain('не фіскальна виручка');
    expect(wrapper.find('.fiscal-overview').text().replace(/\s/g, '')).not.toContain('240000');
    expect(wrapper.find('.mini-kpi-grid').text()).not.toContain('30%');
    await wrapper.find('.currency-field select').setValue('PLN');
    expect(wrapper.findComponent(FiscalSalesOverview).props('currency')).toBe('UAH');
    expect(wrapper.find('.btn-export').attributes('href')).toContain('currency=UAH');
    wrapper.unmount();
  });
});
