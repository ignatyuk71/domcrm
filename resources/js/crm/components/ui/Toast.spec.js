import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Toast from './Toast.vue';
import { useToast } from '../../composables/useToast';

let wrapper;
afterEach(() => { wrapper?.unmount(); document.body.innerHTML = ''; vi.useRealTimers(); });

describe('Спільний toast', () => {
    it('зберігає попередній контракт помилок форми та безпечно виводить текст', () => {
        wrapper = mount(Toast, { props: { show: true, messages: ['<script>bad()</script>'] } });
        const notice = document.querySelector('.app-toast');
        expect(notice.getAttribute('role')).toBe('alert');
        expect(notice.textContent).toContain('Перевірте форму');
        expect(notice.textContent).toContain('<script>bad()</script>');
        expect(notice.querySelector('script')).toBeNull();
    });
    it('підтримує успіх, закриття та явну дію', () => {
        wrapper = mount(Toast, { props: { show: true, type: 'success', title: 'Готово', messages: ['Зміни збережено'], actionLabel: 'Повторити' } });
        expect(document.querySelector('.app-toast').getAttribute('role')).toBe('status');
        document.querySelector('.app-toast-actions button').click();
        expect(wrapper.emitted('action')).toHaveLength(1);
        document.querySelector('.app-toast-close').click();
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
    it('старий таймер успіху не закриває нову помилку; демонтаж очищає таймери', async () => {
        vi.useFakeTimers(); let controller;
        wrapper = mount({ setup() { controller = useToast(); return () => null; } });
        controller.showToast({ type: 'success', title: 'Готово', messages: ['Збережено'] });
        await vi.advanceTimersByTimeAsync(1000);
        controller.showToast({ type: 'error', title: 'Помилка', messages: ['З’єднання втрачено'] });
        await vi.advanceTimersByTimeAsync(10000); await flushPromises();
        expect(controller.toast.show).toBe(true); expect(controller.toast.type).toBe('error');
        controller.showToast({ type: 'success', title: 'Готово', messages: ['Збережено'] });
        wrapper.unmount(); expect(vi.getTimerCount()).toBe(0);
    });
});
