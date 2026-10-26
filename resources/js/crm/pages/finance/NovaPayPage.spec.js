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
    coverage_complete: true, operations_verified: true, unknown_statuses: [], failed_operation_dates: [],
    refresh_schedule: { times: ['14:30', '20:15', '23:45'], timezone: 'Europe/Kyiv', previous_at: '2026-10-04T20:45:00Z', next_at: '2026-10-05T11:30:00Z', grace_minutes: 10 },
    operations: { data: [{ id: 1, document_number: '000123A0', booked_on: '2026-10-05', direction: 'in', amount_minor: '995000', status: 'posted', counterparty: 'NovaPay', counterparty_iban: 'UA2935 •••• 0002', status_changed_at: '2026-10-05T11:19:50Z', purpose: 'Виплата післяплати',
        sender: { name: 'NovaPay', iban: 'UA 29 358700 •••• 0002', tax_code: '0000000002' },
        recipient: { name: 'ФОП Тест', iban: 'UA 29 358700 •••• 0001', tax_code: '0000000001' },
    }], total: 1, last_page: 1, current_page: 1 },
    sync: [], operations_updated_at: '2026-10-05T12:00:00+03:00',
});

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-05T09:00:00Z'));
    vi.clearAllMocks();
    vi.spyOn(HTMLDialogElement.prototype, 'showModal').mockImplementation(function () { this.setAttribute('open', ''); });
    vi.spyOn(HTMLDialogElement.prototype, 'close').mockImplementation(function () { this.removeAttribute('open'); });
    window.history.replaceState(null, '', '/finance/novapay');
    http.get.mockImplementation(async () => ({ data: response() }));
});
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; vi.restoreAllMocks(); vi.useRealTimers(); });

describe('Інтерфейс рахунку NovaPay', () => {
    it('зберігає точний невідомий статус, показує попередження й не позначає всі дані актуальними', async () => {
        const data = response();
        data.unknown_statuses = [{ status: 'Unverified', count: 1 }];
        data.coverage_complete = false;
        data.incoming_minor = null;
        data.operations.data[0] = { ...data.operations.data[0], status: 'unknown', source_status: 'Unverified' };
        http.get.mockResolvedValue({ data });
        wrapper = mount(NovaPayPage, { attachTo: document.body });
        await flushPromises();
        expect(wrapper.get('.payment-status').text()).toContain('Потребує перевірки: Unverified');
        expect(wrapper.get('.state-pill').text()).toBe('Операції потребують перевірки');
        expect(wrapper.get('.state-pill').classes()).not.toContain('state-pill--ok');
        expect(wrapper.get('[data-testid="incoming"]').text()).toBe('—');
        expect(document.querySelector('.app-toast--warning').textContent).toContain('Unverified');
        await vi.advanceTimersByTimeAsync(15000);
        await flushPromises();
        expect(document.querySelector('.app-toast--warning')).not.toBeNull();
        document.querySelector('.app-toast-close').click();
        await vi.advanceTimersByTimeAsync(15000);
        await flushPromises();
        expect(document.querySelector('.app-toast')).toBeNull();
        http.get.mockResolvedValue({ data: response() });
        await vi.advanceTimersByTimeAsync(15000);
        await flushPromises();
        expect(wrapper.get('.state-pill').text()).toBe('Дані актуальні');
        expect(wrapper.get('[data-testid="incoming"]').text()).toBe('9 950,00 грн');
        expect(document.querySelector('.app-toast')).toBeNull();
    });
    it('не перекриває помилку іншим попередженням і враховує збій дня поза останніми задачами', async () => {
        const data = { ...response(), failed_operation_dates: ['2026-10-01'],
            unknown_statuses: [{ status: 'Unverified', count: 1 }],
            sync: [{ id: 20, source: 'balance', status: 'failed', error_message: 'Баланс не отримано.' }],
        };
        http.get.mockResolvedValue({ data });
        wrapper = mount(NovaPayPage, { attachTo: document.body });
        await flushPromises();
        expect(wrapper.get('.state-pill').classes()).not.toContain('state-pill--ok');
        expect(document.querySelector('.app-toast--error').textContent).toContain('Баланс не отримано.');
        expect(document.querySelector('.app-toast--warning')).toBeNull();
    });
    it('не повертає стару помилку після підтвердженого відновлення дня поза останніми 20 задачами', async () => {
        http.get.mockResolvedValue({ data: { ...response(), sync: [
            { id: 2, source: 'operations', date_from: '2026-10-01', date_to: '2026-10-01', status: 'failed', error_message: 'Попередня помилка' },
        ] } });
        wrapper = mount(NovaPayPage, { attachTo: document.body });
        await flushPromises();
        expect(document.querySelector('.app-toast')).toBeNull();
        expect(wrapper.get('.state-pill').classes()).toContain('state-pill--ok');
    });
    it('між трьома сеансами зберігає актуальність балансу й показує чесний розклад', async () => {
        vi.setSystemTime(new Date('2026-10-05T10:00:00Z'));
        http.get.mockResolvedValue({ data: { ...response(), balance: { amount_minor: '2495000', received_at: '2026-10-04T20:46:00Z' } } });
        wrapper = mount(NovaPayPage);
        await flushPromises();
        expect(wrapper.get('.state-pill').text()).toBe('Дані актуальні');
        expect(wrapper.get('[data-testid="refresh-schedule"]').text()).toContain('14:30, 20:15, 23:45');
        expect(wrapper.get('[data-testid="refresh-schedule"]').text()).toContain('також вручну');
        expect(wrapper.text()).toContain('Час за Києвом');
        await vi.advanceTimersByTimeAsync(15000);
        await flushPromises();
        expect(http.post).not.toHaveBeenCalled();
    });
    it('позначає пропущений сеанс після часу на виконання черги, а не через десять хвилин від отримання', async () => {
        vi.setSystemTime(new Date('2026-10-05T11:35:00Z'));
        http.get.mockResolvedValue({ data: { ...response(), refresh_schedule: { ...response().refresh_schedule, previous_at: '2026-10-05T11:30:00Z', next_at: '2026-10-05T17:15:00Z' } } });
        wrapper = mount(NovaPayPage);
        await flushPromises();
        expect(wrapper.get('.state-pill').text()).toBe('Дані актуальні');
        await vi.advanceTimersByTimeAsync(6 * 60000);
        await flushPromises();
        expect(wrapper.get('.state-pill').text()).toBe('Дані не оновлено');
        expect(wrapper.get('[data-testid="balance"]').text()).toBe('24 950,00 грн');
        expect(http.post).not.toHaveBeenCalled();
    });
    it('залишає ручне оновлення за кнопкою без автоматичних запитів синхронізації від polling', async () => {
        http.post.mockResolvedValue({ data: { queued: 1 } });
        wrapper = mount(NovaPayPage);
        await flushPromises();
        await wrapper.findAll('button').find(button => button.text() === 'Оновити дані').trigger('click');
        await flushPromises();
        expect(http.post).toHaveBeenCalledOnce();
        expect(http.post.mock.calls[0][0]).toBe('/finance/novapay/accounts/1/refresh');
        await vi.advanceTimersByTimeAsync(15000);
        await flushPromises();
        expect(http.post).toHaveBeenCalledOnce();
    });
    it('не показує нуль чи відсутність виплат, коли гроші вже є, а деталізація запізнюється', async () => {
        const payouts = { paid_orders_count: null, confirmed_paid_orders_count: 0, coverage_complete: true,
            pending_incoming_count: 2, pending_incoming_minor: '1151207', matched_net_minor: '0',
            rows: { data: [], current_page: 1, last_page: 1 } };
        http.get.mockResolvedValue({ data: { ...response(), payouts } });
        wrapper = mount(NovaPayPage);
        await flushPromises();
        expect(wrapper.get('[data-testid="paid-orders"]').text()).toBe('—');
        await wrapper.findAll('button').find(button => button.text().includes('Переглянути замовлення')).trigger('click');
        expect(wrapper.get('[data-testid="payout-pending"]').text()).toContain('11 512,07 грн');
        expect(wrapper.get('#np-panel-payments').text()).toContain('Очікуємо деталізацію надходжень');
        expect(wrapper.get('#np-panel-payments').text()).not.toContain('Виплат за цей період немає');
        http.get.mockResolvedValue({ data: { ...response(), payouts: { ...payouts, confirmed_paid_orders_count: 417 } } });
        await vi.advanceTimersByTimeAsync(15000);
        await flushPromises();
        expect(wrapper.get('[data-testid="paid-orders"]').text()).toBe('417');
        expect(wrapper.text()).toContain('Деталізація надходжень ще не повна');
    });
    it('показує підтверджені замовлення, комісію та посилання з реєстру післяплати', async () => {
        http.get.mockResolvedValue({ data: { ...response(), payouts: {
            paid_orders_count: 1, matched_net_minor: '49750', coverage_complete: true, unmatched: 0, ambiguous: 0, unconfirmed: 0,
            updated_at: '2026-10-05T12:00:00+03:00', rows: { data: [{ id: 7, ttn: '20450000000001', paid_on: '2026-10-05', register_number: 'BO0001', gross_minor: '50000', fee_minor: '250', net_minor: '49750', order_number: '1001', order_url: '/orders/1/edit', confirmed: true, match_status: 'matched' }], current_page: 1, last_page: 1 },
        } } });
        wrapper = mount(NovaPayPage);
        await flushPromises();
        expect(wrapper.get('[data-testid="paid-orders"]').text()).toBe('1');
        await wrapper.findAll('button').find(button => button.text().includes('Переглянути замовлення')).trigger('click');
        const panel = wrapper.get('#np-panel-payments');
        expect(panel.get('a').attributes('href')).toBe('/orders/1/edit');
        expect(panel.text()).toContain('20450000000001');
        expect(panel.text()).toContain('2,50 грн');
        expect(panel.text()).toContain('497,50 грн');
        expect(panel.text()).toContain('Зараховано');
    });
    it('відрізняє списання та зберігає відправника й отримувача у своїх колонках', async () => {
        const data = response();
        data.operations.data[0] = { ...data.operations.data[0], direction: 'out', amount_minor: '200000',
            sender: { name: 'ФОП Відправник', iban: 'UA 29 358700 •••• 0001' },
            recipient: { name: 'Отримувач переказу', iban: 'UA 29 358700 •••• 0002' },
        };
        http.get.mockResolvedValue({ data });
        wrapper = mount(NovaPayPage);
        await flushPromises();
        expect(wrapper.get('.payment-amount').classes()).toContain('payment-amount--out');
        expect(wrapper.get('.payment-amount').attributes('aria-label')).toContain('Списання');
        expect(wrapper.get('.payment-sender').text()).toContain('ФОП Відправник');
        expect(wrapper.get('.payment-recipient').text()).toContain('Отримувач переказу');
    });
    it('не повторює помилку періоду, який уже успішно імпортовано', async () => {
        http.get.mockResolvedValue({ data: { ...response(), sync: [
            { id: 3, source: 'operations', date_from: '2026-10-01', date_to: '2026-10-01', status: 'success' },
            { id: 2, source: 'operations', date_from: '2026-10-01', date_to: '2026-10-01', status: 'failed', error_message: 'Попередня помилка' },
        ] } });
        wrapper = mount(NovaPayPage, { attachTo: document.body });
        await flushPromises();
        expect(document.querySelector('.app-toast')).toBeNull();
    });
    it('показує доступний залишок і вибраний рахунок без повідомлення про збереження', async () => {
        wrapper = mount(NovaPayPage, { attachTo: document.body });
        await flushPromises();
        expect(wrapper.get('[data-testid="balance"]').text()).toBe('24 950,00 грн');
        expect(wrapper.get('#np-account').element.value).toBe('1');
        expect(wrapper.text()).toContain('1 запис');
        expect(document.querySelector('.app-toast')).toBeNull();
        expect(http.post).not.toHaveBeenCalled();
        expect(http.get.mock.calls[0][1].params).toMatchObject({ from: '2026-10-01', to: '2026-10-05', direction: 'all' });
        expect(wrapper.text()).toContain('UA 29 358700 •••• 0002');
        expect(wrapper.text()).toContain('14:19:50');
        expect(wrapper.findAll('.payment-list th').map(cell => cell.text())).toEqual(['Документ', 'Дата', 'Відправник', 'Сума', 'Отримувач', 'Статус']);
        expect(wrapper.get('.payment-document').text()).toContain('000123A0');
        expect(wrapper.get('.payment-sender').text()).toContain('NovaPay');
        expect(wrapper.get('.payment-recipient').text()).toContain('ФОП Тест');
        expect(wrapper.get('.payment-amount').classes()).toContain('payment-amount--in');
        expect(wrapper.get('.payment-amount').text()).toContain('9 950,00 UAH');
        expect(wrapper.get('.payment-status time').text()).toBe('05.10.2026 14:19:50');
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
        expect(wrapper.get('#np-panel-payments').text()).toContain('Очікуємо реєстр післяплати');
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
