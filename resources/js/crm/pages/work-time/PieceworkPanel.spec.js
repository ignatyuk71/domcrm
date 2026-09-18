import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import PieceworkPanel from './PieceworkPanel.vue';
import * as api from '../../services/pieceworkApi';

vi.mock('../../services/pieceworkApi', () => ({ fetchPiecework: vi.fn(), createPiecework: vi.fn(), updatePiecework: vi.fn() }));
const employee = { id: 2, name: 'Тестовий виконавець', payment_type: 'piecework', archived_on: null };
const entry = { id: 3, employee_id: 2, date: '2026-09-18', description: 'Розкрій', quantity: 100, unit: 'piece', pricing_mode: 'unit', unit_rate: '2.35', total: '235.00', paid: '50.00', balance: '185.00', note: null, version: 1 };
const listing = entries => ({ month: '2026-09', page: 1, last_page: 1, count: entries.length, employees: [employee], entries, summary: { total: '235.00', paid: '50.00', balance: '185.00' } });
let wrapper;
const button = label => wrapper.findAll('button').find(node => node.text().includes(label));
async function open(entries = []) {
  api.fetchPiecework.mockResolvedValue({ data: listing(entries) });
  wrapper = mount(PieceworkPanel, { props: { month: '2026-09' }, attachTo: document.body }); await flushPromises();
}
async function fill() {
  await button('Додати роботу').trigger('click'); await flushPromises();
  await wrapper.get('[name="piece_description"]').setValue('Розкрій');
  await wrapper.get('[name="piece_quantity"]').setValue('100');
  await wrapper.get('[name="unit_rate"]').setValue('2,35');
  await wrapper.get('[name="piece_paid"]').setValue('50');
}
beforeEach(() => {
  vi.clearAllMocks(); vi.spyOn(window, 'confirm').mockReturnValue(true);
  vi.spyOn(HTMLDialogElement.prototype, 'showModal').mockImplementation(function () { this.setAttribute('open', ''); });
  vi.spyOn(HTMLDialogElement.prototype, 'close').mockImplementation(function () { this.removeAttribute('open'); });
  api.createPiecework.mockResolvedValue({ data: entry }); api.updatePiecework.mockResolvedValue({ data: entry });
});
afterEach(() => { wrapper?.unmount(); document.body.innerHTML = ''; vi.restoreAllMocks(); });

describe('Відрядні роботи', () => {
  it('показує працівника без робіт, не вимагає годин і нічого не записує при відкритті', async () => {
    await open(); expect(wrapper.text()).toContain(employee.name); expect(wrapper.text()).toContain('Ще немає виконаних робіт');
    expect(api.fetchPiecework).toHaveBeenCalledWith('2026-09', 1); expect(api.createPiecework).not.toHaveBeenCalled();
    expect(wrapper.find('[name="hours"]').exists()).toBe(false);
  });
  it('рахує кількість × ставку, приймає кому та відправляє лише вихідні дані', async () => {
    await open(); await fill(); expect(wrapper.get('[data-testid="piece-total"]').text()).toContain('235');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(api.createPiecework).toHaveBeenCalledWith(expect.objectContaining({ employee_id: 2, quantity: 100, unit_rate: '2.35', paid: '50.00' }));
    expect(api.createPiecework.mock.calls[0][0]).not.toHaveProperty('total');
    expect(wrapper.find('dialog[open]').exists()).toBe(false);
  });
  it('домовлена сума не множиться повторно на кількість', async () => {
    await open(); await fill(); await wrapper.get('[name="pricing_mode"]').setValue('fixed');
    await wrapper.get('[name="agreed_total"]').setValue('400,01');
    expect(wrapper.get('[data-testid="piece-total"]').text()).toContain('400,01');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(api.createPiecework.mock.calls[0][0]).toMatchObject({ quantity: 100, pricing_mode: 'fixed', agreed_total: '400.01' });
    expect(api.createPiecework.mock.calls[0][0]).not.toHaveProperty('unit_rate');
  });
  it('повтор після збою зберігає той самий ключ та чернетку', async () => {
    api.createPiecework.mockRejectedValueOnce(new Error('offline'));
    await open(); await fill(); await wrapper.get('form').trigger('submit'); await flushPromises();
    const first = api.createPiecework.mock.calls[0][0];
    expect(wrapper.get('[name="piece_description"]').element.value).toBe('Розкрій');
    expect(wrapper.find('dialog[open]').exists()).toBe(true);
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(api.createPiecework.mock.calls[1][0]).toEqual(first);
  });
  it('редагує із версією, а конфлікт не перезаписує', async () => {
    api.updatePiecework.mockRejectedValueOnce({ response: { status: 409 } });
    await open([entry]); await wrapper.get('[aria-label="Редагувати: Розкрій"]').trigger('click'); await flushPromises();
    expect(wrapper.get('[name="piece_employee"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[name="piece_paid"]').setValue('235'); await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(api.updatePiecework).toHaveBeenCalledWith(3, expect.objectContaining({ version: 1, paid: '235.00' }));
    expect(wrapper.text()).toContain('Запис уже змінився'); expect(button('Зберегти роботу').attributes('disabled')).toBeDefined();
  });
  it('не губить чернетку при скасуванні та перевіряє кількість', async () => {
    await open(); await fill(); vi.mocked(window.confirm).mockReturnValue(false);
    await button('Скасувати').trigger('click'); expect(wrapper.find('dialog[open]').exists()).toBe(true);
    await wrapper.get('[name="piece_quantity"]').setValue('1.5'); await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(api.createPiecework).not.toHaveBeenCalled(); expect(wrapper.text()).toContain('цілим числом');
  });
  it('ігнорує запізнілу відповідь попереднього місяця', async () => {
    let resolveOld;
    await open(); api.fetchPiecework.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve; }));
    await wrapper.setProps({ month: '2026-10' });
    api.fetchPiecework.mockResolvedValueOnce({ data: { ...listing([]), month: '2026-11', summary: { total: '999.00', paid: '0.00', balance: '999.00' } } });
    await wrapper.setProps({ month: '2026-11' }); await flushPromises();
    resolveOld({ data: listing([]) }); await flushPromises(); expect(wrapper.find('.pw-summary').text()).toContain('999');
  });
  it('відкриває редагування відрядного працівника без картки годин', async () => {
    await open(); await wrapper.get('.pw-person').trigger('click'); expect(wrapper.emitted('edit-employee')[0]).toEqual([employee]);
  });
});
