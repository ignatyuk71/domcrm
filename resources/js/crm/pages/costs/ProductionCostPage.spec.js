import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import ProductionCostPage from './ProductionCostPage.vue';
import { fetchCostBatches, createCostBatch, updateCostBatch } from '@/crm/services/productionCostsApi';
import { calculateSoleCost, costFields } from '@/crm/utils/soleCosts';

vi.mock('@/crm/services/productionCostsApi', async importOriginal => ({ ...await importOriginal(), fetchCostBatches: vi.fn(), createCostBatch: vi.fn(), updateCostBatch: vi.fn() }));
const inputs = { quantity: '100', goods_cny: '200', china_shipping_cny: '10', commission_percent: '10', international_shipping_usd: '100', ukraine_shipping_uah: '500', other_costs_uah: '0', cny_rate: '6', usd_rate: '40' };
const record = (changes = {}) => {
  const values = { ...inputs, ...changes };
  return { id: 1, version: 1, name: 'Тестова партія', purchased_on: null, note: 'Тестова примітка.', ...changes,
    quantity: Number(values.quantity), inputs: Object.fromEntries(Object.entries(costFields).map(([key, precision]) => [key, Number(values[key]).toFixed(precision)])), calculation: calculateSoleCost(values) };
};
const listing = rows => ({ data: { data: rows, current_page: 1, last_page: 1, total: rows.length } });
let wrapper;
const button = text => wrapper.findAll('button').find(item => item.text().includes(text));
async function open() { wrapper = mount(ProductionCostPage); await flushPromises(); }
beforeEach(() => {
  vi.clearAllMocks();
  vi.spyOn(window, 'confirm').mockReturnValue(true);
  fetchCostBatches.mockResolvedValue(listing([record()]));
  createCostBatch.mockImplementation(async data => ({ data: record({ ...data, id: 2 }) }));
  updateCostBatch.mockImplementation(async (id, data) => ({ data: record({ ...data, id, version: data.version + 1 }) }));
});
afterEach(() => { wrapper?.unmount(); vi.restoreAllMocks(); });

describe('Калькулятор виробництва', () => {
  it('показує підтверджені дані прямо в полях, без записів під час відкриття', async () => {
    await open();
    expect(wrapper.get('[name="quantity"]').element.value).toBe('100');
    expect(wrapper.get('[name="goods_cny"]').element.value).toBe('200.00');
    expect(wrapper.get('[name="purchased_on"]').element.value).toBe('');
    expect(wrapper.get('[name="cny_rate"]').element.value).toBe('6.0000');
    expect(wrapper.get('[data-testid="unit-cost"]').text()).toContain('58,86');
    expect(createCostBatch).not.toHaveBeenCalled();
    expect(updateCostBatch).not.toHaveBeenCalled();
    expect(button('Зберегти зміни').attributes('disabled')).toBeDefined();
  });
  it('перераховує ціну під час введення, але зберігає лише явно', async () => {
    await open();
    await wrapper.get('[name="usd_rate"]').setValue('41');
    expect(wrapper.get('[data-testid="unit-cost"]').text()).toContain('59,86');
    expect(wrapper.text()).toContain('Не збережено');
    expect(updateCostBatch).not.toHaveBeenCalled();
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateCostBatch).toHaveBeenCalledWith(1, expect.objectContaining({ version: 1, usd_rate: '41', quantity: 100 }));
    expect(updateCostBatch.mock.calls[0][1]).not.toHaveProperty('total_uah');
    expect(button('Зберегти зміни').attributes('disabled')).toBeDefined();
  });
  it('не ділить на нуль і не дозволяє зберегти хибну кількість', async () => {
    await open(); await wrapper.get('[name="quantity"]').setValue('0');
    expect(wrapper.get('[data-testid="unit-cost"]').text()).toContain('—');
    expect(wrapper.text()).not.toContain('Infinity');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateCostBatch).not.toHaveBeenCalled();
  });
  it('створює нову партію окремо, зі своїми сумами й курсами', async () => {
    await open(); await button('Нова партія').trigger('click');
    expect(wrapper.get('[name="goods_cny"]').element.value).toBe('');
    expect(wrapper.get('[name="cny_rate"]').element.value).toBe('');
    for (const [key, value] of Object.entries({ quantity: '2000', goods_cny: '4800', cny_rate: '7,20', usd_rate: '46' })) await wrapper.get(`[name="${key}"]`).setValue(value);
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(createCostBatch).toHaveBeenCalledWith(expect.objectContaining({ request_key: expect.any(String), quantity: 2000, goods_cny: '4800', cny_rate: '7.20' }));
    expect(updateCostBatch).not.toHaveBeenCalled();
  });
  it('зберігає ключ повтору після мережевої помилки створення', async () => {
    await open(); await button('Нова партія').trigger('click');
    for (const [key, value] of Object.entries(inputs)) await wrapper.get(`[name="${key}"]`).setValue(value);
    createCostBatch.mockRejectedValueOnce(new Error('network'));
    await wrapper.get('form').trigger('submit'); await flushPromises();
    const key = createCostBatch.mock.calls[0][0].request_key;
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(createCostBatch.mock.calls[1][0].request_key).toBe(key);
  });
  it('не повторює збереження, якщо впало лише оновлення списку', async () => {
    await open(); await wrapper.get('[name="usd_rate"]').setValue('46');
    fetchCostBatches.mockRejectedValueOnce(new Error('network'));
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.text()).toContain('Партію збережено, але список не оновився');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateCostBatch).toHaveBeenCalledTimes(1);
  });
  it('попереджає перед втратою змін і може повернути збережені значення', async () => {
    await open(); await wrapper.get('[name="usd_rate"]').setValue('46');
    window.confirm.mockReturnValueOnce(false);
    await button('Нова партія').trigger('click');
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('46');
    await button('Скасувати зміни').trigger('click');
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('40.0000');
  });
  it('інші складові не видає за пораховані й зберігає форму при перемиканні вкладок', async () => {
    await open(); await wrapper.get('[name="usd_rate"]').setValue('46');
    await button('Нитки').trigger('click');
    expect(wrapper.text()).toContain('Ще не пораховано');
    expect(wrapper.text()).toContain('Нитки для зшивання');
    expect(wrapper.find('[data-testid="unit-cost"]').exists()).toBe(false);
    await button('Підошва').trigger('click');
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('46');
  });
  it('дозволяє повторити невдале завантаження без створення партії', async () => {
    fetchCostBatches.mockRejectedValueOnce(new Error('network'));
    await open(); expect(wrapper.find('form').exists()).toBe(false);
    await button('Оновити список').trigger('click'); await flushPromises();
    expect(wrapper.get('[data-testid="unit-cost"]').text()).toContain('58,86');
    expect(createCostBatch).not.toHaveBeenCalled();
  });
});
