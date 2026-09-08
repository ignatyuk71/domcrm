import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import DeliveryStatusLabel from './DeliveryStatusLabel.vue';
import { formatDate, getDeliveryStatusStyle } from '@/crm/utils/orderDisplay';

const order = (overrides = {}) => ({
  id: 5980,
  ttn: '20451530751773',
  delivery_status: 'Прямує до міста отримувача, орієнтовна дата прибуття 10 вересня',
  delivery_status_description: 'Відправлення перебуває в дорозі до міста отримувача.',
  delivery_status_code: 'in_transit',
  delivery_status_color: '#0ea5e9',
  delivery_status_icon: 'bi-truck',
  delivery_carrier: 'Нова Пошта',
  city_name: 'Львів',
  address: 'Відділення № 1, вул. Городоцька, 355',
  delivery_status_updated_at: '2026-09-09T10:30:00Z',
  last_tracked_at: '2026-09-09T10:35:00Z',
  ...overrides,
});
let wrapper;
let host;

beforeEach(() => {
  vi.useFakeTimers();
  host = document.createElement('div');
  host.style.overflow = 'hidden';
  document.body.appendChild(host);
});

afterEach(() => {
  wrapper?.unmount();
  wrapper = null;
  host.remove();
  vi.restoreAllMocks();
  vi.useRealTimers();
});

function render(props = {}, attrs = {}) {
  wrapper = mount(DeliveryStatusLabel, {
    attachTo: host,
    props: { order: order(), ...props },
    attrs,
  });
  return wrapper.get('button');
}

const tooltip = () => document.body.querySelector('[role="tooltip"]');
const dispatch = async (element, type) => {
  element.dispatchEvent(new MouseEvent(type));
  await nextTick();
};

describe('простий напис статусу доставки з докладною інформацією', () => {
  it('залишає компактний кольоровий напис і показує повний текст поза контейнером таблиці', async () => {
    const button = render({ compact: true }, { class: 'mb-1' });
    expect(button.classes()).toContain('mb-1');
    expect(button.classes()).toContain('delivery-status-label--compact');
    expect(button.element.style.backgroundColor).toBe('');
    expect(button.element.style.borderColor).toBe('');
    expect(tooltip()).toBeNull();

    await button.trigger('mouseenter');
    await nextTick();
    expect(tooltip().parentElement).toBe(document.body);
    expect(host.contains(tooltip())).toBe(false);
    expect(button.attributes('aria-describedby')).toBe(tooltip().id);
    expect(tooltip().querySelector('.delivery-status-tooltip__title').textContent).toContain(order().delivery_status);
    expect(tooltip().textContent).toContain(order().delivery_status_description);
    expect(tooltip().textContent).toContain(order().ttn);
    expect(tooltip().textContent).toContain(order().address);
    expect(tooltip().textContent).toContain('Львів');
    expect(tooltip().textContent).toContain('Нова Пошта');
    expect(tooltip().textContent).toContain(formatDate(order().delivery_status_updated_at));
    expect(tooltip().textContent).toContain(formatDate(order().last_tracked_at));
  });

  it('дозволяє перейти курсором у вікно та закриває його після виходу', async () => {
    const button = render();
    await button.trigger('mouseenter');
    await nextTick();
    await button.trigger('mouseleave');
    await vi.advanceTimersByTimeAsync(100);
    await dispatch(tooltip(), 'mouseenter');
    await vi.advanceTimersByTimeAsync(500);
    expect(tooltip()).not.toBeNull();

    await dispatch(tooltip(), 'mouseleave');
    await vi.advanceTimersByTimeAsync(180);
    expect(tooltip()).toBeNull();
  });

  it('відкриває інформацію з клавіатури й закриває через Escape або втрату фокуса', async () => {
    const button = render();
    await button.trigger('focus');
    await nextTick();
    expect(tooltip()).not.toBeNull();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await nextTick();
    expect(tooltip()).toBeNull();
    expect(button.attributes('aria-describedby')).toBeUndefined();

    await button.trigger('blur');
    await button.trigger('focus');
    await nextTick();
    expect(tooltip()).not.toBeNull();
    await button.trigger('blur');
    await vi.advanceTimersByTimeAsync(180);
    expect(tooltip()).toBeNull();
  });

  it('розміщує вікно в межах екрана біля нижнього правого краю', async () => {
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function () {
      if (this.classList.contains('delivery-status-label')) {
        return {
          left: window.innerWidth - 30, right: window.innerWidth - 10,
          top: window.innerHeight - 20, bottom: window.innerHeight - 4, width: 20, height: 16,
        };
      }
      return { left: 0, right: 320, top: 0, bottom: 180, width: 320, height: 180 };
    });
    const button = render();
    await button.trigger('mouseenter');
    await nextTick();
    expect(tooltip().style.left).toBe(`${window.innerWidth - 328}px`);
    expect(tooltip().style.top).toBe(`${window.innerHeight - 208}px`);
    expect(tooltip().style.visibility).toBe('visible');
  });

  it('оновлює відкриту інформацію, колір та іконку після нового результату відстеження', async () => {
    const button = render({
      order: order({
        delivery_status: 'Прибув у відділення',
        delivery_status_code: 'at_warehouse',
        delivery_status_entered_at: '2026-09-07T08:00:00Z',
        delivery_hold_days: 2,
      }),
    });
    await button.trigger('mouseenter');
    await nextTick();
    expect(tooltip().textContent).toContain('У відділенні з');
    expect(tooltip().textContent).toContain('2 дн.');

    const received = order({
      delivery_status: 'Отримано',
      delivery_status_description: 'Отримано',
      delivery_status_code: 'received',
      delivery_status_color: '#16a34a',
      delivery_status_icon: 'bi-check-circle-fill',
      delivery_status_updated_at: '2026-09-09T12:00:00Z',
      delivery_status_entered_at: '2026-09-07T08:00:00Z',
      delivery_hold_days: 2,
    });
    await wrapper.setProps({ order: received });
    const expectedColor = document.createElement('span');
    expectedColor.style.color = getDeliveryStatusStyle(received).color;
    expect(button.element.style.color).toBe(expectedColor.style.color);
    expect(button.find('.bi-check-circle-fill').exists()).toBe(true);
    expect(tooltip().textContent).toContain('Отримано');
    expect(tooltip().textContent).toContain(formatDate(received.delivery_status_updated_at));
    expect(tooltip().textContent).not.toContain('У відділенні з');
    expect(tooltip().textContent).not.toContain('Зберігання');
    expect(tooltip().querySelector('.delivery-status-tooltip__description')).toBeNull();
  });

  it('показує адресу кур’єра й не вигадує відсутні дані чи дату прибуття', async () => {
    const button = render({
      order: {
        delivery_status: 'Прибув у відділення',
        delivery_status_code: 'at_warehouse',
        delivery_hold_days: 4,
        delivery_type: 'courier',
        address: 'вул. Шевченка',
        building: '10',
        apartment: '5',
      },
    });
    await button.trigger('mouseenter');
    await nextTick();
    expect(tooltip().textContent).toContain('вул. Шевченка, буд. 10, кв. 5');
    expect(tooltip().textContent).not.toContain('ТТН');
    expect(tooltip().textContent).not.toContain('Перевірено');
    expect(tooltip().textContent).not.toContain('Зберігання');
    expect(tooltip().textContent).not.toContain('У відділенні з');
  });

  it('звільняє слухачі подій і таймер та прибирає вікно при демонтажі рядка', async () => {
    const removeWindowListener = vi.spyOn(window, 'removeEventListener');
    const removeDocumentListener = vi.spyOn(document, 'removeEventListener');
    const button = render();
    await button.trigger('mouseenter');
    await nextTick();
    await button.trigger('mouseleave');
    expect(vi.getTimerCount()).toBeGreaterThan(0);
    wrapper.unmount();
    wrapper = null;
    expect(tooltip()).toBeNull();
    expect(vi.getTimerCount()).toBe(0);
    expect(removeWindowListener).toHaveBeenCalledWith('resize', expect.any(Function));
    expect(removeWindowListener).toHaveBeenCalledWith('scroll', expect.any(Function), true);
    expect(removeDocumentListener).toHaveBeenCalledWith('keydown', expect.any(Function));
    expect(removeDocumentListener).toHaveBeenCalledWith('pointerdown', expect.any(Function));
  });
});
