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
const clipboardButton = () => wrapper.findAll('button').find(button => button.text().includes('Вставити з буфера') || button.text() === 'Вставлення…');
const clipboardItem = (type = 'image/png') => ({ types: [type], getType: vi.fn().mockResolvedValue(new Blob(['screenshot'], { type })) });
let readClipboard;
beforeEach(() => {
  vi.clearAllMocks();
  vi.spyOn(window, 'confirm').mockReturnValue(true);
  readClipboard = vi.fn();
  vi.spyOn(navigator, 'clipboard', 'get').mockReturnValue({ read: readClipboard });
  vi.spyOn(URL, 'createObjectURL').mockImplementation(() => `blob:preview-${Math.random()}`);
  vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
});
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; vi.restoreAllMocks(); });

describe('Редактор витрат', () => {
  it('приймає перетягнуту квитанцію, перевіряє ліміт і завантажує її після збереження оплати', async () => {
    const created = { ...expense, id: 9, payments: [{ ...payment, id: 99 }] };
    api.createExpense.mockResolvedValue({ data: { data: created } });
    api.uploadExpenseReceipts.mockResolvedValue({ data: { data: created } });
    open(); await fillNew();
    const file = new File(['receipt'], 'delivery.pdf', { type: 'application/pdf' });
    const zone = wrapper.get('.expense-receipt-upload');
    await zone.trigger('drop', { dataTransfer: { files: [file] } });
    await zone.trigger('drop', { dataTransfer: { files: Array(10).fill(file) } });
    expect(wrapper.get('[role="alert"]').text()).toContain('не більше 10 квитанцій');
    expect(wrapper.findAll('.expense-file-list li')).toHaveLength(1);
    await submit();
    expect(api.uploadExpenseReceipts).toHaveBeenCalledWith(9, 99, [file]);
    expect(wrapper.emitted('saved')).toHaveLength(1);
  });

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


describe('Скріншоти квитанцій із буфера', () => {
  it('читає буфер тільки після натискання, показує прев’ю та надсилає зображення разом з оплатою', async () => {
    const item = clipboardItem();
    item.types.push('image/jpeg', 'text/html');
    readClipboard.mockResolvedValue([item]);
    const created = { ...expense, payments: [payment] };
    api.createExpense.mockResolvedValue({ data: { data: created } });
    api.uploadExpenseReceipts.mockResolvedValue({ data: { data: created } });
    open(); await fillNew();
    expect(readClipboard).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('Додає скопійований скріншот');
    await clipboardButton().trigger('click'); await flushPromises();
    expect(item.getType).toHaveBeenCalledExactlyOnceWith('image/png');
    expect(wrapper.findAll('.expense-file-list li')).toHaveLength(1);
    const image = wrapper.get('.expense-file-preview');
    const previewUrl = image.attributes('src');
    expect(previewUrl).toMatch(/^blob:preview-/);
    expect(api.uploadExpenseReceipts).not.toHaveBeenCalled();
    await submit();
    const uploaded = api.uploadExpenseReceipts.mock.calls[0][2][0];
    expect(uploaded).toBeInstanceOf(File);
    expect(uploaded.type).toBe('image/png');
    expect(uploaded.name).toMatch(/^Скріншот-.*\.png$/);
    expect(URL.revokeObjectURL).toHaveBeenCalledWith(previewUrl);
  });

  it('вставляє клавішами лише в квитанціях і не перехоплює текст у коментарі', async () => {
    open(); await flushPromises();
    const file = new File(['screenshot'], 'screen.png', { type: 'image/png' });
    const screenshotData = { items: [{ kind: 'file', type: file.type, getAsFile: () => file }] };
    const imagePaste = new Event('paste', { bubbles: true, cancelable: true });
    Object.defineProperty(imagePaste, 'clipboardData', { value: screenshotData });
    await wrapper.get('.expense-drop-label').trigger('click');
    const area = wrapper.get('.expense-editor-receipts');
    expect(document.activeElement).toBe(area.element);
    area.element.dispatchEvent(imagePaste); await flushPromises();
    expect(imagePaste.defaultPrevented).toBe(true);
    expect(wrapper.findAll('.expense-file-preview')).toHaveLength(1);
    const textPaste = new Event('paste', { bubbles: true, cancelable: true });
    Object.defineProperty(textPaste, 'clipboardData', { value: { items: [{ kind: 'string', type: 'text/plain' }] } });
    wrapper.get('[name="note"]').element.dispatchEvent(textPaste);
    expect(textPaste.defaultPrevented).toBe(false);
    expect(readClipboard).not.toHaveBeenCalled();
  });

  it.each(['empty', 'denied', 'unsupported'])('пояснює проблему буфера %s, залишаючи чернетку й квитанції', async scenario => {
    if (scenario === 'empty') readClipboard.mockResolvedValue([clipboardItem('text/plain')]);
    if (scenario === 'denied') readClipboard.mockRejectedValue(new DOMException('Denied', 'NotAllowedError'));
    if (scenario === 'unsupported') vi.spyOn(navigator, 'clipboard', 'get').mockReturnValue(undefined);
    open(); await fillNew(); await attachFile();
    await clipboardButton().trigger('click'); await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Не вдалося вставити скріншот');
    expect(wrapper.get('[name="title"]').element.value).toBe('Реклама за вересень');
    expect(wrapper.findAll('.expense-file-list li')).toHaveLength(1);
    const action = wrapper.findAll('button').find(button => button.text() === 'Вставити клавішами');
    await action.trigger('click');
    expect(document.activeElement).toBe(wrapper.get('.expense-editor-receipts').element);
  });

  it('звільняє прев’ю видалених файлів і решти вкладень при закритті', async () => {
    open();
    await attachFile(new File(['first'], 'first.png', { type: 'image/png' }));
    await attachFile(new File(['second'], 'second.jpg', { type: 'image/jpeg' }));
    const urls = wrapper.findAll('.expense-file-preview').map(image => image.attributes('src'));
    await wrapper.get('[aria-label="Прибрати first.png"]').trigger('click');
    expect(URL.revokeObjectURL).toHaveBeenCalledWith(urls[0]);
    expect(URL.revokeObjectURL).not.toHaveBeenCalledWith(urls[1]);
    wrapper.unmount(); wrapper = null;
    expect(URL.revokeObjectURL).toHaveBeenCalledWith(urls[1]);
  });

  it('не дозволяє зберегти оплату до завершення читання та ігнорує результат після закриття', async () => {
    let resolveRead;
    readClipboard.mockReturnValue(new Promise(resolve => { resolveRead = resolve; }));
    open(); await fillNew();
    await clipboardButton().trigger('click');
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    await submit();
    expect(api.createExpense).not.toHaveBeenCalled();
    wrapper.unmount(); wrapper = null;
    const item = clipboardItem();
    resolveRead([item]); await flushPromises();
    expect(item.getType).not.toHaveBeenCalled();
    expect(URL.createObjectURL).not.toHaveBeenCalled();
  });

  it('дотримується ліміту з урахуванням наявних квитанцій і не створює прев’ю відхиленого файлу', async () => {
    readClipboard.mockResolvedValue([clipboardItem()]);
    open({ mode: 'edit-payment', expense, payment: { ...payment, receipts: Array(10).fill({ id: 1 }) } });
    await clipboardButton().trigger('click'); await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('не більше 10 квитанцій');
    expect(URL.createObjectURL).not.toHaveBeenCalled();
    expect(wrapper.find('.expense-file-list').exists()).toBe(false);
  });
});
