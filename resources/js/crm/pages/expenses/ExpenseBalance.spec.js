import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import ExpenseBalance from './ExpenseBalance.vue';
import * as api from '@/crm/services/expensesApi';

vi.mock('@/crm/services/expensesApi', async original => ({ ...await original(), fetchExpenseBalance: vi.fn(), topUpExpenseBalance: vi.fn() }));
const accounts = [{ id: 1, name: 'Картка' }, { id: 2, name: 'Каса' }, { id: 3, name: 'Баланс', system_key: 'balance' }];
const report = amount => ({ balance: { amount, incoming: amount, spent: '0.00', currency: 'UAH', account_id: 3 }, data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
let wrapper;
const button = text => wrapper.findAll('button').find(item => item.text() === text);
async function open() {
  wrapper = mount(ExpenseBalance, { props: { accounts }, global: { stubs: { teleport: true } }, attachTo: document.body }); await flushPromises();
}
beforeEach(() => { vi.clearAllMocks(); api.fetchExpenseBalance.mockResolvedValue({ data: report('0.00') }); vi.spyOn(window, 'confirm').mockReturnValue(false); });
afterEach(() => { wrapper?.unmount(); document.body.innerHTML = ''; vi.restoreAllMocks(); });

describe('Баланс для витрат', () => {
  it('не показує успіх при завантаженні, відкриває просту форму з касою й оновлює підтверджений залишок', async () => {
    api.topUpExpenseBalance.mockResolvedValue({ data: report('3800.00') });
    await open(); expect(wrapper.find('[role="status"]').exists()).toBe(false);
    await button('Поповнити баланс').trigger('click');
    expect(wrapper.get('[name="balance_account_id"]').element.value).toBe('2');
    expect(wrapper.get('[name="balance_account_id"]').text()).not.toContain('Баланс');
    await wrapper.get('[name="balance_amount"]').setValue('3800,00');
    await wrapper.get('[name="balance_note"]').setValue('Отримані кошти');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(api.topUpExpenseBalance).toHaveBeenCalledWith(expect.objectContaining({ amount: '3800.00', account_id: 2, note: 'Отримані кошти' }));
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    expect(wrapper.get('.expense-balance-amount').text()).toContain('3 800');
    expect(wrapper.emitted('updated').at(-1)[0].amount).toBe('3800.00');
  });

  it('повторює невідомий результат із тим самим ключем та зберігає чернетку', async () => {
    api.topUpExpenseBalance.mockRejectedValueOnce(new Error('Немає мережі')).mockResolvedValueOnce({ data: report('3800.00') });
    await open(); await button('Поповнити баланс').trigger('click');
    await wrapper.get('[name="balance_amount"]').setValue('3800');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    const first = api.topUpExpenseBalance.mock.calls[0][0];
    expect(wrapper.get('[name="balance_amount"]').element.value).toBe('3800');
    await button('Повторити').trigger('click'); await flushPromises();
    expect(api.topUpExpenseBalance.mock.calls[1][0].idempotency_key).toBe(first.idempotency_key);
  });

  it('захищає незбережену суму й оновлює баланс після оплати', async () => {
    await open(); await button('Поповнити баланс').trigger('click');
    await wrapper.get('[name="balance_amount"]').setValue('100');
    await button('Скасувати').trigger('click');
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
    const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); expect(event.defaultPrevented).toBe(true);
    api.fetchExpenseBalance.mockResolvedValue({ data: report('1800.00') });
    await wrapper.setProps({ refreshKey: 1 }); await flushPromises();
    expect(wrapper.emitted('updated').at(-1)[0].amount).toBe('1800.00');
  });
});
