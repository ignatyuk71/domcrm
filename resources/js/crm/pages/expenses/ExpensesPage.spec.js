import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import ExpensesPage from './ExpensesPage.vue';
import * as api from '@/crm/services/expensesApi';

vi.mock('@/crm/services/expensesApi', async original => ({ ...await original(), fetchExpenseMeta: vi.fn(), fetchExpenses: vi.fn(), fetchExpense: vi.fn(), deleteExpense: vi.fn(), deleteExpensePayment: vi.fn(), deleteExpenseReceipt: vi.fn(), exportExpenses: vi.fn() }));
const receipt = { id: 11, name: 'receipt.pdf', mime_type: 'application/pdf', size: 100, url: '/api/expenses/receipts/11', download_url: '/api/expenses/receipts/11?download=1' };
const payment = { id: 3, expense_id: 7, title: 'Реклама', category: { id: 1, name: 'Реклама', color: '#6954df' }, account: { id: 2, name: 'Рахунок' }, amount: '120.00', currency: 'UAH', amount_uah: '120.00', paid_on: '2026-09-20', receipts: [receipt] };
const expense = { id: 7, title: 'Реклама', category_id: 1, amount: '120.00', currency: 'UAH', paid_amount: '120.00', remaining_amount: '0.00', due_on: '2026-09-20', version: 3, payments: [payment] };
const response = title => ({ summary: { paid_amount: '120.00', paid_count: 1, pending_amount: '0.00', pending_count: 0, missing_receipts_count: 0, demo_count: 0 }, breakdown: { categories: [{ id: 1, name: 'Реклама', color: '#6954df', amount: '120.00' }], accounts: [{ id: 2, name: 'Рахунок', amount: '120.00' }], recipients: [], groups: [] }, data: [{ ...payment, title }], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } });
const clone = value => JSON.parse(JSON.stringify(value));
let wrapper;
async function open() { wrapper = mount(ExpensesPage, { global: { stubs: { teleport: true } }, attachTo: document.body }); await flushPromises(); }
beforeEach(() => {
  vi.clearAllMocks(); vi.spyOn(window, 'confirm').mockReturnValue(true);
  api.fetchExpenseMeta.mockResolvedValue({ data: { categories: [payment.category], accounts: [payment.account], groups: [], currencies: ['UAH'], today: '2026-09-21' } });
  api.fetchExpenses.mockResolvedValue({ data: response('Реклама') });
  api.fetchExpense.mockResolvedValue({ data: { data: clone(expense) } });
});
afterEach(() => { wrapper?.unmount(); document.body.innerHTML = ''; vi.restoreAllMocks(); });

describe('Сторінка витрат', () => {
  it('показує серверні підсумки без повідомлення успіху і фільтрує кліком на аналітику', async () => {
    await open();
    expect(wrapper.get('.expense-kpi.featured').text()).toContain('120,00');
    expect(wrapper.find('[role="status"]').exists()).toBe(false);
    expect(wrapper.find('.expense-demo').exists()).toBe(false);
    await wrapper.get('.expense-bars button').trigger('click'); await flushPromises();
    expect(api.fetchExpenses.mock.lastCall[0].category_id).toBe(1);
    await wrapper.get('.expense-account').trigger('click'); await flushPromises();
    expect(api.fetchExpenses.mock.lastCall[0].account_id).toBe(2);
  });

  it('пізня відповідь старого фільтра не перекриває новий результат', async () => {
    await open();
    let resolveOld;
    api.fetchExpenses.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve; }));
    api.fetchExpenses.mockResolvedValueOnce({ data: response('Новий результат') });
    await wrapper.get('[aria-label="Фільтр категорії"]').setValue('1');
    await wrapper.get('[aria-label="Фільтр рахунку"]').setValue('2'); await flushPromises();
    expect(wrapper.get('.expense-title-link').text()).toBe('Новий результат');
    resolveOld({ data: response('Стара відповідь') }); await flushPromises();
    expect(wrapper.get('.expense-title-link').text()).toBe('Новий результат');
  });

  it('після вилучення квитанції використовує оновлену версію витрати', async () => {
    const updated = { ...expense, version: 4, payments: [{ ...payment, receipts: [] }] };
    api.fetchExpense.mockResolvedValueOnce({ data: { data: clone(expense) } }).mockResolvedValueOnce({ data: { data: updated } });
    api.deleteExpenseReceipt.mockResolvedValue({ data: { ok: true } });
    api.deleteExpensePayment.mockResolvedValue({ data: { data: { ...updated, version: 5, payments: [] } } });
    await open(); await wrapper.get('.expense-title-link').trigger('click'); await flushPromises();
    await wrapper.get('[aria-label="Видалити квитанцію receipt.pdf"]').trigger('click'); await flushPromises();
    expect(api.fetchExpense).toHaveBeenCalledTimes(2);
    await wrapper.get('[aria-label="Видалити оплату"]').trigger('click'); await flushPromises();
    expect(api.deleteExpensePayment).toHaveBeenCalledWith(7, 3, 4);
  });

  it('після успішного DELETE і помилки оновлення повторює GET, а не видалення', async () => {
    api.fetchExpense.mockResolvedValueOnce({ data: { data: clone(expense) } }).mockRejectedValueOnce(new Error('Немає мережі')).mockResolvedValueOnce({ data: { data: { ...expense, version: 4 } } });
    api.deleteExpenseReceipt.mockResolvedValue({ data: { ok: true } });
    await open(); await wrapper.get('.expense-title-link').trigger('click'); await flushPromises();
    await wrapper.get('[aria-label="Видалити квитанцію receipt.pdf"]').trigger('click'); await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Квитанцію видалено');
    await wrapper.findAll('button').find(button => button.text() === 'Оновити дані').trigger('click'); await flushPromises();
    expect(api.deleteExpenseReceipt).toHaveBeenCalledTimes(1);
    expect(api.fetchExpense).toHaveBeenCalledTimes(3);
  });
});
