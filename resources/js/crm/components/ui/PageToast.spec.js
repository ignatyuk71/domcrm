import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import PageToast from './PageToast.vue';

it('keeps errors from other operations visible until each is resolved', async () => {
    const wrapper = mount(PageToast);
    wrapper.vm.showNotification({ context: 'save', type: 'error', title: 'Збереження', messages: ['Невірний ключ'] });
    wrapper.vm.showNotification({ context: 'preview', type: 'error', title: 'Чернетка', messages: ['Немає відповіді'] });
    wrapper.vm.showNotification({ context: 'test', type: 'success', title: 'Ключ дійсний', messages: ['Готово'] });
    await nextTick();
    expect(document.body.textContent).toContain('Невірний ключ');
    expect(document.body.textContent).toContain('Немає відповіді');
    expect(document.body.textContent).not.toContain('Ключ дійсний');
    wrapper.vm.showNotification({ context: 'save', type: 'success', title: 'Збережено', messages: ['Готово'] });
    await nextTick();
    expect(document.body.textContent).not.toContain('Невірний ключ');
    expect(document.body.textContent).toContain('Немає відповіді');
    wrapper.unmount();
});
