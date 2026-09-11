import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { useOrderStatuses } from './useOrderStatuses';
import { fetchStatuses } from '@/crm/api/statuses';
import { updateOrderStatus } from '@/crm/api/orders';

vi.mock('@/crm/api/statuses', () => ({ fetchStatuses: vi.fn() }));
vi.mock('@/crm/api/orders', () => ({ updateOrderStatus: vi.fn() }));

const nextStatus = { id: 2, code: 'confirmed', name: 'Підтверджено', color: '#15803d', icon: 'bi-check-circle', status_changed_at: '2026-09-11T12:00:00Z' };
const makeOrder = () => reactive({ id: 10, status_id: 1, status_key: 'new', status: 'Новий', status_color: '#888888', status_icon: 'bi-circle', status_changed_at: '2026-09-10T12:00:00Z' });

beforeEach(() => vi.resetAllMocks());

describe('вибір статусу в рядку', () => {
  it('завантажує лише статуси замовлень і повторно використовує порожній довідник', async () => {
    fetchStatuses.mockResolvedValue({ data: { data: [] } });
    const editor = useOrderStatuses(() => null);
    await Promise.all([editor.loadStatuses(), editor.loadStatuses()]);
    await editor.loadStatuses();
    expect(fetchStatuses).toHaveBeenCalledTimes(1);
    expect(fetchStatuses).toHaveBeenCalledWith({ type: 'order' });
    expect(editor.loading).toBe(false);
  });

  it('повторює завантаження після помилки мережі', async () => {
    fetchStatuses.mockRejectedValueOnce(new Error('Мережа')).mockResolvedValueOnce({ data: [nextStatus] });
    const editor = useOrderStatuses(() => null);
    await editor.loadStatuses();
    expect(editor.loadError).toBeTruthy();
    await editor.loadStatuses();
    expect(editor.loadError).toBe('');
    expect(editor.statuses).toEqual([nextStatus]);
  });

  it('не знімає поточний статус та не створює зайвого запиту', async () => {
    const order = makeOrder();
    const editor = useOrderStatuses(() => order);
    await editor.selectStatus(order, { id: '1', code: 'new' });
    delete order.status_id;
    await editor.selectStatus(order, { id: 1, code: 'new' });
    expect(updateOrderStatus).not.toHaveBeenCalled();
    expect(order.status_key).toBe('new');
  });

  it('зберігає один вибір за раз і застосовує підтверджені сервером дані та час', async () => {
    let finish;
    updateOrderStatus.mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
    const order = makeOrder();
    const onSaved = vi.fn();
    const editor = useOrderStatuses(() => order, onSaved);
    const request = editor.selectStatus(order, nextStatus);
    expect(editor.saving[10]).toBe(2);
    expect(order.status_id).toBe(1);
    await editor.selectStatus(order, { id: 3, code: 'shipped' });
    expect(updateOrderStatus).toHaveBeenCalledTimes(1);
    expect(updateOrderStatus).toHaveBeenCalledWith(10, 2);
    finish({ data: { data: nextStatus } });
    await request;
    expect(order).toMatchObject({ status_id: 2, status_key: 'confirmed', status: 'Підтверджено', status_color: '#15803d', status_icon: 'bi-check-circle', status_changed_at: nextStatus.status_changed_at });
    expect(editor.saving[10]).toBeUndefined();
    expect(onSaved).toHaveBeenCalledExactlyOnceWith(1, nextStatus);
  });

  it('зберігає попередній статус після відмови та дозволяє повторити клік', async () => {
    updateOrderStatus.mockRejectedValueOnce(new Error('Відмовлено')).mockResolvedValueOnce({ data: nextStatus });
    const order = makeOrder();
    const previous = { ...order };
    const editor = useOrderStatuses(() => order);
    await editor.selectStatus(order, nextStatus);
    expect(order).toEqual(previous);
    expect(editor.saving[10]).toBeUndefined();
    expect(editor.errors[10]).toBeTruthy();
    await editor.selectStatus(order, nextStatus);
    expect(order.status_id).toBe(2);
    expect(editor.errors[10]).toBe('');
  });

  it('оновлює рядок навіть після перезавантаження списку під час запиту', async () => {
    let finish;
    updateOrderStatus.mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
    const original = makeOrder();
    let current = original;
    const editor = useOrderStatuses(() => current);
    const request = editor.selectStatus(original, nextStatus);
    current = makeOrder();
    finish({ data: { data: nextStatus } });
    await request;
    expect(current.status_id).toBe(2);
    expect(original.status_id).toBe(2);
  });

  it('не очищає статус при некоректній відповіді сервера', async () => {
    updateOrderStatus.mockResolvedValue({ data: {} });
    const order = makeOrder();
    const onSaved = vi.fn();
    const editor = useOrderStatuses(() => order, onSaved);
    await editor.selectStatus(order, nextStatus);
    expect(order.status_id).toBe(1);
    expect(editor.errors[10]).toBeTruthy();
    expect(onSaved).not.toHaveBeenCalled();
  });
});
