import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import OrderStatusPicker from './OrderStatusPicker.vue';
import Toast from '@/crm/components/ui/Toast.vue';
import { useOrderStatuses } from '@/crm/composables/useOrderStatuses';
import { fetchStatuses } from '@/crm/api/statuses';
import { updateOrderStatus } from '@/crm/api/orders';

vi.mock('@/crm/api/statuses', () => ({ fetchStatuses: vi.fn() }));
vi.mock('@/crm/api/orders', () => ({ updateOrderStatus: vi.fn() }));

const statuses = [
  { id: 1, code: 'new', name: 'Новий', color: '#b48a18', icon: 'bi-circle' },
  { id: 2, code: 'in_process', name: 'В обробці' },
  { id: 3, code: 'confirmed', name: 'Підтверджено' },
  { id: 4, code: 'reserved', name: 'Бронювання' },
  { id: 5, code: 'packing', name: 'Упакування' },
  { id: 6, code: 'packed', name: 'Запаковано' },
  { id: 7, code: 'shipped', name: 'Відправлено' },
  { id: 8, code: 'delivered', name: 'У відділенні' },
  { id: 9, code: 'delivered_paid', name: 'Завершено' },
  { id: 10, code: 'returned', name: 'Повернення' },
  { id: 11, code: 'cancelled', name: 'Скасовано' },
];
const makeOrder = () => reactive({ id: 42, order_number: '7514', status_id: 1, status_key: 'new', status: 'Новий' });
let wrapper;
function open(value = makeOrder(), editor = useOrderStatuses(() => value)) {
  editor.statuses = statuses;
  wrapper = mount(OrderStatusPicker, { props: { order: value, editor }, global: { stubs: { teleport: true, transition: false } } });
  return { value, editor };
}
const buttonFor = (name) => wrapper.get(`.status-tab[aria-label="${name}"]`);

beforeEach(() => vi.clearAllMocks());
afterEach(() => wrapper?.unmount());

describe('вкладки статусів замовлення', () => {
  it('показує всі статуси без прихованого меню, скорочуючи лише видимі підписи', async () => {
    open();
    expect(wrapper.get('[role="group"]').attributes('aria-label')).toBe('Статус замовлення');
    expect(wrapper.findAll('.status-tab').map((button) => button.text())).toEqual([
      'Новий', 'В обробці', 'Підтв.', 'Бронювання', 'Пакування', 'Запак.',
      'Відправ.', 'Відділення', 'Заверш.', 'Поверн.', 'Скасовано',
    ]);
    for (const status of statuses) {
      expect(buttonFor(status.name).attributes('title')).toBe(status.name);
    }
    expect(buttonFor('Новий').attributes('aria-pressed')).toBe('true');
    await buttonFor('Новий').trigger('click');
    expect(updateOrderStatus).not.toHaveBeenCalled();
    expect(wrapper.findComponent(Toast).props('show')).toBe(false);
  });

  it('передає вибір серверу, блокує вкладки під час запиту й підтверджує новий статус', async () => {
    let finish;
    updateOrderStatus.mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
    const { value } = open();
    await buttonFor('Відправлено').trigger('click');
    expect(updateOrderStatus).toHaveBeenCalledExactlyOnceWith(42, 7);
    expect(value.status_key).toBe('new');
    expect(wrapper.findAll('.status-tab').every((button) => button.attributes('disabled') !== undefined)).toBe(true);
    expect(wrapper.get('.status-picker').attributes('aria-busy')).toBe('true');
    expect(buttonFor('Відправлено').find('.spinner-border').exists()).toBe(true);
    expect(buttonFor('Новий').find('.spinner-border').exists()).toBe(false);
    await buttonFor('Завершено').trigger('click');
    expect(updateOrderStatus).toHaveBeenCalledTimes(1);

    finish({ data: { data: statuses[6] } });
    await flushPromises();
    expect(value).toMatchObject({ status_id: 7, status_key: 'shipped', status: 'Відправлено' });
    expect(buttonFor('Відправлено').attributes('aria-pressed')).toBe('true');
    expect(wrapper.find('.spinner-border').exists()).toBe(false);
    expect(wrapper.findAll('.status-tab').every((button) => button.attributes('disabled') === undefined)).toBe(true);
    expect(wrapper.get('.status-picker').attributes('aria-busy')).toBe('false');
  });

  it('зберігає видимий поточний статус, відсутній у довіднику', async () => {
    const value = makeOrder();
    Object.assign(value, { status_id: 99, status_key: 'custom', status: 'Особливий статус', status_icon: 'bi-star', status_color: '#654321' });
    open(value);
    expect(wrapper.findAll('.status-tab')).toHaveLength(statuses.length + 1);
    const current = buttonFor('Особливий статус');
    expect(current.attributes('aria-pressed')).toBe('true');
    expect(current.find('.bi-star').exists()).toBe(true);
    await current.trigger('click');
    expect(updateOrderStatus).not.toHaveBeenCalled();
    updateOrderStatus.mockResolvedValueOnce({ data: { data: statuses[6] } });
    await buttonFor('Відправлено').trigger('click');
    expect(updateOrderStatus).toHaveBeenCalledExactlyOnceWith(42, 7);
    await flushPromises();
  });

  it('показує помилку завантаження в toast і дозволяє повторити завантаження', async () => {
    const value = makeOrder();
    const editor = useOrderStatuses(() => value);
    fetchStatuses.mockRejectedValueOnce(new Error('Мережа'));
    await editor.loadStatuses();
    open(value, editor);
    const toast = wrapper.findComponent(Toast);
    expect(toast.props()).toMatchObject({ show: true, type: 'error', title: 'Статуси не завантажено', actionLabel: 'Повторити' });
    expect(toast.props('messages')).toContain('Не вдалося завантажити статуси.');
    expect(wrapper.get('[role="group"]').attributes('aria-invalid')).toBe('true');
    expect(wrapper.findAll('.status-tab').every((button) => button.attributes('disabled') !== undefined)).toBe(true);

    fetchStatuses.mockResolvedValueOnce({ data: { data: statuses } });
    toast.vm.$emit('action');
    await flushPromises();
    expect(fetchStatuses).toHaveBeenCalledTimes(2);
    expect(editor.loadError).toBe('');
    expect(wrapper.get('[role="group"]').attributes('aria-invalid')).toBeUndefined();
    expect(wrapper.findAll('.status-tab').every((button) => button.attributes('disabled') === undefined)).toBe(true);
  });

  it('після помилки залишає попередній статус і повторює саме невдалий вибір через toast', async () => {
    const { value } = open();
    updateOrderStatus.mockRejectedValueOnce(new Error('Відмовлено'));
    await buttonFor('Відправлено').trigger('click');
    await flushPromises();
    expect(value.status_key).toBe('new');
    expect(buttonFor('Новий').attributes('aria-pressed')).toBe('true');
    expect(buttonFor('Відправлено').classes()).toContain('has-error');
    const toast = wrapper.findComponent(Toast);
    expect(toast.props()).toMatchObject({ show: true, type: 'error', title: 'Статус замовлення не збережено', actionLabel: 'Повторити' });
    expect(toast.props('messages')).toContain('Замовлення №7514.');

    updateOrderStatus.mockResolvedValueOnce({ data: { data: statuses[6] } });
    toast.vm.$emit('action');
    await flushPromises();
    expect(updateOrderStatus).toHaveBeenCalledTimes(2);
    expect(updateOrderStatus).toHaveBeenLastCalledWith(42, 7);
    expect(value.status_key).toBe('shipped');
    expect(wrapper.get('[role="group"]').attributes('aria-invalid')).toBeUndefined();
    expect(buttonFor('Відправлено').classes()).not.toContain('has-error');
  });
});
