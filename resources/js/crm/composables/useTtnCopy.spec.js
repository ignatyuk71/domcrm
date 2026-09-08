import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { useTtnCopy } from './useTtnCopy';

let wrapper;
let copy;
let writeText;

function pending() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

beforeEach(async () => {
  vi.useFakeTimers();
  writeText = vi.fn().mockResolvedValue(undefined);
  vi.stubGlobal('navigator', { clipboard: { writeText } });
  wrapper = mount({
    setup() {
      copy = useTtnCopy();
      return () => null;
    },
  });
  await vi.runOnlyPendingTimersAsync();
});

afterEach(() => {
  wrapper?.unmount();
  wrapper = null;
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('підтвердження копіювання ТТН', () => {
  it('показує успіх лише після запису в буфер і прибирає його через дві секунди', async () => {
    const attempt = pending();
    writeText.mockReturnValueOnce(attempt.promise);
    const result = copy.copyTtn(20451530751773);
    expect(writeText).toHaveBeenCalledWith('20451530751773');
    expect(copy.copiedTtn.value).toBe('');
    expect(vi.getTimerCount()).toBe(0);
    attempt.resolve();
    expect(await result).toBe(true);
    expect(copy.copiedTtn.value).toBe('20451530751773');
    await vi.advanceTimersByTimeAsync(1999);
    expect(copy.copiedTtn.value).toBe('20451530751773');
    await vi.advanceTimersByTimeAsync(1);
    expect(copy.copiedTtn.value).toBe('');
  });

  it('скидає попередню галочку та не показує успіх після відмови буфера', async () => {
    await copy.copyTtn('first');
    const attempt = pending();
    writeText.mockReturnValueOnce(attempt.promise);
    const result = copy.copyTtn('second');
    expect(copy.copiedTtn.value).toBe('');
    expect(vi.getTimerCount()).toBe(0);
    attempt.reject(new Error('Доступ до буфера відхилено'));
    expect(await result).toBe(false);
    expect(copy.copiedTtn.value).toBe('');
    expect(vi.getTimerCount()).toBe(0);
  });

  it('обробляє порожній номер та недоступний буфер без помилкового підтвердження', async () => {
    for (const value of ['', '  ', null, undefined]) {
      expect(await copy.copyTtn(value)).toBe(false);
    }
    expect(writeText).not.toHaveBeenCalled();
    vi.stubGlobal('navigator', {});
    expect(await copy.copyTtn('20451530751773')).toBe(false);
    expect(copy.copiedTtn.value).toBe('');
  });

  it('зберігає результат останнього натискання при зворотному порядку відповідей', async () => {
    const first = pending();
    const second = pending();
    writeText.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
    const firstResult = copy.copyTtn('first');
    const secondResult = copy.copyTtn('second');
    second.resolve();
    expect(await secondResult).toBe(true);
    await vi.advanceTimersByTimeAsync(1000);
    first.resolve();
    expect(await firstResult).toBe(false);
    expect(copy.copiedTtn.value).toBe('second');
    expect(vi.getTimerCount()).toBe(1);
    await vi.advanceTimersByTimeAsync(1000);
    expect(copy.copiedTtn.value).toBe('');
  });

  it('не підтверджує старий запит після помилки нового', async () => {
    const first = pending();
    writeText.mockReturnValueOnce(first.promise).mockRejectedValueOnce(new Error('Відмова'));
    const firstResult = copy.copyTtn('first');
    expect(await copy.copyTtn('second')).toBe(false);
    first.resolve();
    expect(await firstResult).toBe(false);
    expect(copy.copiedTtn.value).toBe('');
    expect(vi.getTimerCount()).toBe(0);
  });

  it('звільняє таймер при закритті компонента', async () => {
    await copy.copyTtn('20451530751773');
    expect(vi.getTimerCount()).toBe(1);
    wrapper.unmount();
    wrapper = null;
    expect(vi.getTimerCount()).toBe(0);
    expect(copy.copiedTtn.value).toBe('');
  });

  it('ігнорує відповідь буфера після закриття компонента', async () => {
    const attempt = pending();
    writeText.mockReturnValueOnce(attempt.promise);
    const result = copy.copyTtn('20451530751773');
    wrapper.unmount();
    wrapper = null;
    attempt.resolve();
    expect(await result).toBe(false);
    expect(copy.copiedTtn.value).toBe('');
    expect(vi.getTimerCount()).toBe(0);
  });
});
