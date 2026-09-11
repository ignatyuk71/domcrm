import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { useOrderTags } from './useOrderTags';
import { fetchTags } from '@/crm/api/tags';
import { updateOrderTags } from '@/crm/api/orders';

vi.mock('@/crm/api/tags', () => ({ fetchTags: vi.fn() }));
vi.mock('@/crm/api/orders', () => ({ updateOrderTags: vi.fn() }));

const first = { id: 1, name: 'Передзвонити', color: 'blue' };
const second = { id: 2, name: 'Терміново', color: 'red' };

beforeEach(() => vi.resetAllMocks());

describe('автоматичне збереження тегів замовлення', () => {
  it('завантажує довідник один раз навіть для одночасних запитів і порожнього списку', async () => {
    fetchTags.mockResolvedValue({ data: { data: [] } });
    const editor = useOrderTags(() => null);
    await Promise.all([editor.loadTags(), editor.loadTags()]);
    await editor.loadTags();
    expect(fetchTags).toHaveBeenCalledTimes(1);
    expect(editor.loading).toBe(false);
    expect(editor.loadError).toBe('');
  });

  it('дозволяє повторити завантаження після помилки', async () => {
    fetchTags.mockRejectedValueOnce(new Error('Мережа')).mockResolvedValueOnce({ data: [first] });
    const editor = useOrderTags(() => null);
    await editor.loadTags();
    expect(editor.loadError).toBeTruthy();
    await editor.loadTags();
    expect(editor.loadError).toBe('');
    expect(editor.tags).toEqual([first]);
  });

  it('додає тег одразу, зберігає інші теги та не допускає паралельного перезапису замовлення', async () => {
    let finish;
    updateOrderTags.mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
    const order = reactive({ id: 10, tags: [first] });
    const editor = useOrderTags(() => order);
    const request = editor.toggleTag(order, second);
    expect(order.tags).toEqual([first, second]);
    expect(editor.saving[10]).toBe(true);
    await editor.toggleTag(order, first);
    expect(updateOrderTags).toHaveBeenCalledTimes(1);
    expect(updateOrderTags).toHaveBeenCalledWith(10, [1, 2]);
    finish({ data: { data: [first, second] } });
    await request;
    expect(editor.saving[10]).toBeUndefined();
  });

  it('знімає останній тег і передає порожній список', async () => {
    updateOrderTags.mockResolvedValue({ data: { data: [] } });
    const order = reactive({ id: 10, tags: [first] });
    const editor = useOrderTags(() => order);
    await editor.toggleTag(order, { ...first, id: '1' });
    expect(updateOrderTags).toHaveBeenCalledWith(10, []);
    expect(order.tags).toEqual([]);
  });

  it('відновлює попередній вибір після помилки та дозволяє повторити збереження', async () => {
    updateOrderTags.mockRejectedValueOnce(new Error('Мережа')).mockResolvedValueOnce({ data: [first, second] });
    const order = reactive({ id: 10, tags: [first] });
    const editor = useOrderTags(() => order);
    await editor.toggleTag(order, second);
    expect(order.tags).toEqual([first]);
    expect(editor.errors[10]).toBeTruthy();
    expect(editor.saving[10]).toBeUndefined();
    await editor.toggleTag(order, second);
    expect(order.tags).toEqual([first, second]);
    expect(editor.errors[10]).toBe('');
  });

  it('оновлює поточний рядок, якщо список перезавантажили під час збереження', async () => {
    let finish;
    updateOrderTags.mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
    const original = reactive({ id: 10, tags: [] });
    let current = original;
    const editor = useOrderTags(() => current);
    const request = editor.toggleTag(original, first);
    current = reactive({ id: 10, tags: [] });
    finish({ data: { data: [first] } });
    await request;
    expect(current.tags).toEqual([first]);
    expect(original.tags).toEqual([first]);
  });
});
