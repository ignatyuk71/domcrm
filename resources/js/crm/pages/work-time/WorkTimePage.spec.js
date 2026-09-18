import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import WorkTimePage from './WorkTimePage.vue';
import * as api from '../../services/workTimeApi';
import { parseWorkHours, workDays } from '../../utils/workTime';

vi.mock('../../services/workTimeApi', () => ({ fetchWorkTime: vi.fn(), saveWorkDay: vi.fn(), createWorkEmployee: vi.fn(), updateWorkEmployee: vi.fn(), fetchWorkPayroll: vi.fn(), saveWorkPayroll: vi.fn() }));
const period = '2026-09';
const employee = { id: 1, name: 'Тестова працівниця', position: 'Швачка', archived_on: null, version: 1 };
const record = (replace = {}) => ({ employee_id: 1, date: '2026-09-01', hours: '8.00', note: null, version: 1, updated_by: 'Тест', updated_at: '2026-09-01 10:00:00', ...replace });
const payroll = (replace = {}) => ({ employee_id: 1, month: period, hours: '8.00', hourly_rate: '50.00', bonus: '100.00', paid: '0.00', note: null, version: 1, ...replace });
let wrapper;
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const button = label => wrapper.findAll('button').find(node => node.text().includes(label));
const cell = () => wrapper.get('[data-cell="1|2026-09-01"]');
async function open(props = { canManagePay: true }) { wrapper = mount(WorkTimePage, { props, attachTo: document.body }); await flushPromises(); }
beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date', 'setTimeout', 'clearTimeout'] }); vi.setSystemTime(new Date('2026-09-18T12:00:00Z'));
    vi.clearAllMocks(); vi.spyOn(window, 'confirm').mockReturnValue(true);
    vi.spyOn(HTMLDialogElement.prototype, 'showModal').mockImplementation(function () { this.setAttribute('open', ''); });
    vi.spyOn(HTMLDialogElement.prototype, 'close').mockImplementation(function () { this.removeAttribute('open'); });
    api.fetchWorkTime.mockImplementation(async month => ({ data: { month, employees: [{ ...employee }], entries: month === period ? [record()] : [], can_manage_pay: true } }));
    api.saveWorkDay.mockImplementation(async (id, data) => ({ data: record({ ...data, employee_id: id, version: data.version + 1 }) }));
    api.fetchWorkPayroll.mockResolvedValue({ data: payroll() });
    api.saveWorkPayroll.mockImplementation(async (id, data) => ({ data: payroll({ ...data, version: data.version + 1 }) }));
    api.createWorkEmployee.mockResolvedValue({ data: { ...employee, id: 2 } });
    api.updateWorkEmployee.mockResolvedValue({ data: employee });
});
afterEach(() => { wrapper?.unmount(); document.body.innerHTML = ''; vi.restoreAllMocks(); vi.useRealTimers(); });

describe('Табель робочого часу', () => {
    it('не додає відрядних працівників у сітку годин', async () => {
        api.fetchWorkTime.mockResolvedValue({ data: { month: period, employees: [employee, { ...employee, id: 2, name: 'Відрядний тест', payment_type: 'piecework' }], entries: [], can_manage_pay: true } });
        await open(); expect(wrapper.find('[data-testid="employee-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="employee-2"]').exists()).toBe(false);
        expect(wrapper.find('[data-cell="2|2026-09-01"]').exists()).toBe(false);
        expect(button('Відрядні роботи')).toBeDefined();
    });
    it('відкриває поточний місяць без запису чи завантаження зарплат', async () => {
        await open(); expect(api.fetchWorkTime).toHaveBeenCalledWith(period);
        expect(wrapper.get('[data-testid="all-hours"]').text()).toContain('8');
        expect(cell().element.value).toBe('8');
        expect(api.saveWorkDay).not.toHaveBeenCalled(); expect(api.fetchWorkPayroll).not.toHaveBeenCalled();
        expect(wrapper.find('table').text()).not.toContain('грн');
    });
    it('оператор має картку годин без зарплатної вкладки й запитів', async () => {
        api.fetchWorkTime.mockResolvedValue({ data: { month: period, employees: [employee], entries: [], can_manage_pay: false } });
        await open({ canManagePay: false }); await wrapper.get('[data-testid="employee-1"]').trigger('click'); await flushPromises();
        expect(wrapper.find('dialog').attributes('open')).toBeDefined();
        expect(wrapper.find('[data-testid="pay-tab"]').exists()).toBe(false);
        expect(wrapper.find('[name="hourly_rate"]').exists()).toBe(false);
        expect(api.fetchWorkPayroll).not.toHaveBeenCalled();
        expect(button('Додати працівника')).toBeUndefined();
        expect(button('Відрядні роботи')).toBeUndefined();
    });
    it('автозбереження приймає кому і використовує версію клітинки', async () => {
        await open(); await cell().setValue('7,5'); await vi.advanceTimersByTimeAsync(650); await flushPromises();
        expect(api.saveWorkDay).toHaveBeenCalledWith(1, { date: '2026-09-01', hours: '7.50', note: null, version: 1 });
        expect(wrapper.get('[data-testid="all-hours"]').text()).toContain('7,5');
        expect(wrapper.text()).toContain('Усі зміни збережено');
    });
    it('не втрачає новий текст під час незавершеного запиту', async () => {
        const first = deferred(); api.saveWorkDay.mockImplementationOnce(() => first.promise);
        await open(); await cell().setValue('7'); await cell().trigger('blur'); await flushPromises();
        await cell().setValue('6');
        first.resolve({ data: record({ hours: '7.00', version: 2 }) }); await flushPromises();
        expect(api.saveWorkDay).toHaveBeenLastCalledWith(1, expect.objectContaining({ hours: '6.00', version: 2 }));
        expect(cell().element.value).toBe('6');
    });
    it('показує мережеву помилку і повторює ті самі дані без втрати тексту', async () => {
        api.saveWorkDay.mockRejectedValueOnce(new Error('offline'));
        await open(); await cell().setValue('7'); await cell().trigger('blur'); await flushPromises();
        expect(wrapper.text()).toContain('Є незбережені зміни'); expect(cell().element.value).toBe('7');
        await button('Повторити').trigger('click'); await flushPromises();
        expect(api.saveWorkDay).toHaveBeenCalledTimes(2); expect(wrapper.text()).toContain('Усі зміни збережено');
    });
    it('конфлікт не перезаписує чужу клітинку автоматично', async () => {
        api.saveWorkDay.mockRejectedValue({ response: { status: 409, data: { message: 'Цей день уже змінили' } } });
        await open(); await cell().setValue('7'); await cell().trigger('blur'); await flushPromises();
        expect(wrapper.text()).toContain('Цей день уже змінили');
        await cell().trigger('blur'); await flushPromises(); expect(api.saveWorkDay).toHaveBeenCalledTimes(1);
        await wrapper.get('select[aria-label="Місяць"]').setValue('10'); await flushPromises();
        expect(api.fetchWorkTime).toHaveBeenCalledTimes(1); expect(wrapper.get('select[aria-label="Місяць"]').element.value).toBe('9');
    });
    it('після втрати відповіді повернення до старого значення теж перевіряється сервером', async () => {
        api.saveWorkDay.mockRejectedValueOnce(new Error('response lost'));
        await open(); await cell().setValue('7'); await cell().trigger('blur'); await flushPromises();
        await cell().setValue('8'); await cell().trigger('blur'); await flushPromises();
        expect(api.saveWorkDay).toHaveBeenCalledTimes(2);
        expect(api.saveWorkDay).toHaveBeenLastCalledWith(1, expect.objectContaining({ hours: '8.00', version: 1 }));
    });
    it('підтверджене відкидання чернетки скасовує таймер автозбереження', async () => {
        await open(); await cell().setValue('7');
        await wrapper.get('button[aria-label="Оновити табель"]').trigger('click'); await flushPromises();
        await vi.advanceTimersByTimeAsync(700); await flushPromises();
        expect(api.saveWorkDay).not.toHaveBeenCalled(); expect(cell().element.value).toBe('8');
    });
    it('не надсилає неправильні години, зберігає очищення як null', async () => {
        await open(); await cell().setValue('25'); await cell().trigger('blur'); await flushPromises();
        expect(api.saveWorkDay).not.toHaveBeenCalled(); expect(wrapper.text()).toContain('Введіть від 0 до 24');
        await cell().setValue(''); await cell().trigger('blur'); await flushPromises();
        expect(api.saveWorkDay).toHaveBeenCalledWith(1, expect.objectContaining({ hours: null }));
    });
    it('зміна місяця чекає збереження та відкриває інший період', async () => {
        await open(); await cell().setValue('7'); await wrapper.get('select[aria-label="Місяць"]').setValue('10'); await flushPromises();
        expect(api.saveWorkDay).toHaveBeenCalled(); expect(api.fetchWorkTime).toHaveBeenLastCalledWith('2026-10');
        expect(wrapper.get('[data-testid="all-hours"]').text()).toContain('0');
    });
    it('модальне вікно редагує ті самі години, що і таблиця', async () => {
        await open(); await wrapper.get('[data-testid="employee-1"]').trigger('click'); await flushPromises();
        await wrapper.get('dialog select').setValue('2026-09-01');
        await wrapper.get('[name="day_hours"]').setValue('6'); await button('Зберегти день').trigger('click'); await flushPromises();
        expect(cell().element.value).toBe('6'); expect(wrapper.find('dialog').attributes('open')).toBeDefined();
        await button('Готово').trigger('click'); await flushPromises(); expect(wrapper.find('dialog').attributes('open')).toBeUndefined();
    });
    it('зарплата завантажується лише при відкритті нарахувань і зберігається явно', async () => {
        await open(); await wrapper.get('[data-testid="employee-1"]').trigger('click'); await flushPromises();
        await wrapper.get('[data-testid="pay-tab"]').trigger('click'); await flushPromises();
        expect(api.fetchWorkPayroll).toHaveBeenCalledWith(1, period);
        await wrapper.get('[name="bonus"]').setValue('200'); expect(wrapper.get('[data-testid="balance"]').text()).toContain('600');
        expect(api.saveWorkPayroll).not.toHaveBeenCalled(); await button('Зберегти нарахування').trigger('submit'); await flushPromises();
        expect(api.saveWorkPayroll).toHaveBeenCalledWith(1, expect.objectContaining({ hourly_rate: '50.00', bonus: '200.00', paid: '0.00', month: period, version: 1 }));
    });
    it('відсутня ставка не показує нульову зарплату', async () => {
        api.fetchWorkPayroll.mockResolvedValue({ data: payroll({ hourly_rate: null, version: 0 }) });
        await open(); await wrapper.get('[data-testid="employee-1"]').trigger('click'); await flushPromises(); await wrapper.get('[data-testid="pay-tab"]').trigger('click'); await flushPromises();
        expect(wrapper.get('[data-testid="balance"]').text()).toBe('—');
    });
    it('нарахування використовують актуальні години сервера, а не старий табель', async () => {
        api.fetchWorkPayroll.mockResolvedValue({ data: payroll({ hours: '10.00' }) });
        await open(); await wrapper.get('[data-testid="employee-1"]').trigger('click'); await flushPromises();
        await wrapper.get('[data-testid="pay-tab"]').trigger('click'); await flushPromises();
        expect(wrapper.get('[data-testid="balance"]').text()).toContain('600');
        expect(wrapper.get('.wt-month-total').text()).toContain('10 год');
    });
    it('працівника додає лише явно та повторює ключ після помилки', async () => {
        api.createWorkEmployee.mockRejectedValueOnce(new Error('offline'));
        await open(); await button('Додати працівника').trigger('click'); await flushPromises();
        await wrapper.get('[name="employee_name"]').setValue('Тест');
        const form = wrapper.get('.wt-employee-dialog form'); await form.trigger('submit'); await flushPromises();
        const token = api.createWorkEmployee.mock.calls[0][0].request_key;
        await form.trigger('submit'); await flushPromises(); expect(api.createWorkEmployee.mock.calls[1][0].request_key).toBe(token);
    });
});

describe('Календар і години', () => {
    it('розрізняє роки й високосний лютий', () => { expect(workDays('2028-02')).toHaveLength(29); expect(workDays('2027-02')).toHaveLength(28); });
    it('розрізняє порожнє поле й нуль', () => { expect(parseWorkHours('')).toBeNull(); expect(parseWorkHours('0')).toBe('0.00'); expect(parseWorkHours('7,5')).toBe('7.50'); });
});
