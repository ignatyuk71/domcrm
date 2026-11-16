import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import axios from 'axios';
import OrderShowPage from './OrderShowPage.vue';
import OrderDetails from '@/crm/components/orders/list/OrderDetails.vue';
import Toast from '@/crm/components/ui/Toast.vue';
import { getOrder, updateOrderTags, updateOrderStatus } from '@/crm/api/orders';
import { fetchStatuses } from '@/crm/api/statuses';
import { fetchTags } from '@/crm/api/tags';

vi.mock('@/crm/api/orders', () => ({
  getOrder: vi.fn(),
  updateOrderTags: vi.fn(),
  updateOrderStatus: vi.fn(),
  updateOrderComment: vi.fn(),
}));
vi.mock('@/crm/api/statuses', () => ({ fetchStatuses: vi.fn() }));
vi.mock('@/crm/api/tags', () => ({ fetchTags: vi.fn() }));

let wrapper;

beforeEach(() => {
  vi.clearAllMocks();
  fetchStatuses.mockResolvedValue({ data: { data: [] } });
  fetchTags.mockResolvedValue({ data: { data: [] } });
  getOrder.mockResolvedValue({
    data: {
      data: {
        id: 5980,
        status: 'delivered',
        status_ref: { id: 6, code: 'delivered', name: 'У відділенні', color: '#f59e0b', icon: 'bi-geo-alt' },
        status_changed_at: '2026-09-07T09:00:00Z',
        delivery: {
          ttn: '20450000000001',
          delivery_status_label: 'Прибув у відділення',
          delivery_status_code: 'at_warehouse',
          delivery_status_color: '#f59e0b',
          delivery_status_icon: 'bi-building',
          delivery_status_description: 'Посилка у відділенні',
          active_warehouse_status: { entered_at: '2026-09-07T08:00:00Z' },
        },
      },
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

describe('створення ТТН у картці', () => {
  const prepareOrder = (code = 'confirmed', name = 'Підтверджено', deliveryType = 'warehouse') => {
    getOrder.mockResolvedValue({ data: { data: {
      id: 5980, status: code, status_ref: { id: 3, code, name },
      items: [], delivery: { delivery_type: deliveryType },
    } } });
  };

  it.each([['new', 'Новий'], ['in_process', 'В обробці']])('показує warning у %s без API-запиту', async (code, name) => {
    prepareOrder(code, name);
    const fetch = vi.fn();
    vi.stubGlobal('fetch', fetch);
    wrapper = shallowMount(OrderShowPage, { props: { initialOrderId: 5980 }, global: { stubs: { OrderDetails: false } } });
    await flushPromises();
    await wrapper.get('.btn-create-ttn').trigger('click');
    expect(wrapper.findComponent(Toast).props()).toMatchObject({
      show: true, type: 'warning',
      messages: [`Неможливо створити ТТН у статусі «${name}». Спочатку змініть статус замовлення на «Підтверджено».`],
    });
    expect(fetch).not.toHaveBeenCalled();
  });

  it('повторно перевіряє статус перед запитом з handler', async () => {
    prepareOrder('returned', 'Повернення');
    const fetch = vi.fn();
    vi.stubGlobal('fetch', fetch);
    wrapper = shallowMount(OrderShowPage, { props: { initialOrderId: 5980 } });
    await flushPromises();
    wrapper.findComponent(OrderDetails).vm.$emit('generate-ttn');
    await flushPromises();
    expect(fetch).not.toHaveBeenCalled();
    expect(wrapper.findComponent(Toast).props('type')).toBe('warning');
  });

  it.each([['warehouse', '/orders/5980/generate-ttn'], ['courier', '/orders/5980/generate-ttn-courier']])('дозволяє підтверджене замовлення для %s', async (deliveryType, endpoint) => {
    prepareOrder('confirmed', 'Підтверджено', deliveryType);
    const fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true, ttn: '20450000000002', ref: 'ref-2' }) });
    vi.stubGlobal('fetch', fetch);
    wrapper = shallowMount(OrderShowPage, { props: { initialOrderId: 5980 } });
    await flushPromises();
    const details = wrapper.findComponent(OrderDetails);
    details.vm.$emit('generate-ttn');
    await flushPromises();
    expect(fetch).toHaveBeenCalledWith(endpoint, expect.objectContaining({ method: 'POST' }));
    expect(details.props('order')).toMatchObject({ ttn: '20450000000002', ttn_ref: 'ref-2', loadingTtn: false });
    expect(wrapper.findComponent(Toast).props('type')).toBe('success');
  });

  it.each(['warehouse', 'courier'])('зберігає дослівну серверну помилку %s і дію повтору', async (deliveryType) => {
    vi.useFakeTimers();
    prepareOrder('confirmed', 'Підтверджено', deliveryType);
    const message = 'Неможливо створити ТТН у статусі «Новий». Спочатку змініть статус замовлення на «Підтверджено».';
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: false, json: async () => ({ message }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ success: true, ttn: '20450000000002' }) });
    vi.stubGlobal('fetch', fetch);
    wrapper = shallowMount(OrderShowPage, { props: { initialOrderId: 5980 } });
    await flushPromises();
    const details = wrapper.findComponent(OrderDetails);
    details.vm.$emit('generate-ttn');
    await flushPromises();
    const toast = wrapper.findComponent(Toast);
    expect(toast.props()).toMatchObject({ show: true, type: 'error', actionLabel: 'Повторити', messages: [message] });
    expect(details.props('order')).toMatchObject({ ttn: '', loadingTtn: false });
    await vi.advanceTimersByTimeAsync(10_000);
    expect(toast.props('show')).toBe(true);
    toast.vm.$emit('action');
    await flushPromises();
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(details.props('order').ttn).toBe('20450000000002');
  });

  it('зберігає message із response при відхиленому запиті', async () => {
    prepareOrder();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue({ response: { data: { message: 'Точна серверна помилка.' } } }));
    wrapper = shallowMount(OrderShowPage, { props: { initialOrderId: 5980 } });
    await flushPromises();
    const details = wrapper.findComponent(OrderDetails);
    details.vm.$emit('generate-ttn');
    await flushPromises();
    expect(wrapper.findComponent(Toast).props('messages')).toEqual(['Точна серверна помилка.']);
    expect(details.props('order').loadingTtn).toBe(false);
  });

  it.each(['status', 'ttn'])('повтор після оновлення картки враховує нові дані: %s', async (change) => {
    prepareOrder();
    const fetch = vi.fn().mockResolvedValue({ ok: false, json: async () => ({ message: 'Точна серверна помилка.' }) });
    vi.stubGlobal('fetch', fetch);
    wrapper = shallowMount(OrderShowPage, { props: { initialOrderId: 5980 } });
    await flushPromises();
    const details = wrapper.findComponent(OrderDetails);
    const previous = details.props('order');
    details.vm.$emit('generate-ttn');
    await flushPromises();
    if (change === 'status') prepareOrder('in_process', 'В обробці');
    else getOrder.mockResolvedValue({ data: { data: {
      id: 5980, status: 'confirmed', items: [], delivery: { ttn: '20450000000003' },
    } } });
    await wrapper.get('.hero-actions button').trigger('click');
    await flushPromises();
    wrapper.findComponent(Toast).vm.$emit('action');
    await flushPromises();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(previous.ttn).toBe('');
    if (change === 'status') expect(wrapper.findComponent(Toast).props('messages')[0]).toContain('«В обробці»');
    else expect(details.props('order').ttn).toBe('20450000000003');
  });
});

describe('оновлення доставки у перегляді замовлення', () => {
  it('оновлює статус і час зміни в картці після вибору без модального вікна', async () => {
    const status = { id: 11, code: 'delivered_paid', name: 'Завершено', color: '#16a34a', icon: 'bi-check-all', status_changed_at: '2026-09-11T12:00:00Z' };
    fetchStatuses.mockResolvedValue({ data: { data: [status] } });
    updateOrderStatus.mockResolvedValue({ data: { data: status } });
    wrapper = shallowMount(OrderShowPage, { props: { initialOrderId: 5980 } });
    await flushPromises();
    const details = wrapper.findComponent(OrderDetails);
    await details.props('statusEditor').selectStatus(details.props('order'), status);
    expect(details.props('order')).toMatchObject({ status_id: 11, status: 'Завершено', status_color: '#16a34a', status_icon: 'bi-check-all', status_changed_at: status.status_changed_at });
    expect(wrapper.get('.status-chip').text()).toBe('Завершено');
    expect(updateOrderStatus).toHaveBeenCalledWith(5980, 11);
    expect(getOrder).toHaveBeenCalledTimes(1);
  });

  it('автоматично зберігає тег у деталях без повторного завантаження картки', async () => {
    const tag = { id: 1, name: 'Терміново', color: 'red' };
    fetchTags.mockResolvedValue({ data: { data: [tag] } });
    updateOrderTags.mockResolvedValue({ data: { data: [tag] } });
    wrapper = shallowMount(OrderShowPage, { props: { initialOrderId: 5980 } });
    await flushPromises();
    const details = wrapper.findComponent(OrderDetails);
    const editor = details.props('tagEditor');
    expect(editor.tags).toEqual([tag]);
    await editor.toggleTag(details.props('order'), tag);
    expect(details.props('order').tags).toEqual([tag]);
    expect(updateOrderTags).toHaveBeenCalledWith(5980, [1]);
    expect(getOrder).toHaveBeenCalledTimes(1);
  });

  it('оновлює статус замовлення разом із доставкою у картці та деталях без перезавантаження', async () => {
    const post = vi.spyOn(axios, 'post').mockResolvedValue({
      data: {
        delivery_status_label: 'Отримано',
        delivery_status_code: 'received',
        delivery_status_color: '#16a34a',
        delivery_status_icon: 'bi-check-circle-fill',
        delivery_status_updated_at: '2026-09-08T12:00:00Z',
        delivery_status_description: null,
        last_tracked_at: '2026-09-08T12:00:00Z',
        warehouse_entered_at: null,
        order_status: {
          id: 11,
          code: 'delivered_paid',
          name: 'Завершено',
          color: '#16a34a',
          icon: 'bi-check-all',
          status_changed_at: '2026-09-08T12:00:00Z',
        },
      },
    });
    wrapper = shallowMount(OrderShowPage, { props: { initialOrderId: 5980 } });
    await flushPromises();
    const details = wrapper.findComponent(OrderDetails);
    expect(details.props('order').status_key).toBe('delivered');
    expect(details.props('order').delivery_status_description).toBe('Посилка у відділенні');
    expect(details.props('order').delivery_status_entered_at).toBe('2026-09-07T08:00:00Z');

    details.vm.$emit('refresh-delivery');
    await flushPromises();

    expect(post).toHaveBeenCalledTimes(1);
    expect(post.mock.calls[0][0]).toBe('/orders/5980/track-delivery');
    expect(details.props('order')).toMatchObject({
      delivery_status: 'Отримано',
      delivery_status_code: 'received',
      delivery_status_color: '#16a34a',
      delivery_status_icon: 'bi-check-circle-fill',
      delivery_status_updated_at: '2026-09-08T12:00:00Z',
      delivery_status_description: '',
      last_tracked_at: '2026-09-08T12:00:00Z',
      delivery_status_entered_at: '',
      status_id: 11,
      status_key: 'delivered_paid',
      status: 'Завершено',
      status_color: '#16a34a',
      status_icon: 'bi-check-all',
      status_changed_at: '2026-09-08T12:00:00Z',
      refreshingDelivery: false,
    });
    expect(wrapper.find('.status-chip').text()).toContain('Завершено');
    expect(getOrder).toHaveBeenCalledTimes(1);
  });
});
