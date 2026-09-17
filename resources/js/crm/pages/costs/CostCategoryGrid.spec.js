import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, ref } from 'vue';
import ProductionCostPage from './ProductionCostPage.vue';
import ProductionCostWorkspace from './ProductionCostWorkspace.vue';
import { fetchCostModels, openCostModel, fetchCostBatches, fetchCardboardBatches, fetchCostSummary, updateCostBatch } from '@/crm/services/productionCostsApi';
import { calculateSoleCost } from '@/crm/utils/soleCosts';

vi.mock('@/crm/services/productionCostsApi', async original => ({ ...await original(), fetchCostModels: vi.fn(), openCostModel: vi.fn(), fetchCostBatches: vi.fn(), fetchCardboardBatches: vi.fn(), fetchCostSummary: vi.fn(), updateCostBatch: vi.fn() }));
const catalog = () => ({ models: [{ id: 11, category_id: 1, name: 'Категорія А', photo_url: '/storage/test-a.jpg', records_count: 7, materials_count: 6, is_default: true }], categories: [{ id: 2, name: 'Категорія Б', footwear: true, photo_url: null }, { id: 3, name: 'Інша категорія', footwear: false }] });
let wrapper, unsaved, busy;
const Stub = defineComponent({ props: ['modelId'], setup(_, { expose }) { unsaved = ref(false); busy = ref(false); expose({ hasUnsavedChanges: unsaved, isSaving: busy }); }, template: '<div data-testid="workspace">Модель {{ modelId }}</div>' });
const button = text => wrapper.findAll('button').find(item => item.text().includes(text));
async function open(real = false) { wrapper = mount(ProductionCostPage, { global: { stubs: real ? {} : { ProductionCostWorkspace: Stub } } }); await flushPromises(); }
beforeEach(() => {
  vi.clearAllMocks(); vi.spyOn(window, 'confirm').mockReturnValue(true);
  fetchCostModels.mockResolvedValue({ data: catalog() });
  openCostModel.mockResolvedValue({ data: { id: 22, category_id: 2, name: 'Категорія Б' } });
  fetchCostBatches.mockResolvedValue({ data: { data: [], total: 0, current_page: 1, last_page: 1 } });
  fetchCardboardBatches.mockResolvedValue({ data: { data: [], total: 0, current_page: 1, last_page: 1 } });
  fetchCostSummary.mockImplementation(async modelId => ({ data: { model_id: modelId, components: {} } }));
});
afterEach(() => { wrapper?.unmount(); vi.restoreAllMocks(); });
describe('Грід категорій виробництва', () => {
  it('спочатку показує грід, не відкриває форми і нічого не створює', async () => {
    await open(); expect(wrapper.findAll('.category-card')).toHaveLength(2);
    expect(wrapper.text()).toContain('Матеріалів із даними: 6');
    expect(wrapper.text()).not.toContain('0,00');
    expect(openCostModel).not.toHaveBeenCalled(); expect(fetchCostBatches).not.toHaveBeenCalled();
    expect(wrapper.find('[data-testid="workspace"]').exists()).toBe(false);
    await wrapper.get('img').trigger('error'); expect(wrapper.find('img').exists()).toBe(false);
  });
  it('відкриває існуючу модель без POST та повертається до карток', async () => {
    await open(); await wrapper.get('[data-testid="model-11"]').trigger('click');
    expect(wrapper.get('h1').text()).toBe('Категорія А'); expect(wrapper.get('[data-testid="workspace"]').text()).toContain('11');
    expect(openCostModel).not.toHaveBeenCalled();
    await button('До категорій').trigger('click'); await flushPromises(); expect(wrapper.findAll('.category-card')).toHaveLength(2);
  });
  it('нова категорія має власний ID, подвійний клік не дублюється', async () => {
    let finish; openCostModel.mockReturnValueOnce(new Promise(resolve => { finish = resolve; }));
    await open(); const card = wrapper.get('[data-testid="category-2"]'); await card.trigger('click'); await card.trigger('click');
    expect(openCostModel).toHaveBeenCalledTimes(1); expect(openCostModel).toHaveBeenCalledWith(2);
    finish({ data: { id: 22, name: 'Категорія Б' } }); await flushPromises();
    expect(wrapper.get('[data-testid="workspace"]').text()).toContain('22');
  });
  it('помилку відкриття можна повторити, інша категорія не відкривається', async () => {
    openCostModel.mockRejectedValueOnce(new Error('network')); await open();
    await wrapper.get('[data-testid="category-2"]').trigger('click'); await flushPromises();
    expect(wrapper.find('[role="alert"]').exists()).toBe(true); expect(wrapper.find('[data-testid="workspace"]').exists()).toBe(false);
    await wrapper.get('[data-testid="category-2"]').trigger('click'); await flushPromises(); expect(wrapper.get('h1').text()).toBe('Категорія Б');
  });
  it('запитує про чернетки й блокує вихід під час збереження', async () => {
    await open(); await wrapper.get('[data-testid="model-11"]').trigger('click'); unsaved.value = true; window.confirm.mockReturnValueOnce(false);
    await button('До категорій').trigger('click'); expect(wrapper.find('[data-testid="workspace"]').exists()).toBe(true);
    busy.value = true; await flushPromises(); expect(button('До категорій').attributes('disabled')).toBeDefined();
    busy.value = false; await flushPromises(); await button('До категорій').trigger('click'); await flushPromises(); expect(wrapper.find('[data-testid="workspace"]').exists()).toBe(false);
  });
  it('дозволяє вибрати іншу категорію з CRM, не створює вигадану назву', async () => {
    await open(); await button('Інша категорія з CRM').trigger('click'); await wrapper.get('select').setValue('3');
    await wrapper.get('form').trigger('submit'); await flushPromises(); expect(openCostModel).toHaveBeenCalledWith(3);
  });
  it('не вставляє небезпечний URL фото та повторює збій GET', async () => {
    fetchCostModels.mockRejectedValueOnce(new Error('network')); await open(); expect(wrapper.find('.category-card').exists()).toBe(false);
    const data = catalog(); data.models[0].photo_url = 'javascript:alert(1)'; fetchCostModels.mockResolvedValueOnce({ data });
    await button('Оновити').trigger('click'); await flushPromises(); expect(wrapper.find('img').exists()).toBe(false);
  });
  it('підсумок реагує на форму, скасування й збереження та очищується між категоріями', async () => {
    const inputs = { goods_cny: '200', china_shipping_cny: '10', commission_percent: '10', international_shipping_usd: '100', ukraine_shipping_uah: '500', other_costs_uah: '0', cny_rate: '6', usd_rate: '40' };
    const record = { id: 1, name: 'Тестова партія', version: 1, quantity: 100, inputs, calculation: calculateSoleCost({ ...inputs, quantity: 100 }) };
    fetchCostBatches.mockImplementation(async (page, modelId) => ({ data: { data: modelId === 11 ? [record] : [], total: modelId === 11 ? 1 : 0, current_page: 1, last_page: 1 } }));
    fetchCostSummary.mockImplementation(async modelId => ({ data: { model_id: modelId, components: modelId === 11 ? { soles: record, tape: { name: 'Стрічка', calculation: { unit_cost_uah: 5 } } } : {} } }));
    updateCostBatch.mockImplementation(async (id, payload) => ({ data: { ...record, version: 2, inputs: { ...inputs, usd_rate: payload.usd_rate } } }));
    await open(true); await wrapper.get('[data-testid="model-11"]').trigger('click'); await flushPromises();
    expect(wrapper.get('[data-testid="pair-total"]').text()).toBe('63,86');
    await wrapper.get('[name="usd_rate"]').setValue('41'); expect(wrapper.get('[data-testid="pair-total"]').text()).toBe('64,86');
    expect(wrapper.get('.pair-summary').text()).toContain('Чернетка');
    await button('Скасувати зміни').trigger('click'); expect(wrapper.get('[data-testid="pair-total"]').text()).toBe('63,86');
    await wrapper.get('[name="usd_rate"]').setValue('41'); await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.get('[data-testid="pair-total"]').text()).toBe('64,86'); expect(wrapper.get('.pair-summary').text()).not.toContain('Чернетка');
    await wrapper.get('.pair-summary [aria-controls]').trigger('click');
    const thread = wrapper.findAll('.pair-summary-row').find(row => row.text().includes('Нитки')); await thread.trigger('click'); await flushPromises();
    expect(wrapper.get('.cost-components [aria-pressed="true"]').text()).toBe('Нитки');
    await button('До категорій').trigger('click'); await flushPromises(); await wrapper.get('[data-testid="category-2"]').trigger('click'); await flushPromises();
    expect(wrapper.get('[data-testid="pair-total"]').text()).toBe('—'); expect(wrapper.get('.pair-summary').text()).not.toContain('Стрічка');
  });
  it('реальні форми отримують ID моделі, а чернетка прихованого матеріалу захищена', async () => {
    await open(true); await wrapper.get('[data-testid="model-11"]').trigger('click'); await flushPromises();
    expect(fetchCostBatches).toHaveBeenCalledWith(1, 11);
    await button('Картон').trigger('click'); await flushPromises(); expect(fetchCardboardBatches).toHaveBeenCalledWith(1, 11);
    await wrapper.get('[data-material="cardboard"] [name="goods_uah"]').setValue('123'); await button('Підошва').trigger('click');
    expect(wrapper.getComponent(ProductionCostWorkspace).vm.hasUnsavedChanges).toBe(true);
    window.confirm.mockReturnValueOnce(false); await button('До категорій').trigger('click'); expect(wrapper.findComponent(ProductionCostWorkspace).exists()).toBe(true);
    await button('До категорій').trigger('click'); await flushPromises(); await wrapper.get('[data-testid="category-2"]').trigger('click'); await flushPromises();
    expect(fetchCostBatches).toHaveBeenLastCalledWith(1, 22);
    await button('Картон').trigger('click'); await flushPromises(); expect(fetchCardboardBatches).toHaveBeenLastCalledWith(1, 22);
    expect(wrapper.get('[data-material="cardboard"] [name="goods_uah"]').element.value).toBe('');
  });
});
