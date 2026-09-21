import { computed, onBeforeUnmount, ref } from 'vue';
import { saveMonthlyPayroll } from '../services/workPayrollApi';
import { workError } from '../utils/workTime';

const fields = ['rate_mode', 'rate', 'monthly_salary', 'bonus', 'expenses', 'paid'];
const valuesFrom = row => ({ rate_mode: row.rate_mode, rate: (row.rate_mode === 'daily' ? row.daily_rate : row.hourly_rate) ?? '',
    monthly_salary: row.monthly_salary ?? '0.00', bonus: row.bonus, expenses: row.expenses, paid: row.paid });
const equal = (a, b) => fields.every(key => a[key] === b[key]);
export function payrollAmount(value, nullable = false) {
    const text = String(value ?? '').trim().replace(',', '.');
    if (!text) return nullable ? null : '0.00';
    if (!/^\d{1,7}(\.\d{1,2})?$/.test(text) || Number(text) > 1000000) throw new Error('Введіть суму від 0 до 1 000 000 грн, до двох знаків після коми.');
    return Number(text).toFixed(2);
}

// Один запит на працівника одночасно; новіші чернетки не губляться під час відповіді.
export function usePayrollLedger(notify, requestReload) {
    const rows = ref([]), timers = new Map(), jobs = new Map();
    let alive = true, successTimer;
    const hasUnsaved = computed(() => rows.value.some(s => s.saving || s.pending || s.error || !equal(s.values, s.saved)));
    const errors = computed(() => rows.value.filter(s => s.error));
    const saving = computed(() => rows.value.some(s => s.saving));
    function reset(report) {
        timers.forEach(clearTimeout); timers.clear(); clearTimeout(successTimer);
        rows.value = report.rows.map(server => ({ server, values: valuesFrom(server), saved: valuesFrom(server),
            saving: false, pending: null, error: '', invalid: {}, conflict: false }));
    }
    function showErrors() {
        if (!errors.value.length) return;
        const conflict = errors.value.some(s => s.conflict);
        notify({ type: 'error', title: 'Не всі зміни збережено', messages: errors.value.map(s => `${s.server.employee.name} · ${s.server.month}: ${s.error}`),
            actionLabel: conflict ? 'Оновити дані…' : 'Повторити', onAction: conflict ? requestReload : () => flush(true) });
    }
    function savedToast() {
        clearTimeout(successTimer);
        successTimer = setTimeout(() => {
            if (alive && !hasUnsaved.value) notify({ type: 'success', title: 'Нарахування збережено', messages: ['Зміни записані в базу даних.'] });
        }, 700);
    }
    function edit(state, field, value) {
        clearTimeout(successTimer);
        state.values[field] = value;
        clearTimeout(timers.get(state.server.employee_id));
        // Конфлікт не перезаписуємо автоматично. Невизначений мережевий результат повторюємо явно.
        if (state.conflict || state.pending && state.error) return;
        timers.set(state.server.employee_id, setTimeout(() => save(state), 600));
    }
    function payload(state) {
        const values = { rate_mode: state.values.rate_mode };
        state.invalid = {};
        for (const field of ['rate', 'monthly_salary', 'bonus', 'expenses', 'paid']) {
            try { values[field] = field === 'rate' && values.rate_mode === 'piecework' ? null : payrollAmount(state.values[field], field === 'rate'); }
            catch (error) { state.invalid[field] = true; state.error = error.message; }
        }
        if (Object.keys(state.invalid).length) return null;
        return { ...values, month: state.server.month, version: state.server.version,
            adjustment: state.server.adjustment, adjustment_reason: state.server.adjustment_reason, note: state.server.note };
    }
    async function drain(state, retry) {
        if (state.conflict || state.error && state.pending && !retry) { showErrors(); return false; }
        if (!state.pending && equal(state.values, state.saved)) {
            state.error = ''; state.invalid = {};
        }
        while (alive && (state.pending || !equal(state.values, state.saved))) {
            if (!state.pending) {
                state.error = '';
                const data = payload(state);
                if (!data) { showErrors(); return false; }
                state.pending = { data, snapshot: { ...state.values } };
            }
            const attempt = state.pending;
            state.saving = true;
            try {
                const { data } = await saveMonthlyPayroll(state.server.employee_id, attempt.data);
                if (!alive) return false;
                state.server = data;
                const normalized = valuesFrom(data);
                // Нормалізуємо лише незмінені після відправки клітинки.
                for (const field of fields) if (state.values[field] === attempt.snapshot[field]) state.values[field] = normalized[field];
                state.saved = normalized; state.pending = null; state.error = ''; state.invalid = {}; state.conflict = false;
            } catch (error) {
                if (!alive) return false;
                state.error = workError(error);
                state.invalid = error?.response?.data?.errors || {};
                state.conflict = error?.response?.status === 409;
                // Після втрати відповіді повторюємо той самий запит і версію, потім новіші зміни.
                if (error?.response?.status && error.response.status < 500) state.pending = null;
                showErrors(); return false;
            } finally { state.saving = false; }
        }
        if (!alive) return false;
        if (errors.value.length) showErrors(); else savedToast();
        return !state.error;
    }
    function save(state, retry = false) {
        const id = state.server.employee_id;
        clearTimeout(timers.get(id)); timers.delete(id);
        if (jobs.has(id)) return jobs.get(id);
        // Читання/фокус незміненої клітинки не створює запису й не показує успіх.
        if (!state.pending && !state.error && equal(state.values, state.saved)) return Promise.resolve(true);
        const job = drain(state, retry).finally(() => jobs.delete(id));
        jobs.set(id, job); return job;
    }
    async function flush(retry = false) {
        await Promise.all(rows.value.map(s => save(s, retry)));
        if (errors.value.length) showErrors();
        return !hasUnsaved.value;
    }
    onBeforeUnmount(() => { alive = false; timers.forEach(clearTimeout); clearTimeout(successTimer); });
    return { rows, hasUnsaved, errors, saving, reset, edit, save, flush, showErrors };
}
