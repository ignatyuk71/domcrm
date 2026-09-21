import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import ExpenseEditor from './ExpenseEditor.vue';
import * as api from '@/crm/services/expensesApi';

vi.mock('@/crm/services/expensesApi', async original => ({
  ...await original(), createExpense: vi.fn(), updateExpense: vi.fn(), createExpensePayment: vi.fn(), updateExpensePayment: vi.fn(),
  uploadExpenseReceipts: vi.fn(), createExpenseDictionary: vi.fn(), fetchExpense: vi.fn(),
}));
const meta = { categories: [{ id: 1, name: 'Матеріали', color: '#6954df' }], accounts: [{ id: 2, name: 'Рахунок' }], groups: [], currencies: ['UAH', 'USD'], today: '2026-09-21' };
const payment = { id: 15, expense_id: 7, amount: '25.00', currency: 'USD', exchange_rate: '41.00', amount_uah: '1025.00', paid_on: '2026-09-20', account: { id: 2, name: 'Рахунок' }, note: '', receipts: [] };
const expense = { id: 7, title: 'Матеріали', category_id: 1, account_id: 2, currency: 'USD', expected_exchange_rate: '41', amount: '100.00', paid_amount: '25.00', remaining_amount: '75.00', version: 3, due_on: '2026-09-21', payments: [payment] };
let wrapper;
const open = props => { wrapper = mount(ExpenseEditor, { props: { mode: 'new-paid', meta, ...props }, global: { stubs: { teleport: true } }, attachTo: document.body }); return wrapper; };
const submit = async () => { await wrapper.get('form').trigger('submit'); await flushPromises(); };
async function fillNew() {
  await wrapper.get('[name="title"]').setValue('Реклама за вересень');
  await wrapper.get('[name="category_id"]').setValue('1');
  await wrapper.get('[name="account_id"]').setValue('2');
  await wrapper.get('[name="amount"]').setValue('50.25');
}
async function attachFile(file = new File(['receipt'], 'receipt.pdf', { type: 'application/pdf' })) {
  const input = wrapper.get('input[type="file"]');
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
  await input.trigger('change');
}
beforeEach(() => { vi.clearAllMocks(); vi.spyOn(window, 'confirm').mockReturnValue(true); });
afterEach(() => { wrapper?.unmount(); document.body.innerHTML = ''; vi.restoreAllMocks(); });

describe('Редактор витрат', () => {
  it('залишає чернетку та файли після помилки сервера і підсвічує поле', async () => {
    api.createExpense.mockRejectedValue({ response: { status: 422, data: { errors: { title: ['Назва вже зайнята.'] } } } });
    open(); await fillNew(); await attachFile(); await submit();
    expect(wrapper.get('[name="title"]').element.value).toBe('Реклама за вересень');
    expect(wrapper.get('[name="amount"]').element.value).toBe('50.25');
    expect(wrapper.get('[name="title"]').attributes('aria-invalid')).toBe('true');
    expect(wrapper.get('[role="alert"]').text()).toContain('Назва вже зайнята.');
    expect(wrapper.text()).toContain('receipt.pdf');
    expect(wrapper.emitted('saved')).toBeUndefined();
    expect(api.uploadExpenseReceipts).not.toHaveBeenCalled();
  });

  it('повторює лише квитанції після створення оплати, не створює другу витрату', async () => {
    const created = { ...expense, id: 9, payments: [{ ...payment, id: 99 }] };
    api.createExpense.mockResolvedValue({ data: { data: created } });
    api.uploadExpenseReceipts.mockRejectedValueOnce(new Error('З’єднання перервано')).mockResolvedValueOnce({ data: { data: { ...created, version: 4 } } });
    open(); await fillNew(); await attachFile(); await submit();
    expect(api.createExpense).toHaveBeenCalledTimes(1);
    expect(wrapper.get('[role="alert"]').text()).toContain('Оплату збережено, квитанції не завантажено');
    expect(wrapper.text()).toContain('receipt.pdf');
    expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined();
    await submit();
    expect(api.createExpense).toHaveBeenCalledTimes(1);
    expect(api.uploadExpenseReceipts).toHaveBeenCalledTimes(2);
    expect(api.uploadExpenseReceipts.mock.calls[1].slice(0, 2)).toEqual([9, 99]);
    expect(wrapper.emitted('saved')[0][0].version).toBe(4);
  });

  it('дозволяє часткову оплату й повторює мережеву помилку з тим самим ключем', async () => {
    api.createExpensePayment.mockRejectedValueOnce(new Error('Немає мережі')).mockResolvedValueOnce({ data: { data: { ...expense, version: 4, payments: [...expense.payments, { ...payment, id: 16 }] } } });
    open({ mode: 'add-payment', expense });
    await wrapper.get('[name="amount"]').setValue('12,50');
    await wrapper.get('[name="exchange_rate"]').setValue('40,15');
    await submit(); await submit();
    const first = api.createExpensePayment.mock.calls[0];
    expect(first[0]).toBe(7);
    expect(first[1]).toMatchObject({ version: 3, amount: '12.50', exchange_rate: '40.15', account_id: 2 });
    expect(api.createExpensePayment.mock.calls[1][1].idempotency_key).toBe(first[1].idempotency_key);
    expect(api.createExpense).not.toHaveBeenCalled();
    expect(wrapper.emitted('saved')).toHaveLength(1);
  });

  it('не дозволяє переплату залишку й зберігає введену суму для виправлення', async () => {
    open({ mode: 'add-payment', expense });
    await wrapper.get('[name="amount"]').setValue('75.01'); await submit();
    expect(api.createExpensePayment).not.toHaveBeenCalled();
    expect(wrapper.get('[role="alert"]').text()).toContain('перевищує неоплачений залишок');
    expect(wrapper.get('[name="amount"]').element.value).toBe('75.01');
  });

  it('при редагуванні оплати дозволяє її попередню суму плюс залишок', async () => {
    api.updateExpensePayment.mockResolvedValue({ data: { data: expense } });
    open({ mode: 'edit-payment', expense, payment });
    await wrapper.get('[name="amount"]').setValue('100.00'); await submit();
    expect(api.updateExpensePayment).toHaveBeenCalledWith(7, 15, expect.objectContaining({ amount: '100.00', version: 3 }));
  });

  it('оновлює конфліктну версію без втрати чернетки і враховує новий залишок', async () => {
    api.createExpensePayment.mockRejectedValue({ response: { status: 409, data: { message: 'Запис уже змінено.' } } });
    api.fetchExpense.mockResolvedValue({ data: { data: { ...expense, version: 8, remaining_amount: '10.00' } } });
    open({ mode: 'add-payment', expense });
    await wrapper.get('[name="amount"]').setValue('25.00'); await submit();
    const refresh = wrapper.findAll('button').find(button => button.text() === 'Оновити версію');
    await refresh.trigger('click'); await flushPromises();
    expect(wrapper.get('[name="amount"]').element.value).toBe('25.00');
    await submit();
    expect(api.createExpensePayment).toHaveBeenCalledTimes(1);
    expect(wrapper.get('[role="alert"]').text()).toContain('перевищує неоплачений залишок');
  });

  it('попереджає перед закриттям чернетки й блокує вихід браузера', async () => {
    window.confirm.mockReturnValue(false);
    open(); await fillNew();
    await wrapper.get('[aria-label="Закрити діалог"]').trigger('click');
    expect(window.confirm).toHaveBeenCalled();
    expect(wrapper.emitted('close')).toBeUndefined();
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event); expect(event.defaultPrevented).toBe(true);
  });

  it('відхиляє непідтримуваний файл, не втрачаючи попередню квитанцію', async () => {
    open(); await attachFile();
    await attachFile(new File(['bad'], 'script.html', { type: 'text/html' }));
    expect(wrapper.get('[role="alert"]').text()).toContain('script.html');
    expect(wrapper.get('.expense-file-list').text()).toContain('receipt.pdf');
    expect(wrapper.get('.expense-file-list').text()).not.toContain('script.html');
  });
});
