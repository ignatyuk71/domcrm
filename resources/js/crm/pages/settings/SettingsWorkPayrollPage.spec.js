import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Page from './SettingsWorkPayrollPage.vue';
import * as work from '../../services/workTimeApi';
import * as api from '../../services/workPayrollApi';

vi.mock('../../services/workTimeApi', () => ({ createWorkEmployee: vi.fn(), updateWorkEmployee: vi.fn(), deleteWorkEmployee: vi.fn() }));
vi.mock('../../services/workPayrollApi', () => ({ fetchWorkEmployees: vi.fn(), fetchPayrollReport: vi.fn(), saveMonthlyPayroll: vi.fn() }));
const employee = { id: 101, name: 'Тестова працівниця', position: 'Швачка', payment_type: 'hourly', archived_on: null, version: 1 };
const pieceEmployee = { ...employee, id: 102, name: 'Тестовий виконавець', payment_type: 'piecework' };
const archivedEmployee = { ...employee, id: 103, name: 'Тестовий архів', archived_on: '2026-08-01' };
const row = (replace = {}) => ({ employee, employee_id: 101, month: '2026-09', version: 1, rate_mode: 'daily', daily_hours: 7, hours: '10.50', days: 2,
    hourly_rate: null, daily_rate: '350.00', base_pay: '525.00', adjustment: '0.00', adjustment_reason: null,
    salary: '525.00', bonus: '0.00', expenses: '0.00', accrued: '525.00', paid: '0.00', balance: '525.00', note: null, ...replace });
const report = (replace = {}) => ({ month: '2026-09', rows: [row()], incomplete_count: 0,
    totals: { salary: '525.00', bonus: '0.00', expenses: '0.00', accrued: '525.00', paid: '0.00', balance: '525.00' }, ...replace });
let wrapper;
const button = label => wrapper.findAll('button').find(b => b.text().includes(label));
async function open() { wrapper = mount(Page, { attachTo: document.body }); await flushPromises(); }
async function click(label) { await button(label).trigger('click'); await flushPromises(); }
const dialog = () => wrapper.get('dialog');
beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date', 'setTimeout', 'clearTimeout'] }); vi.setSystemTime(new Date('2026-09-18T12:00:00Z')); vi.clearAllMocks();
    vi.spyOn(HTMLDialogElement.prototype, 'showModal').mockImplementation(function () { this.setAttribute('open', ''); });
    vi.spyOn(HTMLDialogElement.prototype, 'close').mockImplementation(function () { this.removeAttribute('open'); });
    api.fetchWorkEmployees.mockResolvedValue({ data: { employees: [employee, pieceEmployee, archivedEmployee] } });
    api.fetchPayrollReport.mockImplementation(async month => ({ data: report({ month }) }));
    api.saveMonthlyPayroll.mockResolvedValue({ data: row() });
    work.createWorkEmployee.mockResolvedValue({ data: employee });
    work.updateWorkEmployee.mockResolvedValue({ data: employee });
    work.deleteWorkEmployee.mockResolvedValue({ data: { deleted: true } });
});
afterEach(() => { wrapper?.unmount(); document.body.innerHTML = ''; vi.restoreAllMocks(); vi.useRealTimers(); });

describe('Налаштування працівників і зарплати', () => {
    it('об’єднує оклад, години та роботи в одному рядку і попередньому розрахунку', async () => {
        const mixed = { ...employee, payment_type: 'mixed' };
        api.fetchPayrollReport.mockResolvedValue({ data: report({ rows: [row({ employee: mixed, hours: '14.00', daily_rate: '400.00', monthly_salary: '8000.00', time_pay: '800.00', piecework_pay: '300.00', base_pay: '9100.00', salary: '9100.00', accrued: '9100.00', balance: '9100.00' })] }) });
        await open();
        expect(wrapper.findAll('[data-testid="payroll-row-101"]')).toHaveLength(1);
        expect(wrapper.get('[data-testid="payroll-row-101"]').text()).toContain('Оклад 8');
        expect(wrapper.get('[data-field="monthly_salary"]').element.value).toBe('8000.00');
        await click('Тестова працівниця');
        expect(wrapper.get('.wp-preview').text().replace(/\s/g, '')).toContain('9100грн');
        await wrapper.get('[name="monthly_salary"]').setValue('8500');
        expect(wrapper.get('.wp-preview').text().replace(/\s/g, '')).toContain('9600грн');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(api.saveMonthlyPayroll.mock.calls[0][1]).toMatchObject({ monthly_salary: '8500', rate_mode: 'daily', rate: '400.00' });
    });
    it('дозволяє розширити наявного працівника до обох таблиць без дубля', async () => {
        await open(); await click('Працівники');
        await wrapper.get('[aria-label="Редагувати працівника Тестова працівниця"]').trigger('click'); await flushPromises();
        await wrapper.get('[name="payment_type"]').setValue('mixed');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(work.updateWorkEmployee).toHaveBeenCalledWith(101, expect.objectContaining({ payment_type: 'mixed', version: 1 }));
        expect(work.createWorkEmployee).not.toHaveBeenCalled();
    });
    it('змішана оплата без додаткових годин нараховує лише оклад і роботи', async () => {
        api.fetchPayrollReport.mockResolvedValue({ data: report({ rows: [row({ employee: { ...employee, payment_type: 'mixed' }, hours: '0.00', daily_rate: null, monthly_salary: '8000.00', piecework_pay: '0.00' })] }) });
        await open(); await click('Тестова працівниця');
        expect(wrapper.get('.wp-preview').text().replace(/\s/g, '')).toContain('8000грн');
    });
    it.each([[7, '514,29'], [8, '450']])('підпис і попередній розрахунок використовують %s годин із сервера', async (dailyHours, expected) => {
        api.fetchPayrollReport.mockResolvedValue({ data: report({ rows: [row({ daily_hours: dailyHours, hours: '9.00', daily_rate: '400.00' })] }) });
        await open();
        expect(wrapper.get('[aria-label="Одиниця ставки — Тестова працівниця"]').text()).toContain(`за ${dailyHours} годин`);
        await click('Тестова працівниця');
        expect(wrapper.get('[name="rate_mode"]').text()).toContain(`Ставка за день · ${dailyHours} годин`);
        expect(wrapper.get('.wp-preview').text()).toContain(`${expected} грн`);
        expect(dialog().text()).toContain(`години ÷ ${dailyHours}`);
        expect(dialog().text()).toContain('без неоплачуваної перерви');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(api.saveMonthlyPayroll.mock.calls[0][1]).not.toHaveProperty('daily_hours');
    });
    it('читає звіт без створення записів, показує ставки, суми й підсумки', async () => {
        await open();
        expect(api.fetchPayrollReport).toHaveBeenCalledWith('2026-09');
        expect(wrapper.get('[data-field="rate"]').element.value).toBe('350.00');
        expect(wrapper.get('[aria-label="Одиниця ставки — Тестова працівниця"]').element.value).toBe('daily');
        expect(wrapper.get('tfoot').text()).toContain('525');
        expect(wrapper.find('.wp-stats').exists()).toBe(false);
        expect(work.createWorkEmployee).not.toHaveBeenCalled(); expect(api.saveMonthlyPayroll).not.toHaveBeenCalled();
        expect(document.querySelector('.app-toast')).toBeNull();
    });
    it('створює відрядного працівника і повторює той самий ключ після втрати відповіді', async () => {
        work.createWorkEmployee.mockRejectedValueOnce(new Error('offline'));
        await open(); await click('Додати працівника');
        await wrapper.get('[name="employee_name"]').setValue('Новий тест');
        await wrapper.get('[name="payment_type"]').setValue('piecework');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        const data = work.createWorkEmployee.mock.calls[0][0];
        expect(data).toMatchObject({ name: 'Новий тест', payment_type: 'piecework' });
        expect(dialog().find('.app-toast').exists()).toBe(true);
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(work.createWorkEmployee.mock.calls[1][0].request_key).toBe(data.request_key);
        expect(dialog().attributes('open')).toBeUndefined();
    });
    it('показує архів за перемикачем і дозволяє відновлення без зміни типу', async () => {
        await open(); await click('Працівники');
        expect(wrapper.get('.wp-team').text()).not.toContain('Тестовий архів');
        await wrapper.get('.wp-card-heading input[type="checkbox"]').setValue(true);
        await wrapper.get('[aria-label="Редагувати працівника Тестовий архів"]').trigger('click'); await flushPromises();
        expect(wrapper.get('[name="payment_type"] option[value="piecework"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[name="payment_type"] option[value="mixed"]').attributes('disabled')).toBeUndefined();
        await dialog().get('input[type="checkbox"]').setValue(false);
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(work.updateWorkEmployee).toHaveBeenCalledWith(103, expect.objectContaining({ archived: false, version: 1 }));
    });
    it('видалення тільки після підтвердження, скасування не змінює даних', async () => {
        await open(); await click('Працівники');
        await wrapper.get('[aria-label="Видалити працівника Тестова працівниця"]').trigger('click'); await flushPromises();
        expect(dialog().text()).toContain('за всі місяці'); expect(document.activeElement.textContent).toBe('Скасувати');
        await dialog().trigger('cancel'); expect(work.deleteWorkEmployee).not.toHaveBeenCalled();
        await wrapper.get('[aria-label="Видалити працівника Тестова працівниця"]').trigger('click'); await flushPromises();
        await wrapper.get('[data-testid="confirm-delete"]').trigger('click'); await flushPromises();
        expect(work.deleteWorkEmployee).toHaveBeenCalledExactlyOnceWith(101, 1);
        expect(dialog().attributes('open')).toBeUndefined();
    });
    it('рахує попередній результат і зберігає лише вихідні значення за конкретний місяць', async () => {
        await open(); await click('Тестова працівниця');
        await wrapper.get('[name="bonus"]').setValue('100'); await wrapper.get('[name="expenses"]').setValue('200');
        await wrapper.get('[name="adjustment"]').setValue('-20,50'); await wrapper.get('[name="adjustment_reason"]').setValue('Уточнення');
        await wrapper.get('[name="paid"]').setValue('300');
        expect(wrapper.get('.wp-preview').text()).toContain('804,5 грн');
        expect(wrapper.get('.wp-preview').text()).toContain('504,5 грн');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(api.saveMonthlyPayroll).toHaveBeenCalledWith(101, expect.objectContaining({ month: '2026-09', rate_mode: 'daily', rate: '350.00', adjustment: '-20,50', adjustment_reason: 'Уточнення', version: 1 }));
        const sent = api.saveMonthlyPayroll.mock.calls[0][1];
        expect(sent).not.toHaveProperty('balance'); expect(sent).not.toHaveProperty('accrued');
    });
    it('відрядні нарахування не мають поля ставки й використовують суму табеля', async () => {
        api.fetchPayrollReport.mockResolvedValue({ data: report({ rows: [row({ employee: pieceEmployee, employee_id: 102, rate_mode: 'piecework', base_pay: '900.00', salary: '900.00', accrued: '900.00' })] }) });
        await open(); await click('Тестовий виконавець');
        expect(wrapper.find('[name="rate"]').exists()).toBe(false);
        expect(wrapper.get('.wp-preview').text()).toContain('900 грн');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(api.saveMonthlyPayroll).toHaveBeenCalledWith(102, expect.objectContaining({ rate_mode: 'piecework', rate: null }));
    });
    it('без ставки не показує нульову зарплату і позначає неповний підсумок', async () => {
        api.fetchPayrollReport.mockResolvedValue({ data: report({ incomplete_count: 1, rows: [row({ daily_rate: null, accrued: null, base_pay: null, salary: null, balance: null })] }) });
        await open(); expect(wrapper.text()).toContain('Підсумок неповний');
        expect(wrapper.get('[data-field="rate"]').attributes('placeholder')).toBe('Не вказано');
        expect(wrapper.get('[data-field="rate"]').element.value).toBe('');
        await click('Тестова працівниця'); expect(wrapper.get('.wp-preview').text()).toContain('—');
    });
    it('помилка валідації всередині модалки зберігає чернетку та підсвічує поле', async () => {
        api.saveMonthlyPayroll.mockRejectedValueOnce({ response: { status: 422, data: { errors: { expenses: ['Вкажіть коректну суму'] } } } });
        await open(); await click('Тестова працівниця'); await wrapper.get('[name="expenses"]').setValue('bad');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(wrapper.get('[name="expenses"]').element.value).toBe('bad');
        expect(wrapper.get('[name="expenses"]').attributes('aria-invalid')).toBe('true');
        expect(dialog().get('.app-toast').text()).toContain('Вкажіть коректну суму');
        expect(dialog().attributes('open')).toBeDefined();
    });
    it('не втрачає незбережене при Escape і дає явне підтвердження відкидання через toast', async () => {
        await open(); await click('Тестова працівниця'); await wrapper.get('[name="bonus"]').setValue('111');
        await dialog().trigger('cancel'); await flushPromises();
        expect(dialog().attributes('open')).toBeDefined(); expect(dialog().text()).toContain('Є незбережені зміни');
        await click('Продовжити редагування'); expect(wrapper.get('[name="bonus"]').element.value).toBe('111');
        await dialog().trigger('cancel'); await flushPromises(); await click('Відкинути зміни');
        expect(dialog().attributes('open')).toBeUndefined(); expect(api.saveMonthlyPayroll).not.toHaveBeenCalled();
    });
    it('перемикання періоду читає інший місяць і не записує ставки', async () => {
        await open(); await wrapper.get('[aria-label="Місяць"]').setValue('8'); await flushPromises();
        expect(api.fetchPayrollReport).toHaveBeenLastCalledWith('2026-08');
        expect(wrapper.get('.ledger-heading').text()).toContain('Серпень 2026');
        expect(api.saveMonthlyPayroll).not.toHaveBeenCalled();
    });
});

const cell = (field, id = 101) => wrapper.get(`input[data-row="${id}"][data-field="${field}"]`);
const tick = async (ms = 610) => { await vi.advanceTimersByTimeAsync(ms); await flushPromises(); };
const reply = data => ({ data: row({ version: data.version + 1, rate_mode: data.rate_mode,
    hourly_rate: data.rate_mode === 'hourly' ? data.rate : null, daily_rate: data.rate_mode === 'daily' ? data.rate : null,
    bonus: data.bonus, expenses: data.expenses, paid: data.paid, accrued: String(525 + Number(data.bonus) + Number(data.expenses)),
    balance: String(525 + Number(data.bonus) + Number(data.expenses) - Number(data.paid)) }) });

describe('Відомість як Excel: автозбереження', () => {
    it('автозберігає місячний оклад і зберігає його при редагуванні премії', async () => {
        api.fetchPayrollReport.mockResolvedValue({ data: report({ rows: [row({ monthly_salary: '8000.00' })] }) });
        api.saveMonthlyPayroll.mockResolvedValue({ data: row({ monthly_salary: '8500.00', version: 2 }) });
        await open();
        await wrapper.get('[data-field="monthly_salary"]').setValue('8500');
        await vi.advanceTimersByTimeAsync(650); await flushPromises();
        expect(api.saveMonthlyPayroll.mock.calls[0][1].monthly_salary).toBe('8500.00');
        await wrapper.get('[data-field="bonus"]').setValue('200');
        await vi.advanceTimersByTimeAsync(650); await flushPromises();
        expect(api.saveMonthlyPayroll.mock.calls[1][1]).toMatchObject({ monthly_salary: '8500.00', bonus: '200.00', version: 2 });
    });
    it('групує введення рядка, приймає кому та перераховує підтверджені підсумки', async () => {
        api.saveMonthlyPayroll.mockImplementation(async (id, data) => reply(data));
        await open(); await cell('bonus').setValue('100,50'); await cell('expenses').setValue('200');
        expect(api.saveMonthlyPayroll).not.toHaveBeenCalled(); await tick();
        expect(api.saveMonthlyPayroll).toHaveBeenCalledExactlyOnceWith(101, expect.objectContaining({ month: '2026-09', version: 1, bonus: '100.50', expenses: '200.00', adjustment: '0.00' }));
        expect(wrapper.get('tfoot').text()).toContain('825,5'); await tick(710);
        expect(document.querySelector('.app-toast').textContent).toContain('Нарахування збережено');
    });
    it('фокус та blur без зміни не створюють нарахувань', async () => {
        await open(); await cell('bonus').trigger('focus'); await cell('bonus').trigger('blur'); await tick(1500);
        expect(api.saveMonthlyPayroll).not.toHaveBeenCalled(); expect(document.querySelector('.app-toast')).toBeNull();
    });
    it('порожня премія стає нулем, а порожня ставка лишається невідомою', async () => {
        api.saveMonthlyPayroll.mockImplementation(async (id, data) => reply(data));
        await open(); await cell('rate').setValue(''); await cell('bonus').setValue(''); await cell('bonus').trigger('blur'); await flushPromises();
        expect(api.saveMonthlyPayroll).toHaveBeenCalledWith(101, expect.objectContaining({ rate: null, bonus: '0.00' }));
    });
    it('не стирає новий ввід під час запиту і послідовно використовує нову версію', async () => {
        let resolve;
        api.saveMonthlyPayroll.mockImplementationOnce(() => new Promise(r => { resolve = r; })).mockImplementation(async (id, data) => reply(data));
        await open(); await cell('bonus').setValue('100'); await tick();
        await cell('bonus').setValue('250'); await cell('paid').setValue('50'); await tick();
        expect(api.saveMonthlyPayroll).toHaveBeenCalledTimes(1);
        resolve(reply(api.saveMonthlyPayroll.mock.calls[0][1])); await flushPromises();
        expect(api.saveMonthlyPayroll).toHaveBeenCalledTimes(2);
        expect(api.saveMonthlyPayroll.mock.calls[1][1]).toMatchObject({ version: 2, bonus: '250.00', paid: '50.00' });
        expect(cell('bonus').element.value).toBe('250.00');
    });
    it('після невідомого результату повторює старий знімок перед новішою чернеткою', async () => {
        api.saveMonthlyPayroll.mockRejectedValueOnce(new Error('offline')).mockImplementation(async (id, data) => reply(data));
        await open(); await cell('bonus').setValue('100'); await tick();
        const attempt = api.saveMonthlyPayroll.mock.calls[0][1];
        await cell('bonus').setValue('200'); await tick();
        expect(api.saveMonthlyPayroll).toHaveBeenCalledTimes(1);
        document.querySelector('.app-toast-actions button').click(); await flushPromises();
        expect(api.saveMonthlyPayroll.mock.calls[1][1]).toEqual(attempt);
        expect(api.saveMonthlyPayroll.mock.calls[2][1]).toMatchObject({ version: 2, bonus: '200.00' });
    });
    it('не відправляє некоректні суми та зберігає чернетку при зміні місяця', async () => {
        await open(); await cell('expenses').setValue('bad'); await tick();
        expect(api.saveMonthlyPayroll).not.toHaveBeenCalled(); expect(cell('expenses').attributes('aria-invalid')).toBe('true');
        await wrapper.get('[aria-label="Місяць"]').setValue('8'); await flushPromises();
        expect(api.fetchPayrollReport).toHaveBeenCalledTimes(1); expect(wrapper.get('[aria-label="Місяць"]').element.value).toBe('9');
        expect(cell('expenses').element.value).toBe('bad');
        expect(document.querySelector('.app-toast').textContent).toContain('Тестова працівниця · 2026-09');
    });
    it('усуває помилку 422 після виправлення, не стираючи клітинку', async () => {
        api.saveMonthlyPayroll.mockRejectedValueOnce({ response: { status: 422, data: { errors: { expenses: ['Некоректні витрати'] } } } }).mockImplementation(async (id, data) => reply(data));
        await open(); await cell('expenses').setValue('25'); await tick();
        expect(cell('expenses').element.value).toBe('25'); expect(cell('expenses').attributes('aria-invalid')).toBe('true');
        await cell('expenses').setValue('26'); await tick(); expect(cell('expenses').attributes('aria-invalid')).toBe('false');
    });
    it('повернення до збереженого значення прибирає валідаційну помилку без запису', async () => {
        await open(); await cell('bonus').setValue('bad'); await tick();
        await cell('bonus').setValue('0.00'); await tick();
        expect(cell('bonus').attributes('aria-invalid')).toBe('false'); expect(api.saveMonthlyPayroll).not.toHaveBeenCalled();
        const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); expect(event.defaultPrevented).toBe(false);
    });
    it('чернетки зберігаються навіть якщо підтверджене оновлення після конфлікту не вдалося', async () => {
        api.saveMonthlyPayroll.mockRejectedValue({ response: { status: 409, data: {} } });
        await open(); await cell('bonus').setValue('100'); await tick();
        document.querySelector('.app-toast-actions button').click(); await flushPromises();
        api.fetchPayrollReport.mockRejectedValueOnce(new Error('offline'));
        [...document.querySelectorAll('.app-toast-actions button')].find(b => b.textContent.includes('Відкинути й оновити')).click(); await flushPromises();
        expect(cell('bonus').element.value).toBe('100');
    });
    it('конфлікт не перезаписує чужі дані, оновлення вимагає підтвердження', async () => {
        api.saveMonthlyPayroll.mockRejectedValue({ response: { status: 409, data: { message: 'Змінено іншим власником' } } });
        await open(); await cell('bonus').setValue('100'); await tick();
        await cell('bonus').setValue('200'); await tick(); expect(api.saveMonthlyPayroll).toHaveBeenCalledTimes(1);
        document.querySelector('.app-toast-actions button').click(); await flushPromises();
        expect(document.querySelector('.app-toast').textContent).toContain('Незбережені зміни');
        expect(api.fetchPayrollReport).toHaveBeenCalledTimes(1);
        const confirm = [...document.querySelectorAll('.app-toast-actions button')].find(b => b.textContent.includes('Відкинути й оновити'));
        confirm.click(); await flushPromises();
        expect(api.fetchPayrollReport).toHaveBeenCalledTimes(2); expect(cell('bonus').element.value).toBe('0.00');
    });
    it('зберігає старий місяць перед переходом і не відправляє відкладені запити нового', async () => {
        api.saveMonthlyPayroll.mockImplementation(async (id, data) => reply(data));
        await open(); await cell('bonus').setValue('50'); await wrapper.get('[aria-label="Місяць"]').setValue('8'); await flushPromises(); await tick();
        expect(api.saveMonthlyPayroll).toHaveBeenCalledTimes(1); expect(api.saveMonthlyPayroll.mock.calls[0][1].month).toBe('2026-09');
        expect(api.fetchPayrollReport).toHaveBeenLastCalledWith('2026-08');
    });
    it('перехід у працівники не знищує незбережену таблицю, вихід зі сторінки захищений', async () => {
        await open(); await cell('bonus').setValue('bad'); await click('Працівники'); await tick();
        const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); expect(event.defaultPrevented).toBe(true);
        await click('Нарахування за місяць'); expect(cell('bonus').element.value).toBe('bad');
    });
    it('відрядні рядки редагують доплати, але не суму роботи з табеля', async () => {
        api.fetchPayrollReport.mockResolvedValue({ data: report({ rows: [row({ employee: pieceEmployee, employee_id: 102, rate_mode: 'piecework', base_pay: '900.00' })] }) });
        await open(); expect(wrapper.find('[data-field="rate"]').exists()).toBe(false);
        await cell('bonus', 102).setValue('75'); await tick();
        expect(api.saveMonthlyPayroll.mock.calls[0][1]).toMatchObject({ rate_mode: 'piecework', rate: null, bonus: '75.00' });
        expect(api.saveMonthlyPayroll.mock.calls[0][1]).not.toHaveProperty('base_pay');
    });
    it('Enter переходить вниз тієї ж колонки, а успіх іншого рядка не перекриває помилку', async () => {
        api.fetchPayrollReport.mockResolvedValue({ data: report({ rows: [row(), row({ employee: { ...employee, id: 104, name: 'Інша людина' }, employee_id: 104 })] }) });
        api.saveMonthlyPayroll.mockImplementation(async (id, data) => ({ data: { ...reply(data).data, employee_id: id, employee: { ...employee, id } } }));
        await open(); cell('bonus').element.focus(); await cell('bonus').trigger('keydown', { key: 'Enter' });
        expect(document.activeElement).toBe(cell('bonus', 104).element);
        await cell('bonus').setValue('bad'); await tick(); await cell('bonus', 104).setValue('60'); await tick(1500);
        expect(document.querySelector('.app-toast').textContent).toContain('Не всі зміни збережено');
        expect(document.querySelector('.app-toast').className).toContain('app-toast--error');
    });
});
