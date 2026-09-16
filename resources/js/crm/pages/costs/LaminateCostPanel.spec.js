import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import LaminateCostPanel from './LaminateCostPanel.vue';
import ProductionCostPage from './ProductionCostPage.vue';
import { fetchLaminateCosts, createLaminateCost, updateLaminateCost, fetchCostBatches } from '@/crm/services/productionCostsApi';
import { calculateLaminateCost, laminateFields, laminatePrecision } from '@/crm/utils/laminateCosts';

vi.mock('@/crm/services/productionCostsApi', async importOriginal => ({ ...await importOriginal(), fetchLaminateCosts: vi.fn(), createLaminateCost: vi.fn(), updateLaminateCost: vi.fn(), fetchCostBatches: vi.fn() }));
const inputs = { cut_width_cm: '100', plush_price_metre_uah: '120', plush_width_cm: '200', plush_shipping_metre_uah: null, web_roll_price_uah: '800', web_roll_length_m: '40', web_width_cm: '100', web_shipping_roll_uah: null, foam_sheet_price_usd: '4', usd_rate: '40', foam_sheet_length_cm: '200', foam_sheet_width_cm: '100', foam_shipping_sheet_uah: null, insole_length_cm: '25', insole_width_cm: '10', upper_top_cm: '20', upper_bottom_cm: '10', upper_height_cm: '10' };
const record = (changes = {}) => {
  const values = { ...inputs, ...changes };
  return { id: 6, version: 1, quantity: 1, name: 'Тестове полотно', purchased_on: null, note: null, ...changes,
    inputs: Object.fromEntries(laminateFields.map(key => [key, values[key] === null ? null : Number(values[key]).toFixed(laminatePrecision[key])])), calculation: calculateLaminateCost(values) };
};
const listing = rows => ({ data: { data: rows, current_page: 1, last_page: 1, total: rows.length } });
let wrapper;
const button = text => wrapper.findAll('button').find(item => item.text().includes(text));
async function open() { wrapper = mount(LaminateCostPanel); await flushPromises(); }
beforeEach(() => {
  vi.clearAllMocks(); vi.spyOn(window, 'confirm').mockReturnValue(true);
  fetchLaminateCosts.mockResolvedValue(listing([record()])); fetchCostBatches.mockResolvedValue(listing([]));
  createLaminateCost.mockImplementation(async data => ({ data: record({ ...data, id: 7 }) }));
  updateLaminateCost.mockImplementation(async (id, data) => ({ data: record({ ...data, id, version: data.version + 1 }) }));
});
afterEach(() => { wrapper?.unmount(); vi.restoreAllMocks(); });

describe('Форма склеєного полотна', () => {
  it('малює дві незалежні схеми, перевертає лише трапеції й перераховує змінену ширину', async () => {
    fetchLaminateCosts.mockResolvedValue(listing([record({ cut_width_cm: '150', insole_length_cm: '27', insole_width_cm: '11.5', upper_top_cm: '20', upper_bottom_cm: '13', upper_height_cm: '7' })]));
    await open();
    const rectangles = wrapper.get('[data-testid="laminate-insole-layout"]');
    const trapezoids = wrapper.get('[data-testid="laminate-upper-layout"]');
    expect(rectangles.findAll('polygon')).toHaveLength(39);
    expect(trapezoids.findAll('polygon')).toHaveLength(105);
    expect(rectangles.get('polygon').attributes('points')).toBe('0,0 27,0 27,11.5 0,11.5');
    expect(trapezoids.findAll('polygon')[0].attributes('points')).toBe('0,0 20,0 16.5,7 3.5,7');
    expect(trapezoids.findAll('polygon')[1].attributes('points')).toBe('20,0 33,0 36.5,7 16.5,7');
    expect(rectangles.get('[role="img"]').attributes('aria-label')).toContain('3 у ряду, 13 рядів');
    expect(trapezoids.get('[role="img"]').attributes('aria-label')).toContain('5 у ряду, 21 рядів');
    expect(wrapper.get('[data-testid="laminate-insole-cost"]').text()).toContain('12,63');
    expect(wrapper.get('[data-testid="laminate-upper-cost"]').text()).toContain('4,62');
    expect(wrapper.get('thead').text()).toContain('2 устілки'); expect(wrapper.get('thead').text()).toContain('2 деталі верху');
    expect(wrapper.text()).not.toContain('Разом');
    await wrapper.get('[name="cut_width_cm"]').setValue('100');
    expect(wrapper.get('[data-testid="laminate-metre-cost"]').text()).toContain('160,00');
    expect(rectangles.findAll('polygon')).toHaveLength(24); expect(trapezoids.findAll('polygon')).toHaveLength(70);
    expect(wrapper.get('[data-testid="laminate-insole-cost"]').text()).toContain('13,33');
    expect(wrapper.get('[data-testid="laminate-upper-cost"]').text()).toContain('4,57');
    expect(updateLaminateCost).not.toHaveBeenCalled();
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateLaminateCost).toHaveBeenCalledWith(6, expect.objectContaining({ cut_width_cm: '100' }));
  });
  it('старий запис без ширини не показує нуль або стару спільну суму', async () => {
    fetchLaminateCosts.mockResolvedValue(listing([record({ cut_width_cm: null })]));
    await open();
    expect(wrapper.get('[name="cut_width_cm"]').element.value).toBe('');
    expect(wrapper.get('[data-testid="laminate-insole-cost"]').text()).toContain('—');
    expect(wrapper.get('[data-testid="laminate-upper-cost"]').text()).toContain('—');
    expect(wrapper.find('[data-testid="laminate-unit-cost"]').exists()).toBe(false);
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateLaminateCost).not.toHaveBeenCalled();
    await wrapper.get('[name="cut_width_cm"]').setValue('150');
    expect(wrapper.get('[data-testid="laminate-insole-cost"]').text()).toContain('8,00');
  });
  it('заповнює форму з БД, показує три шари та окремі витрати без записів', async () => {
    await open();
    expect(wrapper.get('[name="plush_price_metre_uah"]').element.value).toBe('120.00');
    expect(wrapper.get('[name="web_roll_length_m"]').element.value).toBe('40.0000');
    expect(wrapper.get('[name="purchased_on"]').element.value).toBe('');
    expect(wrapper.get('[data-testid="laminate-metre-cost"]').text()).toContain('160,00');
    expect(wrapper.get('[data-testid="laminate-insole-cost"]').text()).toContain('8,00');
    expect(wrapper.get('[data-testid="laminate-upper-cost"]').text()).toContain('5,33');
    expect(wrapper.find('[data-testid="laminate-unit-cost"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="laminate-area"]').exists()).toBe(false);
    expect(wrapper.text()).not.toContain('Разом на пару');
    expect(wrapper.text()).toContain('Клейова павутинка'); expect(wrapper.text()).toContain('Менша поролонова вставка рахується окремо');
    expect(wrapper.text()).toContain('Доставка не врахована');
    expect(wrapper.find('[name="quantity"]').exists()).toBe(false);
    expect(createLaminateCost).not.toHaveBeenCalled(); expect(updateLaminateCost).not.toHaveBeenCalled();
  });
  it('ціни й геометрія одразу змінюють підсумок, запис тільки за кнопкою', async () => {
    await open(); await wrapper.get('[name="foam_sheet_price_usd"]').setValue('5,00');
    expect(wrapper.get('[data-testid="laminate-insole-cost"]').text()).toContain('9,00');
    expect(wrapper.get('[data-testid="laminate-upper-cost"]').text()).toContain('6,00');
    await wrapper.get('[name="insole_length_cm"]').setValue('50');
    expect(wrapper.get('[data-testid="laminate-insole-cost"]').text()).toContain('18,00');
    expect(wrapper.get('[data-testid="laminate-upper-cost"]').text()).toContain('6,00');
    expect(updateLaminateCost).not.toHaveBeenCalled();
    await wrapper.get('form').trigger('submit'); await flushPromises();
    const payload = updateLaminateCost.mock.calls[0][1];
    expect(payload).toMatchObject({ version: 1, foam_sheet_price_usd: '5.00', insole_length_cm: '50', purchased_on: null, plush_shipping_metre_uah: null });
    expect(payload).not.toHaveProperty('quantity'); expect(payload).not.toHaveProperty('total_uah'); expect(payload).not.toHaveProperty('unit_cost_uah');
    expect(button('Зберегти зміни').attributes('disabled')).toBeDefined();
  });
  it('не підміняє невідому доставку нулем і розподіляє кожну на правильну одиницю', async () => {
    await open();
    for (const field of ['plush_shipping_metre_uah', 'web_shipping_roll_uah', 'foam_shipping_sheet_uah']) await wrapper.get(`[name="${field}"]`).setValue('0');
    expect(wrapper.text()).not.toContain('Доставка не врахована');
    await wrapper.get('[name="plush_shipping_metre_uah"]').setValue('20');
    await wrapper.get('[name="web_shipping_roll_uah"]').setValue('400');
    await wrapper.get('[name="foam_shipping_sheet_uah"]').setValue('20');
    expect(wrapper.get('[data-testid="laminate-insole-cost"]').text()).toContain('9,50');
    expect(wrapper.get('[data-testid="laminate-upper-cost"]').text()).toContain('6,33');
  });
  it.each([['usd_rate', '0'], ['web_roll_length_m', '0'], ['plush_width_cm', '5'], ['upper_height_cm', '0']])('не зберігає некоректне поле %s', async (field, value) => {
    await open(); await wrapper.get(`[name="${field}"]`).setValue(value);
    expect(wrapper.get('[data-testid="laminate-insole-cost"]').text()).toContain('—');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateLaminateCost).not.toHaveBeenCalled();
  });
  it('новий розрахунок очищує ціни, лишає розміри й повторює UUID після мережевої помилки', async () => {
    await open(); await button('Новий розрахунок').trigger('click');
    expect(wrapper.get('[name="plush_price_metre_uah"]').element.value).toBe('');
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('');
    expect(wrapper.get('[name="insole_length_cm"]').element.value).toBe('25.00');
    for (const [key, value] of Object.entries(inputs)) if (value !== null) await wrapper.get(`[name="${key}"]`).setValue(value);
    createLaminateCost.mockRejectedValueOnce(new Error('network'));
    await wrapper.get('form').trigger('submit'); await flushPromises();
    const key = createLaminateCost.mock.calls[0][0].request_key;
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(createLaminateCost.mock.calls[1][0].request_key).toBe(key);
    expect(updateLaminateCost).not.toHaveBeenCalled();
  });
  it('не повторює запис, коли після успішного збереження не оновився список', async () => {
    await open(); await wrapper.get('[name="usd_rate"]').setValue('41');
    fetchLaminateCosts.mockRejectedValueOnce(new Error('network'));
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.text()).toContain('Розрахунок збережено, але список не оновився');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(updateLaminateCost).toHaveBeenCalledTimes(1);
  });
  it('конфлікт не губить чернетку, скасування повертає збережені ціни', async () => {
    await open(); await wrapper.get('[name="usd_rate"]').setValue('41');
    updateLaminateCost.mockRejectedValueOnce({ response: { status: 409, data: { message: 'Оновіть список і відкрийте розрахунок ще раз.' } } });
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('41');
    window.confirm.mockReturnValueOnce(false); await button('Новий розрахунок').trigger('click');
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('41');
    await button('Скасувати зміни').trigger('click');
    expect(wrapper.get('[name="usd_rate"]').element.value).toBe('40.0000');
  });
  it('порожня база не підставляє вигадані ціни, завантаження можна повторити', async () => {
    fetchLaminateCosts.mockRejectedValueOnce(new Error('network')); await open();
    expect(wrapper.find('form').exists()).toBe(false);
    fetchLaminateCosts.mockResolvedValueOnce(listing([])); await button('Оновити список').trigger('click'); await flushPromises();
    expect(wrapper.get('[name="plush_price_metre_uah"]').element.value).toBe('');
    expect(wrapper.get('[data-testid="laminate-insole-cost"]').text()).toContain('—');
    expect(createLaminateCost).not.toHaveBeenCalled();
  });
  it('ліниве завантаження та збереження чернетки між вкладками матеріалів', async () => {
    wrapper = mount(ProductionCostPage); await flushPromises();
    expect(fetchLaminateCosts).not.toHaveBeenCalled();
    await button('Плюш + поролон').trigger('click'); await flushPromises();
    expect(fetchLaminateCosts).toHaveBeenCalledTimes(1);
    await wrapper.get('[data-material="laminate"] [name="usd_rate"]').setValue('41');
    await button('Нитки').trigger('click'); await button('Плюш + поролон').trigger('click');
    expect(wrapper.get('[data-material="laminate"] [name="usd_rate"]').element.value).toBe('41');
    expect(fetchLaminateCosts).toHaveBeenCalledTimes(1);
    const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(true);
  });
  it('захищає форму й вкладки під час збереження', async () => {
    wrapper = mount(ProductionCostPage); await flushPromises();
    await button('Плюш + поролон').trigger('click'); await flushPromises();
    await wrapper.get('[data-material="laminate"] [name="usd_rate"]').setValue('41');
    let finish;
    updateLaminateCost.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
    await wrapper.get('[data-material="laminate"] form').trigger('submit'); await flushPromises();
    expect(wrapper.get('[data-material="laminate"] fieldset').attributes('disabled')).toBeDefined();
    expect(button('Нитки').attributes('disabled')).toBeDefined();
    finish({ data: record({ usd_rate: '41', version: 2 }) }); await flushPromises();
    expect(button('Нитки').attributes('disabled')).toBeUndefined();
  });
});
