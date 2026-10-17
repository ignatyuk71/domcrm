import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import NovaPayPage from './NovaPayPage.vue';
import http from '@/crm/api/http';

vi.mock('@/crm/api/http', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));

const response = () => ({
    accounts: [{ id: 1, connection_id: 1, client_name: 'ФОП Тест', iban: 'UA2935 •••• 0001', currency: 'UAH', timezone: 'Europe/Kyiv', enabled: true, requires_auth: false }],
    connections: [{ id: 1 }], account_id: 1, balance: null, incoming_minor: null,
    coverage_complete: false, operations_verified: true,
    refresh_schedule: { times: ['14:30', '20:15', '23:45'], timezone: 'Europe/Kyiv', previous_at: '2026-10-16T20:45:00Z', next_at: '2026-10-17T11:30:00Z', grace_minutes: 10 },
    operations: { data: [], total: 0, last_page: 1, current_page: 1 }, sync: [],
});
let wrapper;
const dialog = () => wrapper.get('#np-settings');
const trigger = () => wrapper.findAll('button').find(button => button.text() === 'Підключення');
const isOpen = () => wrapper.find('#np-settings[open]').exists();
async function openPage() {
    wrapper = mount(NovaPayPage, { attachTo: document.body });
    await flushPromises();
}
async function openConnection() {
    trigger().element.focus();
    await trigger().trigger('click');
    await flushPromises();
}
async function fillConnection() {
    await dialog().get('#np-connection').setValue('1');
    await dialog().get('#np-token').setValue('test-refresh-token');
    await dialog().get('#np-certificate').setValue('test-public-certificate');
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-17T09:00:00Z'));
    vi.clearAllMocks();
    window.history.replaceState(null, '', '/finance/novapay');
    document.body.style.overflow = 'auto';
    vi.spyOn(HTMLDialogElement.prototype, 'showModal').mockImplementation(function () { this.setAttribute('open', ''); });
    vi.spyOn(HTMLDialogElement.prototype, 'close').mockImplementation(function () { this.removeAttribute('open'); });
    http.get.mockImplementation(async () => ({ data: response() }));
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    document.body.style.overflow = '';
    vi.restoreAllMocks();
    vi.useRealTimers();
});

describe('Модальне підключення NovaPay', () => {
    it('відкриває окремий діалог і після закриття повертає фокус та прокрутку сторінки', async () => {
        await openPage();
        expect(isOpen()).toBe(false);
        expect(trigger().attributes('aria-haspopup')).toBe('dialog');
        await openConnection();
        expect(HTMLDialogElement.prototype.showModal).toHaveBeenCalledOnce();
        expect(isOpen()).toBe(true);
        expect(dialog().element.tagName).toBe('DIALOG');
        expect(document.body.style.overflow).toBe('hidden');
        await dialog().get('button[aria-label="Закрити підключення"]').trigger('click');
        await flushPromises();
        expect(isOpen()).toBe(false);
        expect(document.body.style.overflow).toBe('auto');
        expect(document.activeElement).toBe(trigger().element);
        expect(http.post).not.toHaveBeenCalled();
    });

    it('закриває діалог подією Escape та відновлює прокрутку при демонтажі', async () => {
        await openPage();
        await openConnection();
        await dialog().trigger('cancel');
        await flushPromises();
        expect(isOpen()).toBe(false);
        expect(document.body.style.overflow).toBe('auto');
        await openConnection();
        expect(document.body.style.overflow).toBe('hidden');
        wrapper.unmount();
        wrapper = null;
        expect(document.body.style.overflow).toBe('auto');
    });

    it('зберігає чернетку та помилки при повторному відкритті, а toast розміщує всередині діалогу', async () => {
        http.post.mockRejectedValue({ response: { data: { errors: { refresh_token: ['Токен не прийнято. Перевірте значення.'] } } } });
        await openPage();
        await openConnection();
        await fillConnection();
        await dialog().get('form').trigger('submit');
        await flushPromises();
        expect(isOpen()).toBe(true);
        expect(dialog().get('#np-token').classes()).toContain('is-invalid');
        expect(dialog().element.querySelector('.app-toast')?.textContent).toContain('Токен не прийнято');
        await dialog().get('button[aria-label="Закрити підключення"]').trigger('click');
        await flushPromises();
        expect(document.querySelector('.app-toast')?.textContent).toContain('Токен не прийнято');
        await openConnection();
        expect(dialog().get('#np-token').element.value).toBe('test-refresh-token');
        expect(dialog().get('#np-certificate').element.value).toBe('test-public-certificate');
        expect(dialog().get('#np-token').attributes('aria-invalid')).toBe('true');
        expect(dialog().element.querySelector('.app-toast')?.textContent).toContain('Токен не прийнято');
        await vi.advanceTimersByTimeAsync(15000);
        await flushPromises();
        expect(dialog().get('#np-token').element.value).toBe('test-refresh-token');
        expect(dialog().element.querySelector('.app-toast')?.textContent).toContain('Токен не прийнято');
    });

    it('після підтвердженого збереження закриває діалог та очищує секретні поля', async () => {
        http.post.mockResolvedValue({ data: { connection_id: 1, run_id: 21 } });
        await openPage();
        await openConnection();
        await fillConnection();
        await dialog().get('form').trigger('submit');
        await flushPromises();
        expect(http.post).toHaveBeenCalledOnce();
        expect(http.post.mock.calls[0][0]).toBe('/finance/novapay/connections');
        expect(isOpen()).toBe(false);
        expect(document.body.style.overflow).toBe('auto');
        expect(document.querySelector('.app-toast')?.textContent).toContain('Параметри збережено');
        await openConnection();
        expect(dialog().get('#np-token').element.value).toBe('');
        expect(dialog().get('#np-certificate').element.value).toBe('');
    });

    it('не закривається через хрестик чи Escape під час збереження', async () => {
        let finish;
        http.post.mockImplementation(() => new Promise(resolve => { finish = resolve; }));
        await openPage();
        await openConnection();
        await fillConnection();
        await dialog().get('form').trigger('submit');
        await flushPromises();
        expect(dialog().get('button[aria-label="Закрити підключення"]').attributes('disabled')).toBeDefined();
        await dialog().get('button[aria-label="Закрити підключення"]').trigger('click');
        await dialog().trigger('cancel');
        await flushPromises();
        expect(isOpen()).toBe(true);
        expect(document.body.style.overflow).toBe('hidden');
        finish({ data: { connection_id: 1 } });
        await flushPromises();
        expect(isOpen()).toBe(false);
    });

    it('не відкриває форму самостійно при завантаженні та фоновому оновленні без рахунків', async () => {
        http.get.mockImplementation(async () => ({ data: { ...response(), accounts: [], connections: [], account_id: null } }));
        await openPage();
        expect(isOpen()).toBe(false);
        expect(HTMLDialogElement.prototype.showModal).not.toHaveBeenCalled();
        await openConnection();
        await dialog().get('button[aria-label="Закрити підключення"]').trigger('click');
        await flushPromises();
        await vi.advanceTimersByTimeAsync(15000);
        await flushPromises();
        expect(http.get).toHaveBeenCalledTimes(2);
        expect(isOpen()).toBe(false);
        expect(HTMLDialogElement.prototype.showModal).toHaveBeenCalledOnce();
        expect(http.post).not.toHaveBeenCalled();
    });
});
