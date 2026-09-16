import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import SoleInventoryPage from './SoleInventoryPage.vue';
import { fetchSoleInventory, saveSolePlan, addSoleMovement } from '@/crm/services/soleInventoryApi';

vi.mock('@/crm/services/soleInventoryApi', async importOriginal => ({
  ...await importOriginal(), fetchSoleInventory: vi.fn(), saveSolePlan: vi.fn(), addSoleMovement: vi.fn(),
}));

let wrapper;
function report(configured = false) {
  return {
    today: '2026-09-16', as_of: '2026-09-16T12:00:00+03:00', missing_categories: [], unlinked_item_count: 0, history_available_from: '2026-01-27 00:00:00', journal: [],
    totals: { configured_sizes: configured ? 3 : 0, total_sizes: 3, remaining: configured ? 250 : 0, order_now: 0 },
    categories: [{ id: 1, name: 'Домашні капці Halluci (хутряні)', window_from: '2026-08-17', window_to: '2026-09-15',
      settings: { version: configured ? 1 : 0, opening_date: configured ? '2026-09-01' : null, lead_time_days: null, safety_days: 14, lookback_days: 30 },
      rows: ['36/37', '38/39', '40/41'].map(size => ({ size, opening_quantity: configured ? 100 : null, movements_quantity: 0, consumed: 10, remaining: configured ? 90 : null, shipped_in_window: 10, daily_rate: 0.33, days_remaining: null, depletion_date: null, reorder_date: null, reorder_point: null, status: configured ? 'missing_lead' : 'unconfigured' })),
      warnings: { unknown_size_pairs: 0, missing_date_orders: 0, duplicate_orders: 0, approximate_date_pairs: 0, without_ttn_orders: 0 }, issues: [], recent_shipments: [], trend: [{ date: '2026-09-15', quantity: 10 }],
    }],
  };
}
const button = label => wrapper.findAll('button').find(item => item.text().includes(label));
async function open(configured = false) {
  fetchSoleInventory.mockResolvedValue({ data: report(configured) });
  wrapper = mount(SoleInventoryPage);
  await flushPromises();
}
beforeEach(() => {
  vi.clearAllMocks();
  saveSolePlan.mockResolvedValue({ data: { saved: true } });
  addSoleMovement.mockResolvedValue({ data: { id: 1 } });
});
afterEach(() => wrapper?.unmount());

describe('Запас підошви', () => {
  it('показує відправлення без вигаданого початкового залишку', async () => {
    await open();
    expect(wrapper.text()).toContain('Почніть із залишків');
    expect(wrapper.find('.sole-metrics strong').text()).toBe('— пар');
    expect(wrapper.findAll('.sole-status').every(item => item.text() === 'Внесіть залишок')).toBe(true);
    expect(button('Рух запасу').attributes('disabled')).toBeDefined();
    expect(saveSolePlan).not.toHaveBeenCalled();
  });

  it('зберігає порожні величини як невідомі, а введений нуль як нуль', async () => {
    await open();
    await button('Внести залишки').trigger('click');
    await wrapper.get('[data-size="36/37"]').setValue('0');
    await wrapper.get('[data-size="38/39"]').setValue('250');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(saveSolePlan).toHaveBeenCalledWith(1, expect.objectContaining({ version: 0, opening_date: '2026-09-16', lead_time_days: null,
      opening_balances: [{ size: '36/37', quantity: 0 }, { size: '38/39', quantity: 250 }, { size: '40/41', quantity: null }] }));
    expect(fetchSoleInventory).toHaveBeenCalledTimes(2);
    expect(wrapper.find('form').exists()).toBe(false);
  });

  it('зберігає введені дані та показує конфлікт версій', async () => {
    await open(true);
    saveSolePlan.mockRejectedValue({ response: { data: { message: 'Налаштування вже змінилися.' }, status: 409 } });
    await button('Налаштувати').trigger('click');
    await wrapper.get('[data-testid="lead-time"]').setValue('90');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Налаштування вже змінилися');
    expect(wrapper.get('[data-testid="lead-time"]').element.value).toBe('90');
    expect(fetchSoleInventory).toHaveBeenCalledTimes(1);
  });

  it('повторює невдалий запит руху з тим самим ключем, не створюючи нову операцію', async () => {
    await open(true);
    addSoleMovement.mockRejectedValueOnce(new Error('network'));
    await button('Рух запасу').trigger('click');
    await wrapper.get('[data-testid="movement-quantity"]').setValue('50');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const first = addSoleMovement.mock.calls[0][1];
    expect(first.quantity).toBe(50);
    expect(first.request_key).toMatch(/^[\da-f-]{36}$/);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(addSoleMovement.mock.calls[1][1].request_key).toBe(first.request_key);
  });

  it('не пропонує повторно зберігати рух, якщо збереження успішне, а оновлення звіту впало', async () => {
    await open(true);
    await button('Рух запасу').trigger('click');
    await wrapper.get('[data-testid="movement-quantity"]').setValue('50');
    fetchSoleInventory.mockRejectedValueOnce(new Error('network'));
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.find('form').exists()).toBe(false);
    expect(wrapper.text()).toContain('Запис збережено');
    expect(wrapper.text()).toContain('На екрані попередні дані');
    expect(addSoleMovement).toHaveBeenCalledTimes(1);
  });

  it('показує проблему даних із посиланням на замовлення', async () => {
    const data = report();
    data.categories[0].warnings.unknown_size_pairs = 6;
    data.categories[0].issues = [{ order_id: 123, order_number: '900123', message: 'Невідомий розмір: 42/23' }];
    fetchSoleInventory.mockResolvedValue({ data });
    wrapper = mount(SoleInventoryPage);
    await flushPromises();
    expect(wrapper.text()).toContain('Не розпізнано розмір: 6 пар');
    expect(wrapper.get('a[href="/orders/123"]').text()).toBe('№ 900123');
  });

  it('дозволяє повторити початкове завантаження після помилки', async () => {
    fetchSoleInventory.mockRejectedValueOnce(new Error('network'));
    wrapper = mount(SoleInventoryPage);
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Не вдалося виконати запит');
    fetchSoleInventory.mockResolvedValueOnce({ data: report() });
    await button('Оновити').trigger('click');
    await flushPromises();
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    expect(wrapper.find('.sole-metrics').exists()).toBe(true);
  });
});
