import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import FurCostPanel from './FurCostPanel.vue';
import ProductionCostPage from './ProductionCostPage.vue';
import { fetchFurBatches, createFurBatch, updateFurBatch, fetchCostBatches } from '@/crm/services/productionCostsApi';
import { calculateFurCost, activeFurFields, furGeometry } from '@/crm/utils/furCosts';
import { costFields } from '@/crm/utils/soleCosts';

vi.mock('@/crm/services/productionCostsApi', async importOriginal => ({ ...await importOriginal(), fetchFurBatches: vi.fn(), createFurBatch: vi.fn(), updateFurBatch: vi.fn(), fetchCostBatches: vi.fn() }));
const inputs = { fabric_length: '10', length_unit: 'yard', fabric_width_cm: '200', cut_length_cm: '100', top_width_cm: '20', bottom_width_cm: '10', height_cm: '10', goods_cny: '100', china_shipping_cny: '10', commission_percent: '10', international_shipping_usd: '10', ukraine_shipping_uah: null, other_costs_uah: '0', cny_rate: '6', usd_rate: '40' };
const record = (changes = {}) => {
  const values = { ...inputs, ...changes }, precision = { ...costFields, ...furGeometry, goods_uah: 2 };
  return { id: 4, version: 1, name: 'Тестове хутро', quantity: 1, purchased_on: null, note: null, ...changes,
    inputs: { ...Object.fromEntries(activeFurFields(values).map(key => [key, values[key] === null ? null : Number(values[key]).toFixed(precision[key])])), length_unit: values.length_unit, ...(values.purchase_source ? { purchase_source: values.purchase_source } : {}) }, calculation: calculateFurCost(values) };
};
const listing = rows => ({ data: { data: rows, current_page: 1, last_page: 1, total: rows.length } });
let wrapper;
const button = text => wrapper.findAll('button').find(item => item.text().includes(text));
async function open() { wrapper = mount(FurCostPanel); await flushPromises(); }
beforeEach(() => {
  vi.clearAllMocks(); vi.spyOn(window, 'confirm').mockReturnValue(true);
  fetchFurBatches.mockResolvedValue(listing([record()])); fetchCostBatches.mockResolvedValue(listing([]));
  createFurBatch.mockImplementation(async data => ({ data: record({ ...data, id: 5 }) }));
  updateFurBatch.mockImplementation(async (id, data) => ({ data: record({ ...data, id, version: data.version + 1 }) }));
});
afterEach(() => { wrapper?.unmount(); vi.restoreAllMocks(); });

describe('Форма хутра', () => {
  it('показує ряди, обрізки, ціну деталі й пари та зберігає змінену довжину розкрою', async () => {
    fetchFurBatches.mockResolvedValue(listing([record({ purchase_source: 'ukraine', goods_uah: '1200', length_unit: 'metre', fabric_length: '1', fabric_width_cm: '180', bottom_width_cm: '13', height_cm: '8' })]));
    await open();
    expect(wrapper.get('[name="cut_length_cm"]').element.value).toBe('100.00');
    expect(wrapper.get('[data-testid="fur-layout"]').text()).toContain('5 у ряду × 22 ряди = 110 деталей');
    expect(wrapper.get('[data-testid="fur-pieces"]').text()).toBe('110 деталей');
    expect(wrapper.get('[data-testid="fur-pairs"]').text()).toBe('55 пар');
    expect(wrapper.get('[data-testid="fur-piece-cost"]').text()).toContain('10,91');
    expect(wrapper.get('[data-testid="fur-unit-cost"]').text()).toContain('21,82');
    expect(wrapper.findAll('.cutting-row polygon')).toHaveLength(5);
    expect(wrapper.text()).not.toContain('Орієнтовно за площею');
    await wrapper.get('[name="height_cm"]').setValue('9');
    expect(wrapper.get('[data-testid="fur-unit-cost"]').text()).toContain('24,00');
    await wrapper.get('[name="cut_length_cm"]').setValue('50');
    expect(wrapper.get('[data-testid="fur-pieces"]').text()).toBe('80 деталей');
    expect(wrapper.get('[data-testid="fur-unit-cost"]').text()).toContain('30,00');
    expect(updateFurBatch).not.toHaveBeenCalled();
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateFurBatch).toHaveBeenCalledWith(4, expect.objectContaining({ cut_length_cm: '50', height_cm: '9' }));
    await button('Нова партія').trigger('click');
    expect(wrapper.get('[name="cut_length_cm"]').element.value).toBe('50.00');
  });
  it('перемикання на Україну прибирає курси й комісію та надсилає тільки українські поля', async () => {
    await open();
    await wrapper.get('[name="purchase_source"][value="ukraine"]').setValue();
    expect(wrapper.find('[name="usd_rate"]').exists()).toBe(false);
    expect(wrapper.find('[name="goods_cny"]').exists()).toBe(false);
    expect(wrapper.find('.commission').exists()).toBe(false);
    expect(wrapper.get('[name="length_unit"]').element.value).toBe('yard');
    expect(wrapper.get('[data-testid="fur-unit-cost"]').text()).toContain('—');
    await wrapper.get('[name="length_unit"]').setValue('metre');
    await wrapper.get('[name="goods_uah"]').setValue('3000,00');
    await wrapper.get('[name="ukraine_shipping_uah"]').setValue('100');
    await wrapper.get('[name="other_costs_uah"]').setValue('20');
    expect(wrapper.get('[data-testid="fur-unit-cost"]').text()).toContain('5,20');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    const payload = updateFurBatch.mock.calls[0][1];
    expect(payload).toMatchObject({ purchase_source: 'ukraine', goods_uah: '3000.00', ukraine_shipping_uah: '100', other_costs_uah: '20', version: 1 });
    for (const field of ['goods_cny', 'cny_rate', 'usd_rate', 'commission_percent', 'international_shipping_usd']) expect(payload).not.toHaveProperty(field);
    expect(button('Зберегти зміни').attributes('disabled')).toBeDefined();
  });
  it('обидві чернетки цін переживають перемикання постачальника, зміни не зберігаються автоматично', async () => {
    await open(); await wrapper.get('[name="goods_cny"]').setValue('123');
    await wrapper.get('[name="purchase_source"][value="ukraine"]').setValue();
    await wrapper.get('[name="goods_uah"]').setValue('2500');
    await wrapper.get('[name="purchase_source"][value="china"]').setValue();
    expect(wrapper.get('[name="goods_cny"]').element.value).toBe('123');
    expect(wrapper.find('[name="goods_uah"]').exists()).toBe(false);
    await wrapper.get('[name="purchase_source"][value="ukraine"]').setValue();
    expect(wrapper.get('[name="goods_uah"]').element.value).toBe('2500');
    await button('Скасувати зміни').trigger('click');
    expect(wrapper.get('[name="purchase_source"][value="china"]').element.checked).toBe(true);
    expect(wrapper.get('[name="goods_cny"]').element.value).toBe('100.00');
    expect(updateFurBatch).not.toHaveBeenCalled(); expect(createFurBatch).not.toHaveBeenCalled();
  });
  it('відкриває українську партію, показує джерело в історії та не переносить оплату в нову', async () => {
    fetchFurBatches.mockResolvedValue(listing([record({ purchase_source: 'ukraine', goods_uah: '3000', length_unit: 'metre' })]));
    await open();
    expect(wrapper.get('[name="purchase_source"][value="ukraine"]').element.checked).toBe(true);
    expect(wrapper.get('[name="goods_uah"]').element.value).toBe('3000.00');
    expect(wrapper.get('tbody').text()).toContain('Україна');
    await button('Нова партія').trigger('click');
    expect(wrapper.get('[name="purchase_source"][value="ukraine"]').element.checked).toBe(true);
    expect(wrapper.get('[name="goods_uah"]').element.value).toBe('');
    expect(wrapper.get('[name="fabric_length"]').element.value).toBe('');
    expect(wrapper.get('[name="fabric_width_cm"]').element.value).toBe('200.00');
  });
  it('нова українська закупівля починається в метрах і не вимагає валютних курсів', async () => {
    fetchFurBatches.mockResolvedValue(listing([])); await open();
    await wrapper.get('[name="purchase_source"][value="ukraine"]').setValue();
    expect(wrapper.get('[name="length_unit"]').element.value).toBe('metre');
    for (const [field, value] of Object.entries({ fabric_length: '10', fabric_width_cm: '200', top_width_cm: '20', bottom_width_cm: '10', height_cm: '10', goods_uah: '3000' })) await wrapper.get(`[name="${field}"]`).setValue(value);
    expect(wrapper.get('[data-testid="fur-unit-cost"]').text()).toContain('5,00');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(createFurBatch).toHaveBeenCalledWith(expect.objectContaining({ purchase_source: 'ukraine', goods_uah: '3000', ukraine_shipping_uah: null }));
  });
  it('показує дані з бази, ярди та дві трапеції, нічого не записує при відкритті', async () => {
    await open();
    expect(wrapper.get('[name="fabric_length"]').element.value).toBe('10.0000');
    expect(wrapper.get('[name="length_unit"]').element.value).toBe('yard');
    expect(wrapper.get('[name="goods_cny"]').element.value).toBe('100.00');
    expect(wrapper.get('[name="purchased_on"]').element.value).toBe('');
    expect(wrapper.get('[data-testid="fur-length"]').text()).toContain('9,144');
    expect(wrapper.get('[data-testid="fur-unit-cost"]').text()).toContain('2,09');
    expect(wrapper.text()).toContain('2 трапеції'); expect(wrapper.text()).toContain('Плюш і поролон');
    expect(wrapper.text()).toContain('Окрема доставка Україною не врахована');
    expect(createFurBatch).not.toHaveBeenCalled(); expect(updateFurBatch).not.toHaveBeenCalled();
  });
  it('зміна одиниці, курсу та геометрії одразу перераховує, запис — за кнопкою', async () => {
    await open(); await wrapper.get('[name="length_unit"]').setValue('metre');
    expect(wrapper.get('[data-testid="fur-unit-cost"]').text()).toContain('1,88');
    await wrapper.get('[name="usd_rate"]').setValue('41,00');
    expect(wrapper.get('[data-testid="fur-unit-cost"]').text()).toContain('1,89');
    await wrapper.get('[name="height_cm"]').setValue('20');
    expect(wrapper.get('[data-testid="fur-unit-cost"]').text()).toContain('3,79');
    expect(updateFurBatch).not.toHaveBeenCalled();
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateFurBatch).toHaveBeenCalledWith(4, expect.objectContaining({ version: 1, length_unit: 'metre', usd_rate: '41.00', height_cm: '20', ukraine_shipping_uah: null }));
    expect(updateFurBatch.mock.calls[0][1]).not.toHaveProperty('quantity');
    expect(updateFurBatch.mock.calls[0][1]).not.toHaveProperty('total_uah');
    expect(button('Зберегти зміни').attributes('disabled')).toBeDefined();
  });
  it('невідому місцеву доставку можна внести або явно вказати нуль', async () => {
    await open(); await wrapper.get('[name="ukraine_shipping_uah"]').setValue('0');
    expect(wrapper.text()).not.toContain('Окрема доставка Україною не врахована');
    await wrapper.get('[name="ukraine_shipping_uah"]').setValue('100,00');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateFurBatch).toHaveBeenCalledWith(4, expect.objectContaining({ ukraine_shipping_uah: '100.00' }));
  });
  it.each([['fabric_length', '0'], ['fabric_width_cm', '9'], ['usd_rate', '0'], ['cut_length_cm', '19']])('не зберігає некоректне поле %s', async (field, value) => {
    await open(); await wrapper.get(`[name="${field}"]`).setValue(value);
    expect(wrapper.get('[data-testid="fur-unit-cost"]').text()).toContain('—');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateFurBatch).not.toHaveBeenCalled();
  });
  it('нова партія не копіює старі суми й курси; повтор запиту не дублює партію', async () => {
    await open(); await button('Нова партія').trigger('click');
    expect(wrapper.get('[name="top_width_cm"]').element.value).toBe('20.00');
    expect(wrapper.get('[name="length_unit"]').element.value).toBe('yard');
    expect(wrapper.get('[name="fabric_length"]').element.value).toBe('');
    expect(wrapper.get('[name="goods_cny"]').element.value).toBe('');
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('');
    for (const [key, value] of Object.entries(inputs)) { if (value !== null) await wrapper.get(`[name="${key}"]`).setValue(value); }
    createFurBatch.mockRejectedValueOnce(new Error('network'));
    await wrapper.get('form').trigger('submit'); await flushPromises();
    const key = createFurBatch.mock.calls[0][0].request_key;
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(createFurBatch.mock.calls[1][0].request_key).toBe(key);
    expect(updateFurBatch).not.toHaveBeenCalled();
  });
  it('не повторює успішний запис після помилки оновлення списку', async () => {
    await open(); await wrapper.get('[name="usd_rate"]').setValue('41');
    fetchFurBatches.mockRejectedValueOnce(new Error('network'));
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.text()).toContain('Партію збережено, але список не оновився');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateFurBatch).toHaveBeenCalledTimes(1);
  });
  it('конфлікт версії не губить чернетку, відкидання змін вимагає підтвердження', async () => {
    await open(); await wrapper.get('[name="usd_rate"]').setValue('41');
    updateFurBatch.mockRejectedValueOnce({ response: { status: 409, data: { message: 'Оновіть список і відкрийте партію ще раз.' } } });
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('41');
    window.confirm.mockReturnValueOnce(false); await button('Нова партія').trigger('click');
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('41');
    await button('Скасувати зміни').trigger('click');
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('40.0000');
  });
  it('можна повторити завантаження; порожня база не підставляє вигадані суми', async () => {
    fetchFurBatches.mockRejectedValueOnce(new Error('network')); await open();
    expect(wrapper.find('form').exists()).toBe(false);
    fetchFurBatches.mockResolvedValueOnce(listing([])); await button('Оновити список').trigger('click'); await flushPromises();
    expect(wrapper.get('[name="goods_cny"]').element.value).toBe('');
    expect(wrapper.get('[data-testid="fur-unit-cost"]').text()).toContain('—');
    expect(createFurBatch).not.toHaveBeenCalled();
  });
  it('вкладка завантажується один раз, чернетка переживає перемикання', async () => {
    wrapper = mount(ProductionCostPage); await flushPromises();
    expect(fetchFurBatches).not.toHaveBeenCalled();
    await button('Хутро').trigger('click'); await flushPromises();
    expect(fetchFurBatches).toHaveBeenCalledTimes(1);
    await wrapper.get('[data-material="fur"] [name="usd_rate"]').setValue('41');
    await button('Нитки').trigger('click'); await button('Хутро').trigger('click');
    expect(wrapper.get('[data-material="fur"] [name="usd_rate"]').element.value).toBe('41');
    expect(fetchFurBatches).toHaveBeenCalledTimes(1);
    const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(true);
  });
});
