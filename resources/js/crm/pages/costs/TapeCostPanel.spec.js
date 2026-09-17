import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import TapeCostPanel from './TapeCostPanel.vue';
import ProductionCostPage from './ProductionCostWorkspace.vue';
import { fetchTapeBatches, createTapeBatch, updateTapeBatch, fetchCostBatches } from '@/crm/services/productionCostsApi';
import { calculateTapeCost, tapeFields, tapePrecision } from '@/crm/utils/tapeCosts';

vi.mock('@/crm/services/productionCostsApi', async importOriginal => ({ ...await importOriginal(), fetchTapeBatches: vi.fn(), createTapeBatch: vi.fn(), updateTapeBatch: vi.fn(), fetchCostBatches: vi.fn() }));
const inputs = { length_m: '100', goods_uah: '1000', shipping_uah: '200', per_slipper_cm: '75', allowance_cm: '5' };
const record = (changes = {}) => {
  const values = { ...inputs, ...changes };
  return { id: 7, version: 1, name: 'Тестова стрічка', purchased_on: null, note: null, quantity: 1, ...changes,
    inputs: Object.fromEntries(tapeFields.map(key => [key, values[key] === null ? null : Number(values[key]).toFixed(tapePrecision[key])])), calculation: calculateTapeCost(values) };
};
const listing = rows => ({ data: { data: rows, current_page: 1, last_page: 1, total: rows.length } });
let wrapper;
const button = text => wrapper.findAll('button').find(item => item.text().includes(text));
async function open() { wrapper = mount(TapeCostPanel); await flushPromises(); }
beforeEach(() => {
  vi.clearAllMocks(); vi.spyOn(window, 'confirm').mockReturnValue(true);
  fetchTapeBatches.mockResolvedValue(listing([record()])); fetchCostBatches.mockResolvedValue(listing([]));
  createTapeBatch.mockImplementation(async data => ({ data: record({ ...data, id: 8 }) }));
  updateTapeBatch.mockImplementation(async (id, data) => ({ data: record({ ...data, id, version: data.version + 1 }) }));
});
afterEach(() => { wrapper?.unmount(); vi.restoreAllMocks(); });

describe('Калькулятор окантовки', () => {
  it('підставляє збережені поля, рахує запас двічі та нічого не записує при відкритті', async () => {
    await open();
    expect(wrapper.get('[name="length_m"]').element.value).toBe('100.0000');
    expect(wrapper.get('[name="goods_uah"]').element.value).toBe('1000.00');
    expect(wrapper.get('[name="purchased_on"]').element.value).toBe('');
    expect(wrapper.get('[data-testid="tape-unit-cost"]').text()).toContain('19,20');
    expect(wrapper.get('[data-testid="tape-metre-cost"]').text()).toContain('12,00');
    expect(wrapper.get('[data-testid="tape-slipper-length"]').text()).toBe('80 см');
    expect(wrapper.get('[data-testid="tape-pair-length"]').text()).toBe('1,6 м');
    expect(wrapper.get('[data-testid="tape-yield"]').text()).toBe('62');
    expect(wrapper.text()).not.toContain('Доставка не врахована');
    expect(wrapper.find('[name="quantity"]').exists()).toBe(false);
    expect(createTapeBatch).not.toHaveBeenCalled(); expect(updateTapeBatch).not.toHaveBeenCalled();
    expect(button('Зберегти зміни').attributes('disabled')).toBeDefined();
  });
  it('одразу перераховує поля й зберігає лише після кнопки', async () => {
    await open(); await wrapper.get('[name="allowance_cm"]').setValue('10,00');
    expect(wrapper.get('[data-testid="tape-unit-cost"]').text()).toContain('20,40');
    expect(wrapper.get('[data-testid="tape-pair-length"]').text()).toBe('1,7 м');
    expect(updateTapeBatch).not.toHaveBeenCalled();
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateTapeBatch).toHaveBeenCalledWith(7, expect.objectContaining({ version: 1, allowance_cm: '10.00', purchased_on: null }));
    const payload = updateTapeBatch.mock.calls[0][1];
    expect(payload).not.toHaveProperty('total_uah'); expect(payload).not.toHaveProperty('unit_cost_uah'); expect(payload).not.toHaveProperty('quantity');
    expect(button('Зберегти зміни').attributes('disabled')).toBeDefined();
  });
  it('відрізняє невідому доставку від нульової', async () => {
    await open(); await wrapper.get('[name="shipping_uah"]').setValue('');
    expect(wrapper.text()).toContain('Доставка не врахована');
    expect(wrapper.get('[data-testid="tape-unit-cost"]').text()).toContain('16,00');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateTapeBatch).toHaveBeenCalledWith(7, expect.objectContaining({ shipping_uah: null }));
    await wrapper.get('[name="shipping_uah"]').setValue('0');
    expect(wrapper.text()).not.toContain('Доставка не врахована');
  });
  it.each([['length_m', '0'], ['length_m', '1'], ['goods_uah', ''], ['per_slipper_cm', '-1'], ['allowance_cm', '-1']])('не зберігає некоректне поле %s=%s', async (field, value) => {
    await open(); await wrapper.get(`[name="${field}"]`).setValue(value);
    expect(wrapper.get('[data-testid="tape-unit-cost"]').text()).toContain('—');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateTapeBatch).not.toHaveBeenCalled();
  });
  it('нова партія очищує суми й довжину, зберігає норму й той самий UUID при повторі', async () => {
    await open(); await button('Нова партія').trigger('click');
    expect(wrapper.get('[name="goods_uah"]').element.value).toBe('');
    expect(wrapper.get('[name="length_m"]').element.value).toBe('');
    expect(wrapper.get('[name="allowance_cm"]').element.value).toBe('5.00');
    for (const [field, value] of Object.entries(inputs)) await wrapper.get(`[name="${field}"]`).setValue(value);
    createTapeBatch.mockRejectedValueOnce(new Error('network'));
    await wrapper.get('form').trigger('submit'); await flushPromises();
    const key = createTapeBatch.mock.calls[0][0].request_key;
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(createTapeBatch.mock.calls[1][0].request_key).toBe(key);
    expect(updateTapeBatch).not.toHaveBeenCalled();
  });
  it('не повторює успішний запис, коли не оновився лише список', async () => {
    await open(); await wrapper.get('[name="shipping_uah"]').setValue('300');
    fetchTapeBatches.mockRejectedValueOnce(new Error('network'));
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.text()).toContain('Партію збережено, але список не оновився');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateTapeBatch).toHaveBeenCalledTimes(1);
  });
  it('конфлікт зберігає чернетку, відмова від нової партії не губить полів', async () => {
    await open(); await wrapper.get('[name="shipping_uah"]').setValue('300');
    updateTapeBatch.mockRejectedValueOnce({ response: { status: 409, data: { message: 'Цю партію вже змінили.' } } });
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.get('[name="shipping_uah"]').element.value).toBe('300');
    window.confirm.mockReturnValueOnce(false); await button('Нова партія').trigger('click');
    expect(wrapper.get('[name="shipping_uah"]').element.value).toBe('300');
    await button('Скасувати зміни').trigger('click');
    expect(wrapper.get('[name="shipping_uah"]').element.value).toBe('200.00');
  });
  it('порожня база не вигадує цін, невдале завантаження можна повторити', async () => {
    fetchTapeBatches.mockRejectedValueOnce(new Error('network')); await open();
    expect(wrapper.find('form').exists()).toBe(false);
    fetchTapeBatches.mockResolvedValueOnce(listing([])); await button('Оновити список').trigger('click'); await flushPromises();
    expect(wrapper.get('[name="goods_uah"]').element.value).toBe('');
    expect(wrapper.get('[data-testid="tape-unit-cost"]').text()).toContain('—');
    expect(createTapeBatch).not.toHaveBeenCalled();
  });
  it('ліниво відкриває окантовку без заглушки й зберігає чернетку між вкладками', async () => {
    wrapper = mount(ProductionCostPage); await flushPromises();
    expect(fetchTapeBatches).not.toHaveBeenCalled();
    await button('Окантовка').trigger('click'); await flushPromises();
    expect(fetchTapeBatches).toHaveBeenCalledTimes(1);
    expect(wrapper.text()).not.toContain('Ще не пораховано');
    await wrapper.get('[data-material="tape"] [name="allowance_cm"]').setValue('10');
    await button('Нитки').trigger('click'); await button('Окантовка').trigger('click');
    expect(wrapper.get('[data-material="tape"] [name="allowance_cm"]').element.value).toBe('10');
    expect(fetchTapeBatches).toHaveBeenCalledTimes(1);
    const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); expect(event.defaultPrevented).toBe(true);
  });
  it('під час збереження блокує форму й перемикання матеріалів', async () => {
    wrapper = mount(ProductionCostPage); await flushPromises();
    await button('Окантовка').trigger('click'); await flushPromises();
    await wrapper.get('[data-material="tape"] [name="allowance_cm"]').setValue('10');
    let finish;
    updateTapeBatch.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
    await wrapper.get('[data-material="tape"] form').trigger('submit'); await flushPromises();
    expect(wrapper.get('[data-material="tape"] fieldset').attributes('disabled')).toBeDefined();
    expect(button('Нитки').attributes('disabled')).toBeDefined();
    finish({ data: record({ allowance_cm: '10', version: 2 }) }); await flushPromises();
    expect(button('Нитки').attributes('disabled')).toBeUndefined();
  });
});
