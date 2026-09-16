import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('vue3-apexcharts', () => ({ default: { template: '<div />' } }));
vi.mock('@/crm/services/salesAnalyticsApi', () => ({ fetchSalesAnalytics: vi.fn() }));

import SalesAnalyticsPage from './SalesAnalyticsPage.vue';
import DateRangePicker from '@/crm/components/ui/DateRangePicker.vue';
import { fetchSalesAnalytics } from '@/crm/services/salesAnalyticsApi';

let wrapper;
const mountPage = () => mount(SalesAnalyticsPage, { attachTo: document.body });
beforeEach(() => {
  vi.clearAllMocks();
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-09-15T12:00:00Z'));
  window.history.replaceState({}, '', '/analytics');
  fetchSalesAnalytics.mockResolvedValue({ data: {
    fiscal: {}, meta: { currency: 'UAH' }, filters: {
      sources: [{ id: 2, code: 'web', name: 'Сайт' }],
      managers: [{ id: 3, name: 'Менеджер' }],
      sale_types: [{ value: 'retail', label: 'Роздріб' }],
    },
  } });
});
afterEach(() => {
  wrapper?.unmount();
  document.body.innerHTML = '';
  vi.useRealTimers();
});

describe('Компактна панель аналітики', () => {
  it('залишає заголовок і один вибір періоду без відкритих додаткових полів', async () => {
    wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('.analytics-header h1').text()).toBe('Аналітика продажів');
    expect(wrapper.find('.analytics-header p').exists()).toBe(false);
    expect(wrapper.find('.eyebrow').exists()).toBe(false);
    expect(wrapper.find('.btn-refresh').attributes('aria-label')).toBe('Оновити аналітику');
    expect(wrapper.find('.btn-refresh').text()).toBe('');
    expect(wrapper.findAllComponents(DateRangePicker)).toHaveLength(1);
    expect(wrapper.findAll('.filter-toolbar input')).toHaveLength(0);
    expect(wrapper.findAll('.filter-toolbar .preset-btn')).toHaveLength(0);
    expect(wrapper.find('.btn-apply').exists()).toBe(false);
    expect(wrapper.find('.additional-filters').isVisible()).toBe(false);
    expect(wrapper.find('.filter-toggle').attributes('aria-expanded')).toBe('false');
    expect(wrapper.find('.btn-reset').exists()).toBe(false);
    await wrapper.find('.filter-toggle').trigger('click');
    expect(wrapper.find('.additional-filters').isVisible()).toBe(true);
    expect(wrapper.find('.filter-toggle').attributes('aria-expanded')).toBe('true');
    expect(fetchSalesAnalytics).toHaveBeenCalledTimes(1);
  });

  it('відкриває умови зі збереженого посилання та зберігає їх лічильник після згортання', async () => {
    window.history.replaceState({}, '', '/analytics?date_from=2026-08-01&date_to=2026-09-01&sale_type=retail&source_id=2&manager_id=3');
    wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('.additional-filters').isVisible()).toBe(true);
    expect(wrapper.find('.filter-count').text()).toBe('3');
    await wrapper.find('.filter-toggle').trigger('click');
    expect(wrapper.find('.additional-filters').isVisible()).toBe(false);
    expect(wrapper.find('.filter-count').text()).toBe('3');
    await wrapper.find('.btn-refresh').trigger('click');
    await flushPromises();
    expect(fetchSalesAnalytics).toHaveBeenLastCalledWith({
      date_from: '2026-08-01', date_to: '2026-09-01', sale_type: 'retail', source_id: '2', manager_id: '3', currency: 'UAH', fiscal_only: 1, fresh: 1,
    });
  });

  it('застосовує власні дати й додаткові фільтри однією кнопкою', async () => {
    wrapper = mountPage();
    await flushPromises();
    await wrapper.find('.filter-toggle').trigger('click');
    await wrapper.find('[name="sale_type"]').setValue('retail');
    await wrapper.find('[name="source_id"]').setValue('2');
    await wrapper.find('[name="manager_id"]').setValue('3');
    expect(fetchSalesAnalytics).toHaveBeenCalledTimes(1);
    wrapper.findComponent(DateRangePicker).vm.$emit('apply', { from: '2026-08-01', to: '2026-09-01' });
    await flushPromises();
    expect(fetchSalesAnalytics).toHaveBeenLastCalledWith({
      date_from: '2026-08-01', date_to: '2026-09-01', sale_type: 'retail', source_id: '2', manager_id: '3', currency: 'UAH', fiscal_only: 1,
    });
    expect(window.location.search).toContain('source_id=2');
  });

  it('скидає додаткові умови без зміни вибраного періоду', async () => {
    window.history.replaceState({}, '', '/analytics?date_from=2026-08-01&date_to=2026-09-01&source_id=2');
    wrapper = mountPage();
    await flushPromises();
    await wrapper.find('.btn-reset').trigger('click');
    await flushPromises();
    expect(fetchSalesAnalytics).toHaveBeenLastCalledWith({ date_from: '2026-08-01', date_to: '2026-09-01', currency: 'UAH', fiscal_only: 1 });
    expect(wrapper.findComponent(DateRangePicker).props('from')).toBe('2026-08-01');
    expect(wrapper.findComponent(DateRangePicker).props('to')).toBe('2026-09-01');
    expect(wrapper.find('.filter-count').exists()).toBe(false);
    expect(wrapper.find('.btn-reset').exists()).toBe(false);
    expect(window.location.search).not.toContain('source_id');
  });

  it('зберігає швидкий вибір дат і додаткові умови', async () => {
    window.history.replaceState({}, '', '/analytics?source_id=2');
    wrapper = mountPage();
    await flushPromises();
    const ranges = [
      ['2026-09-15', '2026-09-15'], ['2026-09-09', '2026-09-15'],
      ['2026-09-01', '2026-09-15'], ['2026-08-01', '2026-08-31'],
    ];
    for (const [date_from, date_to] of ranges) {
      wrapper.findComponent(DateRangePicker).vm.$emit('apply', { from: date_from, to: date_to });
      await flushPromises();
      expect(fetchSalesAnalytics).toHaveBeenLastCalledWith({ date_from, date_to, source_id: '2', currency: 'UAH', fiscal_only: 1 });
      expect(wrapper.findComponent(DateRangePicker).props('from')).toBe(date_from);
    }
  });
});
