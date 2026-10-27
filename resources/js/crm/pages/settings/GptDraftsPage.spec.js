import { mount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import GptDraftsPage from './GptDraftsPage.vue';
import { gptDraftsApi as api } from '../../services/gptDraftsApi';

vi.mock('../../services/gptDraftsApi', () => ({ gptDraftsApi: {
    data: vi.fn(), saveSettings: vi.fn(), create: vi.fn(), run: vi.fn(), dialogue: vi.fn(),
} }));

const settings = { enabled: true, model: 'gpt-test', has_api_key: true, store_policies: '', version: 1 };
const pending = { id: 1, gpt_dialogue_id: 2, input_message: 'Покажіть чорні', status: 'pending' };
const completed = { ...pending, status: 'completed', result: {
    message: 'Ось варіант.\nЦіна: 1290.00 UAH', mode: 'drafts', photos: [], action: 'reply',
} };
let wrapper;

async function open() {
    wrapper = mount(GptDraftsPage, { attachTo: document.body });
    await flushPromises();
    return wrapper;
}

describe('Окрема сторінка чернеток GPT', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
        api.data.mockResolvedValue({ settings: { ...settings }, groups: [{ id: 7, name: 'М’які тапочки' }], dialogues: [] });
    });
    afterEach(() => {
        wrapper?.unmount();
        document.body.innerHTML = '';
        vi.useRealTimers();
    });

    it('завантаження не показує повідомлення про збереження', async () => {
        await open();
        expect(wrapper.text()).toContain('Без підключення до робочих чатів');
        expect(document.querySelector('.app-toast')).toBeNull();
        expect(wrapper.find('#gpt-key').element.value).toBe('');
    });

    it('помилка не стирає повідомлення та повтор використовує той самий ключ запиту', async () => {
        api.create.mockRejectedValue({ response: { data: { message: 'Тимчасова помилка', errors: { message: ['Перевірте запит'] } } } });
        await open();
        await wrapper.get('#gpt-message').setValue('Покажіть чорні');
        await wrapper.get('#gpt-group').setValue('7');
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        expect(wrapper.get('#gpt-message').element.value).toBe('Покажіть чорні');
        expect(wrapper.get('#gpt-message').classes()).toContain('is-invalid');
        expect(wrapper.get('#gpt-group').element.value).toBe('7');
        const payload = api.create.mock.calls[0][0];
        document.querySelector('.app-toast-actions button').click();
        await flushPromises();
        expect(api.create.mock.calls[1][0]).toEqual(payload);
        expect(document.querySelector('.app-toast').getAttribute('role')).toBe('alert');
    });

    it('показує лише готову чернетку без дії відправлення клієнту', async () => {
        api.create.mockResolvedValue({ run: completed });
        await open();
        await wrapper.get('#gpt-message').setValue('Покажіть чорні');
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        expect(wrapper.text()).toContain('1290.00 UAH');
        expect(wrapper.text()).toContain('Копіювати чернетку');
        expect(wrapper.text()).not.toContain('Надіслати клієнту');
        expect(wrapper.get('#gpt-message').element.value).toBe('');
        expect(api.create.mock.calls[0][0]).toMatchObject({ message: 'Покажіть чорні', dialogue_id: null });
    });

    it('зберігає новий текст під час очікування та припиняє опитування після результату', async () => {
        api.create.mockResolvedValue({ run: pending });
        api.run.mockResolvedValue({ run: completed });
        await open();
        await wrapper.get('#gpt-message').setValue('Покажіть чорні');
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        await wrapper.get('#gpt-message').setValue('А які ще є кольори?');
        await vi.advanceTimersByTimeAsync(1500);
        await flushPromises();
        expect(wrapper.get('#gpt-message').element.value).toBe('А які ще є кольори?');
        await vi.advanceTimersByTimeAsync(5000);
        expect(api.run).toHaveBeenCalledTimes(1);
    });

    it('помилка генерації залишає введений текст та доступне пояснення', async () => {
        api.create.mockResolvedValue({ run: { ...pending, status: 'failed', error_message: 'Перевірте API-ключ.' } });
        await open();
        await wrapper.get('#gpt-message').setValue('Покажіть чорні');
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        expect(wrapper.get('#gpt-message').element.value).toBe('Покажіть чорні');
        expect(document.querySelector('.app-toast').textContent).toContain('Перевірте API-ключ.');
        expect(wrapper.text()).toContain('Переглянути помилку / повторити');
        await vi.advanceTimersByTimeAsync(10000);
        expect(document.querySelector('.app-toast')).not.toBeNull();
    });

    it('успішна генерація не перекриває невиправлену помилку налаштувань', async () => {
        api.saveSettings.mockRejectedValue({ response: { data: { message: 'Помилка збереження', errors: { api_key: ['Перевірте ключ'] } } } });
        api.create.mockResolvedValue({ run: completed });
        await open();
        await wrapper.get('#gpt-key').setValue('sk-draft-test');
        await wrapper.findAll('form')[0].trigger('submit');
        await flushPromises();
        expect(wrapper.get('#gpt-key').element.value).toBe('sk-draft-test');
        await wrapper.get('#gpt-message').setValue('Покажіть чорні');
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        expect(document.querySelector('.app-toast').textContent).toContain('Помилка збереження');
        expect(document.querySelector('.app-toast').classList.contains('app-toast--error')).toBe(true);
    });

    it('розмонтування зупиняє опитування', async () => {
        api.create.mockResolvedValue({ run: pending });
        await open();
        await wrapper.get('#gpt-message').setValue('Покажіть чорні');
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        wrapper.unmount(); wrapper = null;
        await vi.advanceTimersByTimeAsync(5000);
        expect(api.run).not.toHaveBeenCalled();
    });
});
