import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import NovaPayPage from './NovaPayPage.vue';
import http from '@/crm/api/http';

vi.mock('@/crm/api/http', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));

let wrapper;
const response = () => ({
    accounts: [{ id: 1, connection_id: 1, client_name: 'ФОП Тест', iban: 'UA2935 •••• 0001', currency: 'UAH', timezone: 'Europe/Kyiv', enabled: true, requires_auth: false }],
    connections: [{ id: 1 }], account_id: 1,
    balance: { amount_minor: '2495000', received_at: '2026-10-05T12:00:00+03:00' }, incoming_minor: '995000',
    coverage_complete: true, operations_verified: true, refresh_minutes: 5,
    operations: { data: [{ id: 1, booked_on: '2026-10-05', direction: 'in', amount_minor: '995000', status: 'posted', counterparty: 'NovaPay', purpose: 'Виплата післяплати' }], total: 1, last_page: 1, current_page: 1 },
    sync: [], operations_updated_at: '2026-10-05T12:00:00+03:00',
});

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-05T09:00:00Z'));
    vi.clearAllMocks();
    window.history.replaceState(null, '', '/finance/novapay');
    http.get.mockImplementation(async () => ({ data: response() }));
});
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; vi.useRealTimers(); });

describe('Інтерфейс рахунку NovaPay', () => {
    it('показує доступний залишок і вибраний рахунок без повідомлення про збереження', async () => {
        wrapper = mount(NovaPayPage, { attachTo: document.body });
        await flushPromises();
        expect(wrapper.get('[data-testid="balance"]').text()).toBe('24 950,00 грн');
        expect(wrapper.get('#np-account').element.value).toBe('1');
        expect(wrapper.text()).toContain('1 запис');
        expect(document.querySelector('.app-toast')).toBeNull();
        expect(http.post).not.toHaveBeenCalled();
    });

    it('змінює період надходжень і позначає неповноту, зберігаючи поточний баланс', async () => {
        http.get.mockImplementation(async (_url, options) => ({ data: {
            ...response(), incoming_minor: options.params.from === '2026-10-04' ? null : '995000',
            coverage_complete: options.params.from !== '2026-10-04',
        } }));
        wrapper = mount(NovaPayPage);
        await flushPromises();
        await wrapper.findAll('button').find(button => button.text() === 'Вчора').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-testid="balance"]').text()).toBe('24 950,00 грн');
        expect(wrapper.get('[data-testid="incoming"]').text()).toBe('—');
        expect(wrapper.text()).toContain('Дані за період ще не підтверджено');
        expect(window.location.search).toContain('from=2026-10-04');
    });

    it('відкриває довільний період і дозволяє навігацію вкладками з клавіатури', async () => {
        wrapper = mount(NovaPayPage);
        await flushPromises();
        await wrapper.findAll('button').find(button => button.text() === 'Свій період').trigger('click');
        expect(wrapper.find('input[aria-label="Початок періоду"]').exists()).toBe(true);
        await wrapper.get('#np-tab-operations').trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.get('#np-tab-payments').attributes('aria-selected')).toBe('true');
        expect(wrapper.get('#np-panel-payments').text()).toContain('Деталізація ще не отримана');
        await wrapper.get('#np-tab-payments').trigger('keydown', { key: 'End' });
        expect(wrapper.get('#np-tab-sync').attributes('tabindex')).toBe('0');
    });

    it('зберігає введені параметри і помилку після невдалого збереження та фонового завантаження', async () => {
        http.post.mockRejectedValue({ response: { data: { errors: { refresh_token: ['Токен не прийнято. Перевірте значення.'] } } } });
        wrapper = mount(NovaPayPage, { attachTo: document.body });
        await flushPromises();
        await wrapper.findAll('button').find(button => button.text() === 'Підключення').trigger('click');
        await wrapper.get('#np-connection').setValue('1');
        await wrapper.get('#np-token').setValue('test-token');
        await wrapper.get('#np-certificate').setValue('test-certificate');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(wrapper.get('#np-token').element.value).toBe('test-token');
        expect(wrapper.get('#np-token').classes()).toContain('is-invalid');
        expect(document.querySelector('.app-toast').textContent).toContain('Токен не прийнято');
        await vi.advanceTimersByTimeAsync(15000);
        await flushPromises();
        expect(document.querySelector('.app-toast').textContent).toContain('Токен не прийнято');
        expect(wrapper.get('#np-certificate').element.value).toBe('test-certificate');
    });
});
