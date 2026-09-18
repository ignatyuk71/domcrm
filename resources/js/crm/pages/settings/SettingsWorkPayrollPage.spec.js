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
const row = (replace = {}) => ({ employee, employee_id: 101, month: '2026-09', version: 1, rate_mode: 'daily', hours: '12.00', days: 2,
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
    it('читає звіт без створення записів, показує ставки, суми й підсумки', async () => {
        await open();
        expect(api.fetchPayrollReport).toHaveBeenCalledWith('2026-09');
        expect(wrapper.get('[data-testid="payroll-row-101"]').text()).toContain('350 грн / 8 год');
        expect(wrapper.get('.wp-stats').text()).toContain('525 грн');
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
        expect(wrapper.get('[name="payment_type"]').attributes('disabled')).toBeDefined();
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
        expect(wrapper.get('[data-testid="payroll-row-101"]').text()).toContain('Не вказано');
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
        expect(wrapper.get('.wp-card-heading').text()).toContain('Серпень 2026');
        expect(api.saveMonthlyPayroll).not.toHaveBeenCalled();
    });
});
