import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import SheetMaterialCostPanel from './SheetMaterialCostPanel.vue';
import ProductionCostPage from './ProductionCostPage.vue';
import { fetchFoamCosts, createFoamCost, updateFoamCost, fetchCardboardBatches, fetchCostBatches, updateCardboardBatch } from '@/crm/services/productionCostsApi';
import { calculateFoamCost, foamFields } from '@/crm/utils/foamCosts';
import { calculateCardboardCost } from '@/crm/utils/cardboardCosts';

vi.mock('@/crm/services/productionCostsApi', async importOriginal => ({ ...await importOriginal(), fetchFoamCosts: vi.fn(), createFoamCost: vi.fn(), updateFoamCost: vi.fn(), fetchCardboardBatches: vi.fn(), fetchCostBatches: vi.fn(), updateCardboardBatch: vi.fn() }));
const inputs = { sheet_price_usd: '4.50', usd_rate: '40', shipping_uah: null, sheet_length_cm: '120', sheet_width_cm: '200', blank_length_cm: '25', blank_width_cm: '10' };
const record = (changes = {}) => {
  const values = { ...inputs, ...changes };
  return { id: 3, version: 1, name: 'Тестова вставка', quantity: 1, purchased_on: null, note: null, ...changes,
    inputs: Object.fromEntries(foamFields.map(key => [key, values[key] === null ? null : Number(values[key]).toFixed(key === 'usd_rate' ? 4 : 2)])), calculation: calculateFoamCost(values) };
};
const listing = rows => ({ data: { data: rows, current_page: 1, last_page: 1, total: rows.length } });
let wrapper;
const button = text => wrapper.findAll('button').find(item => item.text().includes(text));
async function open() { wrapper = mount(SheetMaterialCostPanel, { props: { material: 'foam' } }); await flushPromises(); }
beforeEach(() => {
  vi.clearAllMocks(); vi.spyOn(window, 'confirm').mockReturnValue(true);
  fetchFoamCosts.mockResolvedValue(listing([record()])); fetchCostBatches.mockResolvedValue(listing([]));
  const cardboard = { goods_uah: '3600', shipping_uah: null, sheet_length_cm: '120', sheet_width_cm: '80', blank_length_cm: '25', blank_width_cm: '9' };
  fetchCardboardBatches.mockResolvedValue(listing([{ id: 2, version: 1, name: 'Картон', quantity: 20, purchased_on: null, note: null, inputs: cardboard, calculation: calculateCardboardCost({ ...cardboard, quantity: '20' }) }]));
  createFoamCost.mockImplementation(async data => ({ data: record({ ...data, id: 4 }) }));
  updateFoamCost.mockImplementation(async (id, data) => ({ data: record({ ...data, id, version: data.version + 1 }) }));
});
afterEach(() => { wrapper?.unmount(); vi.restoreAllMocks(); });

describe('Форма окремої поролонової вставки', () => {
  it('заповнює ціну й курс із приватної бази, не вимагає кількості та не додає плюш', async () => {
    await open();
    expect(wrapper.get('[name="sheet_price_usd"]').element.value).toBe('4.50');
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('40.0000');
    expect(wrapper.get('[name="purchased_on"]').element.value).toBe('');
    expect(wrapper.get('[data-testid="foam-unit-cost"]').text()).toContain('3,75');
    expect(wrapper.get('[data-testid="foam-sheet-cost"]').text()).toContain('180,00');
    expect(wrapper.find('[name="quantity"]').exists()).toBe(false);
    expect(wrapper.find('[name="goods_uah"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('Поролон, склеєний із плюшем, сюди не входить');
    expect(wrapper.text()).toContain('Доставка поролону не врахована');
    expect(wrapper.text()).toContain('крайові відходи');
    expect(createFoamCost).not.toHaveBeenCalled(); expect(updateFoamCost).not.toHaveBeenCalled();
  });
  it('перераховує курс та доставку на лист і зберігає без клієнтських підсумків', async () => {
    await open(); await wrapper.get('[name="usd_rate"]').setValue('41,00');
    expect(wrapper.get('[data-testid="foam-unit-cost"]').text()).toContain('3,84');
    expect(updateFoamCost).not.toHaveBeenCalled();
    await wrapper.get('[name="shipping_uah"]').setValue('7,50');
    expect(wrapper.get('[data-testid="foam-unit-cost"]').text()).toContain('4,00');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateFoamCost).toHaveBeenCalledWith(3, expect.objectContaining({ usd_rate: '41.00', shipping_uah: '7.50', version: 1 }));
    expect(updateFoamCost.mock.calls[0][1]).not.toHaveProperty('quantity');
    expect(updateFoamCost.mock.calls[0][1]).not.toHaveProperty('unit_cost_uah');
    expect(updateCardboardBatch).not.toHaveBeenCalled();
  });
  it.each([['usd_rate', '0'], ['sheet_price_usd', '-1'], ['blank_length_cm', '201']])('не зберігає хибне поле %s', async (field, value) => {
    await open(); await wrapper.get(`[name="${field}"]`).setValue(value);
    expect(wrapper.get('[data-testid="foam-unit-cost"]').text()).toContain('—');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateFoamCost).not.toHaveBeenCalled();
  });
  it('нова ціна зберігається окремо, новий курс і сума не вигадуються', async () => {
    await open(); await button('Новий розрахунок').trigger('click');
    expect(wrapper.get('[name="sheet_price_usd"]').element.value).toBe('');
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('');
    expect(wrapper.get('[name="blank_width_cm"]').element.value).toBe('10.00');
    await wrapper.get('[name="sheet_price_usd"]').setValue('5'); await wrapper.get('[name="usd_rate"]').setValue('40');
    createFoamCost.mockRejectedValueOnce(new Error('network'));
    await wrapper.get('form').trigger('submit'); await flushPromises();
    const key = createFoamCost.mock.calls[0][0].request_key;
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(createFoamCost.mock.calls[1][0]).toMatchObject({ sheet_price_usd: '5', usd_rate: '40', request_key: key });
    expect(updateFoamCost).not.toHaveBeenCalled();
  });
  it('не повторює запис після збою оновлення списку', async () => {
    await open(); await wrapper.get('[name="usd_rate"]').setValue('41');
    fetchFoamCosts.mockRejectedValueOnce(new Error('network'));
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.text()).toContain('Розрахунок збережено, але список не оновився');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateFoamCost).toHaveBeenCalledTimes(1);
  });
  it('зберігає чернетку при конфлікті версії й захищає від випадкової втрати', async () => {
    await open(); await wrapper.get('[name="usd_rate"]').setValue('41');
    updateFoamCost.mockRejectedValueOnce({ response: { status: 409, data: { message: 'Оновіть список і відкрийте розрахунок ще раз.' } } });
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('41');
    window.confirm.mockReturnValueOnce(false); await button('Новий розрахунок').trigger('click');
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('41');
    await button('Скасувати зміни').trigger('click');
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('40.0000');
  });
  it('невдале завантаження можна повторити, порожня база не означає нульову ціну', async () => {
    fetchFoamCosts.mockRejectedValueOnce(new Error('network')); await open();
    expect(wrapper.find('form').exists()).toBe(false);
    fetchFoamCosts.mockResolvedValueOnce(listing([])); await button('Оновити список').trigger('click'); await flushPromises();
    expect(wrapper.get('[name="sheet_price_usd"]').element.value).toBe('');
    expect(wrapper.get('[data-testid="foam-unit-cost"]').text()).toContain('—');
    expect(createFoamCost).not.toHaveBeenCalled();
  });
  it('картон та вставка ліниво завантажуються й утримують незалежні чернетки', async () => {
    wrapper = mount(ProductionCostPage); await flushPromises();
    expect(fetchFoamCosts).not.toHaveBeenCalled();
    await button('Картон').trigger('click'); await flushPromises();
    await wrapper.get('[data-material="cardboard"] [name="blank_width_cm"]').setValue('8');
    await button('Поролон-вставка').trigger('click'); await flushPromises();
    expect(fetchFoamCosts).toHaveBeenCalledTimes(1);
    await wrapper.get('[data-material="foam"] [name="usd_rate"]').setValue('41');
    await button('Картон').trigger('click');
    expect(wrapper.get('[data-material="cardboard"] [name="blank_width_cm"]').element.value).toBe('8');
    await button('Поролон-вставка').trigger('click');
    expect(wrapper.get('[data-material="foam"] [name="usd_rate"]').element.value).toBe('41');
    expect(wrapper.get('[data-material="foam"] [name="blank_width_cm"]').element.value).toBe('10.00');
    expect(fetchFoamCosts).toHaveBeenCalledTimes(1);
  });
});
