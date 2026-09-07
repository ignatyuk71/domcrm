import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('@/crm/api/http', () => ({ default: { get: vi.fn() } }));

import http from '@/crm/api/http';
import OrderStatusHistory from './OrderStatusHistory.vue';

const entry = (id, overrides = {}) => ({
  id,
  old_status: 'confirmed',
  old_status_name: 'Підтверджено',
  new_status: 'shipped',
  new_status_name: 'Відправлено',
  actor_name: 'Оператор',
  source_label: 'Редагування замовлення',
  reason: 'Статус змінено менеджером',
  occurred_at: '2026-09-07T09:00:07Z',
  metadata: {},
  ...overrides,
});

const response = (data = [], current_page = 1, next_page_url = null) => ({
  data: { data, current_page, next_page_url },
});

function button(wrapper, label) {
  return wrapper.findAll('button').find((candidate) => candidate.text().includes(label));
}

beforeEach(() => vi.resetAllMocks());

describe('OrderStatusHistory', () => {
  it('завантажує історію лише при відкритті та показує час за Києвом', async () => {
    http.get.mockResolvedValue(response([entry(1)]));
    const wrapper = mount(OrderStatusHistory, { props: { orderId: 5866 } });

    expect(http.get).not.toHaveBeenCalled();
    expect(wrapper.find('table').exists()).toBe(false);
    await button(wrapper, 'Історія статусів').trigger('click');
    await flushPromises();

    expect(http.get).toHaveBeenCalledWith('/orders/5866/status-history', { params: { page: 1 } });
    expect(wrapper.text()).toContain('Підтверджено');
    expect(wrapper.text()).toContain('Відправлено');
    expect(wrapper.text()).toContain('Оператор');
    expect(wrapper.text()).toContain('12:00:07');
    await button(wrapper, 'Історія статусів').trigger('click');
    await button(wrapper, 'Історія статусів').trigger('click');
    expect(http.get).toHaveBeenCalledTimes(1);
    wrapper.unmount();
  });

  it('показує порожній стан та оновлює журнал вручну', async () => {
    http.get.mockResolvedValueOnce(response()).mockResolvedValueOnce(response([entry(2)]));
    const wrapper = mount(OrderStatusHistory, { props: { orderId: 5866 } });
    await button(wrapper, 'Історія статусів').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Зміни фіксуються після ввімкнення журналу.');
    await button(wrapper, 'Оновити').trigger('click');
    await flushPromises();
    expect(wrapper.findAll('tbody tr')).toHaveLength(1);
    expect(http.get).toHaveBeenCalledTimes(2);
    wrapper.unmount();
  });

  it('повторює невдалу сторінку без втрати вже отриманих записів', async () => {
    http.get
      .mockResolvedValueOnce(response([entry(1)], 1, '/orders/5866/status-history?page=2'))
      .mockRejectedValueOnce(new Error('Збій мережі'))
      .mockResolvedValueOnce(response([entry(1), entry(2)], 2));
    const wrapper = mount(OrderStatusHistory, { props: { orderId: 5866 } });
    await button(wrapper, 'Історія статусів').trigger('click');
    await flushPromises();
    await button(wrapper, 'Показати ще').trigger('click');
    await flushPromises();

    expect(wrapper.find('[role="alert"]').text()).toContain('Не вдалося завантажити історію статусів.');
    expect(wrapper.findAll('tbody tr')).toHaveLength(1);
    await button(wrapper, 'Спробувати ще раз').trigger('click');
    await flushPromises();

    expect(http.get).toHaveBeenLastCalledWith('/orders/5866/status-history', { params: { page: 2 } });
    expect(wrapper.findAll('tbody tr')).toHaveLength(2);
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    expect(button(wrapper, 'Показати ще')).toBeUndefined();
    wrapper.unmount();
  });

  it('не домішує відповідь попереднього замовлення після зміни orderId', async () => {
    let resolvePrevious;
    http.get
      .mockImplementationOnce(() => new Promise((resolve) => { resolvePrevious = resolve; }))
      .mockResolvedValueOnce(response([entry(2, { actor_name: 'Поточний оператор' })]));
    const wrapper = mount(OrderStatusHistory, { props: { orderId: 5866 } });
    await button(wrapper, 'Історія статусів').trigger('click');
    await wrapper.setProps({ orderId: 5864 });
    expect(http.get).toHaveBeenCalledTimes(1);
    await button(wrapper, 'Історія статусів').trigger('click');
    await flushPromises();
    resolvePrevious(response([entry(1, { actor_name: 'Попередній оператор' })]));
    await flushPromises();

    expect(wrapper.text()).toContain('Поточний оператор');
    expect(wrapper.text()).not.toContain('Попередній оператор');
    expect(wrapper.findAll('tbody tr')).toHaveLength(1);
    wrapper.unmount();
  });

  it('показує лише дозволені деталі НП, зберігаючи текст без HTML-виконання', async () => {
    http.get.mockResolvedValue(response([entry(1, {
      actor_name: null,
      source_label: 'Синхронізація НП',
      reason: '<script>небезпечний текст</script>',
      metadata: {
        ttn: '20451529199555',
        np_response: { StatusCode: '3', Status: 'Номер не знайдено' },
        secret: 'не показувати довільні метадані',
      },
    })]));
    const wrapper = mount(OrderStatusHistory, { props: { orderId: 5866 } });
    await button(wrapper, 'Історія статусів').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Система');
    expect(wrapper.text()).toContain('НП: 3 — Номер не знайдено');
    expect(wrapper.text()).toContain('ТТН: 20451529199555');
    expect(wrapper.text()).not.toContain('не показувати довільні метадані');
    expect(wrapper.find('script').exists()).toBe(false);
    wrapper.unmount();
  });
});
