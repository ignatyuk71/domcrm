import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import SoleInventoryPage from './SoleInventoryPage.vue';
import { fetchSoleInventory, saveSolePlan, addSoleBatch, updateSoleBatch } from '@/crm/services/soleInventoryApi';

vi.mock('@/crm/services/soleInventoryApi', async importOriginal => ({
  ...await importOriginal(), fetchSoleInventory: vi.fn(), saveSolePlan: vi.fn(), addSoleBatch: vi.fn(), updateSoleBatch: vi.fn(),
}));

let wrapper;
function report(configured = false) {
  return {
    today: '2026-09-16', as_of: '2026-09-16T12:00:00+03:00', missing_categories: [], unlinked_item_count: 0, history_available_from: '2026-01-27 00:00:00',
    categories: [{ id: 1, name: 'Домашні капці Halluci (хутряні)', counting_from: configured ? '2026-09-01' : null, legacy_basis: false,
      window_from: '2026-09-01', window_to: '2026-09-15', rate_days: configured ? 15 : 0,
      settings: { version: configured ? 1 : 0, lead_time_days: configured ? 60 : null, safety_days: 0, lookback_days: 0 },
      totals: { received: configured ? 1350 : 0, consumed: configured ? 150 : 0, remaining: configured ? 1200 : 0 },
      rows: ['36/37', '38/39', '40/41'].map((size, i) => ({ size, tracked: configured, received_quantity: configured && !i ? 1350 : 0,
        consumed: configured && !i ? 150 : 0, remaining: configured ? (i ? 0 : 1200) : null, legacy_quantity: null, adjustment_quantity: 0,
        remaining_percent: 89, daily_rate: configured && !i ? 10 : 0, days_remaining: configured && !i ? 120 : null,
        days_until_reorder: configured && !i ? 60 : null, depletion_date: configured && !i ? '2027-01-14' : null,
        reorder_date: configured && !i ? '2026-11-15' : null, status: configured ? (i ? 'not_received' : 'sufficient') : 'unconfigured' })),
      next_order: null, warnings: { unknown_size_pairs: 0, missing_date_orders: 0, duplicate_orders: 0, approximate_date_pairs: 0, without_ttn_orders: 0 }, issues: [], months: [],
      batches: configured ? [{ id: 10, version: 1, received_on: '2026-09-01', note: null, quantities: [{ size: '36/37', quantity: 1350 }, { size: '38/39', quantity: 0 }, { size: '40/41', quantity: 0 }] }] : [],
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
  addSoleBatch.mockResolvedValue({ data: { id: 1 } });
  updateSoleBatch.mockResolvedValue({ data: { id: 10 } });
});
afterEach(() => wrapper?.unmount());

describe('Партії підошви та автоматичний прогноз', () => {
  it('дозволяє додати першу партію без ручних залишків чи попереднього налаштування', async () => {
    await open();
    expect(wrapper.text()).toContain('Додайте першу партію');
    expect(button('Додати партію').attributes('disabled')).toBeUndefined();
    expect(wrapper.text()).not.toContain('Внести залишки');
    expect(wrapper.text()).not.toContain('Останні 20');
    expect(wrapper.text()).not.toContain('Останні відправлення');
    expect(saveSolePlan).not.toHaveBeenCalled();
  });

  it('надсилає дату приїзду та отримані кількості, не вимагаючи залишку чи вартості', async () => {
    await open();
    await button('Додати партію').trigger('click');
    await wrapper.get('[data-testid="received-on"]').setValue('2026-03-01');
    await wrapper.get('[data-size="36/37"]').setValue('1000');
    await wrapper.get('[data-size="38/39"]').setValue('2000');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(addSoleBatch).toHaveBeenCalledWith(1, { request_key: expect.any(String), received_on: '2026-03-01', note: null,
      quantities: [{ size: '36/37', quantity: 1000 }, { size: '38/39', quantity: 2000 }, { size: '40/41', quantity: 0 }] });
    expect(saveSolePlan).not.toHaveBeenCalled();
    expect(fetchSoleInventory).toHaveBeenCalledTimes(2);
    expect(wrapper.find('form').exists()).toBe(false);
  });

  it('не зберігає порожню партію', async () => {
    await open();
    await button('Додати партію').trigger('click');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('хоча б для одного розміру');
    expect(addSoleBatch).not.toHaveBeenCalled();
  });

  it('показує запас і час до закупівлі приблизно в місяцях', async () => {
    await open(true);
    expect(wrapper.find('.sole-forecast').text()).toContain('≈ 3,9 міс.');
    expect(wrapper.find('.sole-date-list').text()).toContain('через ≈ 2 міс.');
    expect(wrapper.find('.sole-size-metrics').text()).toContain('Отримано');
    expect(wrapper.find('.sole-size-metrics').text()).toContain('Витрачено');
    expect(wrapper.find('.sole-size-metrics').text()).toContain('Залишок');
    expect(wrapper.text()).not.toContain('Останні 20 товарних позицій');
  });

  it('редагує дату та кількість збереженої партії з перевіркою версії', async () => {
    await open(true);
    await button('Редагувати').trigger('click');
    await wrapper.get('[data-size="36/37"]').setValue('1500');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(updateSoleBatch).toHaveBeenCalledWith(1, 10, expect.objectContaining({ version: 1, received_on: '2026-09-01' }));
    expect(updateSoleBatch.mock.calls[0][2]).not.toHaveProperty('request_key');
    expect(updateSoleBatch.mock.calls[0][2].quantities[0].quantity).toBe(1500);
    expect(addSoleBatch).not.toHaveBeenCalled();
  });

  it('повторює невдале створення з тим самим ключем, щоб не подвоїти партію', async () => {
    await open();
    addSoleBatch.mockRejectedValueOnce(new Error('network'));
    await button('Додати партію').trigger('click');
    await wrapper.get('[data-size="36/37"]').setValue('50');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const first = addSoleBatch.mock.calls[0][1];
    expect(first.request_key).toMatch(/^[\da-f-]{36}$/);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(addSoleBatch.mock.calls[1][1].request_key).toBe(first.request_key);
  });

  it('не повторює збереження, якщо впало лише оновлення звіту', async () => {
    await open();
    await button('Додати партію').trigger('click');
    await wrapper.get('[data-size="36/37"]').setValue('50');
    fetchSoleInventory.mockRejectedValueOnce(new Error('network'));
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.find('form').exists()).toBe(false);
    expect(wrapper.text()).toContain('Запис збережено');
    expect(wrapper.text()).toContain('На екрані попередні дані');
    expect(addSoleBatch).toHaveBeenCalledTimes(1);
  });

  it('налаштування прогнозу не містять редагування залишків чи отриманих кількостей', async () => {
    await open(true);
    saveSolePlan.mockRejectedValue({ response: { data: { message: 'Налаштування вже змінилися.' }, status: 409 } });
    await button('Прогноз').trigger('click');
    await wrapper.get('[data-testid="lead-time"]').setValue('90');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(saveSolePlan).toHaveBeenCalledWith(1, { version: 1, lead_time_days: 90, safety_days: 0, lookback_days: 0 });
    expect(wrapper.get('[role="alert"]').text()).toContain('Налаштування вже змінилися');
    expect(wrapper.get('[data-testid="lead-time"]').element.value).toBe('90');
  });

  it('показує проблеми розмірів із посиланням на замовлення', async () => {
    const data = report(true);
    data.categories[0].warnings.unknown_size_pairs = 6;
    data.categories[0].issues = [{ order_id: 123, order_number: '900123', message: 'Невідомий розмір: 42/23' }];
    fetchSoleInventory.mockResolvedValue({ data });
    wrapper = mount(SoleInventoryPage);
    await flushPromises();
    expect(wrapper.text()).toContain('Не розпізнано розмір: 6 пар');
    expect(wrapper.get('a[href="/orders/123"]').text()).toBe('№ 900123');
  });

  it('дозволяє повторити завантаження після помилки', async () => {
    fetchSoleInventory.mockRejectedValueOnce(new Error('network'));
    wrapper = mount(SoleInventoryPage);
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Не вдалося виконати запит');
    fetchSoleInventory.mockResolvedValueOnce({ data: report() });
    await wrapper.get('[aria-label="Оновити розрахунок"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
  });
});
