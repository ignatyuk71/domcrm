import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import FinancePage from './FinancePage.vue';
import { fetchFinanceSettings } from '@/crm/services/financeApi';

vi.mock('@/crm/services/financeApi', () => ({
  fetchFinanceSettings: vi.fn(), saveFinanceSettings: vi.fn(), testFinanceConnection: vi.fn(),
  openFinanceShift: vi.fn(), closeFinanceShift: vi.fn(), processFinanceQueue: vi.fn(),
}));
vi.mock('vue3-apexcharts', () => ({ default: { name: 'ApexChart', props: ['series', 'options'], template: '<div data-chart />' } }));

let wrapper;
const response = (breakdown = { account_cents: 875000, cod_cents: 660200, other_cents: 0 }) => ({
  settings: {}, shift_status: 'OPENED', queue: { waiting: 0 },
  receipts: { daily_total: 1535200, hourly_amounts: Array(24).fill(0), items: [], payment_breakdown: breakdown,
    payment_receipt_counts: { account: 16, cod: 13, other: 0 } },
});

beforeEach(() => { vi.clearAllMocks(); fetchFinanceSettings.mockResolvedValue(response()); });
afterEach(() => { wrapper?.unmount(); vi.restoreAllMocks(); document.body.innerHTML = ''; });

describe('Компактний розподіл оплат каси', () => {
  it('показує обидві суми в одному блоці безпосередньо після графіка', async () => {
    wrapper = mount(FinancePage);
    await flushPromises();
    const block = wrapper.get('.payment-breakdown');
    expect(wrapper.findAll('.payment-breakdown')).toHaveLength(1);
    expect(block.element.previousElementSibling.querySelector('[data-chart]')).not.toBeNull();
    expect(block.findAll('.payment-breakdown__item')).toHaveLength(2);
    expect(block.findAll('.payment-breakdown__count').map(item => item.text())).toEqual(['Чеків: 16', 'Чеків: 13']);
    expect(block.text().replace(/\s/g, '')).toContain('8750,00');
    expect(block.text().replace(/\s/g, '')).toContain('6602,00');
    expect(block.text()).toContain('На рахунок / картку');
    expect(block.text()).toContain('Післяплата / NovaPay');
    expect(block.find('.payment-breakdown__other').exists()).toBe(false);
    expect(document.querySelector('.app-toast')).toBeNull();
  });

  it('показує нулі для порожнього дня та від’ємні суми після повернення', async () => {
    fetchFinanceSettings.mockResolvedValue(response({ account_cents: 0, cod_cents: -427500, other_cents: 1200 }));
    wrapper = mount(FinancePage);
    await flushPromises();
    const amounts = wrapper.findAll('.payment-breakdown__amount').map(item => item.text().replace(/\s/g, ''));
    expect(amounts[0]).toContain('0,00');
    expect(amounts[1]).toContain('-4275,00');
    expect(wrapper.get('.payment-breakdown__other').text().replace(/\s/g, '')).toContain('12,00');
  });

  it('під час завантаження та без нового поля API не підставляє вигадані нулі', async () => {
    let resolve;
    fetchFinanceSettings.mockImplementation(() => new Promise(done => { resolve = done; }));
    wrapper = mount(FinancePage);
    await wrapper.vm.$nextTick();
    expect(wrapper.get('.payment-breakdown').attributes('aria-busy')).toBe('true');
    expect(wrapper.findAll('.payment-breakdown__amount').every(item => item.text() === '—')).toBe(true);
    expect(wrapper.findAll('.payment-breakdown__count').every(item => item.text() === 'Чеків: —')).toBe(true);
    const legacy = response(null);
    delete legacy.receipts.payment_receipt_counts;
    resolve(legacy);
    await flushPromises();
    expect(wrapper.get('.payment-breakdown').attributes('aria-busy')).toBe('false');
    expect(wrapper.findAll('.payment-breakdown__amount').every(item => item.text() === '—')).toBe(true);
    expect(wrapper.findAll('.payment-breakdown__count').every(item => item.text() === 'Чеків: —')).toBe(true);
  });

  it('показує нуль чеків і зберігає кількість інших чеків навіть за нульової суми', async () => {
    const data = response({ account_cents: 0, cod_cents: 0, other_cents: 0 });
    data.receipts.payment_receipt_counts = { account: 0, cod: 0, other: 2 };
    fetchFinanceSettings.mockResolvedValue(data);
    wrapper = mount(FinancePage);
    await flushPromises();
    expect(wrapper.findAll('.payment-breakdown__count').map(item => item.text())).toEqual(['Чеків: 0', 'Чеків: 0']);
    expect(wrapper.get('.payment-breakdown__other').text()).toContain('Чеків: 2');
  });

  it('зберігає текст помилки в toast і дозволяє повторити завантаження сум', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    fetchFinanceSettings.mockRejectedValueOnce(new Error('Мережа недоступна')).mockResolvedValueOnce(response());
    wrapper = mount(FinancePage);
    await flushPromises();
    expect(document.querySelector('[role="alert"]').textContent).toContain('Мережа недоступна');
    const retry = [...document.querySelectorAll('.app-toast button')].find(button => button.textContent === 'Повторити');
    retry.click();
    await flushPromises();
    expect(fetchFinanceSettings).toHaveBeenCalledTimes(2);
    expect(wrapper.get('.payment-breakdown').text().replace(/\s/g, '')).toContain('8750,00');
    expect(document.querySelector('.app-toast')).toBeNull();
  });

  it('резервний розрахунок графіка також віднімає повернення', async () => {
    const data = response();
    delete data.receipts.hourly_amounts;
    data.receipts.items = [
      { status: 'success', type: 'sell', total_amount: 50000, created_at: '2026-10-22T10:00:00Z' },
      { status: 'success', type: 'return', total_amount: 20000, created_at: '2026-10-22T10:30:00Z' },
    ];
    fetchFinanceSettings.mockResolvedValue(data);
    wrapper = mount(FinancePage);
    await flushPromises();
    expect(wrapper.getComponent({ name: 'ApexChart' }).props('series')[0].data.reduce((a, b) => a + b, 0)).toBe(300);
  });
});
