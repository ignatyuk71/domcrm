import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { nextTick } from 'vue';
import OrdersTable from './OrdersTable.vue';
import { formatStatusDuration } from '@/crm/utils/orderDisplay';

const now = Date.parse('2026-09-08T11:00:00Z');
const order = (overrides = {}) => ({
  id: 5980,
  client: 'Тестовий клієнт',
  status: 'Підтверджено',
  status_key: 'confirmed',
  status_changed_at: '2026-09-08T08:40:00+03:00',
  items: [],
  tags: [],
  total: 399,
  currency: 'UAH',
  ...overrides,
});
let wrapper;

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(now);
});
afterEach(() => {
  wrapper?.unmount();
  wrapper = null;
  vi.useRealTimers();
});

describe('тривалість поточного статусу', () => {
  it.each([
    [0, 'Щойно у статусі'],
    [59_000, 'Щойно у статусі'],
    [60_000, '1 хв у статусі'],
    [59 * 60_000, '59 хв у статусі'],
    [60 * 60_000, '1 год у статусі'],
    [320 * 60_000, '5 год 20 хв у статусі'],
    [24 * 60 * 60_000, '1 дн у статусі'],
    [51 * 60 * 60_000, '2 дн 3 год у статусі'],
  ])('форматує %i мілісекунд', (elapsed, expected) => {
    expect(formatStatusDuration(new Date(now - elapsed).toISOString(), now)).toBe(expected);
  });

  it('не вигадує час без дати та не показує від’ємну тривалість', () => {
    expect(formatStatusDuration(null, now)).toBe('');
    expect(formatStatusDuration('invalid', now)).toBe('');
    expect(formatStatusDuration('2026-09-09T11:00:00Z', now)).toBe('Щойно у статусі');
  });

  it('враховує часовий пояс, оновлюється щохвилини та звільняє таймер', async () => {
    wrapper = shallowMount(OrdersTable, {
      props: { orders: [order()], expandedRows: new Set() },
    });

    expect(wrapper.find('.cell-status').text()).toContain('5 год 20 хв у статусі');
    expect(wrapper.find('time').attributes('title')).toContain('08:40');
    await vi.advanceTimersByTimeAsync(60_000);
    await nextTick();
    expect(wrapper.find('time').text()).toBe('5 год 21 хв у статусі');
    expect(vi.getTimerCount()).toBe(1);
    wrapper.unmount();
    wrapper = null;
    expect(vi.getTimerCount()).toBe(0);
  });

  it('після зміни статусу використовує новий серверний час, а для давніх записів приховує підпис', async () => {
    wrapper = shallowMount(OrdersTable, {
      props: {
        orders: [order({ status_changed_at: null, updated_at: '2026-09-08T10:59:00Z' })],
        expandedRows: new Set(),
      },
    });
    expect(wrapper.find('.order-status-age').exists()).toBe(false);
    await wrapper.setProps({ orders: [order({ status_changed_at: '2026-09-08T11:00:00Z' })] });
    expect(wrapper.find('time').text()).toBe('Щойно у статусі');
    await wrapper.setProps({ orders: [order({ status_changed_at: 'invalid' })] });
    expect(wrapper.find('.order-status-age').exists()).toBe(false);
  });
});
