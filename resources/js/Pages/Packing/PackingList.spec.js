import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import PackingList from './PackingList.vue';
import { readDeferredRun } from './deferredPackingQueue';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

const order = (id, packing_status, overrides = {}) => ({
  id, order_number: id, packing_status, items: [],
  created_at: '2026-09-01T10:00:00Z', updated_at: '2026-09-10T10:00:00Z',
  delivery: { city_name: 'Київ' }, ...overrides,
});

let wrapper;

async function showList(orders, history = []) {
  axios.get.mockImplementation(async (url) => ({ data: url === '/api/packing/list' ? orders : history }));
  wrapper = mount(PackingList);
  await flushPromises();
  axios.post.mockClear();
  return wrapper;
}

beforeEach(() => {
  vi.resetAllMocks();
  sessionStorage.clear();
  window.history.replaceState({}, '', '/packing/list');
  vi.stubGlobal('alert', vi.fn());
  vi.spyOn(console, 'error').mockImplementation(() => {});
  axios.post.mockResolvedValue({ data: { success: true, packing_session_id: 101 } });
});

afterEach(() => {
  wrapper?.unmount();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('кнопка пакування відкладених', () => {
  it('недоступна під час завантаження та коли немає відкладених', async () => {
    let resolveList;
    axios.get.mockImplementation((url) => url === '/api/packing/list'
      ? new Promise((resolve) => { resolveList = resolve; })
      : Promise.resolve({ data: [] }));
    wrapper = mount(PackingList);
    expect(wrapper.get('.btn-deferred-action').attributes('disabled')).toBeDefined();
    await flushPromises();
    resolveList({ data: [order(1, 'pending')] });
    await flushPromises();

    expect(wrapper.get('.btn-deferred-action').attributes('disabled')).toBeDefined();
    expect(wrapper.get('.btn-main-action').attributes('disabled')).toBeUndefined();
    await wrapper.get('.btn-deferred-action').trigger('click');
    expect(axios.post.mock.calls).toEqual([['/packing/return-to-queue']]);
  });

  it('бере всі жовті замовлення у видимому порядку незалежно від пошуку', async () => {
    await showList([
      order(11, 'pending'),
      order(20, 'skipped', { updated_at: '2026-09-09T10:00:00Z' }),
      order(31, 'skipped', { delivery: { city_name: 'Львів' } }),
      order(30, 'skipped'),
      order(40, 'processing'),
    ]);
    expect(wrapper.findAll('.is-skipped .order-id').map((row) => row.text())).toEqual(['#31', '#30', '#20']);
    await wrapper.get('#packing-search').setValue('Львів');
    expect(wrapper.findAll('.order-row-modern')).toHaveLength(1);
    expect(wrapper.get('.deferred-count').text()).toBe('3');

    await wrapper.get('.btn-deferred-action').trigger('click');
    await flushPromises();
    const params = new URLSearchParams(window.location.search);
    expect(window.location.pathname).toBe('/packing/31');
    expect(params.get('queue')).toBe('skipped');
    expect(readDeferredRun(params.get('run'))).toEqual({ remainingIds: [30, 20], activeId: 31, activeSessionId: 101, results: {} });
    expect(axios.post).toHaveBeenCalledWith('/packing/31/start', { queue: 'skipped' });
  });

  it('повторює невдалий старт без нового проходу чи втрати кандидата', async () => {
    await showList([order(20, 'skipped'), order(10, 'skipped')]);
    axios.post.mockRejectedValueOnce(new Error('Мережа недоступна'));
    await wrapper.get('.btn-deferred-action').trigger('click');
    await flushPromises();
    expect(wrapper.get('[role="status"]').text()).toContain('Не вдалося відкрити наступне');
    const runKey = sessionStorage.key(0);
    const retry = wrapper.findAll('button').find((candidate) => candidate.text() === 'Спробувати ще раз');
    await retry.trigger('click');
    await flushPromises();

    expect(sessionStorage.length).toBe(1);
    expect(sessionStorage.key(0)).toBe(runKey);
    expect(window.location.pathname).toBe('/packing/20');
    expect(axios.post.mock.calls.map(([url]) => url)).toEqual(['/packing/20/start', '/packing/20/start']);
  });

  it('повідомляє про порожній прохід коли всі кандидати вже недоступні', async () => {
    await showList([order(20, 'skipped'), order(10, 'skipped')]);
    axios.post.mockRejectedValue({ response: { status: 423 } });
    await wrapper.get('.btn-deferred-action').trigger('click');
    await flushPromises();

    expect(window.location.pathname).toBe('/packing/list');
    expect(wrapper.get('[role="status"]').text()).toContain('Доступних відкладених замовлень більше немає');
    expect(axios.get).toHaveBeenCalledTimes(4);
    expect(axios.post).toHaveBeenCalledTimes(2);
    expect(sessionStorage.length).toBe(0);
  });

  it('залишає звичайний старт у pending черзі без режиму skipped', async () => {
    await showList([order(20, 'skipped'), order(11, 'pending'), order(12, 'pending', { is_priority: true })]);
    await wrapper.get('.btn-main-action').trigger('click');
    await flushPromises();

    expect(axios.post.mock.calls).toEqual([['/packing/12/start']]);
    expect(window.location.pathname).toBe('/packing/12');
    expect(window.location.search).toBe('');
    expect(sessionStorage.length).toBe(0);
  });
});


describe('повернення незавершеного пакування в чергу', () => {
  it('відновлює чергу до завантаження списку та знову вмикає кнопку', async () => {
    let release;
    axios.post.mockImplementationOnce(() => new Promise(resolve => { release = resolve; }));
    axios.get.mockResolvedValue({ data: [order(1, 'pending')] });
    wrapper = mount(PackingList);
    expect(axios.get).not.toHaveBeenCalled();
    expect(wrapper.get('.btn-main-action').attributes('disabled')).toBeDefined();
    release({ data: { success: true, released: 7 } });
    await flushPromises();
    expect(axios.post).toHaveBeenCalledWith('/packing/return-to-queue');
    expect(wrapper.get('.btn-main-action').attributes('disabled')).toBeUndefined();
    expect(wrapper.text()).not.toContain('У роботі');
  });

  it('повторно відновлює чергу при поверненні браузером із кешу сторінки', async () => {
    await showList([order(1, 'pending')]);
    const event = new Event('pageshow');
    Object.defineProperty(event, 'persisted', { value: true });
    window.dispatchEvent(event);
    await flushPromises();
    expect(axios.post.mock.calls).toEqual([['/packing/return-to-queue']]);
    expect(wrapper.get('.btn-main-action').attributes('disabled')).toBeUndefined();
  });

  it('показує помилку та дозволяє повторити відновлення після втрати мережі', async () => {
    axios.post.mockRejectedValueOnce(new Error('offline'));
    await showList([order(1, 'pending')]);
    expect(wrapper.get('[role="alert"]').text()).toContain('Не вдалося повернути');
    expect(wrapper.get('.btn-main-action').attributes('disabled')).toBeDefined();
    expect(axios.get).not.toHaveBeenCalled();
    await wrapper.get('[role="alert"] button').trigger('click');
    await flushPromises();
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    expect(wrapper.get('.btn-main-action').attributes('disabled')).toBeUndefined();
  });

  it('фонове оновлення не закриває поточне пакування', async () => {
    vi.useFakeTimers();
    try {
      await showList([order(1, 'pending')]);
      await vi.advanceTimersByTimeAsync(30000);
      expect(axios.post).not.toHaveBeenCalled();
      expect(axios.get).toHaveBeenCalledTimes(4);
    } finally {
      vi.useRealTimers();
    }
  });
});
