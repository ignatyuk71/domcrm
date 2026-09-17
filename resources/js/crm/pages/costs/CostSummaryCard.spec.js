import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import CostSummaryCard from './CostSummaryCard.vue';
import { fetchCostSummary } from '@/crm/services/productionCostsApi';

vi.mock('@/crm/services/productionCostsApi', () => ({ fetchCostSummary: vi.fn() }));
const row = (value, name = 'Збережена партія') => ({ name, calculation: { unit_cost_uah: value } });
let wrapper;
const openDetails = async () => wrapper.get('[aria-controls]').trigger('click');
async function open(props = {}) { wrapper = mount(CostSummaryCard, { props: { modelId: 12, ...props }, attachTo: document.body }); await flushPromises(); }
beforeEach(() => { vi.clearAllMocks(); fetchCostSummary.mockResolvedValue({ data: { model_id: 12, components: { soles: row(40), fur: row(15) } } }); });
afterEach(() => { wrapper?.unmount(); });
describe('Картка підсумку', () => {
  it('показує часткову ціну та приховує деталі до кліку', async () => {
    await open(); expect(fetchCostSummary).toHaveBeenCalledWith(12);
    expect(wrapper.get('[data-testid="pair-total"]').text()).toBe('55,00');
    expect(wrapper.text()).toContain('Враховано 2 із 9'); expect(wrapper.text()).toContain('без ниток і роботи');
    expect(wrapper.find('.pair-summary-details').exists()).toBe(false);
    await openDetails(); expect(wrapper.findAll('.pair-summary-row')).toHaveLength(9);
    expect(wrapper.text()).toContain('Це ще не повна собівартість');
  });
  it('чернетки, зміна партії та скасування одразу оновлюють суму без додавання двох партій', async () => {
    await open(); await wrapper.setProps({ snapshots: { fur: { ...row(20, 'Інша партія'), dirty: true } } });
    expect(wrapper.get('[data-testid="pair-total"]').text()).toBe('60,00'); expect(wrapper.text()).toContain('Чернетка');
    await wrapper.setProps({ snapshots: { fur: { calculation: null, dirty: true } } });
    expect(wrapper.get('[data-testid="pair-total"]').text()).toBe('40,00');
    await wrapper.setProps({ snapshots: { fur: row(15) } }); expect(wrapper.get('[data-testid="pair-total"]').text()).toBe('55,00');
  });
  it('пізній GET не перезаписує редагування форми', async () => {
    let finish; fetchCostSummary.mockReturnValueOnce(new Promise(resolve => { finish = resolve; }));
    await open({ snapshots: { soles: { ...row(60), dirty: true } } });
    expect(wrapper.get('[data-testid="pair-total"]').text()).toBe('—');
    finish({ data: { model_id: 12, components: { soles: row(40), fur: row(15) } } }); await flushPromises();
    expect(wrapper.get('[data-testid="pair-total"]').text()).toBe('75,00');
  });
  it('помилка не показує неперевірену суму, повтор та порожні дані обробляються', async () => {
    fetchCostSummary.mockRejectedValueOnce(new Error('network')); await open();
    expect(wrapper.get('[data-testid="pair-total"]').text()).toBe('—');
    fetchCostSummary.mockResolvedValueOnce({ data: { model_id: 12, components: {} } });
    await wrapper.get('button').trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('Додайте перший розрахунок'); expect(wrapper.get('[data-testid="pair-total"]').text()).toBe('—');
  });
  it('не використовує відповідь іншої категорії', async () => {
    fetchCostSummary.mockResolvedValueOnce({ data: { model_id: 99, components: { soles: row(500) } } }); await open();
    expect(wrapper.get('[data-testid="pair-total"]').text()).toBe('—'); expect(wrapper.text()).toContain('Не вдалося');
  });
  it('відкриває матеріал, закривається Escape і кліком поза карткою та блокує вибір під час збереження', async () => {
    await open(); await openDetails(); await wrapper.get('.pair-summary-row').trigger('click');
    expect(wrapper.emitted('select-part')).toEqual([['soles']]); expect(wrapper.find('.pair-summary-details').exists()).toBe(false);
    await openDetails(); document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' })); await flushPromises();
    expect(wrapper.get('[aria-controls]').element).toBe(document.activeElement);
    await openDetails(); document.body.dispatchEvent(new Event('pointerdown', { bubbles: true })); await flushPromises();
    expect(wrapper.find('.pair-summary-details').exists()).toBe(false);
    await wrapper.setProps({ busy: true }); await openDetails(); expect(wrapper.get('.pair-summary-row').attributes('disabled')).toBeDefined();
  });
});
