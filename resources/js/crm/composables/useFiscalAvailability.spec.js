import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { fetchFiscalAvailability } from '@/crm/api/fiscal';
import { useFiscalAvailability } from './useFiscalAvailability';

vi.mock('@/crm/api/fiscal', () => ({ fetchFiscalAvailability: vi.fn() }));
const opened = { available: true, shift_status: 'OPENED', code: null, message: '' };
const closed = { available: false, shift_status: 'CLOSED', code: 'fiscal_shift_closed', message: 'Каса закрита' };
let wrappers;
function consumer(onError = null) {
  const wrapper = mount(defineComponent({ setup: () => useFiscalAvailability({ onError }), template: '<div />' }));
  wrappers.push(wrapper);
  return wrapper;
}
beforeEach(() => {
  wrappers = [];
  vi.useFakeTimers();
  vi.clearAllMocks();
  fetchFiscalAvailability.mockResolvedValue({ data: opened });
});
afterEach(() => {
  wrappers.forEach((wrapper) => wrapper.unmount());
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe('спільна доступність каси', () => {
  it('блокує дії до першої відповіді та ділить один запит і таймер між компонентами', async () => {
    const interval = vi.spyOn(window, 'setInterval');
    const clearInterval = vi.spyOn(window, 'clearInterval');
    let finish;
    fetchFiscalAvailability.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve; }));
    const first = consumer();
    const second = consumer();
    expect(first.vm.available).toBe(false);
    expect(second.vm.disabledReason).toBe('Перевіряємо стан каси…');
    await flushPromises();
    expect(fetchFiscalAvailability).toHaveBeenCalledTimes(1);
    expect(interval).toHaveBeenCalledTimes(1);
    finish({ data: opened });
    await flushPromises();
    expect(first.vm.available).toBe(true);
    expect(second.vm.available).toBe(true);
    first.unmount();
    wrappers = [second];
    expect(interval).toHaveBeenCalledTimes(1);
    second.unmount();
    wrappers = [];
    expect(clearInterval).toHaveBeenCalledWith(interval.mock.results[0].value);
    window.dispatchEvent(new Event('focus'));
    document.dispatchEvent(new Event('visibilitychange'));
    await flushPromises();
    expect(fetchFiscalAvailability).toHaveBeenCalledTimes(1);
  });

  it('оновлює спільний стан після відкриття каси через focus та кожні 20 хвилин', async () => {
    fetchFiscalAvailability.mockResolvedValueOnce({ data: closed });
    const first = consumer();
    const second = consumer();
    await flushPromises();
    expect(first.vm.disabledReason).toBe('Каса закрита');
    expect(second.vm.available).toBe(false);
    window.dispatchEvent(new Event('focus'));
    document.dispatchEvent(new Event('visibilitychange'));
    await flushPromises();
    expect(fetchFiscalAvailability).toHaveBeenCalledTimes(2);
    expect(first.vm.available).toBe(true);
    await vi.advanceTimersByTimeAsync(20 * 60_000 - 1);
    expect(fetchFiscalAvailability).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(1);
    expect(fetchFiscalAvailability).toHaveBeenCalledTimes(3);
  });

  it.each(['OPENING', 'CLOSING', 'UNKNOWN'])('не дозволяє ручні чеки для стану %s', async (status) => {
    fetchFiscalAvailability.mockResolvedValue({ data: { ...opened, shift_status: status } });
    const wrapper = consumer();
    await flushPromises();
    expect(wrapper.vm.available).toBe(false);
  });

  it('показує одну помилку на вкладку, повтор перевіряє касу й не створює чеків', async () => {
    fetchFiscalAvailability.mockRejectedValueOnce(new Error('Offline'));
    const firstError = vi.fn();
    const secondError = vi.fn();
    const first = consumer(firstError);
    consumer(secondError);
    await flushPromises();
    expect(first.vm.available).toBe(false);
    expect(firstError).toHaveBeenCalledTimes(1);
    expect(secondError).not.toHaveBeenCalled();
    await firstError.mock.calls[0][1]();
    await flushPromises();
    expect(fetchFiscalAvailability).toHaveBeenCalledTimes(2);
    expect(first.vm.available).toBe(true);
  });

  it('не застосовує запізнілу відповідь після закриття останнього споживача', async () => {
    let finishOld;
    fetchFiscalAvailability.mockImplementationOnce(() => new Promise((resolve) => { finishOld = resolve; }));
    const old = consumer();
    await flushPromises();
    old.unmount();
    wrappers = [];
    fetchFiscalAvailability.mockResolvedValueOnce({ data: closed });
    const current = consumer();
    await flushPromises();
    finishOld({ data: opened });
    await flushPromises();
    expect(current.vm.available).toBe(false);
    expect(current.vm.disabledReason).toBe('Каса закрита');
  });
});
