import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import {
  completeDeferredOrder,
  createDeferredRun,
  deferredRunStats,
  disposeDeferredRun,
  readDeferredRun,
  startNextDeferredOrder,
} from './deferredPackingQueue';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

const skipped = (id) => ({ id, packing_status: 'skipped' });

beforeEach(() => {
  vi.resetAllMocks();
  sessionStorage.clear();
  axios.post.mockResolvedValue({ data: { success: true, packing_session_id: 101 } });
});

describe('прохід відкладених замовлень', () => {
  it('зберігає одноразовий знімок лише відкладених без дублікатів у переданому порядку', () => {
    const orders = [skipped('30'), { id: 10, packing_status: 'pending' }, skipped(20), skipped(30), { id: 40, packing_status: 'processing' }];
    const runId = createDeferredRun(orders);
    orders.push(skipped(50));

    expect(readDeferredRun(runId)).toEqual({ remainingIds: [30, 20], activeId: null, activeSessionId: null, results: {} });
    expect(axios.post).not.toHaveBeenCalled();
  });

  it('відновлює активне замовлення і результати після перезавантаження модуля', async () => {
    const runId = createDeferredRun([skipped(30), skipped(20)]);
    await startNextDeferredOrder(runId);
    completeDeferredOrder(runId, 30, 'packed');
    const url = await startNextDeferredOrder(runId);
    vi.resetModules();
    const restored = await import('./deferredPackingQueue');

    expect(restored.readDeferredRun(runId)).toEqual({ remainingIds: [], activeId: 20, activeSessionId: 101, results: { 30: 'packed' } });
    expect(await restored.startNextDeferredOrder(runId)).toBe(url);
    expect(axios.post).toHaveBeenCalledTimes(2);
    expect(restored.deferredRunStats(runId)).toEqual({ packed: 1, deferred: 0 });
  });

  it('переходить після пакування і повторного відкладання без повернення до опрацьованих', async () => {
    const runId = createDeferredRun([skipped(30), skipped(20), skipped(10)]);
    expect(await startNextDeferredOrder(runId)).toBe(`/packing/30?queue=skipped&run=${runId}`);
    completeDeferredOrder(runId, 30, 'packed');
    expect(await startNextDeferredOrder(runId)).toBe(`/packing/20?queue=skipped&run=${runId}`);
    completeDeferredOrder(runId, 20, 'skipped');
    expect(await startNextDeferredOrder(runId)).toBe(`/packing/10?queue=skipped&run=${runId}`);
    completeDeferredOrder(runId, 10, 'packed');

    expect(await startNextDeferredOrder(runId)).toBeNull();
    expect(await startNextDeferredOrder(runId)).toBeNull();
    expect(axios.post.mock.calls).toEqual([
      ['/packing/30/start', { queue: 'skipped' }],
      ['/packing/20/start', { queue: 'skipped' }],
      ['/packing/10/start', { queue: 'skipped' }],
    ]);
    expect(deferredRunStats(runId)).toEqual({ packed: 2, deferred: 1 });
  });

  it('пропускає недоступних кандидатів з 404, 409 і 423 та бере наступного', async () => {
    const runId = createDeferredRun([skipped(40), skipped(30), skipped(20), skipped(10)]);
    axios.post
      .mockRejectedValueOnce({ response: { status: 404 } })
      .mockRejectedValueOnce({ response: { status: 409 } })
      .mockRejectedValueOnce({ response: { status: 423 } });

    expect(await startNextDeferredOrder(runId)).toBe(`/packing/10?queue=skipped&run=${runId}`);
    expect(readDeferredRun(runId)).toEqual({ remainingIds: [], activeId: 10, activeSessionId: 101, results: {} });
    expect(axios.post).toHaveBeenCalledTimes(4);
    expect(deferredRunStats(runId)).toEqual({ packed: 0, deferred: 0 });
  });

  it.each([undefined, 500, 403])('не губить кандидата після помилки мережі або сервера %s', async (status) => {
    const runId = createDeferredRun([skipped(30), skipped(20)]);
    const error = status ? { response: { status } } : new Error('Збій мережі');
    axios.post.mockRejectedValueOnce(error);

    await expect(startNextDeferredOrder(runId)).rejects.toBe(error);
    expect(readDeferredRun(runId)).toEqual({ remainingIds: [30, 20], activeId: null, activeSessionId: null, results: {} });
    expect(await startNextDeferredOrder(runId)).toBe(`/packing/30?queue=skipped&run=${runId}`);
    expect(axios.post.mock.calls.map(([url]) => url)).toEqual(['/packing/30/start', '/packing/30/start']);
  });

  it('повторює start того самого кандидата після втрати відповіді на успішне захоплення', async () => {
    const runId = createDeferredRun([skipped(30)]);
    let claimed = false;
    axios.post.mockImplementation(async (url, payload) => {
      expect(url).toBe('/packing/30/start');
      expect(payload).toEqual({ queue: 'skipped' });
      if (!claimed) {
        claimed = true;
        throw new Error('Відповідь втрачена після захоплення');
      }
      return { data: { success: true } };
    });

    await expect(startNextDeferredOrder(runId)).rejects.toThrow('Відповідь втрачена');
    expect(await startNextDeferredOrder(runId)).toBe(`/packing/30?queue=skipped&run=${runId}`);
    expect(readDeferredRun(runId).activeId).toBe(30);
    expect(axios.post).toHaveBeenCalledTimes(2);
  });

  it('завершує порожній прохід без звернення до звичайної черги', async () => {
    const runId = createDeferredRun([{ id: 1, packing_status: 'pending' }]);
    expect(await startNextDeferredOrder(runId)).toBeNull();
    expect(deferredRunStats(runId)).toEqual({ packed: 0, deferred: 0 });
    expect(axios.post).not.toHaveBeenCalled();
  });

  it('очищає завершений прохід і не видаляє інші проходи у вкладці', async () => {
    const finishedRunId = createDeferredRun([skipped(30)]);
    const anotherRunId = createDeferredRun([skipped(20)]);
    await startNextDeferredOrder(finishedRunId);
    completeDeferredOrder(finishedRunId, 30, 'packed');
    expect(readDeferredRun(finishedRunId).activeSessionId).toBeNull();
    const stats = deferredRunStats(finishedRunId);
    disposeDeferredRun(finishedRunId);

    expect(stats).toEqual({ packed: 1, deferred: 0 });
    expect(() => readDeferredRun(finishedRunId)).toThrow('Прохід відкладених замовлень недоступний');
    expect(readDeferredRun(anotherRunId).remainingIds).toEqual([20]);
  });

  it('не дозволяє повторно завершити замовлення чи змінити результат іншого', async () => {
    const runId = createDeferredRun([skipped(30), skipped(20)]);
    await startNextDeferredOrder(runId);
    expect(() => completeDeferredOrder(runId, 20, 'packed')).toThrow('вже опрацьоване');
    completeDeferredOrder(runId, 30, 'packed');
    expect(() => completeDeferredOrder(runId, 30, 'skipped')).toThrow('вже опрацьоване');
    expect(deferredRunStats(runId)).toEqual({ packed: 1, deferred: 0 });
  });

  it.each([null, 'missing'])('відхиляє відсутній прохід %s', (runId) => {
    expect(() => readDeferredRun(runId)).toThrow('Прохід відкладених замовлень недоступний');
  });
});
