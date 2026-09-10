import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import PackingWorkspace from './PackingWorkspace.vue';
import { createDeferredRun, readDeferredRun, startNextDeferredOrder } from './deferredPackingQueue';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

const order = (id) => ({
  id, order_number: id, packing_status: 'processing',
  delivery: { ttn: '20451529199555' },
  items: [{ id: 1, qty: 1, product_title: 'Капці', color: 'Чорний', size: '38' }],
});

let wrapper;

function showOrder(id) {
  wrapper?.unmount();
  wrapper = mount(PackingWorkspace, { props: { order: order(id) } });
  return wrapper;
}

async function openDeferred(ids) {
  const runId = createDeferredRun(ids.map((id) => ({ id, packing_status: 'skipped' })));
  const url = await startNextDeferredOrder(runId);
  window.history.replaceState({}, '', url);
  axios.post.mockClear();
  showOrder(ids[0]);
  return runId;
}

async function packCurrent() {
  await wrapper.get('.modern-card').trigger('click');
  await wrapper.get('[data-bs-target="#ttnModal"]').trigger('click');
  await wrapper.get('.actions-footer > .btn-brand-accent').trigger('click');
  await flushPromises();
}

beforeEach(() => {
  vi.resetAllMocks();
  sessionStorage.clear();
  window.history.replaceState({}, '', '/packing/30');
  vi.stubGlobal('alert', vi.fn());
  axios.post.mockResolvedValue({ data: { success: true, packing_session_id: 101 } });
  axios.get.mockResolvedValue({ data: [] });
});

afterEach(() => {
  wrapper?.unmount();
  wrapper = null;
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('пакування відкладених у робочому місці', () => {
  it('завершує поточне та захоплює наступне відкладене зі знімка', async () => {
    const runId = await openDeferred([30, 20]);
    expect(wrapper.get('.deferred-mode-label').text()).toContain('залишилось: 2');
    expect(wrapper.get('.actions-footer > .btn-brand-accent').attributes('disabled')).toBeDefined();
    await packCurrent();

    expect(axios.post.mock.calls).toEqual([
      ['/packing/30/finish', { queue: 'skipped', packing_session_id: 101 }],
      ['/packing/20/start', { queue: 'skipped' }],
    ]);
    expect(window.location.pathname).toBe('/packing/20');
    expect(new URLSearchParams(window.location.search).get('run')).toBe(runId);
    expect(readDeferredRun(runId)).toEqual({ remainingIds: [], activeId: 20, activeSessionId: 101, results: { 30: 'packed' } });
    expect(axios.get).not.toHaveBeenCalled();
  });

  it('відкладає повторно та переходить далі без повернення до цього замовлення', async () => {
    const runId = await openDeferred([30, 20]);
    await wrapper.get('.btn-skip-confirm').trigger('click');
    await flushPromises();

    expect(axios.post.mock.calls).toEqual([
      ['/packing/30/problem', { queue: 'skipped', packing_session_id: 101 }],
      ['/packing/20/start', { queue: 'skipped' }],
    ]);
    expect(window.location.pathname).toBe('/packing/20');
    expect(readDeferredRun(runId)).toEqual({ remainingIds: [], activeId: 20, activeSessionId: 101, results: { 30: 'skipped' } });
    expect(axios.get).not.toHaveBeenCalled();
  });

  it('зберігає прохід після reload і завершує з окремими підсумками пакування та відкладання', async () => {
    const runId = await openDeferred([30, 20]);
    await packCurrent();
    showOrder(20);
    expect(wrapper.get('.deferred-mode-label').text()).toContain('залишилось: 1');
    await wrapper.get('.btn-skip-confirm').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Прохід відкладених завершено');
    expect(wrapper.findAll('.stats-card .stat-box').map((box) => [box.get('.stat-val').text(), box.get('.stat-lbl').text()]))
      .toEqual([['1', 'Запаковано'], ['1', 'Знову відкладено']]);
    expect(() => readDeferredRun(runId)).toThrow('Прохід відкладених замовлень недоступний');
    expect(sessionStorage.length).toBe(0);
    expect(axios.post.mock.calls.map(([url]) => url)).toEqual(['/packing/30/finish', '/packing/20/start', '/packing/20/problem']);
    expect(axios.get).not.toHaveBeenCalled();
  });

  it('повторює лише перехід після збою мережі, не повторюючи finish', async () => {
    const runId = await openDeferred([30, 20]);
    axios.post.mockResolvedValueOnce({ data: { success: true } }).mockRejectedValueOnce(new Error('Збій мережі'));
    await packCurrent();
    expect(wrapper.text()).toContain('Поточне замовлення вже опрацьоване');
    expect(readDeferredRun(runId)).toEqual({ remainingIds: [20], activeId: null, activeSessionId: null, results: { 30: 'packed' } });
    expect(wrapper.get('.actions-footer > .btn-brand-accent').attributes('disabled')).toBeDefined();
    expect(wrapper.get('.btn-skip-confirm').attributes('disabled')).toBeDefined();

    await wrapper.findAll('button').find((candidate) => candidate.text() === 'Спробувати ще раз').trigger('click');
    await flushPromises();
    expect(axios.post.mock.calls.map(([url]) => url)).toEqual(['/packing/30/finish', '/packing/20/start', '/packing/20/start']);
    expect(window.location.pathname).toBe('/packing/20');
  });

  it('не позначає дію виконаною при помилці finish і дозволяє повторити її', async () => {
    const runId = await openDeferred([30, 20]);
    axios.post.mockRejectedValueOnce(new Error('Збій збереження'));
    await packCurrent();
    expect(readDeferredRun(runId)).toEqual({ remainingIds: [20], activeId: 30, activeSessionId: 101, results: {} });
    expect(window.location.pathname).toBe('/packing/30');
    expect(alert).toHaveBeenCalled();
    expect(wrapper.get('.actions-footer > .btn-brand-accent').attributes('disabled')).toBeUndefined();

    await wrapper.get('.actions-footer > .btn-brand-accent').trigger('click');
    await flushPromises();
    expect(axios.post.mock.calls.map(([url]) => url)).toEqual(['/packing/30/finish', '/packing/30/finish', '/packing/20/start']);
  });

  it.each([
    ['finish', 'packed'],
    ['problem', 'skipped'],
  ])('повторює %s після втраченої відповіді з тією самою сесією пакування', async (action, result) => {
    const runId = await openDeferred([30, 20]);
    let responseLost = false;
    axios.post.mockImplementation(async (url) => {
      if (url === `/packing/30/${action}` && !responseLost) {
        responseLost = true;
        throw new Error('Відповідь втрачена після збереження');
      }
      return { data: { success: true, packing_session_id: 202 } };
    });
    if (action === 'finish') await packCurrent();
    else {
      await wrapper.get('.btn-skip-confirm').trigger('click');
      await flushPromises();
    }
    expect(readDeferredRun(runId).activeSessionId).toBe(101);

    // Reload не змінює ключ ідемпотентності вже збереженої сервером дії.
    showOrder(30);
    if (action === 'finish') await packCurrent();
    else {
      await wrapper.get('.btn-skip-confirm').trigger('click');
      await flushPromises();
    }

    expect(axios.post.mock.calls).toEqual([
      [`/packing/30/${action}`, { queue: 'skipped', packing_session_id: 101 }],
      [`/packing/30/${action}`, { queue: 'skipped', packing_session_id: 101 }],
      ['/packing/20/start', { queue: 'skipped' }],
    ]);
    expect(readDeferredRun(runId)).toEqual({ remainingIds: [], activeId: 20, activeSessionId: 202, results: { 30: result } });
    expect(window.location.pathname).toBe('/packing/20');
  });

  it.each(['?queue=skipped', '?queue=skipped&run=missing'])('не переходить до pending без доступного проходу %s', async (search) => {
    window.history.replaceState({}, '', `/packing/30${search}`);
    axios.get.mockResolvedValue({ data: [{ id: 99, packing_status: 'pending' }] });
    showOrder(30);
    expect(wrapper.text()).toContain('Прохід недоступний');
    await packCurrent();
    await wrapper.get('.btn-skip-confirm').trigger('click');
    await flushPromises();

    expect(axios.post).not.toHaveBeenCalled();
    expect(axios.get).not.toHaveBeenCalled();
    expect(window.location.pathname).toBe('/packing/30');
  });

  it('блокує застарілу сторінку вже опрацьованого замовлення', async () => {
    const runId = await openDeferred([30, 20]);
    await packCurrent();
    window.history.replaceState({}, '', `/packing/30?queue=skipped&run=${runId}`);
    axios.post.mockClear();
    showOrder(30);
    expect(wrapper.text()).toContain('Прохід недоступний');
    await wrapper.get('.btn-skip-confirm').trigger('click');
    expect(axios.post).not.toHaveBeenCalled();
  });
});

describe('звичайний режим пакування', () => {
  it('після finish бере pending зі звичайної черги та ігнорує skipped', async () => {
    vi.useFakeTimers();
    axios.get.mockResolvedValue({ data: [{ id: 20, packing_status: 'skipped' }, { id: 10, packing_status: 'pending' }] });
    showOrder(30);
    expect(wrapper.find('.deferred-mode-label').exists()).toBe(false);
    await packCurrent();
    await vi.advanceTimersByTimeAsync(1500);

    expect(axios.post.mock.calls).toEqual([['/packing/30/finish']]);
    expect(axios.get).toHaveBeenCalledWith('/api/packing/list');
    expect(window.location.pathname).toBe('/packing/10');
    expect(window.location.search).toBe('');
    expect(sessionStorage.length).toBe(0);
  });

  it('після problem продовжує pending без створення проходу відкладених', async () => {
    axios.get.mockResolvedValue({ data: [{ id: 20, packing_status: 'skipped' }, { id: 10, packing_status: 'pending' }] });
    showOrder(30);
    await wrapper.get('.btn-skip-confirm').trigger('click');
    await flushPromises();

    expect(axios.post.mock.calls).toEqual([['/packing/30/problem']]);
    expect(window.location.pathname).toBe('/packing/10');
    expect(window.location.search).toBe('');
    expect(sessionStorage.length).toBe(0);
  });
});
