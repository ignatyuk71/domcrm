import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import FiscalBlock from './FiscalBlock.vue';
import { fetchFiscalAvailability, fetchFiscalStatus, fiscalize, refund } from '@/crm/api/fiscal';

vi.mock('@/crm/api/fiscal', () => ({
  fetchFiscalAvailability: vi.fn(), fetchFiscalStatus: vi.fn(), fiscalize: vi.fn(), refund: vi.fn(),
}));
const opened = { available: true, shift_status: 'OPENED', code: null, message: '' };
const closed = { available: false, shift_status: 'CLOSED', code: 'fiscal_shift_closed', message: 'Каса закрита' };
const receipt = { status: 'success', type: 'sell', already_paid_cents: 10000, total_amount: 10000, check_link: 'https://check.example/one', all_success_receipts: [{ id: 1, type: 'sell', amount: 100, link: 'https://check.example/one' }] };
let wrapper;
function render(props = {}) {
  fetchFiscalStatus.mockResolvedValue({ data: props.receipt || null });
  wrapper = mount(FiscalBlock, {
    props: { orderId: 42, totalAmount: 399, prepayAmount: 100, ...props },
    global: { stubs: { teleport: true } },
  });
  return wrapper;
}
beforeEach(() => {
  vi.useFakeTimers();
  vi.clearAllMocks();
  fetchFiscalAvailability.mockResolvedValue({ data: opened });
  fiscalize.mockResolvedValue({ data: { status: 'pending' } });
  refund.mockResolvedValue({ data: { status: 'pending' } });
});
afterEach(() => {
  wrapper?.unmount();
  wrapper = null;
  vi.useRealTimers();
});

describe('ручна фіскалізація та каса', () => {
  it('тримає аванс і повний чек вимкненими під час перевірки та при закритій касі', async () => {
    let finish;
    fetchFiscalAvailability.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve; }));
    render();
    expect(wrapper.get('.btn-prepay').attributes('disabled')).toBeDefined();
    expect(wrapper.get('.btn-main').attributes('title')).toBe('Перевіряємо стан каси…');
    await flushPromises();
    finish({ data: closed });
    await flushPromises();
    expect(wrapper.get('.btn-main').attributes('disabled')).toBeDefined();
    expect(wrapper.get('.btn-prepay').attributes('title')).toBe('Каса закрита');
    await wrapper.get('.btn-main').trigger('click');
    expect(wrapper.find('.fiscal-confirm-card').exists()).toBe(false);
    expect(fiscalize).not.toHaveBeenCalled();
  });

  it.each([['.btn-main', 'sell'], ['.btn-prepay', 'prepay']])('дозволяє %s після підтвердженого відкриття без зміни суми', async (selector, type) => {
    render();
    await flushPromises();
    expect(wrapper.get(selector).attributes('disabled')).toBeUndefined();
    await wrapper.get(selector).trigger('click');
    expect(wrapper.get('.confirm-amount').text()).toContain(type === 'prepay' ? '100,00' : '399,00');
    await wrapper.get('.fiscal-confirm-card .btn-danger').trigger('click');
    await flushPromises();
    expect(fiscalize).toHaveBeenCalledExactlyOnceWith(42, type);
    expect(refund).not.toHaveBeenCalled();
    expect(wrapper.find('.fiscal-confirm-card').exists()).toBe(false);
  });

  it('блокує повернення при закритій касі та зберігає доступ до готового чека', async () => {
    fetchFiscalAvailability.mockResolvedValue({ data: closed });
    render({ receipt });
    await flushPromises();
    expect(wrapper.get('.btn-refund').attributes('disabled')).toBeDefined();
    expect(wrapper.get('.btn-refund').attributes('title')).toBe('Каса закрита');
    expect(wrapper.findAll('a').some((link) => link.attributes('href') === receipt.check_link)).toBe(true);
    expect(refund).not.toHaveBeenCalled();
    fetchFiscalAvailability.mockResolvedValue({ data: opened });
    window.dispatchEvent(new Event('focus'));
    await flushPromises();
    await wrapper.get('.btn-refund').trigger('click');
    await wrapper.get('.fiscal-confirm-card .btn-danger').trigger('click');
    await flushPromises();
    expect(refund).toHaveBeenCalledExactlyOnceWith(42);
  });

  it('блокує вже відкрите підтвердження після закриття каси без постійного spinner', async () => {
    render();
    await flushPromises();
    await wrapper.get('.btn-main').trigger('click');
    fetchFiscalAvailability.mockResolvedValue({ data: closed });
    window.dispatchEvent(new Event('focus'));
    await flushPromises();
    const confirm = wrapper.get('.fiscal-confirm-card .btn-danger');
    expect(confirm.attributes('disabled')).toBeDefined();
    expect(confirm.attributes('title')).toBe('Каса закрита');
    expect(confirm.text()).toBe('Так, фіскалізувати');
    expect(confirm.find('.spinner-border').exists()).toBe(false);
    await confirm.trigger('click');
    expect(fiscalize).not.toHaveBeenCalled();
    await wrapper.get('.fiscal-confirm-card .btn-light').trigger('click');
    expect(wrapper.find('.fiscal-confirm-card').exists()).toBe(false);
  });

  it('перевіряє касу ще раз перед POST і не відкладає запит на потім', async () => {
    render();
    await flushPromises();
    await wrapper.get('.btn-main').trigger('click');
    fetchFiscalAvailability.mockResolvedValue({ data: closed });
    await wrapper.get('.fiscal-confirm-card .btn-danger').trigger('click');
    await flushPromises();
    expect(fiscalize).not.toHaveBeenCalled();
    fetchFiscalAvailability.mockResolvedValue({ data: opened });
    await vi.advanceTimersByTimeAsync(60_000);
    expect(fiscalize).not.toHaveBeenCalled();
    expect(wrapper.get('.btn-main').attributes('disabled')).toBeUndefined();
  });

  it('відображає помилку перевірки як toast із повтором без фіскалізації', async () => {
    fetchFiscalAvailability.mockRejectedValueOnce(new Error('Offline'));
    render();
    await flushPromises();
    expect(wrapper.get('.app-toast').text()).toContain('Не вдалося перевірити стан каси');
    expect(wrapper.get('.btn-main').attributes('disabled')).toBeDefined();
    await wrapper.get('.app-toast-actions button').trigger('click');
    await flushPromises();
    expect(wrapper.get('.btn-main').attributes('disabled')).toBeUndefined();
    expect(fiscalize).not.toHaveBeenCalled();
  });

  it('після серверного відхилення робить кнопки неактивними та лишає помилку в toast', async () => {
    fiscalize.mockRejectedValueOnce({ response: { status: 409, data: closed } });
    render();
    await flushPromises();
    await wrapper.get('.btn-main').trigger('click');
    await wrapper.get('.fiscal-confirm-card .btn-danger').trigger('click');
    await flushPromises();
    expect(wrapper.get('.app-toast').text()).toContain('Каса закрита');
    expect(wrapper.get('.btn-main').attributes('disabled')).toBeDefined();
    expect(wrapper.find('.error-box').exists()).toBe(false);
    await vi.advanceTimersByTimeAsync(5_000);
    expect(wrapper.get('.app-toast').text()).toContain('Каса закрита');
    expect(fiscalize).toHaveBeenCalledTimes(1);
    await wrapper.get('.app-toast-actions button').trigger('click');
    await flushPromises();
    expect(fiscalize).toHaveBeenCalledTimes(1);
    expect(wrapper.find('.fiscal-confirm-card').exists()).toBe(true);
  });
});
