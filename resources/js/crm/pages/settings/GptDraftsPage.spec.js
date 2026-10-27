import { mount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import GptDraftsPage from './GptDraftsPage.vue';
import { gptDraftsApi as api } from '../../services/gptDraftsApi';

vi.mock('../../services/gptDraftsApi', () => ({ gptDraftsApi: {
    data: vi.fn(), saveSettings: vi.fn(), create: vi.fn(), run: vi.fn(), dialogue: vi.fn(), upload: vi.fn(),
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
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        expect(wrapper.get('#gpt-message').element.value).toBe('Покажіть чорні');
        expect(wrapper.get('#gpt-message').classes()).toContain('is-invalid');
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
        expect(wrapper.text()).toContain('Копіювати відповідь');
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

    it('показує розмову бульбашками та поле введення після історії', async () => {
        api.create.mockResolvedValue({ run: completed });
        await open();
        await wrapper.get('#gpt-message').setValue('Покажіть чорні');
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        expect(wrapper.get('[role="log"]').text()).toContain('Покажіть чорні');
        expect(wrapper.get('.gpt-chat-bubble--user').text()).toContain('Покажіть чорні');
        expect(wrapper.get('.gpt-chat-bubble--assistant').text()).toContain('1290.00 UAH');
        expect(wrapper.get('[role="log"]').element.nextElementSibling).toBe(wrapper.get('.gpt-chat-composer').element);
        expect(wrapper.get('.gpt-chat-composer').find('#gpt-message').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Тестове повідомлення клієнта');
    });

    it('Enter надсилає повідомлення, Shift+Enter залишає новий рядок', async () => {
        api.create.mockResolvedValue({ run: completed });
        await open();
        await wrapper.get('#gpt-message').setValue('Покажіть чорні');
        await wrapper.get('#gpt-message').trigger('keydown', { key: 'Enter', shiftKey: true });
        expect(api.create).not.toHaveBeenCalled();
        await wrapper.get('#gpt-message').trigger('keydown', { key: 'Enter' });
        await flushPromises();
        expect(api.create).toHaveBeenCalledTimes(1);
    });

    it('завантажує вибране фото та показує посилання на нього в чаті', async () => {
        const file = new File(['photo'], 'photo.jpg', { type: 'image/jpeg' });
        api.upload.mockResolvedValue({ files: [{ id: 'photo', name: 'photo.jpg', url: 'https://example.com/photo.jpg' }] });
        api.create.mockImplementation(payload => Promise.resolve({ run: { ...completed, input_message: payload.message } }));
        await open();
        Object.defineProperty(wrapper.get('#gpt-files').element, 'files', { value: [file], configurable: true });
        await wrapper.get('#gpt-files').trigger('change');
        await flushPromises();
        expect(api.upload).toHaveBeenCalledWith([file], expect.any(Function));
        expect(wrapper.get('#gpt-message').element.value).toBe('');
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        expect(api.create.mock.calls[0][0].message).toBe('Фото: https://example.com/photo.jpg');
        expect(wrapper.get('.gpt-chat-bubble--user a').attributes('href')).toBe('https://example.com/photo.jpg');
    });

    it('показує фотографії та колажі відповіді з посиланнями для відкриття', async () => {
        api.create.mockResolvedValue({ run: { ...completed, result: { ...completed.result, photos: [
            { id: 1, url: '/ai-gallery/collage.jpg', kind: 'collage' },
            { id: 2, url: '/ai-gallery/black.jpg', kind: 'color' },
        ] } } });
        await open();
        await wrapper.get('#gpt-message').setValue('Покажіть фото');
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        expect(wrapper.get('.gpt-chat-photos a').attributes('target')).toBe('_blank');
        expect(wrapper.findAll('.gpt-chat-photos a').map(link => link.attributes('href'))).toEqual(['/ai-gallery/collage.jpg', '/ai-gallery/black.jpg']);
        expect(wrapper.find('#gpt-group').exists()).toBe(false);
        expect(wrapper.find('#gpt-image-url').exists()).toBe(false);
        expect(wrapper.findAll('.gpt-chat-photos a')).toHaveLength(2);
    });
});
