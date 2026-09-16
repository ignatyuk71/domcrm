import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import CardboardCostPanel from './SheetMaterialCostPanel.vue';
import ProductionCostPage from './ProductionCostPage.vue';
import { fetchCardboardBatches, createCardboardBatch, updateCardboardBatch, fetchCostBatches } from '@/crm/services/productionCostsApi';
import { calculateCardboardCost, cardboardFields } from '@/crm/utils/cardboardCosts';

vi.mock('@/crm/services/productionCostsApi', async importOriginal => ({ ...await importOriginal(), fetchCardboardBatches: vi.fn(), createCardboardBatch: vi.fn(), updateCardboardBatch: vi.fn(), fetchCostBatches: vi.fn() }));
const inputs = { quantity: '20', goods_uah: '3600', shipping_uah: null, sheet_length_cm: '120', sheet_width_cm: '80', blank_length_cm: '25', blank_width_cm: '9' };
const record = (changes = {}) => {
  const values = { ...inputs, ...changes };
  return { id: 2, version: 1, name: 'Тестовий картон', purchased_on: null, note: null, ...changes,
    quantity: Number(values.quantity), inputs: Object.fromEntries(cardboardFields.map(key => [key, values[key] === null ? null : Number(values[key]).toFixed(2)])), calculation: calculateCardboardCost(values) };
};
const listing = rows => ({ data: { data: rows, current_page: 1, last_page: 1, total: rows.length } });
let wrapper;
const button = text => wrapper.findAll('button').find(item => item.text().includes(text));
async function open() { wrapper = mount(CardboardCostPanel); await flushPromises(); }
beforeEach(() => {
  vi.clearAllMocks(); vi.spyOn(window, 'confirm').mockReturnValue(true);
  fetchCardboardBatches.mockResolvedValue(listing([record()]));
  fetchCostBatches.mockResolvedValue(listing([]));
  createCardboardBatch.mockImplementation(async data => ({ data: record({ ...data, id: 3 }) }));
  updateCardboardBatch.mockImplementation(async (id, data) => ({ data: record({ ...data, id, version: data.version + 1 }) }));
});
afterEach(() => { wrapper?.unmount(); vi.restoreAllMocks(); });

describe('Форма картону', () => {
  it('завантажує приватні дані в поля без автоматичних записів', async () => {
    await open();
    expect(wrapper.get('[name="quantity"]').element.value).toBe('20');
    expect(wrapper.get('[name="goods_uah"]').element.value).toBe('3600.00');
    expect(wrapper.get('[name="sheet_length_cm"]').element.value).toBe('120.00');
    expect(wrapper.get('[name="purchased_on"]').element.value).toBe('');
    expect(wrapper.get('[data-testid="cardboard-unit-cost"]').text()).toContain('8,44');
    expect(wrapper.text()).toContain('Доставка картону не врахована');
    expect(wrapper.text()).toContain('додаткові проміжки');
    expect(createCardboardBatch).not.toHaveBeenCalled(); expect(updateCardboardBatch).not.toHaveBeenCalled();
  });
  it('миттєво перераховує, але записує лише за кнопкою', async () => {
    await open(); await wrapper.get('[name="goods_uah"]').setValue('4000');
    expect(wrapper.get('[data-testid="cardboard-unit-cost"]').text()).toContain('9,38');
    expect(updateCardboardBatch).not.toHaveBeenCalled();
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateCardboardBatch).toHaveBeenCalledWith(2, expect.objectContaining({ goods_uah: '4000', quantity: 20, version: 1, shipping_uah: null }));
    expect(updateCardboardBatch.mock.calls[0][1]).not.toHaveProperty('unit_cost_uah');
    expect(button('Зберегти зміни').attributes('disabled')).toBeDefined();
  });
  it('розрізняє невідому та нульову доставку й приймає кому', async () => {
    await open(); await wrapper.get('[name="shipping_uah"]').setValue('240,00');
    expect(wrapper.get('[data-testid="cardboard-unit-cost"]').text()).toContain('9,00');
    expect(wrapper.text()).not.toContain('Доставка картону не врахована');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateCardboardBatch).toHaveBeenCalledWith(2, expect.objectContaining({ shipping_uah: '240.00' }));
  });
  it.each([['quantity', '0'], ['blank_length_cm', '121']])('не зберігає некоректне поле %s', async (field, value) => {
    await open(); await wrapper.get(`[name="${field}"]`).setValue(value);
    expect(wrapper.get('[data-testid="cardboard-unit-cost"]').text()).toContain('—');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateCardboardBatch).not.toHaveBeenCalled();
  });
  it('нова партія зберігає розміри, але очищує ціни, кількість і дату', async () => {
    await open(); await button('Нова партія').trigger('click');
    expect(wrapper.get('[name="sheet_width_cm"]').element.value).toBe('80.00');
    expect(wrapper.get('[name="goods_uah"]').element.value).toBe('');
    expect(wrapper.get('[name="quantity"]').element.value).toBe('');
    await wrapper.get('[name="quantity"]').setValue('30'); await wrapper.get('[name="goods_uah"]').setValue('5400');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(createCardboardBatch).toHaveBeenCalledWith(expect.objectContaining({ request_key: expect.any(String), quantity: 30, goods_uah: '5400', purchased_on: null }));
    expect(updateCardboardBatch).not.toHaveBeenCalled();
  });
  it('повтор POST після мережевої помилки використовує той самий ключ', async () => {
    await open(); await button('Нова партія').trigger('click');
    await wrapper.get('[name="quantity"]').setValue('20'); await wrapper.get('[name="goods_uah"]').setValue('3600');
    createCardboardBatch.mockRejectedValueOnce(new Error('network'));
    await wrapper.get('form').trigger('submit'); await flushPromises();
    const key = createCardboardBatch.mock.calls[0][0].request_key;
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(createCardboardBatch.mock.calls[1][0].request_key).toBe(key);
  });
  it('збій GET після успішного запису не повторює PUT', async () => {
    await open(); await wrapper.get('[name="goods_uah"]').setValue('4000');
    fetchCardboardBatches.mockRejectedValueOnce(new Error('network'));
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.text()).toContain('Партію збережено, але список не оновився');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateCardboardBatch).toHaveBeenCalledTimes(1);
  });
  it('не втрачає чернетку при конфлікті версії та дозволяє скасувати зміни', async () => {
    await open(); await wrapper.get('[name="goods_uah"]').setValue('4000');
    updateCardboardBatch.mockRejectedValueOnce({ response: { status: 409, data: { message: 'Оновіть список і відкрийте партію ще раз.' } } });
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.get('[name="goods_uah"]').element.value).toBe('4000');
    expect(wrapper.text()).toContain('Оновіть список');
    window.confirm.mockReturnValueOnce(false); await button('Нова партія').trigger('click');
    expect(wrapper.get('[name="goods_uah"]').element.value).toBe('4000');
    await button('Скасувати зміни').trigger('click');
    expect(wrapper.get('[name="goods_uah"]').element.value).toBe('3600.00');
  });
  it('порожня база не заповнюється вигаданими даними, помилку завантаження можна повторити', async () => {
    fetchCardboardBatches.mockRejectedValueOnce(new Error('network')); await open();
    expect(wrapper.find('form').exists()).toBe(false);
    fetchCardboardBatches.mockResolvedValueOnce(listing([]));
    await button('Оновити список').trigger('click'); await flushPromises();
    expect(wrapper.get('[name="sheet_length_cm"]').element.value).toBe('');
    expect(wrapper.get('[data-testid="cardboard-unit-cost"]').text()).toContain('—');
    expect(createCardboardBatch).not.toHaveBeenCalled();
  });
  it('вкладка ліниво завантажує картон і зберігає чернетку при перемиканні', async () => {
    wrapper = mount(ProductionCostPage); await flushPromises();
    expect(fetchCardboardBatches).not.toHaveBeenCalled();
    await button('Картон').trigger('click'); await flushPromises();
    expect(fetchCardboardBatches).toHaveBeenCalledTimes(1);
    await wrapper.get('[name="goods_uah"]').setValue('4000');
    await button('Хутро').trigger('click');
    await button('Картон').trigger('click');
    expect(wrapper.get('[name="goods_uah"]').element.value).toBe('4000');
    expect(fetchCardboardBatches).toHaveBeenCalledTimes(1);
    const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(true);
  });
});
