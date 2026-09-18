import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { fetchWorkTime, saveWorkDay } from '../services/workTimeApi';
import { parseWorkHours, periodKey, workDays, workError } from '../utils/workTime';

// Однакова надійна черга збереження для годин і грошових клітинок.
export function useWorkTime({ fetchData = fetchWorkTime, saveData = saveWorkDay, parseValue = parseWorkHours,
    valueField = 'hours', employeeType = 'hourly', autoLoad = true } = {}) {
    const now = new Date();
    const period = ref(periodKey(now.getFullYear(), now.getMonth() + 1));
    const allEmployees = ref([]), loading = ref(false), ready = ref(false), loadError = ref('');
    const employees = computed(() => allEmployees.value.filter(employee => (employee.payment_type || 'hourly') === employeeType));
    const canManage = ref(false), entries = reactive({}), drafts = reactive({}), states = reactive({});
    const timers = new Map(), running = new Map();
    let alive = true;
    const key = (id, date) => `${id}|${date}`;
    const days = computed(() => workDays(period.value));
    const dirtyKey = k => {
        if (states[k]?.uncertain) return true;
        const draft = drafts[k], saved = entries[k];
        if (!draft) return false;
        try { return parseValue(draft[valueField]) !== (saved?.[valueField] ?? null) || (draft.note.trim() || null) !== (saved?.note ?? null); }
        catch { return true; }
    };
    const pending = computed(() => Object.values(states).some(s => s.pending));
    const unsaved = computed(() => Object.keys(drafts).some(dirtyKey));
    const failures = computed(() => Object.entries(states).filter(([, state]) => state.error));
    const employeeHours = id => days.value.reduce((sum, day) => sum + Math.round(Number(entries[key(id, day.date)]?.[valueField] || 0) * 100), 0) / 100;
    const employeeDays = id => days.value.filter(day => Number(entries[key(id, day.date)]?.[valueField]) > 0).length;
    const allHours = computed(() => employees.value.reduce((sum, employee) => sum + Math.round(employeeHours(employee.id) * 100), 0) / 100);
    const dayHours = date => employees.value.reduce((sum, employee) => sum + Math.round(Number(entries[key(employee.id, date)]?.[valueField] || 0) * 100), 0) / 100;
    const lastDay = computed(() => days.value.filter(day => employees.value.some(e => entries[key(e.id, day.date)]?.[valueField] != null)).at(-1)?.day);

    async function load(target = period.value) {
        loading.value = true; loadError.value = '';
        timers.forEach(clearTimeout); timers.clear();
        try {
            const { data } = await fetchData(target);
            if (!alive) return false;
            for (const collection of [entries, drafts, states]) Object.keys(collection).forEach(k => delete collection[k]);
            period.value = target; allEmployees.value = data.employees; canManage.value = data.can_manage_pay === true;
            data.entries.forEach(entry => entries[key(entry.employee_id, entry.date)] = entry);
            employees.value.forEach(employee => days.value.forEach(day => {
                const k = key(employee.id, day.date), entry = entries[k];
                drafts[k] = { employeeId: employee.id, date: day.date, [valueField]: entry?.[valueField] == null ? '' : String(Number(entry[valueField])), note: entry?.note || '' };
            }));
            ready.value = true;
            return true;
        } catch (error) { if (alive) loadError.value = workError(error); return false; }
        finally { if (alive) loading.value = false; }
    }

    function flush(k) {
        clearTimeout(timers.get(k)); timers.delete(k);
        if (running.has(k)) return running.get(k);
        if (states[k]?.conflict) return Promise.resolve(false);
        if (!dirtyKey(k)) { if (states[k]) states[k].error = ''; return Promise.resolve(true); }
        const work = async () => {
            while (alive && dirtyKey(k)) {
                const draft = drafts[k];
                let value;
                try { value = parseValue(draft[valueField]); }
                catch (error) { states[k] = { error: error.message, pending: false }; return false; }
                if (states[k]?.conflict) return false;
                const snapshot = { ...draft };
                states[k] = { pending: true, error: '' };
                try {
                    const { data } = await saveData(draft.employeeId, {
                        date: draft.date, [valueField]: value, note: draft.note.trim() || null, version: entries[k]?.version || 0,
                    });
                    if (!alive) return false;
                    entries[k] = data;
                    // Не затираємо текст, який користувач устиг ввести під час запиту.
                    if (draft[valueField] === snapshot[valueField] && draft.note === snapshot.note) {
                        draft[valueField] = data[valueField] == null ? '' : String(Number(data[valueField])); draft.note = data.note || '';
                    }
                    states[k] = { pending: false, error: '' };
                } catch (error) {
                    if (alive) states[k] = { pending: false, error: workError(error), conflict: error?.response?.status === 409,
                        uncertain: !error?.response || error.response.status >= 500 };
                    return false;
                }
            }
            return true;
        };
        const promise = work().finally(() => running.delete(k));
        running.set(k, promise);
        return promise;
    }

    function schedule(k) {
        clearTimeout(timers.get(k));
        timers.set(k, setTimeout(() => flush(k), 600));
    }

    async function flushAll() {
        const keys = Object.keys(drafts).filter(k => dirtyKey(k) || running.has(k) || states[k]?.conflict);
        // Обмежуємо паралельні запити, якщо змінили багато клітинок поспіль.
        let success = true;
        for (let i = 0; i < keys.length; i += 4) {
            const results = await Promise.all(keys.slice(i, i + 4).map(flush));
            if (results.includes(false)) success = false;
        }
        return success;
    }

    async function changePeriod(target) {
        if (loading.value || !await flushAll()) return false;
        return load(target);
    }
    async function reload() {
        if (pending.value) return;
        if ((unsaved.value || failures.value.length) && !window.confirm('Оновити дані з сервера? Незбережені зміни буде втрачено.')) return;
        return load();
    }
    const unload = event => { if (unsaved.value || pending.value) { event.preventDefault(); event.returnValue = ''; } };
    onMounted(() => { if (autoLoad) load(); window.addEventListener('beforeunload', unload); });
    onBeforeUnmount(() => { alive = false; timers.forEach(clearTimeout); window.removeEventListener('beforeunload', unload); });
    return { period, days, employees, allEmployees, loading, ready, loadError, canManage, entries, drafts, states, pending, unsaved, failures,
        key, employeeHours, employeeDays, allHours, dayHours, lastDay, load, flush, schedule, flushAll, changePeriod, reload };
}
