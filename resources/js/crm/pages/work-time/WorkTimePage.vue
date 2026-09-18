<template>
  <section class="work-time" :aria-busy="loading">
    <header class="wt-heading">
      <div><span class="wt-eyebrow">КОМАНДА · ОБЛІК ЧАСУ</span><h1>Табель робочого часу</h1><p>Години команди — в одному місці</p></div>
      <button v-if="owner" class="btn btn-primary" type="button" :disabled="loading || pending" @click="openEmployeeForm()"><i class="bi bi-plus-lg" aria-hidden="true"></i> Додати працівника</button>
    </header>
    <div v-if="ready" class="wt-stats">
      <div><span>Відпрацьовано за місяць</span><strong data-testid="all-hours">{{ number(allHours) }} <small>год</small></strong></div>
      <div><span>Працівників у табелі</span><strong>{{ employees.length }}</strong></div>
      <div><span>Заповнено по</span><strong>{{ lastDay ? `${lastDay} ${monthGen[month - 1]}` : 'Ще немає' }}</strong></div>
    </div>
    <div v-if="loadError" class="alert alert-danger" role="alert">{{ loadError }} <button class="btn btn-sm btn-outline-danger" @click="reload">Повторити</button></div>
    <div class="wt-sheet">
      <div class="wt-toolbar">
        <div class="wt-period"><i class="bi bi-calendar3" aria-hidden="true"></i><select class="form-select" :value="month" aria-label="Місяць" :disabled="loading || pending" @change="selectPeriod($event, 'month')"><option v-for="(label, i) in workMonths" :key="label" :value="i + 1">{{ label }}</option></select><select class="form-select wt-year" :value="year" aria-label="Рік" :disabled="loading || pending" @change="selectPeriod($event, 'year')"><option v-for="value in years" :key="value">{{ value }}</option></select></div>
        <div class="wt-toolbar-right"><span class="wt-save-state" :class="{ error: failures.length, pending: pending || unsaved }" role="status">{{ loading ? 'Завантажуємо…' : failures.length ? 'Є незбережені зміни' : pending ? 'Зберігаємо…' : unsaved ? 'Очікуємо збереження…' : ready ? 'Усі зміни збережено' : '' }}</span><button type="button" class="btn wt-refresh" :disabled="loading || pending" aria-label="Оновити табель" @click="reload"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button></div>
      </div>
      <div v-if="failures.length" class="wt-errors" role="alert"><div v-for="[k, state] in failures" :key="k"><span>{{ failureLabel(k) }}: {{ state.error }}</span><button v-if="!state.conflict" class="btn btn-sm btn-outline-danger" @click="flush(k)">Повторити</button><button v-else class="btn btn-sm btn-outline-danger" @click="reload">Оновити табель</button></div></div>
      <div v-if="ready && employees.length" class="wt-scroll">
        <table aria-label="Години роботи працівників за днями">
          <thead><tr><th class="wt-person" scope="col">Працівник</th><th v-for="day in days" :key="day.date" scope="col" :class="{ weekend: day.weekend, today: day.date === today }"><b>{{ day.day }}</b><span>{{ day.label }}</span></th><th class="wt-total" scope="col">Години</th></tr></thead>
          <tbody><tr v-for="employee in employees" :key="employee.id">
            <th class="wt-person" scope="row"><button class="wt-person-button" type="button" :data-testid="`employee-${employee.id}`" :disabled="loading" @click="openPerson(employee)"><span class="wt-avatar">{{ employee.name.slice(0, 1) }}</span><span><b>{{ employee.name }}</b><small>{{ employee.archived_on ? 'В архіві' : employee.position || 'Працівник' }}</small></span></button></th>
            <td v-for="day in days" :key="day.date" :class="{ weekend: day.weekend, 'wt-cell-error': states[key(employee.id, day.date)]?.error, 'wt-cell-saving': states[key(employee.id, day.date)]?.pending }">
              <input v-model="drafts[key(employee.id, day.date)].hours" type="text" inputmode="decimal" maxlength="5" :data-cell="key(employee.id, day.date)" :aria-label="`${employee.name}, ${day.day} ${monthGen[month - 1]}, години`" :title="entryHint(employee.id, day.date)" :aria-invalid="!!states[key(employee.id, day.date)]?.error" :disabled="loading || !!(employee.archived_on && day.date > employee.archived_on)" @input="schedule(key(employee.id, day.date))" @blur="flush(key(employee.id, day.date))" @keydown.enter.prevent="nextEmployee(employee.id, day.date)" />
            </td><td class="wt-total"><b>{{ number(employeeHours(employee.id)) }}</b><small>{{ employeeDays(employee.id) }} дн.</small></td>
          </tr></tbody>
          <tfoot><tr><th class="wt-person" scope="row">Разом за день</th><td v-for="day in days" :key="day.date">{{ number(dayHours(day.date)) }}</td><td class="wt-total">{{ number(allHours) }}</td></tr></tfoot>
        </table>
      </div>
      <div v-else-if="ready && !loading" class="wt-empty"><i class="bi bi-people" aria-hidden="true"></i><h2>Додайте команду до табеля</h2><p>{{ owner ? 'Створіть працівника, потім вводьте години навпроти потрібного дня.' : 'Власник CRM має додати працівників. Після цього тут можна вести години.' }}</p></div>
      <div v-else-if="loading && !ready" class="wt-empty" role="status">Завантажуємо табель…</div>
      <footer class="wt-sheet-footer"><span><i class="wt-weekend-swatch"></i> Вихідні <span class="ms-3">Порожня клітинка = 0 год у підсумку</span></span><span>Tab → наступна клітинка · Enter ↓</span></footer>
    </div>
    <p class="wt-bottom-note"><i class="bi bi-cursor" aria-hidden="true"></i> Натисніть на ім’я — відкриється картка працівника. {{ owner ? 'Зарплатні дані доступні лише вам у вкладці «Нарахування».' : '' }}</p>

    <dialog ref="personDialog" class="wt-dialog" aria-labelledby="wt-person-title" @cancel.prevent="closePerson">
      <template v-if="selected">
        <header class="wt-dialog-header"><div><span class="wt-eyebrow">КАРТКА ПРАЦІВНИКА</span><h2 id="wt-person-title">{{ selected.name }}</h2><p>{{ workMonths[month - 1] }} {{ year }} · {{ selected.position || 'Працівник' }}</p></div><button type="button" class="wt-close" aria-label="Закрити картку" :disabled="paySaving" @click="closePerson"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
        <div class="wt-tabs" role="group" aria-label="Розділ картки"><button type="button" :aria-pressed="section === 'hours'" @click="section = 'hours'"><i class="bi bi-clock" aria-hidden="true"></i> Години</button><button v-if="owner" type="button" :aria-pressed="section === 'pay'" data-testid="pay-tab" :disabled="payLoading || paySaving" @click="openPay"><i class="bi bi-lock" aria-hidden="true"></i> Нарахування</button></div>
        <div class="wt-month-total"><span>Відпрацьовано за місяць<small>{{ section === 'pay' ? 'За збереженими даними сервера' : `${employeeDays(selected.id)} робочих днів · із табеля` }}</small></span><strong>{{ number(section === 'pay' ? payHours : employeeHours(selected.id)) }} год</strong></div>
        <section v-show="section === 'hours'" aria-label="Години за день">
          <div class="wt-fields"><label>День місяця<select class="form-select" v-model="editDate"><option v-for="day in days" :key="day.date" :value="day.date">{{ day.day }} {{ monthGen[month - 1] }} · {{ day.label }}</option></select></label><label>Відпрацьовано, год<input v-model="dayDraft.hours" class="form-control" name="day_hours" type="text" inputmode="decimal" maxlength="5" :disabled="dayArchived" @input="schedule(key(selected.id, editDate))" @blur="flush(key(selected.id, editDate))" /></label><label class="wt-full">Примітка за день<textarea v-model="dayDraft.note" class="form-control" name="day_note" rows="2" maxlength="500" :disabled="dayArchived" placeholder="Наприклад, пів дня або заміна" @input="schedule(key(selected.id, editDate))" @blur="flush(key(selected.id, editDate))"></textarea></label></div>
          <div v-if="states[key(selected.id, editDate)]?.error" class="alert alert-danger mt-3" role="alert">{{ states[key(selected.id, editDate)].error }}</div>
          <div class="wt-day-actions"><span>{{ dayArchived ? 'Працівника вже архівовано' : states[key(selected.id, editDate)]?.pending ? 'Зберігаємо…' : 'Години зберігаються автоматично' }}</span><button class="btn btn-primary" type="button" :disabled="dayArchived || states[key(selected.id, editDate)]?.pending" @click="flush(key(selected.id, editDate))">Зберегти день</button></div>
        </section>
        <section v-if="owner && section === 'pay'" aria-label="Нарахування зарплати">
          <p class="wt-private"><i class="bi bi-shield-lock" aria-hidden="true"></i> Лише власник · ставка для {{ workMonths[month - 1].toLowerCase() }} {{ year }}</p>
          <div v-if="payError" class="alert alert-danger" role="alert">{{ payError }} <button v-if="!paySaving" class="btn btn-sm btn-outline-danger" @click="refreshPay">Оновити</button></div>
          <p v-if="payLoading" role="status">Завантажуємо нарахування…</p>
          <form v-if="payForm && !payLoading" @submit.prevent="submitPay">
            <div class="wt-fields"><label>Ставка, грн / год<input v-model="payForm.hourly_rate" class="form-control" name="hourly_rate" inputmode="decimal" maxlength="10" placeholder="Не вказана" :disabled="paySaving" /></label><label>Премія за місяць, грн<input v-model="payForm.bonus" class="form-control" name="bonus" inputmode="decimal" maxlength="10" :disabled="paySaving" /></label><label class="wt-full">Примітка до нарахувань<textarea v-model="payForm.note" class="form-control" name="pay_note" maxlength="500" rows="2" placeholder="За що премія або коли виплачено аванс" :disabled="paySaving"></textarea></label><label class="wt-full">Уже виплачено / аванс за місяць, грн<input v-model="payForm.paid" class="form-control" name="paid" inputmode="decimal" maxlength="10" :disabled="paySaving" /></label></div>
            <div class="wt-pay-lines" aria-live="polite"><div><span>{{ number(payHours) }} год × ставка</span><b>{{ money(payPreview.base) }}</b></div><div><span>Нараховано з премією</span><b>{{ money(payPreview.accrued) }}</b></div><div><span>Уже виплачено</span><b>{{ money(payPreview.paid) }}</b></div><div class="wt-balance"><span>{{ payPreview.balance < 0 ? 'Виплачено понад нараховане' : 'Залишилось виплатити' }}</span><strong data-testid="balance">{{ money(payPreview.balance === null ? null : Math.abs(payPreview.balance)) }}</strong></div></div>
            <p v-if="payPreview.base === null" class="wt-private">Вкажіть ставку, щоб розрахувати суму. Відсутня ставка не вважається нульовою.</p>
            <p class="wt-private">Внутрішній розрахунок без податків. Нова ставка не змінює попередні місяці.</p>
            <div class="wt-day-actions"><span role="status">{{ paySaving ? 'Зберігаємо…' : payDirty ? 'Є незбережені зміни' : 'Збережені дані' }}</span><button class="btn btn-primary" type="submit" :disabled="paySaving || !payDirty">Зберегти нарахування</button></div>
          </form>
        </section>
        <footer class="wt-dialog-footer"><button v-if="owner" type="button" class="btn btn-link" :disabled="paySaving" @click="editEmployee">Редагувати працівника</button><button type="button" class="btn btn-light ms-auto" :disabled="paySaving" @click="closePerson">Готово</button></footer>
      </template>
    </dialog>

    <dialog v-if="owner" ref="employeeDialog" class="wt-dialog wt-employee-dialog" aria-labelledby="wt-edit-title" @cancel.prevent="closeEmployeeForm">
      <form @submit.prevent="submitEmployee"><header class="wt-dialog-header"><h2 id="wt-edit-title">{{ employeeForm.id ? 'Працівник' : 'Новий працівник' }}</h2><button class="wt-close" type="button" aria-label="Закрити" :disabled="employeeSaving" @click="closeEmployeeForm"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header><div v-if="employeeError" class="alert alert-danger" role="alert">{{ employeeError }}</div><label class="wt-field">Ім’я та прізвище<input v-model="employeeForm.name" class="form-control" name="employee_name" required maxlength="100" :disabled="employeeSaving" autocomplete="off" /></label><label class="wt-field">Посада<input v-model="employeeForm.position" class="form-control" name="employee_position" maxlength="100" :disabled="employeeSaving" /></label><label v-if="employeeForm.id" class="form-check my-3"><input v-model="employeeForm.archived" class="form-check-input" type="checkbox" :disabled="employeeSaving" /><span class="form-check-label">В архіві — зберегти історію, не додавати нові дні</span></label><p class="wt-private">Працівник табеля — не обліковий запис для входу в CRM.</p><button class="btn btn-primary w-100" type="submit" :disabled="employeeSaving">{{ employeeSaving ? 'Зберігаємо…' : employeeForm.id ? 'Зберегти' : 'Додати працівника' }}</button></form>
    </dialog>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useWorkTime } from '../../composables/useWorkTime';
import { workMonths, periodKey, workError } from '../../utils/workTime';
import { createWorkEmployee, updateWorkEmployee, fetchWorkPayroll, saveWorkPayroll } from '../../services/workTimeApi';

const props = defineProps({ canManagePay: { type: Boolean, default: false } });
const { period, days, employees, loading, ready, loadError, canManage, entries, drafts, states, pending, unsaved, failures,
  key, employeeHours, employeeDays, allHours, dayHours, lastDay, load, flush, schedule, flushAll, changePeriod, reload } = useWorkTime();
const owner = computed(() => props.canManagePay && canManage.value);
const year = computed(() => Number(period.value.slice(0, 4))), month = computed(() => Number(period.value.slice(5)));
const years = Array.from({ length: 101 }, (_, i) => 2000 + i);
const monthGen = ['січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];
const now = new Date(), today = `${periodKey(now.getFullYear(), now.getMonth() + 1)}-${String(now.getDate()).padStart(2, '0')}`;
const number = value => new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 2 }).format(value);
const money = value => value === null || !Number.isFinite(value) ? '—' : `${number(value)} грн`;
const personDialog = ref(), employeeDialog = ref(), selected = ref(null), section = ref('hours'), editDate = ref('');
const dayDraft = computed(() => drafts[key(selected.value?.id, editDate.value)] || { hours: '', note: '' });
const dayArchived = computed(() => !!(selected.value?.archived_on && editDate.value > selected.value.archived_on));
const payForm = ref(null), payOriginal = ref(''), paySaving = ref(false), payLoading = ref(false), payError = ref('');
const payHours = ref(0);
const payDirty = computed(() => payForm.value && JSON.stringify(payForm.value) !== payOriginal.value);
const employeeForm = reactive({}), employeeSaving = ref(false), employeeError = ref('');
let lastFocus = null;
const numericMoney = (value, nullable = false) => {
  const text = String(value ?? '').trim().replace(',', '.');
  if (!text && nullable) return null;
  if (!/^\d{1,7}(\.\d{1,2})?$/.test(text) || Number(text) > 1000000) throw new Error('Вкажіть суму від 0 до 1 000 000 грн, до двох знаків після коми.');
  return Math.round(Number(text) * 100);
};
const payPreview = computed(() => {
  try {
    const rate = numericMoney(payForm.value?.hourly_rate, true), bonus = numericMoney(payForm.value?.bonus ?? '0'), paid = numericMoney(payForm.value?.paid ?? '0');
    const base = rate === null ? null : Math.round(Math.round(payHours.value * 100) * rate / 100);
    return { base: base === null ? null : base / 100, accrued: base === null ? null : (base + bonus) / 100, paid: paid / 100, balance: base === null ? null : (base + bonus - paid) / 100 };
  } catch { return { base: null, accrued: null, paid: null, balance: null }; }
});
function entryHint(id, date) { const e = entries[key(id, date)]; return e ? `${e.note ? e.note + ' · ' : ''}${e.updated_by || 'CRM'} · ${e.updated_at}` : ''; }
function failureLabel(k) { const [id, date] = k.split('|'); return `${employees.value.find(e => e.id === Number(id))?.name || 'Працівник'}, ${date}`; }
async function selectPeriod(event, field) {
  const value = Number(event.target.value), target = periodKey(field === 'year' ? value : year.value, field === 'month' ? value : month.value);
  await changePeriod(target); event.target.value = String(field === 'year' ? year.value : month.value);
}
function nextEmployee(id, date) {
  flush(key(id, date));
  const next = employees.value[(employees.value.findIndex(e => e.id === id) + 1) % employees.value.length];
  const input = document.querySelector(`[data-cell="${key(next.id, date)}"]`); input?.focus(); input?.select();
}
async function showDialog(dialog) { await nextTick(); dialog.value.showModal(); }
async function openPerson(employee) {
  lastFocus = document.activeElement; selected.value = employee; editDate.value = today.startsWith(period.value) ? today : days.value[0].date;
  section.value = 'hours'; payForm.value = null; payOriginal.value = ''; payError.value = '';
  payHours.value = employeeHours(employee.id);
  await showDialog(personDialog);
}
async function closePerson() {
  if (paySaving.value || payLoading.value) return false;
  // Помилкові години лишаються в таблиці: закриття картки не губить чернетку.
  await flushAll();
  if (payDirty.value && !window.confirm('Закрити без збереження змін у нарахуваннях?')) return false;
  personDialog.value.close(); selected.value = null; payForm.value = null; lastFocus?.focus(); return true;
}
async function openPay() {
  if (!owner.value || !await flushAll()) return;
  section.value = 'pay';
  await loadPay(!!payForm.value);
}
async function loadPay(keepFields = false) {
  payLoading.value = true; payError.value = '';
  try {
    const { data } = await fetchWorkPayroll(selected.value.id, period.value);
    payHours.value = Number(data.hours);
    if (!keepFields) {
      payForm.value = { hourly_rate: data.hourly_rate ?? '', bonus: data.bonus, paid: data.paid, note: data.note ?? '', version: data.version };
      payOriginal.value = JSON.stringify(payForm.value);
    }
  } catch (error) { payError.value = workError(error); }
  finally { payLoading.value = false; }
}
async function refreshPay() { if (payDirty.value && !window.confirm('Замінити незбережені нарахування даними із сервера?')) return; await loadPay(); }
async function submitPay() {
  if (!owner.value || paySaving.value || !await flushAll()) return;
  let values;
  try {
    values = Object.fromEntries(['hourly_rate', 'bonus', 'paid'].map(field => {
      const cents = numericMoney(payForm.value[field], field === 'hourly_rate'); return [field, cents === null ? null : (cents / 100).toFixed(2)];
    }));
  } catch (error) { payError.value = error.message; return; }
  paySaving.value = true; payError.value = '';
  try {
    const { data } = await saveWorkPayroll(selected.value.id, { ...values, note: payForm.value.note.trim() || null, version: payForm.value.version, month: period.value });
    payHours.value = Number(data.hours);
    payForm.value = { hourly_rate: data.hourly_rate ?? '', bonus: data.bonus, paid: data.paid, note: data.note ?? '', version: data.version };
    payOriginal.value = JSON.stringify(payForm.value);
  } catch (error) { payError.value = workError(error); }
  finally { paySaving.value = false; }
}
async function openEmployeeForm(employee = null) {
  if (!owner.value || !await flushAll()) return;
  Object.assign(employeeForm, { id: employee?.id ?? null, name: employee?.name || '', position: employee?.position || '', archived: !!employee?.archived_on, version: employee?.version || 0, request_key: crypto.randomUUID() });
  employeeError.value = ''; await showDialog(employeeDialog);
}
async function editEmployee() { const employee = selected.value; if (await closePerson()) await openEmployeeForm(employee); }
function closeEmployeeForm() { if (!employeeSaving.value) employeeDialog.value.close(); }
async function submitEmployee() {
  if (employeeSaving.value) return;
  if (employeeForm.id && employeeForm.archived && !window.confirm('Архівувати працівника? Попередні години й нарахування збережуться.')) return;
  employeeSaving.value = true; employeeError.value = '';
  try {
    const values = { name: employeeForm.name.trim(), position: employeeForm.position.trim() || null };
    if (employeeForm.id) await updateWorkEmployee(employeeForm.id, { ...values, archived: employeeForm.archived, version: employeeForm.version });
    else await createWorkEmployee({ ...values, request_key: employeeForm.request_key });
    employeeDialog.value.close(); await load();
  } catch (error) { employeeError.value = workError(error); }
  finally { employeeSaving.value = false; }
}
const protectPay = event => { if (payDirty.value || paySaving.value) { event.preventDefault(); event.returnValue = ''; } };
onMounted(() => window.addEventListener('beforeunload', protectPay));
onBeforeUnmount(() => window.removeEventListener('beforeunload', protectPay));
</script>

<style scoped>
.work-time{max-width:1800px;margin:auto;color:#202a40}.wt-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:26px}.wt-eyebrow{font-size:10px;font-weight:750;letter-spacing:.12em;color:#7565cb}.wt-heading h1{font-size:28px;font-weight:800;letter-spacing:-.04em;margin:8px 0}.wt-heading p,.wt-dialog-header p{font-size:13px;color:#6b7c93;margin:0}.work-time .btn-primary{background:#6250df;border-color:#6250df;border-radius:9px;font-size:13px;padding:10px 15px}.wt-stats{display:flex;gap:38px;flex-wrap:wrap;margin-bottom:26px}.wt-stats>div{padding-left:15px;border-left:2px solid #e2e5ef;min-width:140px}.wt-stats>div:first-child{border-color:#6250df}.wt-stats span{font-size:12px;color:#6b7c93;display:block;margin-bottom:5px}.wt-stats strong{font-size:25px;font-weight:650;letter-spacing:-.03em}.wt-stats small{font-size:13px;color:#6b7c93;font-weight:500}.wt-sheet{background:#fff;border:1px solid #e3e9f2;border-radius:14px;overflow:hidden}.wt-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px;flex-wrap:wrap}.wt-period{display:flex;align-items:center;gap:9px}.wt-period>i{color:#7466cb;font-size:18px;margin-right:4px}.wt-period .form-select{width:150px;font-size:13px;border-radius:8px;min-height:39px;border-color:#e3e9f2}.wt-period .wt-year{width:100px}.wt-toolbar-right{display:flex;align-items:center;gap:12px}.wt-save-state{font-size:12px;color:#27816b}.wt-save-state.error{color:#b32d45}.wt-save-state.pending{color:#8a6a20}.wt-refresh{border:1px solid #e3e9f2;border-radius:8px;color:#6b7c93}.wt-scroll{overflow:auto;max-width:100%;scrollbar-width:thin}.wt-scroll table{border-collapse:separate;border-spacing:0;width:100%;font-variant-numeric:tabular-nums}.wt-scroll th,.wt-scroll td{text-align:center;border-top:1px solid #e8edf4;border-right:1px solid #e8edf4;background:#fff;min-width:38px;padding:0;height:68px}.wt-scroll thead th{height:56px;font-size:11px;font-weight:500;color:#7b879b}.wt-scroll thead b{display:block;font-size:13px;color:#38465f;font-weight:650;margin-bottom:3px}.wt-scroll thead span{display:block}.wt-scroll .weekend{background:#f6f7fb}.wt-scroll .today b{background:#6250df;color:white;border-radius:6px;display:inline-block;min-width:25px;padding:2px}.wt-scroll .wt-person{position:sticky;left:0;z-index:2;min-width:210px;max-width:210px;text-align:left;padding:0 14px;box-shadow:4px 0 7px #24304b05}.wt-person-button{display:flex;align-items:center;gap:10px;padding:10px 0;text-align:left;background:none;border:0;color:inherit;width:100%}.wt-person-button b{font-size:13px;font-weight:650;display:block;white-space:normal;overflow-wrap:anywhere}.wt-person-button small{display:block;font-size:11px;color:#7a869a;margin-top:3px;font-weight:400}.wt-person-button:hover b{color:#6250df}.wt-avatar{display:grid;place-items:center;flex-shrink:0;width:31px;height:31px;border-radius:10px;background:#f0edff;color:#7460da;font-size:12px}.wt-scroll input{border:0;background:transparent;text-align:center;width:38px;padding:10px 1px;border-radius:4px;font-size:13px;color:#344158;appearance:textfield;height:44px}.wt-scroll input:hover{background:#f0edff}.wt-scroll input:focus-visible{outline:2px solid #8e80e9;outline-offset:-2px;background:#f4f1ff}.wt-scroll input:disabled{color:#a8b1c2;background:#f1f3f6}.wt-scroll .wt-cell-error input{background:#fff0f2;color:#b32d45}.wt-scroll .wt-cell-saving{box-shadow:inset 0 -2px #c1b8f8}.wt-scroll .wt-total{position:sticky;right:0;z-index:2;min-width:85px;border-left:1px solid #e8edf4;border-right:0;font-size:13px}.wt-total b{font-weight:650}.wt-total small{display:block;color:#7a869a;font-size:11px;margin-top:3px}.wt-scroll tfoot th,.wt-scroll tfoot td{height:43px;font-size:11px;background:#f8f9fc;color:#6b7c93;font-weight:600}.wt-sheet-footer{padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border-top:1px solid #e8edf4;color:#7a869a;font-size:11px}.wt-weekend-swatch{display:inline-block;width:11px;height:11px;border:1px solid #e3e9f2;background:#f6f7fb;border-radius:3px;margin-right:4px}.wt-bottom-note{color:#6b7c93;font-size:12px;margin:17px 0}.wt-errors{padding:12px 16px;background:#fff5f6;color:#b32d45;font-size:12px}.wt-errors>div{display:flex;justify-content:space-between;gap:14px;align-items:center;margin:5px 0}.wt-empty{text-align:center;padding:65px 20px;color:#6b7c93}.wt-empty>i{font-size:32px;color:#9c90df}.wt-empty h2{font-size:20px;color:#344158;margin:18px 0 10px}.wt-empty p{font-size:13px;margin:0}.wt-dialog{width:min(540px,calc(100% - 24px));max-height:calc(100dvh - 36px);padding:26px;border:1px solid #e3e9f2;border-radius:16px;color:#202a40;background:#fff;box-shadow:0 24px 80px #18223930;overflow:auto}.wt-dialog::backdrop{background:#17213966;backdrop-filter:blur(2px)}.wt-dialog-header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:20px}.wt-dialog-header h2{font-size:23px;line-height:1.35;font-weight:750;letter-spacing:-.03em;margin:5px 0 4px;overflow-wrap:anywhere}.wt-close{width:34px;height:34px;flex-shrink:0;display:grid;place-items:center;border:1px solid #e3e9f2;background:#f8f9fc;color:#66748c;border-radius:9px}.wt-tabs{display:flex;gap:5px;padding:4px;background:#f5f6fb;border-radius:10px;margin-bottom:19px}.wt-tabs button{flex:1;background:none;border:0;min-height:40px;border-radius:7px;color:#6b7c93;font-size:13px;font-weight:650}.wt-tabs button[aria-pressed=true]{background:#fff;box-shadow:0 1px 5px #24304b0a;color:#6250df}.wt-tabs i{margin-right:5px}.wt-month-total{display:flex;align-items:center;justify-content:space-between;gap:14px;background:#f1edff;border-radius:11px;padding:16px 18px;margin-bottom:22px;font-size:12px;color:#716a91}.wt-month-total small{display:block;font-size:11px;margin-top:5px;color:#807793}.wt-month-total strong{font-size:24px;color:#6650cf;white-space:nowrap}.wt-fields{display:grid;grid-template-columns:1fr 1fr;gap:16px}.wt-fields label,.wt-field{font-size:12px;font-weight:600;color:#68768d;display:block;min-width:0}.wt-fields .form-control,.wt-fields .form-select,.wt-field .form-control{margin-top:7px;border-color:#e3e9f2;border-radius:8px;font-size:14px;min-height:42px;color:#24314a}.wt-full{grid-column:1/-1}.wt-day-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:20px}.wt-day-actions>span{font-size:11px;color:#7a869a}.wt-dialog-footer{display:flex;justify-content:space-between;gap:10px;border-top:1px solid #edf0f6;margin-top:22px;padding-top:17px}.wt-dialog-footer .btn{font-size:12px}.wt-dialog-footer .btn-link{color:#796bc1;text-decoration:none;padding-left:0}.wt-private{font-size:11px;color:#7a869a;line-height:1.65;margin:0 0 15px}.wt-pay-lines{margin-top:20px;border-top:1px solid #e8edf4;padding-top:8px}.wt-pay-lines>div{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:9px 0;color:#7a869a;font-size:12px}.wt-pay-lines b{color:#344158;font-weight:600;font-variant-numeric:tabular-nums}.wt-pay-lines .wt-balance{border-top:1px solid #e8edf4;margin-top:6px;padding:17px 0;color:#344158;font-size:14px}.wt-balance strong{font-size:25px;color:#238571;font-variant-numeric:tabular-nums}.wt-employee-dialog{max-width:450px}.wt-field{margin-bottom:16px}.wt-employee-dialog .form-check-label{font-size:12px;color:#6b7c93}button:focus-visible{outline:3px solid #a5b4fc;outline-offset:2px}button:disabled{opacity:.6;cursor:not-allowed}.wt-dialog .alert{font-size:12px}.wt-dialog textarea{resize:vertical}@media(max-width:700px){.wt-heading h1{font-size:24px}.wt-stats{gap:20px}.wt-stats>div:last-child{display:none}.wt-stats>div{min-width:115px}.wt-stats strong{font-size:22px}.wt-scroll .wt-person{min-width:154px;max-width:154px;padding:0 10px}.wt-avatar{display:none}.wt-person-button b{font-size:12px}.wt-scroll .wt-total{min-width:65px}.wt-period{gap:6px}.wt-period .form-select{width:135px}.wt-period .wt-year{width:90px}.wt-toolbar{padding:12px}.wt-dialog{padding:20px}.wt-month-total strong{font-size:21px}.wt-balance strong{font-size:21px}.wt-fields{gap:12px}.wt-sheet-footer{line-height:1.8}.wt-errors>div{flex-wrap:wrap}}@media(pointer:coarse){.wt-scroll input{width:44px;min-height:44px;font-size:16px}.wt-close{width:44px;height:44px}.wt-tabs button{min-height:44px}}
</style>
