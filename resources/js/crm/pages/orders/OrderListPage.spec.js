import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import axios from 'axios';
import OrderListPage from './OrderListPage.vue';
import OrdersTable from '@/crm/components/orders/list/OrdersTable.vue';
import OrdersTopbar from '@/crm/components/orders/list/OrdersTopbar.vue';
import OrdersPagination from '@/crm/components/orders/list/OrdersPagination.vue';
import Toast from '@/crm/components/ui/Toast.vue';
import { listOrders, updateOrderTags, updateOrderStatus } from '@/crm/api/orders';
import { fetchStatuses } from '@/crm/api/statuses';
import { fetchTags } from '@/crm/api/tags';

vi.mock('@/crm/api/orders', () => ({
  listOrders: vi.fn(),
  deleteOrder: vi.fn(),
  updateOrderTags: vi.fn(),
  updateOrderStatus: vi.fn(),
  updateOrderComment: vi.fn(),
}));
vi.mock('@/crm/api/statuses', () => ({ fetchStatuses: vi.fn() }));
vi.mock('@/crm/api/tags', () => ({ fetchTags: vi.fn() }));

// Доступність каси окремо перевіряється у FiscalAvailability-тестах.
vi.mock('@/crm/composables/useFiscalAvailability', () => ({
  useFiscalAvailability: () => ({ available: true, disabledReason: '' }),
}));

let wrapper;

beforeEach(() => {
  vi.clearAllMocks();
  listOrders.mockReset();
  fetchTags.mockResolvedValue({ data: { data: [] } });
  fetchStatuses.mockResolvedValue({ data: { data: [] } });
  listOrders.mockResolvedValue({
    data: {
      status_counts: { 5: 3, 6: 2 },
      data: [{
        id: 5980,
        status: 'shipped',
        status_ref: { id: 5, code: 'shipped', name: 'Відправлено', color: '#0ea5e9', icon: 'bi-truck' },
        status_changed_at: '2026-09-07T09:00:00Z',
        delivery: {
          ttn: '20450000000001',
          delivery_status_label: 'У дорозі',
          delivery_status_code: 'in_transit',
          delivery_status_color: '#0ea5e9',
          delivery_status_icon: 'bi-truck',
          delivery_status_description: 'Попередній опис перевізника',
          last_tracked_at: '2026-09-07T09:00:00Z',
        },
      }],
    },
  });
});

afterEach(() => {
  wrapper?.unmount();
  wrapper = null;
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('універсальний пошук замовлень', () => {
  const result = (id) => ({ data: {
    data: [{ id, order_number: String(id), items: [] }],
    current_page: 1, last_page: 4, total: 4, status_counts: { 5: id },
  } });
  const deferred = () => {
    let resolve, reject;
    const promise = new Promise((done, fail) => { resolve = done; reject = fail; });
    return { promise, resolve, reject };
  };
  const mountSearch = () => {
    wrapper = shallowMount(OrderListPage, { global: { stubs: { OrdersTopbar: false } } });
    return wrapper.find('.search-input');
  };

  it.each(['12345', '+38 (099) 111-22-33', 'Гордієнко Зоряна', 'Зоряна Гордієнко'])('надсилає запит %s без спотворення та скидає сторінку', async (search) => {
    vi.useFakeTimers();
    listOrders.mockResolvedValue(result(1));
    const input = mountSearch();
    await flushPromises();
    wrapper.findComponent(OrdersPagination).vm.$emit('change-page', 3);
    await flushPromises();
    expect(listOrders.mock.calls.at(-1)[0].page).toBe(3);

    await input.setValue(search);
    await vi.advanceTimersByTimeAsync(299);
    expect(listOrders).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(1);
    expect(listOrders.mock.calls.at(-1)[0]).toMatchObject({ search, page: 1 });
  });

  it('об’єднує швидке введення в один запит і очищення повертає загальний список', async () => {
    vi.useFakeTimers();
    const input = mountSearch();
    await flushPromises();
    for (const value of ['1', '12', '12345']) {
      await input.setValue(value);
      await vi.advanceTimersByTimeAsync(100);
    }
    await vi.advanceTimersByTimeAsync(200);
    expect(listOrders).toHaveBeenCalledTimes(2);
    expect(listOrders.mock.calls.at(-1)[0].search).toBe('12345');

    await input.setValue('');
    await vi.advanceTimersByTimeAsync(300);
    expect(listOrders.mock.calls.at(-1)[0].search).toBeUndefined();
  });

  it('старі відповіді не замінюють нові результати, пагінацію та лічильники', async () => {
    vi.useFakeTimers();
    const older = deferred();
    const newer = deferred();
    listOrders.mockResolvedValueOnce(result(1)).mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise);
    fetchStatuses.mockResolvedValue({ data: { data: [{ id: 5, code: 'shipped', name: 'Відправлено' }] } });
    const input = mountSearch();
    await flushPromises();
    await input.setValue('12345');
    await vi.advanceTimersByTimeAsync(300);
    const olderSignal = listOrders.mock.calls[1][1]?.signal;
    await input.setValue('Гордієнко');
    expect(olderSignal?.aborted).toBe(true);
    await vi.advanceTimersByTimeAsync(300);

    newer.resolve({ data: { ...result(22).data, total: 22, last_page: 22 } });
    await flushPromises();
    older.resolve(result(11));
    await flushPromises();
    expect(wrapper.findComponent(OrdersTable).props('orders').map((order) => order.id)).toEqual([22]);
    expect(wrapper.findComponent(OrdersPagination).props('meta')).toMatchObject({ total: 22, last_page: 22 });
    expect(wrapper.findComponent(OrdersTopbar).props('statusChips').find((chip) => chip.value === 5).label).toContain('22');
    expect(wrapper.findComponent(OrdersTable).props('loading')).toBe(false);
  });

  it('відкидає стару відповідь одразу після нового вводу, ще до завершення затримки', async () => {
    vi.useFakeTimers();
    const older = deferred();
    listOrders.mockResolvedValueOnce(result(1)).mockReturnValueOnce(older.promise);
    const input = mountSearch();
    await flushPromises();
    await input.setValue('12345');
    await vi.advanceTimersByTimeAsync(300);
    await input.setValue('Зоряна');
    older.resolve(result(11));
    await flushPromises();
    expect(wrapper.findComponent(OrdersTable).props('orders').map((order) => order.id)).toEqual([1]);
    expect(wrapper.findComponent(OrdersTable).props('loading')).toBe(true);
  });

  it('показує помилку з повтором, зберігаючи запит і попередні результати', async () => {
    vi.useFakeTimers();
    listOrders.mockResolvedValueOnce(result(1)).mockRejectedValueOnce({ response: { data: { message: 'Пошуковий сервіс недоступний' } } });
    const input = mountSearch();
    await flushPromises();
    await input.setValue('12345');
    await vi.advanceTimersByTimeAsync(300);
    await flushPromises();
    const notice = wrapper.findComponent(Toast);
    expect(notice.props()).toMatchObject({ show: true, type: 'error', actionLabel: 'Повторити' });
    expect(notice.props('messages').join(' ')).toContain('Пошуковий сервіс недоступний');
    expect(notice.props('messages').join(' ')).toContain('12345');
    expect(input.element.value).toBe('12345');
    expect(wrapper.findComponent(OrdersTable).props('orders')[0].id).toBe(1);
    await vi.advanceTimersByTimeAsync(10_000);
    expect(notice.props('show')).toBe(true);

    listOrders.mockResolvedValueOnce(result(2));
    notice.vm.$emit('action');
    await flushPromises();
    expect(listOrders.mock.calls.at(-1)[0].search).toBe('12345');
    expect(wrapper.findComponent(OrdersTable).props('orders')[0].id).toBe(2);
  });

  it('не показує помилки скасованих запитів і не вимикає завантаження нового', async () => {
    vi.useFakeTimers();
    const older = deferred();
    const newer = deferred();
    listOrders.mockResolvedValueOnce(result(1)).mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise);
    const input = mountSearch();
    await flushPromises();
    await input.setValue('12345');
    await vi.advanceTimersByTimeAsync(300);
    await input.setValue('Зоряна');
    await vi.advanceTimersByTimeAsync(300);
    older.reject(new Error('Стара помилка'));
    await flushPromises();
    expect(wrapper.findComponent(OrdersTable).props('loading')).toBe(true);
    expect(wrapper.findComponent(Toast).props('show')).toBe(false);
    newer.resolve(result(2));
    await flushPromises();
  });

  it('підсвічує некоректний запит і зберігає пояснення сервера до виправлення', async () => {
    vi.useFakeTimers();
    const message = 'Пошуковий запит має містити не більше 200 символів.';
    listOrders.mockRejectedValueOnce({ response: { data: { errors: { search: [message] } } } });
    const input = mountSearch();
    await flushPromises();
    expect(input.attributes('aria-invalid')).toBe('true');
    expect(wrapper.findComponent(Toast).props('messages')).toContain(message);
    await input.setValue('12345');
    expect(input.attributes('aria-invalid')).toBeUndefined();
    expect(wrapper.findComponent(Toast).props('show')).toBe(true);
  });

  it('скасовує запити й відкладений пошук при виході зі сторінки', async () => {
    vi.useFakeTimers();
    const pending = deferred();
    listOrders.mockReturnValueOnce(pending.promise);
    const input = mountSearch();
    const signal = listOrders.mock.calls[0][1]?.signal;
    await input.setValue('12345');
    wrapper.unmount();
    wrapper = null;
    await vi.advanceTimersByTimeAsync(300);
    expect(signal?.aborted).toBe(true);
    expect(listOrders).toHaveBeenCalledTimes(1);
    pending.resolve(result(1));
    await flushPromises();
  });
});

describe('оновлення доставки у списку замовлень', () => {
  it('плитка бронювання показує лише бронювання та скидає несумісний контроль зберігання', async () => {
    fetchStatuses.mockResolvedValue({ data: { data: [
      { id: 3, code: 'confirmed', name: 'Підтверджено' },
      { id: 12, code: 'reserved', name: 'Бронювання', color: '#0f766e' },
      { id: 9, code: 'returned', name: 'Повернення' },
    ] } });
    wrapper = shallowMount(OrderListPage, { global: { stubs: { OrdersTopbar: false } } });
    await flushPromises();
    const topbar = wrapper.findComponent(OrdersTopbar);
    expect(topbar.get('.status-scroll-area').text()).toContain('Підтверджено');
    expect(topbar.findAll('.reservation-filter')).toHaveLength(1);
    expect(topbar.find('.status-scroll-area .reservation-filter').exists()).toBe(false);
    expect(topbar.find('.special-filter-zone .reservation-filter').exists()).toBe(true);
    expect(topbar.get('.status-scroll-area').text()).toContain('Повернення');
    await topbar.get('.alert-toggle-btn').trigger('click');
    await flushPromises();
    await topbar.get('.reservation-filter').trigger('click');
    await flushPromises();
    expect(listOrders.mock.calls.at(-1)[0]).toMatchObject({ page: 1, status_ids: '12', delivery_hold_days: undefined });
    expect(topbar.get('.reservation-filter').attributes('aria-pressed')).toBe('true');
    expect(topbar.props('holdFilterActive')).toBe(false);
    await topbar.get('.reservation-filter').trigger('click');
    await flushPromises();
    expect(listOrders.mock.calls.at(-1)[0].status_ids).toBeUndefined();
    expect(topbar.get('.reservation-filter').attributes('aria-pressed')).toBe('false');
    expect(updateOrderStatus).not.toHaveBeenCalled();
  });

  it('змінює статус, час і лічильники без перезавантаження таблиці', async () => {
    const previous = { id: 5, code: 'shipped', name: 'Відправлено' };
    const status = { id: 6, code: 'delivered', name: 'У відділенні', color: '#f59e0b', icon: 'bi-building', status_changed_at: '2026-09-11T12:00:00Z' };
    fetchStatuses.mockResolvedValue({ data: { data: [previous, status] } });
    updateOrderStatus.mockResolvedValue({ data: { data: status } });
    wrapper = shallowMount(OrderListPage);
    await flushPromises();
    const table = wrapper.findComponent(OrdersTable);
    const editor = table.props('statusEditor');
    await editor.selectStatus(table.props('orders')[0], status);
    expect(table.props('orders')[0]).toMatchObject({ status_id: 6, status: 'У відділенні', status_color: '#f59e0b', status_icon: 'bi-building', status_changed_at: status.status_changed_at });
    const chips = wrapper.findComponent(OrdersTopbar).props('statusChips');
    expect(chips.find((chip) => chip.value === 5).label).toBe('Відправлено · 2');
    expect(chips.find((chip) => chip.value === 6).label).toBe('У відділенні · 3');
    expect(updateOrderStatus).toHaveBeenCalledWith(5980, 6);
    expect(listOrders).toHaveBeenCalledTimes(1);
  });

  it('автоматично оновлює теги в таблиці без повторного завантаження замовлень', async () => {
    const tag = { id: 1, name: 'Терміново', color: 'red' };
    fetchTags.mockResolvedValue({ data: { data: [tag] } });
    updateOrderTags.mockResolvedValue({ data: { data: [tag] } });
    wrapper = shallowMount(OrderListPage);
    await flushPromises();
    const table = wrapper.findComponent(OrdersTable);
    const editor = table.props('tagEditor');
    expect(editor.tags).toEqual([tag]);
    await editor.toggleTag(table.props('orders')[0], tag);
    expect(table.props('orders')[0].tags).toEqual([tag]);
    expect(updateOrderTags).toHaveBeenCalledWith(5980, [1]);
    expect(listOrders).toHaveBeenCalledTimes(1);
  });

  it('показує галочку в рядку лише після успішного копіювання та не розгортає замовлення', async () => {
    let finishCopy;
    const writeText = vi.fn(() => new Promise((resolve) => { finishCopy = resolve; }));
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    wrapper = shallowMount(OrderListPage, { global: { stubs: { OrdersTable: false } } });
    await flushPromises();

    const button = wrapper.get('.ttn-row');
    await button.trigger('click');
    expect(writeText).toHaveBeenCalledWith('20450000000001');
    expect(button.find('.bi-check-lg').exists()).toBe(false);
    expect(wrapper.findComponent(OrdersTable).props('expandedRows').size).toBe(0);

    finishCopy();
    await flushPromises();
    expect(button.find('.ttn-copy-icon.is-copied .bi-check-lg').exists()).toBe(true);
    expect(button.attributes('title')).toBe('ТТН скопійовано');
    expect(button.find('.bi-copy').exists()).toBe(false);
    expect(listOrders).toHaveBeenCalledTimes(1);
  });

  it('передає таблиці нові статуси, колір та іконку без повторного завантаження списку', async () => {
    const post = vi.spyOn(axios, 'post').mockResolvedValue({
      data: {
        delivery_status_label: 'Прибув у відділення',
        delivery_status_code: 'at_warehouse',
        delivery_status_color: '#f59e0b',
        delivery_status_icon: 'bi-building',
        delivery_status_updated_at: '2026-09-08T12:00:00Z',
        delivery_status_description: 'Посилка готова до видачі',
        last_tracked_at: '2026-09-08T12:00:00Z',
        warehouse_entered_at: '2026-09-08T10:00:00Z',
        order_status: {
          id: 6,
          code: 'delivered',
          name: 'У відділенні',
          color: '#f59e0b',
          icon: 'bi-geo-alt',
          status_changed_at: '2026-09-08T12:00:00Z',
        },
      },
    });
    wrapper = shallowMount(OrderListPage);
    await flushPromises();
    const table = wrapper.findComponent(OrdersTable);
    expect(table.props('orders')[0].delivery_status_color).toBe('#0ea5e9');
    expect(table.props('orders')[0].delivery_status_description).toBe('Попередній опис перевізника');
    expect(table.props('orders')[0].last_tracked_at).toBe('2026-09-07T09:00:00Z');

    table.vm.$emit('refresh-delivery', table.props('orders')[0]);
    await flushPromises();

    expect(post).toHaveBeenCalledTimes(1);
    expect(post.mock.calls[0][0]).toBe('/orders/5980/track-delivery');
    expect(table.props('orders')[0]).toMatchObject({
      delivery_status: 'Прибув у відділення',
      delivery_status_code: 'at_warehouse',
      delivery_status_color: '#f59e0b',
      delivery_status_icon: 'bi-building',
      delivery_status_updated_at: '2026-09-08T12:00:00Z',
      delivery_status_description: 'Посилка готова до видачі',
      last_tracked_at: '2026-09-08T12:00:00Z',
      delivery_status_entered_at: '2026-09-08T10:00:00Z',
      status_id: 6,
      status_key: 'delivered',
      status: 'У відділенні',
      status_color: '#f59e0b',
      status_icon: 'bi-geo-alt',
      status_changed_at: '2026-09-08T12:00:00Z',
      refreshingDelivery: false,
    });
    expect(listOrders).toHaveBeenCalledTimes(1);
  });
});
