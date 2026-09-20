import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DOMWrapper, flushPromises, mount } from '@vue/test-utils';
import WorkTimePage from './WorkTimePage.vue';
import PieceworkDayPopover from './PieceworkDayPopover.vue';
import * as api from '../../services/workTimeApi';
import * as pieceApi from '../../services/pieceworkApi';
import { parseWorkHours, workDays } from '../../utils/workTime';

vi.mock('../../services/workTimeApi', () => ({ fetchWorkTime: vi.fn(), saveWorkDay: vi.fn(), createWorkEmployee: vi.fn(), updateWorkEmployee: vi.fn(), deleteWorkEmployee: vi.fn(), fetchWorkPayroll: vi.fn(), saveWorkPayroll: vi.fn() }));
vi.mock('../../services/pieceworkApi', () => ({ fetchPieceworkDays: vi.fn(), savePieceworkDay: vi.fn() }));
const period = '2026-09';
const employee = { id: 1, name: 'Тестова працівниця', position: 'Швачка', archived_on: null, version: 1 };
const pieceEmployee = { id: 2, name: 'Відрядний тест', payment_type: 'piecework', archived_on: null, version: 1 };
const record = (replace = {}) => ({ employee_id: 1, date: '2026-09-01', hours: '8.00', note: null, version: 1, updated_by: 'Тест', updated_at: '2026-09-01 10:00:00', ...replace });
const payroll = (replace = {}) => ({ employee_id: 1, month: period, hours: '8.00', hourly_rate: '50.00', bonus: '100.00', paid: '0.00', note: null, version: 1, ...replace });
let wrapper;
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const toastText = () => document.querySelector('.app-toast')?.textContent || '';
const toastButton = label => [...document.querySelectorAll('.app-toast button')].find(node => node.textContent.includes(label) || node.getAttribute('aria-label') === label);
async function clickToast(label) { toastButton(label).click(); await flushPromises(); }
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
    api.deleteWorkEmployee.mockResolvedValue({ data: { deleted: true } });
    pieceApi.fetchPieceworkDays.mockImplementation(async month => ({ data: { month, employees: [pieceEmployee], entries: [] } }));
    pieceApi.savePieceworkDay.mockImplementation(async (id, data) => ({ data: { employee_id: id, date: data.date, amount: data.amount, note: data.note, version: data.version + 1 } }));
});
afterEach(() => { wrapper?.unmount(); document.body.innerHTML = ''; vi.restoreAllMocks(); vi.useRealTimers(); });

describe('Табель робочого часу', () => {
    it('показує всі три підсумки в шапці поруч із заголовком', async () => {
        await open();
        const heading = wrapper.get('.wt-heading');
        expect(heading.get('.wt-title h1').text()).toBe('Табель і виконані роботи');
        expect(heading.findAll('.wt-stats > div')).toHaveLength(3);
        expect(heading.get('[data-testid="all-hours"]').text()).toContain('8');
        expect(heading.get('.wt-stats').text()).toContain('Заповнено по');
        expect(wrapper.findAll('.wt-stats')).toHaveLength(1);
    });
    async function openNote(date = '2026-09-20') {
        await wrapper.get(`[data-note-cell="2|${date}"]`).trigger('click'); await flushPromises();
        return new DOMWrapper(document.querySelector('.piece-day-popover'));
    }
    async function saveAmount(amount, date = '2026-09-20') {
        const editor = await openNote(date);
        await editor.get('input').setValue(amount); await editor.trigger('submit'); await flushPromises();
        return editor;
    }
    it('відрядна таблиця має лише суми та постійні кнопки, а години залишаються полями', async () => {
        await open();
        expect(wrapper.find('.wt-money-sheet input').exists()).toBe(false);
        expect(wrapper.find('.wt-money-sheet [contenteditable]').exists()).toBe(false);
        expect(wrapper.findAll('.wt-money-sheet [data-note-cell]')).toHaveLength(30);
        expect(wrapper.get('[data-money-cell="2|2026-09-20"]').element.tagName).toBe('SPAN');
        expect(cell().element.tagName).toBe('INPUT');
        const editor = await openNote(); await editor.get('input').setValue('34');
        await editor.get('textarea').setValue('Нарізання поролону');
        await vi.advanceTimersByTimeAsync(1000); await editor.get('input').trigger('blur');
        expect(pieceApi.savePieceworkDay).not.toHaveBeenCalled();
        await editor.trigger('submit'); await flushPromises();
        expect(wrapper.get('[data-note-cell="2|2026-09-20"]').attributes('title')).toBe('34 грн — Нарізання поролону');
        expect(wrapper.get('[data-money-cell="2|2026-09-20"]').text()).toBe('34');
    });
    it('зберігає суму та пояснення разом через компактне віконце', async () => {
        await open(); const editor = await openNote();
        expect(editor.get('textarea').attributes('rows')).toBe('2');
        expect(editor.text()).toContain('20 вересня');
        await editor.get('input').setValue('300,50');
        await editor.get('textarea').setValue('Допомога у вихідний');
        expect(pieceApi.savePieceworkDay).not.toHaveBeenCalled();
        await editor.trigger('submit'); await flushPromises();
        expect(pieceApi.savePieceworkDay).toHaveBeenCalledWith(2, { date: '2026-09-20', amount: '300.50', note: 'Допомога у вихідний', version: 0 });
        expect(wrapper.findComponent(PieceworkDayPopover).exists()).toBe(false);
        const icon = wrapper.get('[data-note-cell="2|2026-09-20"]');
        expect(icon.classes()).toContain('has-note'); expect(icon.attributes('title')).toBe('300,5 грн — Допомога у вихідний');
        expect(document.activeElement).toBe(icon.element);
        expect(wrapper.get('[data-testid="piece-total-2"]').text()).toBe('300,5');
    });
    it('скасування не зберігає локальну чернетку, а Escape та зміна місяця її не стирають', async () => {
        await open(); const editor = await openNote();
        await editor.get('textarea').setValue('Ще не збережено');
        await editor.trigger('keydown', { key: 'Escape' }); await flushPromises();
        expect(toastText()).toContain('Є незбережені зміни');
        await wrapper.get('[aria-label="Місяць"]').setValue('10'); await flushPromises();
        expect(pieceApi.fetchPieceworkDays).toHaveBeenCalledTimes(1);
        expect(editor.get('textarea').element.value).toBe('Ще не збережено');
        const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);
        await editor.get('.piece-cancel').trigger('click'); await flushPromises();
        expect(wrapper.findComponent(PieceworkDayPopover).exists()).toBe(false);
        expect(pieceApi.savePieceworkDay).not.toHaveBeenCalled();
        expect(wrapper.get('[data-note-cell="2|2026-09-20"]').classes()).not.toContain('has-note');
    });
    it('не втрачає пояснення після помилки, повторює з тією самою версією', async () => {
        pieceApi.savePieceworkDay.mockRejectedValueOnce(new Error('network'));
        await open(); const editor = await openNote();
        await editor.get('input').setValue('300'); await editor.get('textarea').setValue('Розкрій');
        await editor.trigger('submit'); await flushPromises();
        expect(editor.get('textarea').element.value).toBe('Розкрій');
        expect(editor.get('input').attributes('aria-invalid')).toBe('true');
        expect(document.querySelector('.app-toast').getAttribute('role')).toBe('alert');
        await editor.trigger('submit'); await flushPromises();
        expect(pieceApi.savePieceworkDay).toHaveBeenCalledTimes(2);
        expect(pieceApi.savePieceworkDay.mock.calls[1]).toEqual(pieceApi.savePieceworkDay.mock.calls[0]);
        expect(wrapper.findComponent(PieceworkDayPopover).exists()).toBe(false);
    });
    it('повторне відкриття невдалої чернетки не записує її без кнопки збереження', async () => {
        pieceApi.savePieceworkDay.mockRejectedValueOnce(new Error('network'));
        await open(); const editor = await saveAmount('34');
        await editor.get('.piece-cancel').trigger('click'); await flushPromises();
        const reopened = await openNote(); await vi.advanceTimersByTimeAsync(1000);
        expect(reopened.get('input').element.value).toBe('34');
        expect(pieceApi.savePieceworkDay).toHaveBeenCalledTimes(1);
        expect(wrapper.get('[data-money-cell="2|2026-09-20"]').text()).toBe('');
        await reopened.trigger('submit'); await flushPromises();
        expect(wrapper.get('[data-money-cell="2|2026-09-20"]').text()).toBe('34');
    });
    it('залишає пояснення при редагуванні суми у формі та дозволяє окремо його очистити', async () => {
        pieceApi.fetchPieceworkDays.mockResolvedValue({ data: { month: period, employees: [pieceEmployee], entries: [
            { employee_id: 2, date: '2026-09-20', amount: '300.00', note: 'Розкрій', version: 1 },
        ] } });
        await open(); await saveAmount('600');
        expect(pieceApi.savePieceworkDay).toHaveBeenLastCalledWith(2, expect.objectContaining({ amount: '600.00', note: 'Розкрій', version: 1 }));
        const editor = await openNote(); await editor.get('textarea').setValue('');
        await editor.trigger('submit'); await flushPromises();
        expect(pieceApi.savePieceworkDay).toHaveBeenLastCalledWith(2, expect.objectContaining({ amount: '600.00', note: null, version: 2 }));
        expect(wrapper.get('[data-note-cell="2|2026-09-20"]').classes()).not.toContain('has-note');
    });
    it('помилкова сума лишається у віконці без запиту та підсвічується', async () => {
        await open(); const editor = await openNote(); await editor.get('input').setValue('-20');
        await editor.trigger('submit'); await flushPromises();
        expect(pieceApi.savePieceworkDay).not.toHaveBeenCalled();
        expect(editor.get('input').attributes('aria-invalid')).toBe('true');
        expect(toastText()).toContain('Перевірте суму');
        await cell().setValue('7'); await cell().trigger('blur'); await flushPromises();
        expect(toastText()).toContain('Перевірте суму');
        expect(toastText()).not.toContain('Усі зміни збережено');
    });
    it('не відкриває пояснення оператору чи після дати архівації', async () => {
        await open({ canManagePay: false }); expect(wrapper.find('[data-note-cell]').exists()).toBe(false);
        wrapper.unmount();
        pieceApi.fetchPieceworkDays.mockResolvedValue({ data: { month: period, employees: [{ ...pieceEmployee, archived_on: '2026-09-19' }], entries: [] } });
        await open(); expect(wrapper.get('[data-note-cell="2|2026-09-20"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-note-cell="2|2026-09-19"]').attributes('disabled')).toBeUndefined();
    });
    it.each([true, false])('не має керування працівниками або модальних форм, власник: %s', async canManagePay => {
        await open({ canManagePay });
        expect(wrapper.find('dialog').exists()).toBe(false);
        expect(wrapper.find('.wt-delete').exists()).toBe(false);
        expect(wrapper.find('.wt-heading button').exists()).toBe(false);
        expect(wrapper.find('.wt-person button').exists()).toBe(false);
        await wrapper.get('[data-testid="employee-1"]').trigger('click');
        expect(api.fetchWorkPayroll).not.toHaveBeenCalled();
        expect(api.createWorkEmployee).not.toHaveBeenCalled();
        expect(api.updateWorkEmployee).not.toHaveBeenCalled();
        expect(api.deleteWorkEmployee).not.toHaveBeenCalled();
        expect(pieceApi.fetchPieceworkDays).toHaveBeenCalledTimes(canManagePay ? 1 : 0);
    });
    it('не повідомляє про збереження при відкритті, успіх зникає після реального запису', async () => {
        await open(); expect(document.querySelector('.app-toast')).toBeNull();
        await cell().setValue('7'); await cell().trigger('blur'); await flushPromises();
        expect(toastText()).toContain('Усі зміни збережено');
        expect(document.querySelector('.app-toast').getAttribute('role')).toBe('status');
        expect(wrapper.find('.wt-save-state').exists()).toBe(false);
        await vi.advanceTimersByTimeAsync(4000); await flushPromises();
        expect(document.querySelector('.app-toast')).toBeNull();
    });
    it('помилка містить працівника і дату, не зникає та відкривається повторно', async () => {
        await open(); await cell().setValue('25'); await cell().trigger('blur'); await flushPromises();
        expect(toastText()).toContain(employee.name); expect(toastText()).toContain('2026-09-01');
        expect(document.querySelector('.app-toast').getAttribute('role')).toBe('alert');
        expect(cell().attributes('aria-invalid')).toBe('true');
        expect(wrapper.find('.wt-errors').exists()).toBe(false);
        await vi.advanceTimersByTimeAsync(10000); expect(toastText()).toContain('Введіть від 0 до 24');
        await clickToast('Закрити');
        await wrapper.get('[aria-label="Показати помилки табеля"]').trigger('click'); await flushPromises();
        expect(toastText()).toContain('Введіть від 0 до 24');
    });
    it('успіх іншої клітинки не перекриває невиправлену помилку', async () => {
        await open(); await cell().setValue('25'); await cell().trigger('blur'); await flushPromises();
        await saveAmount('600', '2026-09-09');
        expect(toastText()).toContain('Введіть від 0 до 24');
        expect(toastText()).not.toContain('Усі зміни збережено');
        expect(document.querySelectorAll('.app-toast')).toHaveLength(1);
    });
    it('помилка завантаження показується у toast і має повтор', async () => {
        api.fetchWorkTime.mockRejectedValueOnce({ response: { status: 500, data: { message: 'Табель недоступний' } } });
        await open(); expect(toastText()).toContain('Табель недоступний');
        expect(wrapper.find('.alert-danger').exists()).toBe(false);
        await clickToast('Оновити табель');
        expect(api.fetchWorkTime).toHaveBeenCalledTimes(2);
        expect(cell().element.value).toBe('8');
        expect(toastText()).toContain('Табель оновлено');
    });
    it('закриття попередження не відкидає чернетки та не викликає браузерну модалку', async () => {
        await open(); await cell().setValue('25'); await cell().trigger('blur'); await flushPromises();
        await wrapper.get('[aria-label="Оновити табель"]').trigger('click'); await flushPromises();
        expect(toastText()).toContain('Незбережені зміни буде втрачено');
        await clickToast('Залишити зміни');
        expect(cell().element.value).toBe('25');
        expect(api.fetchWorkTime).toHaveBeenCalledTimes(1);
        expect(window.confirm).not.toHaveBeenCalled();
    });






    it('має однакову сітку колонок для годин і сум у місяцях різної довжини', async () => {
        await open();
        for (const [month, count] of [['9', 30], ['10', 31], ['2', 28]]) {
            if (month !== '9') { await wrapper.get('[aria-label="Місяць"]').setValue(month); await flushPromises(); }
            const tables = wrapper.findAll('table.wt-calendar');
            expect(tables).toHaveLength(2);
            for (const table of tables) {
                expect(table.element.style.getPropertyValue('--wt-day-count')).toBe(String(count));
                expect(table.findAll('colgroup .wt-day-col')).toHaveLength(count);
                expect(table.get('colgroup col:first-child').classes()).toContain('wt-person-col');
                expect(table.get('colgroup col:last-child').classes()).toContain('wt-total-col');
            }
            expect(tables[0].get('colgroup').html()).toBe(tables[1].get('colgroup').html());
        }
    });
    it('не обрізає саме значення довгої суми при компактному відображенні', async () => {
        await open(); await saveAmount('123456,78', '2026-09-09');
        expect(pieceApi.savePieceworkDay).toHaveBeenCalledWith(2, expect.objectContaining({ amount: '123456.78' }));
        const display = wrapper.get('[data-money-cell="2|2026-09-09"]');
        expect(display.text().replace(/\s/g, '')).toBe('123456,78');
        expect(display.attributes('title').replace(/\s/g, '')).toBe('123456,78грн');
        const editor = await openNote('2026-09-09'); expect(editor.get('input').element.value).toBe('123456.78');
    });

    it('зберігає 600 і 300 у різні дні та показує 900 без полів кількості', async () => {
        await open();
        await saveAmount('600', '2026-09-09'); await saveAmount('300');
        expect(pieceApi.savePieceworkDay).toHaveBeenCalledWith(2, { date: '2026-09-09', amount: '600.00', note: null, version: 0 });
        expect(wrapper.get('[data-testid="piece-total-2"]').text()).toBe('900');
        expect(wrapper.get('[data-testid="piece-all-total"]').text()).toBe('900');
        expect(wrapper.find('[name="piece_quantity"]').exists()).toBe(false);
        expect(api.saveWorkDay).not.toHaveBeenCalled();
    });
    it('блокує форму під час запиту, а після підтвердження дозволяє нову зміну', async () => {
        const first = deferred(); pieceApi.savePieceworkDay.mockImplementationOnce(() => first.promise);
        await open(); const editor = await saveAmount('600', '2026-09-09');
        expect(editor.get('input').attributes('disabled')).toBeDefined();
        expect(editor.get('.piece-save').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-money-cell="2|2026-09-09"]').text()).toBe('');
        first.resolve({ data: { employee_id: 2, date: '2026-09-09', amount: '600.00', note: null, version: 1 } }); await flushPromises();
        await saveAmount('650,25', '2026-09-09');
        expect(pieceApi.savePieceworkDay).toHaveBeenLastCalledWith(2, expect.objectContaining({ amount: '650.25', version: 1 }));
        expect(wrapper.get('[data-money-cell="2|2026-09-09"]').text()).toBe('650,25');
    });
    it('помилка грошової клітинки не дає загубити дані при зміні місяця', async () => {
        pieceApi.savePieceworkDay.mockRejectedValue({ response: { status: 409, data: { message: 'Суму вже змінили' } } });
        await open(); const editor = await saveAmount('600', '2026-09-09');
        await wrapper.get('[aria-label="Місяць"]').setValue('10'); await flushPromises();
        expect(wrapper.get('[aria-label="Місяць"]').element.value).toBe('9');
        expect(editor.get('input').element.value).toBe('600'); expect(toastText()).toContain('Суму вже змінили');
    });
    it('оновлює обидві таблиці при виборі року та місяця', async () => {
        await open(); await wrapper.get('[aria-label="Місяць"]').setValue('10'); await flushPromises();
        expect(api.fetchWorkTime).toHaveBeenLastCalledWith('2026-10'); expect(pieceApi.fetchPieceworkDays).toHaveBeenLastCalledWith('2026-10');
        expect(wrapper.find('[data-money-cell="2|2026-10-31"]').exists()).toBe(true);
        expect(wrapper.find('[data-money-cell="2|2026-09-01"]').exists()).toBe(false);
    });
    it('від’ємні суми не надсилає, очищення і явний нуль розрізняє', async () => {
        await open(); const editor = await saveAmount('-1', '2026-09-09');
        expect(pieceApi.savePieceworkDay).not.toHaveBeenCalled();
        await editor.get('input').setValue('0'); await editor.trigger('submit'); await flushPromises();
        expect(pieceApi.savePieceworkDay).toHaveBeenLastCalledWith(2, expect.objectContaining({ amount: '0.00' }));
        expect(wrapper.get('[data-money-cell="2|2026-09-09"]').text()).toBe('0');
        expect(wrapper.get('[data-note-cell="2|2026-09-09"]').attributes('title')).toBe('0 грн');
        await saveAmount('', '2026-09-09');
        expect(pieceApi.savePieceworkDay).toHaveBeenLastCalledWith(2, expect.objectContaining({ amount: null, version: 1 }));
        expect(wrapper.get('[data-money-cell="2|2026-09-09"]').text()).toBe('');
    });
    it('не додає відрядних працівників у сітку годин', async () => {
        api.fetchWorkTime.mockResolvedValue({ data: { month: period, employees: [employee, { ...employee, id: 2, name: 'Відрядний тест', payment_type: 'piecework' }], entries: [], can_manage_pay: true } });
        await open(); expect(wrapper.find('[data-testid="employee-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="employee-2"]').exists()).toBe(true);
        expect(wrapper.find('[data-cell="2|2026-09-01"]').exists()).toBe(false);
        expect(wrapper.find('[data-money-cell="2|2026-09-01"]').exists()).toBe(true);
        expect(wrapper.findAll('table')).toHaveLength(2);
        expect(wrapper.find('[aria-label="Тип обліку"]').exists()).toBe(false);
    });
    it('відкриває поточний місяць без запису чи завантаження зарплат', async () => {
        await open(); expect(api.fetchWorkTime).toHaveBeenCalledWith(period);
        expect(wrapper.get('[data-testid="all-hours"]').text()).toContain('8');
        expect(cell().element.value).toBe('8');
        expect(api.saveWorkDay).not.toHaveBeenCalled(); expect(api.fetchWorkPayroll).not.toHaveBeenCalled();
        expect(wrapper.find('table').text()).not.toContain('грн');
    });

    it('автозбереження приймає кому і використовує версію клітинки', async () => {
        await open(); await cell().setValue('7,5'); await vi.advanceTimersByTimeAsync(650); await flushPromises();
        expect(api.saveWorkDay).toHaveBeenCalledWith(1, { date: '2026-09-01', hours: '7.50', note: null, version: 1 });
        expect(wrapper.get('[data-testid="all-hours"]').text()).toContain('7,5');
        expect(toastText()).toContain('Усі зміни збережено');
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
        expect(toastText()).toContain('Не вдалося'); expect(cell().element.value).toBe('7');
        await clickToast('Повторити');
        expect(api.saveWorkDay).toHaveBeenCalledTimes(2); expect(toastText()).toContain('Усі зміни збережено');
    });
    it('конфлікт не перезаписує чужу клітинку автоматично', async () => {
        api.saveWorkDay.mockRejectedValue({ response: { status: 409, data: { message: 'Цей день уже змінили' } } });
        await open(); await cell().setValue('7'); await cell().trigger('blur'); await flushPromises();
        expect(toastText()).toContain('Цей день уже змінили');
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
        expect(toastText()).toContain('Незбережені зміни буде втрачено');
        await clickToast('Відкинути зміни й оновити');
        await vi.advanceTimersByTimeAsync(700); await flushPromises();
        expect(api.saveWorkDay).not.toHaveBeenCalled(); expect(cell().element.value).toBe('8');
    });
    it('не надсилає неправильні години, зберігає очищення як null', async () => {
        await open(); await cell().setValue('25'); await cell().trigger('blur'); await flushPromises();
        expect(api.saveWorkDay).not.toHaveBeenCalled(); expect(toastText()).toContain('Введіть від 0 до 24');
        await cell().setValue(''); await cell().trigger('blur'); await flushPromises();
        expect(api.saveWorkDay).toHaveBeenCalledWith(1, expect.objectContaining({ hours: null }));
    });
    it('зміна місяця чекає збереження та відкриває інший період', async () => {
        await open(); await cell().setValue('7'); await wrapper.get('select[aria-label="Місяць"]').setValue('10'); await flushPromises();
        expect(api.saveWorkDay).toHaveBeenCalled(); expect(api.fetchWorkTime).toHaveBeenLastCalledWith('2026-10');
        expect(wrapper.get('[data-testid="all-hours"]').text()).toContain('0');
    });





});

describe('Календар і години', () => {
    it('розрізняє роки й високосний лютий', () => { expect(workDays('2028-02')).toHaveLength(29); expect(workDays('2027-02')).toHaveLength(28); });
    it('розрізняє порожнє поле й нуль', () => { expect(parseWorkHours('')).toBeNull(); expect(parseWorkHours('0')).toBe('0.00'); expect(parseWorkHours('7,5')).toBe('7.50'); });
});
