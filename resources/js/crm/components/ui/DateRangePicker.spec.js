import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DOMWrapper, flushPromises, mount } from '@vue/test-utils';
import DateRangePicker from './DateRangePicker.vue';

let wrapper;
const popup = () => new DOMWrapper(document.querySelector('.period-popover'));
const day = (iso) => popup().find(`[data-date="${iso}"]`);
async function openPicker(props = {}) {
  wrapper = mount(DateRangePicker, { props: { from: '2026-09-01', to: '2026-09-15', ...props }, attachTo: document.body });
  await wrapper.find('.period-trigger').trigger('click');
  await flushPromises();
}
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-09-15T12:00:00Z'));
});
afterEach(() => {
  wrapper?.unmount();
  document.body.innerHTML = '';
  document.body.style.overflow = '';
  vi.useRealTimers();
});

describe('Вибір періоду в календарі', () => {
  it('показує український діапазон, кількість днів і календар із понеділка', async () => {
    await openPicker();
    expect(wrapper.find('.period-trigger').text()).toContain('вересня');
    expect(wrapper.find('.period-duration').text()).toBe('15 днів');
    expect(wrapper.find('.period-trigger').attributes('aria-expanded')).toBe('true');
    expect(popup().attributes('role')).toBe('dialog');
    expect(popup().findAll('[role="columnheader"]').map(item => item.text())).toEqual(['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд']);
    expect(popup().findAll('.calendar-day')).toHaveLength(42);
    expect(popup().findAll('.calendar-cell.in-range')).toHaveLength(15);
    expect(day('2026-09-15').attributes('aria-current')).toBe('date');
    expect(document.activeElement.getAttribute('data-date')).toBe('2026-09-01');
    expect(popup().findAll('.calendar-day[tabindex="0"]')).toHaveLength(1);
  });

  it('не змінює період до підтвердження та підтримує зворотний вибір дат', async () => {
    await openPicker();
    await day('2026-09-12').trigger('click');
    expect(popup().find('.period-confirm').attributes('disabled')).toBeDefined();
    expect(popup().text()).toContain('Тепер оберіть кінцеву дату');
    expect(wrapper.emitted('apply')).toBeUndefined();
    await day('2026-09-07').trigger('mouseenter');
    expect(popup().findAll('.calendar-cell.in-range')).toHaveLength(6);
    await day('2026-09-07').trigger('click');
    expect(popup().find('[name="date_from"]').element.value).toBe('2026-09-07');
    expect(popup().find('[name="date_to"]').element.value).toBe('2026-09-12');
    expect(wrapper.emitted('apply')).toBeUndefined();
    await popup().find('.period-confirm').trigger('click');
    await flushPromises();
    expect(wrapper.emitted('apply')).toEqual([[{ from: '2026-09-07', to: '2026-09-12' }]]);
    expect(document.querySelector('.period-popover')).toBeNull();
    expect(document.activeElement).toBe(wrapper.find('.period-trigger').element);
  });

  it('підтверджує один день і швидкий варіант лише один раз', async () => {
    await openPicker();
    await day('2026-09-08').trigger('click');
    await day('2026-09-08').trigger('click');
    expect(popup().find('.period-footer strong').text()).toBe('1 день');
    await popup().find('.period-confirm').trigger('click');
    expect(wrapper.emitted('apply')[0]).toEqual([{ from: '2026-09-08', to: '2026-09-08' }]);
    await wrapper.find('.period-trigger').trigger('click');
    await popup().findAll('.preset-btn')[3].trigger('click');
    expect(popup().find('[name="date_from"]').element.value).toBe('2026-08-01');
    expect(popup().find('[name="date_to"]').element.value).toBe('2026-08-31');
    expect(popup().findAll('.preset-btn')[3].attributes('aria-pressed')).toBe('true');
    expect(wrapper.emitted('apply')).toHaveLength(1);
    await popup().find('.period-confirm').trigger('click');
    expect(wrapper.emitted('apply')[1]).toEqual([{ from: '2026-08-01', to: '2026-08-31' }]);
  });

  it('перемикає місяць і рік без втрати вибраного початку', async () => {
    await openPicker({ from: '2024-02-01', to: '2024-02-29' });
    await day('2024-02-29').trigger('click');
    await popup().find('.next-month').trigger('click');
    await day('2024-03-03').trigger('click');
    expect(popup().find('.period-footer strong').text()).toBe('4 дні');
    await popup().find('.period-confirm').trigger('click');
    expect(wrapper.emitted('apply')[0]).toEqual([{ from: '2024-02-29', to: '2024-03-03' }]);
    await wrapper.find('.period-trigger').trigger('click');
    await popup().find('select[aria-label="Рік"]').setValue('2025');
    expect(popup().find('[data-date="2025-02-29"]').exists()).toBe(false);
    await popup().find('select[aria-label="Місяць"]').setValue('11');
    await popup().find('.next-month').trigger('click');
    expect(popup().find('select[aria-label="Рік"]').element.value).toBe('2026');
    expect(popup().find('select[aria-label="Місяць"]').element.value).toBe('0');
  });

  it('перевіряє ручні дати, порядок і обмеження довжини періоду', async () => {
    await openPicker();
    await popup().find('[name="date_from"]').setValue('2026-09-20');
    expect(popup().text()).toContain('Кінцева дата не може бути раніше');
    expect(popup().find('.period-confirm').attributes('disabled')).toBeDefined();
    await popup().find('[name="date_to"]').setValue('');
    expect(popup().text()).toContain('Вкажіть обидві коректні дати');
    await popup().find('[name="date_from"]').setValue('2024-01-01');
    await popup().find('[name="date_to"]').setValue('2026-01-01');
    expect(popup().text()).toContain('Максимальний період — 731 день');
    await popup().find('[name="date_to"]').trigger('keydown', { key: 'Enter' });
    expect(wrapper.emitted('apply')).toBeUndefined();
    await popup().find('[name="date_to"]').setValue('2025-12-31');
    expect(popup().find('.period-confirm').attributes('disabled')).toBeUndefined();
    await popup().find('[name="date_to"]').trigger('keydown', { key: 'Enter' });
    expect(wrapper.emitted('apply')).toEqual([[{ from: '2024-01-01', to: '2025-12-31' }]]);
  });

  it('скасовує чернетку через Escape або клік поза календарем і відновлює прокручування', async () => {
    document.body.style.overflow = 'auto';
    await openPicker();
    expect(document.body.style.overflow).toBe('hidden');
    await day('2026-09-08').trigger('click');
    await popup().trigger('keydown', { key: 'Escape' });
    expect(document.body.style.overflow).toBe('auto');
    expect(wrapper.emitted('apply')).toBeUndefined();
    await wrapper.find('.period-trigger').trigger('click');
    expect(popup().find('[name="date_from"]').element.value).toBe('2026-09-01');
    expect(popup().find('[name="date_to"]').element.value).toBe('2026-09-15');
    await new DOMWrapper(document.querySelector('.period-backdrop')).trigger('click');
    expect(document.querySelector('.period-popover')).toBeNull();
    expect(wrapper.emitted('apply')).toBeUndefined();
  });

  it('підтримує стрілки, Home/End, PageUp/Down і утримує фокус у діалозі', async () => {
    await openPicker({ from: '2026-01-31', to: '2026-02-02' });
    await day('2026-01-31').trigger('keydown', { key: 'PageDown' });
    await flushPromises();
    expect(document.activeElement.getAttribute('data-date')).toBe('2026-02-28');
    await day('2026-02-28').trigger('keydown', { key: 'ArrowRight' });
    await flushPromises();
    expect(document.activeElement.getAttribute('data-date')).toBe('2026-03-01');
    await day('2026-03-01').trigger('keydown', { key: 'Home' });
    await flushPromises();
    expect(document.activeElement.getAttribute('data-date')).toBe('2026-02-23');
    await day('2026-02-23').trigger('keydown', { key: 'End' });
    await flushPromises();
    expect(document.activeElement.getAttribute('data-date')).toBe('2026-03-01');
    const close = popup().find('[aria-label="Закрити календар"]');
    close.element.focus();
    await close.trigger('keydown', { key: 'Tab', shiftKey: true });
    expect(document.activeElement).toBe(popup().find('.period-confirm').element);
    await popup().find('.period-confirm').trigger('keydown', { key: 'Tab' });
    expect(document.activeElement).toBe(close.element);
  });

  it('відновлює стан сторінки при демонтажі відкритого календаря', async () => {
    await openPicker();
    wrapper.unmount();
    wrapper = null;
    expect(document.body.style.overflow).toBe('');
    expect(document.querySelector('.period-popover')).toBeNull();
  });
});
