import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Page from './SandboxPage.vue';
import api from './api';

vi.mock('./api', () => ({ default: {
    state: vi.fn(), save: vi.fn(), connection: vi.fn(), newChat: vi.fn(), send: vi.fn(), retry: vi.fn(),
} }));

let wrapper;
const saved = () => ({ has_key: true, key_hint: '••••1234', model: 'gpt-test' });
const turn = (overrides = {}) => ({ id: 'turn-1', request_id: 'request-1', text: 'Привіт', reply: 'Вітаю!', status: 'completed', error: null, model: 'gpt-test', images: [], ...overrides });
async function open(state = { settings: saved(), chat: { id: 'chat-1', turns: [] } }) {
    api.state.mockResolvedValue({ data: state });
    wrapper = mount(Page, { attachTo: document.body });
    await flushPromises();
}
beforeEach(() => {
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:test-photo'), revokeObjectURL: vi.fn() }));
});
afterEach(() => {
    wrapper?.unmount();
    document.body.innerHTML = '';
    vi.resetAllMocks();
    vi.unstubAllGlobals();
});

describe('окремий тестовий чат GPT', () => {
    it('зберігає вибраний магазин разом із налаштуваннями моделі', async () => {
        await open({ settings: { ...saved(), meta_connection_id: 2 }, stores: [{ id: 1, name: 'Перший' }, { id: 2, name: 'Другий' }], chat: { id: 'chat-1', turns: [] } });
        expect(wrapper.find('#gpt-store').element.value).toBe('2');
        await wrapper.find('#gpt-store').setValue('1');
        api.save.mockResolvedValue({ data: { settings: { ...saved(), meta_connection_id: 1 } } });
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(api.save).toHaveBeenCalledWith({ api_key: null, model: 'gpt-test', meta_connection_id: 1 });
    });

    it('відновлює оформлення магазину й зупиняє агента після передачі заявки', async () => {
        await open({ settings: saved(), chat: { id: 'chat-1', turns: [], checkout: {
            status: 'ready_for_manager', items: [{ title: 'Котики', color: 'Сірий', size: '42/43', qty: 1, price: '380.50', line_total: '380.50' }],
            total: '380.50', payment: 'deposit', pay_now: '100.00', pay_on_delivery: '280.50',
        } } });
        expect(wrapper.find('[data-test="checkout-card"]').text()).toContain('Готова до опрацювання');
        expect(wrapper.find('[data-test="checkout-card"]').text()).toContain('Разом: 380.50 грн');
        expect(wrapper.find('#gpt-message').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-test="pay-now"]').text()).toBe('100.00 грн');
        expect(wrapper.find('[data-test="pay-on-delivery"]').text()).toBe('280.50 грн');
    });

    it('оновлює заявку після відповіді й відображає реквізити окремими повідомленнями без HTML', async () => {
        await open();
        api.send.mockResolvedValue({ data: { turn: turn({ checkout_messages: ['Сума: 380 грн', '<script>не виконувати</script>', 'UA-test'] }),
            checkout: { status: 'draft', items: [], payment: 'передоплата' } } });
        await wrapper.find('#gpt-message').setValue('Реквізити для передоплати');
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        expect(wrapper.find('[data-test="checkout-card"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-test="checkout-message"]')).toHaveLength(3);
        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.text()).toContain('<script>не виконувати</script>');
    });

    it('відкриває сторінку без запитів до OpenAI та повідомлення про збереження', async () => {
        await open();
        expect(wrapper.find('#gpt-api-key').element.value).toBe('');
        expect(wrapper.find('#gpt-model').element.value).toBe('gpt-test');
        expect(document.querySelector('.app-toast')).toBeNull();
        expect(api.connection).not.toHaveBeenCalled();
        expect(api.save).not.toHaveBeenCalled();
        expect(api.send).not.toHaveBeenCalled();
    });

    it('зберігає ключ лише за дією користувача та блокує чат до збереження змін', async () => {
        await open();
        await wrapper.find('#gpt-api-key').setValue('sk-new-key');
        await wrapper.find('#gpt-message').setValue('Привіт');
        expect(wrapper.find('[data-test="send"]').attributes('disabled')).toBeDefined();
        api.save.mockResolvedValue({ data: { settings: saved() } });
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(api.save).toHaveBeenCalledWith({ api_key: 'sk-new-key', model: 'gpt-test', meta_connection_id: null });
        expect(wrapper.find('#gpt-api-key').element.value).toBe('');
        expect(wrapper.find('[data-test="send"]').attributes('disabled')).toBeUndefined();
    });

    it('не стирає ключ і не перекриває помилку успіхом іншої дії', async () => {
        await open();
        await wrapper.find('#gpt-api-key').setValue('sk-draft');
        api.save.mockRejectedValue({ response: { data: { errors: { api_key: ['Ключ відхилено.'] } } } });
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(wrapper.find('#gpt-api-key').element.value).toBe('sk-draft');
        expect(wrapper.find('#gpt-api-key').attributes('aria-invalid')).toBe('true');
        api.newChat.mockResolvedValue({ data: { chat: { id: 'chat-2', turns: [] } } });
        await wrapper.find('[data-test="new-chat"]').trigger('click');
        await flushPromises();
        expect(document.querySelector('[role="alert"]').textContent).toContain('Ключ відхилено.');
    });

    it('оновлює список моделей без збереження ключа', async () => {
        await open();
        api.connection.mockResolvedValue({ data: { models: ['gpt-first', 'gpt-second'] } });
        await wrapper.find('[data-test="connection"]').trigger('click');
        await flushPromises();
        expect(wrapper.findAll('datalist option').map(option => option.attributes('value'))).toEqual(['gpt-first', 'gpt-second']);
        expect(api.save).not.toHaveBeenCalled();
    });

    it('надсилає текст і файл разом та залишає їх після збою API', async () => {
        await open();
        await wrapper.find('#gpt-message').setValue('Опишіть це фото');
        const file = new File(['image'], 'photo.png', { type: 'image/png' });
        Object.defineProperty(wrapper.find('input[type="file"]').element, 'files', { value: [file], configurable: true });
        await wrapper.find('input[type="file"]').trigger('change');
        api.send.mockRejectedValue({ response: { data: { message: 'OpenAI не відповів.' } } });
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        const body = api.send.mock.calls[0][1];
        expect(body.get('text')).toBe('Опишіть це фото');
        expect(body.get('images[]').name).toBe('photo.png');
        expect(wrapper.find('#gpt-message').element.value).toBe('Опишіть це фото');
        expect(wrapper.find('img').attributes('src')).toBe('blob:test-photo');
        expect(document.querySelector('[role="alert"]').textContent).toContain('OpenAI не відповів.');
    });

    it('повтор мережевого збою використовує той самий ідентифікатор запиту', async () => {
        await open();
        await wrapper.find('#gpt-message').setValue('Привіт');
        api.send.mockRejectedValueOnce(new Error('Мережевий збій')).mockResolvedValueOnce({ data: { turn: turn() } });
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        expect(api.send.mock.calls[0][1].get('request_id')).toBe(api.send.mock.calls[1][1].get('request_id'));
        expect(wrapper.find('#gpt-message').element.value).toBe('');
        expect(wrapper.text()).toContain('Вітаю!');
    });

    it('відновлює невдалу репліку й повторює її без дублювання повідомлення', async () => {
        await open({ settings: saved(), chat: { id: 'chat-1', turns: [turn({ reply: null, status: 'failed', error: 'Перевищено квоту.' })] } });
        expect(wrapper.find('#gpt-message').attributes('disabled')).toBeDefined();
        api.retry.mockResolvedValue({ data: { turn: turn() } });
        await wrapper.find('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(api.retry).toHaveBeenCalledWith('chat-1', 'turn-1');
        expect(wrapper.findAll('.gpt-message-user')).toHaveLength(1);
        expect(wrapper.findAll('.gpt-message-assistant')).toHaveLength(1);
        expect(document.querySelector('.app-toast')).toBeNull();
    });

    it('відхиляє непідтримувані файли без втрати тексту', async () => {
        await open();
        await wrapper.find('#gpt-message').setValue('Моя чернетка');
        Object.defineProperty(wrapper.find('input[type="file"]').element, 'files', { value: [new File(['svg'], 'bad.svg', { type: 'image/svg+xml' })], configurable: true });
        await wrapper.find('input[type="file"]').trigger('change');
        expect(wrapper.findAll('img')).toHaveLength(0);
        expect(wrapper.find('#gpt-message').element.value).toBe('Моя чернетка');
        expect(document.querySelector('[role="alert"]').textContent).toContain('JPG, PNG або WEBP');
        expect(api.send).not.toHaveBeenCalled();
    });

    it('захищає незбережену чернетку від випадкового закриття', async () => {
        await open();
        await wrapper.find('#gpt-message').setValue('Не втрачати');
        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);
    });

    it('показує підтверджене підключення та скидає індикатор для нового ключа', async () => {
        await open({ settings: { ...saved(), connection_status: 'connected', verified_at: '2026-10-28T10:00:00Z' }, chat: null });
        expect(wrapper.find('[data-test="connection-state"]').text()).toContain('Підключено до OpenAI');
        await wrapper.find('#gpt-api-key').setValue('sk-new');
        expect(wrapper.find('[data-test="connection-state"]').text()).toContain('Новий ключ · не збережено');
        api.connection.mockResolvedValue({ data: { models: ['gpt-test'] } });
        await wrapper.find('[data-test="connection"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-test="connection-state"]').text()).toContain('Ключ перевірено · збережіть');
    });

    it('саме збереження ключа не видає неперевірений ключ за підключений', async () => {
        await open({ settings: { has_key: false, model: '', connection_status: 'missing' }, chat: null });
        expect(wrapper.find('[data-test="connection-state"]').text()).toContain('Ключ не додано');
        await wrapper.find('#gpt-api-key').setValue('sk-draft');
        await wrapper.find('#gpt-model').setValue('gpt-test');
        api.save.mockResolvedValue({ data: { settings: { ...saved(), connection_status: 'saved' } } });
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(wrapper.find('[data-test="connection-state"]').text()).toContain('Ключ збережено · перевірте');
    });

    it('позначає збій API в індикаторі замість старого успішного підключення', async () => {
        await open({ settings: { ...saved(), connection_status: 'connected' }, chat: { id: 'chat-1', turns: [] } });
        await wrapper.find('#gpt-message').setValue('Тест');
        api.send.mockRejectedValue({ response: { data: { message: 'Перевищено квоту.', settings: { ...saved(), connection_status: 'error' } } } });
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        expect(wrapper.find('[data-test="connection-state"]').text()).toContain('Помилка підключення');
    });

    it('показує фото галереї у відповіді GPT та зберігає завантажене фото користувача', async () => {
        await open({ settings: saved(), chat: { id: 'chat-1', turns: [turn({
            images: [{ name: 'Моє фото', url: '/private/customer-photo' }],
            gallery_photos: [{ id: 7, label: 'Домашні рожеві капці', kind: 'product', url: '/settings/gpt-sandbox/catalog/photos/7' }],
        })] } });
        expect(wrapper.findAll('.gpt-message-user img')).toHaveLength(1);
        expect(wrapper.find('[data-test="gallery-photos"] img').attributes('src')).toBe('/settings/gpt-sandbox/catalog/photos/7');
        expect(wrapper.find('[data-test="gallery-photos"]').text()).toContain('Домашні рожеві капці');
        expect(api.send).not.toHaveBeenCalled();
    });

    it('позначає локальну копію каталогу часом отримання із сервера', async () => {
        await open({ settings: saved(), chat: null, catalog: {
            products: 98, categories: [{ id: 1, name: 'Домашні' }], photos: 136,
            source: 'server_snapshot', captured_at: '2026-10-28T11:26:13+00:00',
        } });
        expect(wrapper.find('[data-test="catalog-state"]').text()).toContain('Активних товарів: 98');
        expect(wrapper.find('[data-test="catalog-state"]').text()).toContain('Копія із сервера');
        expect(wrapper.find('[data-test="catalog-state"]').text()).toContain('Залишки відповідають часу копії');
    });
});
