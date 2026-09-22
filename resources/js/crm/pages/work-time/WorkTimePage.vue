<template>
  <section class="work-time" :aria-busy="loading">
    <header class="wt-heading">
      <div class="wt-title"><span class="wt-eyebrow">КОМАНДА · РОБОЧИЙ ОБЛІК</span><h1>Табель і виконані роботи</h1><p>Погодинна та відрядна робота — окремо, без плутанини</p></div>
      <div v-if="ready" class="wt-stats">
        <div><span>Відпрацьовано за місяць</span><strong data-testid="all-hours">{{ number(allHours) }} <small>год</small></strong></div>
        <div><span>Працівників у табелі</span><strong>{{ employees.length }}</strong></div>
        <div><span>Заповнено по</span><strong>{{ lastDay ? `${lastDay} ${monthGen[month - 1]}` : 'Ще немає' }}</strong></div>
      </div>
    </header>
    <div class="wt-sheet">
      <div class="wt-toolbar">
        <div class="wt-period"><i class="bi bi-calendar3" aria-hidden="true"></i><select class="form-select" :value="month" aria-label="Місяць" :disabled="loading || pending || piece.loading || piece.pending" @change="selectPeriod($event, 'month')"><option v-for="(label, i) in workMonths" :key="label" :value="i + 1">{{ label }}</option></select><select class="form-select wt-year" :value="year" aria-label="Рік" :disabled="loading || pending || piece.loading || piece.pending" @change="selectPeriod($event, 'year')"><option v-for="value in years" :key="value">{{ value }}</option></select></div>
        <div class="wt-toolbar-right"><button v-if="problems.length" class="btn wt-refresh text-danger" type="button" aria-label="Показати помилки табеля" @click="showProblems"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></button><button type="button" class="btn wt-refresh" :disabled="loading || pending || piece.loading || piece.pending" aria-label="Оновити табель" @click="reload()"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button></div>
      </div>
      <div class="wt-table-heading"><div><h2>Табель годин <span>ГОДИНИ</span></h2><p>Відпрацьований час за кожен день</p></div></div>
      <div v-if="ready && employees.length" class="wt-scroll">
        <table class="wt-calendar" :style="{ '--wt-day-count': days.length }" aria-label="Години роботи працівників за днями">
          <colgroup><col class="wt-person-col" /><col v-for="day in days" :key="day.date" class="wt-day-col" /><col class="wt-total-col" /></colgroup>
          <thead><tr><th class="wt-person" scope="col">Працівник</th><th v-for="day in days" :key="day.date" scope="col" :class="{ weekend: day.weekend, today: day.date === today }"><b>{{ day.day }}</b><span>{{ day.label }}</span></th><th class="wt-total" scope="col">Години</th></tr></thead>
          <tbody><tr v-for="employee in employees" :key="employee.id">
            <th class="wt-person" scope="row"><div class="wt-person-label" :data-testid="`employee-${employee.id}`"><span class="wt-avatar">{{ employee.name.slice(0, 1) }}</span><span><b>{{ employee.name }}</b><small>{{ employee.archived_on ? 'В архіві' : employee.position || 'Працівник' }}</small></span></div></th>
            <td v-for="day in days" :key="day.date" :class="{ weekend: day.weekend, today: day.date === today, 'wt-cell-error': states[key(employee.id, day.date)]?.error, 'wt-cell-saving': states[key(employee.id, day.date)]?.pending }">
              <input v-model="drafts[key(employee.id, day.date)].hours" type="text" inputmode="decimal" maxlength="5" :data-cell="key(employee.id, day.date)" :aria-label="`${employee.name}, ${day.day} ${monthGen[month - 1]}, години`" :title="entryHint(employee.id, day.date)" :aria-invalid="!!states[key(employee.id, day.date)]?.error" :disabled="loading || !!(employee.archived_on && day.date > employee.archived_on)" @focus="states[key(employee.id, day.date)]?.error && showProblems()" @input="schedule(key(employee.id, day.date))" @blur="flush(key(employee.id, day.date))" @keydown.enter.prevent="nextEmployee(employee.id, day.date)" />
            </td><td class="wt-total"><b>{{ number(employeeHours(employee.id)) }}</b><small>{{ employeeDays(employee.id) }} дн.</small></td>
          </tr></tbody>
          <tfoot><tr><th class="wt-person" scope="row">Разом за день</th><td v-for="day in days" :key="day.date" :class="{ weekend: day.weekend, today: day.date === today }"><span class="wt-day-sum" :title="number(dayHours(day.date))">{{ number(dayHours(day.date)) }}</span></td><td class="wt-total">{{ number(allHours) }}</td></tr></tfoot>
        </table>
      </div>
      <div v-else-if="ready && !loading" class="wt-empty"><i class="bi bi-people" aria-hidden="true"></i><h2>У табелі ще немає працівників</h2><p>Працівників додає власник. Тут вводяться лише години.</p></div>
      <div v-else-if="loading && !ready" class="wt-empty" role="status">Завантажуємо табель…</div>
      <footer class="wt-sheet-footer"><span><i class="wt-weekend-swatch"></i> Вихідні <span class="ms-3">Порожня клітинка = 0 год у підсумку</span></span><span>Tab → наступна клітинка · Enter ↓</span></footer>
    </div>

    <section v-if="owner" class="wt-sheet wt-money-sheet" aria-labelledby="wt-piece-title" :aria-busy="piece.loading">
      <header class="wt-table-heading"><div><h2 id="wt-piece-title">За виконану роботу <span>ГРИВНІ</span></h2></div></header>
      <div v-if="piece.ready && piece.period === period && piece.employees.length" class="wt-scroll wt-money-scroll">
        <table class="wt-calendar" :style="{ '--wt-day-count': days.length }" aria-label="Суми за виконану роботу за днями">
          <colgroup><col class="wt-person-col" /><col v-for="day in days" :key="day.date" class="wt-day-col" /><col class="wt-total-col" /></colgroup>
          <thead><tr><th class="wt-person" scope="col">Працівник</th><th v-for="day in days" :key="day.date" scope="col" :class="{ weekend: day.weekend, today: day.date === today }"><b>{{ day.day }}</b><span>{{ day.label }}</span></th><th class="wt-total" scope="col">Разом, грн</th></tr></thead>
          <tbody><tr v-for="employee in piece.employees" :key="employee.id">
            <th class="wt-person" scope="row"><div class="wt-person-label" :data-testid="`employee-${employee.id}`"><span class="wt-avatar">{{ employee.name.slice(0, 1) }}</span><span><b>{{ employee.name }}</b><small>{{ employee.archived_on ? 'В архіві' : employee.position || 'Працівник' }}</small></span></div></th>
            <td v-for="day in days" :key="day.date" :class="{ weekend: day.weekend, today: day.date === today, 'wt-cell-error': piece.states[key(employee.id, day.date)]?.error, 'wt-cell-saving': piece.states[key(employee.id, day.date)]?.pending }">
              <span class="wt-money-value" :data-money-cell="key(employee.id, day.date)" :title="pieceHint(employee.id, day.date)">{{ piece.entries[key(employee.id, day.date)]?.amount == null ? '' : number(Number(piece.entries[key(employee.id, day.date)].amount)) }}</span>
              <button type="button" class="wt-day-note" :class="{ 'has-note': !!piece.entries[key(employee.id, day.date)]?.note }"
                :data-note-cell="key(employee.id, day.date)" :title="pieceHint(employee.id, day.date)"
                :aria-label="`${employee.name}, ${day.day} ${monthGen[month - 1]}: ${pieceHint(employee.id, day.date)}. Редагувати нарахування`" aria-haspopup="dialog"
                :aria-invalid="!!piece.states[key(employee.id, day.date)]?.error"
                :aria-expanded="dayEditor?.key === key(employee.id, day.date)"
                :disabled="loading || piece.loading || !!openingDay || (!!dayEditor && dayEditor.key !== key(employee.id, day.date)) || !!(employee.archived_on && day.date > employee.archived_on)"
                @click="openDayEditor(employee, day.date, $event.currentTarget)"><i class="bi bi-chat-left-text" aria-hidden="true"></i></button>
            </td><td class="wt-total"><b :title="money(piece.employeeHours(employee.id))" :data-testid="`piece-total-${employee.id}`">{{ number(piece.employeeHours(employee.id)) }}</b><small>грн за місяць</small></td>
          </tr></tbody>
          <tfoot><tr><th class="wt-person" scope="row">Разом за день, грн</th><td v-for="day in days" :key="day.date" :class="{ weekend: day.weekend, today: day.date === today }"><span class="wt-day-sum" :title="money(piece.dayHours(day.date))">{{ number(piece.dayHours(day.date)) }}</span></td><td class="wt-total" data-testid="piece-all-total" :title="money(piece.allHours)">{{ number(piece.allHours) }}</td></tr></tfoot>
        </table>
      </div>
      <div v-else-if="piece.loading" class="wt-empty" role="status">Завантажуємо суми…</div>
      <div v-else-if="!piece.loadError" class="wt-empty"><i class="bi bi-people" aria-hidden="true"></i><h2>Працівники з оплатою за роботу</h2><p>Тут вводяться домовлені суми по днях для працівників, яких додав власник.</p></div>
      <footer class="wt-sheet-footer"><span>Натисніть іконку, щоб ввести суму та пояснення · Порожня клітинка = 0 грн</span><span>Сума до оплати за роботу, не факт виплати · Лише власник</span></footer>
    </section>

    <PieceworkDayPopover v-if="dayEditor" :employee="dayEditor.employee" :date="dayEditor.date" :anchor="dayEditor.anchor"
      :initial-amount="dayEditor.amount" :initial-note="dayEditor.note" :saving="savingDay"
      :error="piece.states[dayEditor.key]?.error || ''" @save="saveDayEditor" @close="closeDayEditor" @notice="dayNotice" />
    <Toast v-bind="toast" @close="closeToast" @action="runAction" @secondary="runSecondary" />
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, shallowRef, watch } from 'vue';
import PieceworkDayPopover from './PieceworkDayPopover.vue';
import { useWorkTime } from '../../composables/useWorkTime';
import { useToast } from '../../composables/useToast';
import Toast from '../../components/ui/Toast.vue';
import { workMonths, periodKey, parseWorkAmount } from '../../utils/workTime';
import { fetchPieceworkDays, savePieceworkDay } from '../../services/pieceworkApi';

const props = defineProps({ canManagePay: { type: Boolean, default: false } });
const hoursSource = useWorkTime();
const hours = reactive(hoursSource);
const { period, days, employees, loading, ready, loadError, canManage, entries, drafts, states, pending, unsaved, failures,
  key, employeeHours, employeeDays, allHours, dayHours, lastDay, load: loadHours, flush, schedule, flushAll } = hoursSource;
const owner = computed(() => props.canManagePay && canManage.value);
const piece = reactive(useWorkTime({ fetchData: fetchPieceworkDays, saveData: savePieceworkDay, parseValue: parseWorkAmount,
  valueField: 'amount', employeeType: 'piecework', autoLoad: false }));
const { toast, showToast, closeToast, runAction, runSecondary } = useToast();
const dayEditor = shallowRef(null), savingDay = ref(false), openingDay = ref(''), dayError = shallowRef(null);
let alive = true;
onBeforeUnmount(() => { alive = false; });
async function openDayEditor(employee, date, anchor) {
  if (dayEditor.value || openingDay.value || loading.value || piece.loading) return;
  const k = key(employee.id, date);
  if (employee.archived_on && date > employee.archived_on) return;
  openingDay.value = k;
  // Відкриття форми не зберігає невдалу чернетку без натискання «Зберегти».
  if (piece.states[k]?.pending) await piece.flush(k);
  if (!alive) return;
  const draft = piece.drafts[k];
  dayEditor.value = { key: k, employee, date, anchor, amount: draft.amount, note: draft.note };
  openingDay.value = '';
}
function dayNotice(notice) {
  if (notice.type === 'error') dayError.value = notice;
  // Не приховуємо помилку іншої клітинки локальним попередженням.
  const localErrors = dayError.value && dayError.value !== notice ? dayError.value.messages : [];
  showToast({ ...notice, messages: [...notice.messages, ...localErrors, ...problems.value.map(p => p.message)],
    type: problems.value.length || dayError.value ? 'error' : notice.type });
}
async function closeDayEditor() {
  if (savingDay.value) return;
  const anchor = dayEditor.value?.anchor;
  dayEditor.value = null;
  dayError.value = null;
  if (problems.value.length) showProblems(); else closeToast();
  await nextTick(); anchor?.focus();
}
async function saveDayEditor(values) {
  if (!dayEditor.value || savingDay.value) return;
  dayError.value = null;
  const k = dayEditor.value.key;
  Object.assign(piece.drafts[k], values);
  savingDay.value = true;
  const ok = await piece.flush(k);
  savingDay.value = false;
  if (!alive) return;
  if (ok) {
    const anchor = dayEditor.value?.anchor;
    dayEditor.value = null;
    if (problems.value.length) showProblems();
    else showToast({ type: 'success', title: 'Нарахування збережено', messages: ['Суму та пояснення оновлено.'] });
    await nextTick(); anchor?.focus();
  } else showProblems();
}
watch(owner, allowed => { if (allowed) piece.load(period.value); });
const year = computed(() => Number(period.value.slice(0, 4))), month = computed(() => Number(period.value.slice(5)));
const years = Array.from({ length: 101 }, (_, i) => 2000 + i);
const monthGen = ['січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];
const now = new Date(), today = `${periodKey(now.getFullYear(), now.getMonth() + 1)}-${String(now.getDate()).padStart(2, '0')}`;
const number = value => new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 2 }).format(value);
const money = value => value === null || !Number.isFinite(value) ? '—' : `${number(value)} грн`;
const problems = computed(() => [
  ...(loadError.value ? [{ id: 'hours-load', message: loadError.value, reload: true }] : []),
  ...(owner.value && piece.loadError ? [{ id: 'money-load', message: piece.loadError, reload: true }] : []),
  ...failures.value.map(([k, state]) => ({ id: k, message: `${failureLabel(employees.value, k)}: ${state.error}`, reload: state.conflict })),
  ...(owner.value ? piece.failures.map(([k, state]) => ({ id: k, message: `${failureLabel(piece.employees, k)}: ${state.error}`, reload: state.conflict })) : []),
]);
function failureLabel(team, k) {
  const [id, date] = k.split('|');
  return `${team.find(e => e.id === Number(id))?.name || 'Працівник'}, ${date}`;
}
function showProblems() {
  if (dayError.value) { dayNotice(dayError.value); return; }
  if (!problems.value.length) return;
  const mustReload = problems.value.some(p => p.reload);
  showToast({ type: 'error', title: 'Не вдалося зберегти або завантажити дані',
    messages: problems.value.map(p => p.message), actionLabel: mustReload ? 'Оновити табель' : 'Повторити',
    onAction: mustReload ? () => reload() : async () => { await flushBoth(); if (problems.value.length) showProblems(); } });
}
watch(() => problems.value.map(p => `${p.id}:${p.message}`).join('|'), value => { if (value) showProblems(); });
let reportedRevision = 0;
watch(() => [hours.savedRevision + piece.savedRevision, pending.value || piece.pending, unsaved.value || piece.unsaved, problems.value.length],
  ([revision, saving, dirty, errors]) => {
    if (errors || dayError.value) return;
    // Одне повідомлення на чергу вводу. Читання даних не є збереженням.
    if (saving || dirty) showToast({ type: 'info', title: saving ? 'Зберігаємо…' : 'Очікуємо збереження…', messages: ['Зміни зберігаються автоматично.'] });
    else if (revision > reportedRevision) {
      reportedRevision = revision;
      showToast({ type: 'success', title: 'Усі зміни збережено', messages: ['Години та суми оновлено.'] });
    } else if (toast.type === 'info' || toast.type === 'error') closeToast();
  });
async function flushBoth() {
  const results = await Promise.all([flushAll(), owner.value ? piece.flushAll() : true]);
  return results.every(Boolean);
}
async function load(target = period.value) {
  const ok = await loadHours(target);
  if (ok && owner.value) return piece.load(target);
  return ok;
}
async function reload(confirmed = false) {
  if (dayEditor.value || openingDay.value) { dayNotice({ type: 'warning', title: 'Спочатку закрийте нарахування', messages: ['Збережіть зміни або натисніть «Скасувати» у віконці.'] }); return; }
  if (loading.value || pending.value || piece.loading || piece.pending) return;
  if (!confirmed && (unsaved.value || failures.value.length || piece.unsaved || piece.failures.length)) {
    showToast({ type: 'warning', title: 'Оновити обидві таблиці?',
      messages: ['Незбережені зміни буде втрачено. Збережені записи залишаться без змін.'],
      actionLabel: 'Відкинути зміни й оновити', onAction: () => reload(true),
      secondaryLabel: 'Залишити зміни', onSecondary: showProblems });
    return;
  }
  closeToast();
  if (await load()) showToast({ type: 'success', title: 'Табель оновлено', messages: ['Завантажено актуальні дані за вибраний місяць.'] });
  else showProblems();
}
async function selectPeriod(event, field) {
  if (dayEditor.value || openingDay.value) {
    event.target.value = String(field === 'year' ? year.value : month.value);
    dayNotice({ type: 'warning', title: 'Спочатку закрийте нарахування', messages: ['Збережіть зміни або натисніть «Скасувати» у віконці.'] }); return;
  }
  const value = Number(event.target.value), target = periodKey(field === 'year' ? value : year.value, field === 'month' ? value : month.value);
  if (!loading.value && !piece.loading && await flushBoth()) await load(target);
  else showProblems();
  event.target.value = String(field === 'year' ? year.value : month.value);
}
function entryHint(id, date) {
  const e = entries[key(id, date)];
  return states[key(id, date)]?.error || (e ? `${e.note ? e.note + ' · ' : ''}${e.updated_by || 'CRM'} · ${e.updated_at}` : '');
}
function pieceHint(id, date) {
  const entry = piece.entries[key(id, date)];
  if (entry?.amount == null) return entry?.note ? `Суму не вказано — ${entry.note}` : 'Додати суму та пояснення';
  return `${money(Number(entry.amount))}${entry.note ? ` — ${entry.note}` : ''}`;
}
function nextEmployee(id, date) {
  flush(key(id, date));
  const next = employees.value[(employees.value.findIndex(e => e.id === id) + 1) % employees.value.length];
  const input = document.querySelector(`[data-cell="${key(next.id, date)}"]`); input?.focus(); input?.select();
}
</script>

<style scoped>
.wt-table-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:22px 20px;border-bottom:1px solid #e8edf4}.wt-table-heading h2{font-size:18px;font-weight:750;margin:0 0 6px;color:#28344c}.wt-table-heading h2 span{font-size:9px;letter-spacing:.08em;color:#7464c1;background:#f1edff;padding:5px 7px;border-radius:5px;vertical-align:middle;margin-left:8px}.wt-table-heading p{font-size:12px;color:#7a869a;margin:0}.wt-money-sheet{margin-top:30px}.wt-money-sheet .wt-table-heading h2 span{color:#24816f;background:#e8f7f1}.wt-money-scroll input:hover,.wt-money-scroll input:focus-visible{background:#eef9f4}.wt-money-scroll .wt-total{color:#24816f}.wt-money-scroll .wt-avatar{background:#e8f7f1;color:#24816f}@media(max-width:700px){.wt-table-heading{align-items:flex-start;flex-direction:column;padding:18px 14px}}
.work-time{max-width:1800px;margin:auto;color:#202a40}.wt-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:26px}.wt-eyebrow{font-size:10px;font-weight:750;letter-spacing:.12em;color:#7565cb}.wt-heading h1{font-size:28px;font-weight:800;letter-spacing:-.04em;margin:8px 0}.wt-heading p,.wt-dialog-header p{font-size:13px;color:#6b7c93;margin:0}.work-time .btn-primary{background:#6250df;border-color:#6250df;border-radius:9px;font-size:13px;padding:10px 15px}.wt-stats{display:flex;gap:38px;flex-wrap:wrap;margin-bottom:26px}.wt-stats>div{padding-left:15px;border-left:2px solid #e2e5ef;min-width:140px}.wt-stats>div:first-child{border-color:#6250df}.wt-stats span{font-size:12px;color:#6b7c93;display:block;margin-bottom:5px}.wt-stats strong{font-size:25px;font-weight:650;letter-spacing:-.03em}.wt-stats small{font-size:13px;color:#6b7c93;font-weight:500}.wt-sheet{background:#fff;border:1px solid #e3e9f2;border-radius:14px;overflow:hidden}.wt-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px;flex-wrap:wrap}.wt-period{display:flex;align-items:center;gap:9px}.wt-period>i{color:#7466cb;font-size:18px;margin-right:4px}.wt-period .form-select{width:150px;font-size:13px;border-radius:8px;min-height:39px;border-color:#e3e9f2}.wt-period .wt-year{width:100px}.wt-toolbar-right{display:flex;align-items:center;gap:12px}.wt-refresh{border:1px solid #e3e9f2;border-radius:8px;color:#6b7c93}.wt-scroll{overflow:auto;max-width:100%;scrollbar-width:thin}.wt-scroll table{border-collapse:separate;border-spacing:0;width:100%;font-variant-numeric:tabular-nums}.wt-scroll th,.wt-scroll td{text-align:center;border-top:1px solid var(--wt-grid-color);border-right:1px solid var(--wt-grid-color);background:#fff;min-width:38px;padding:0;height:68px}.wt-scroll thead th{height:56px;font-size:11px;font-weight:500;color:#7b879b}.wt-scroll thead b{display:block;font-size:13px;color:#38465f;font-weight:650;margin-bottom:3px}.wt-scroll thead span{display:block}.wt-scroll .today b{background:#6250df;color:white;border-radius:6px;display:inline-block;min-width:25px;padding:2px}.wt-scroll .wt-person{position:sticky;left:0;z-index:2;min-width:210px;max-width:210px;text-align:left;padding:0 14px;box-shadow:4px 0 7px #24304b05}.wt-person-label{display:flex;align-items:center;gap:10px;padding:10px 0;text-align:left;background:none;border:0;color:inherit;width:100%}.wt-person-label b{font-size:13px;font-weight:650;display:block;white-space:normal;overflow-wrap:anywhere}.wt-person-label small{display:block;font-size:11px;color:#7a869a;margin-top:3px;font-weight:400}.wt-avatar{display:grid;place-items:center;flex-shrink:0;width:31px;height:31px;border-radius:10px;background:#f0edff;color:#7460da;font-size:12px}.wt-scroll input{border:0;background:transparent;text-align:center;width:38px;padding:10px 1px;border-radius:4px;font-size:13px;color:#344158;appearance:textfield;height:44px}.wt-scroll input:hover{background:#f0edff}.wt-scroll input:focus-visible{outline:2px solid #8e80e9;outline-offset:-2px;background:#f4f1ff}.wt-scroll input:disabled{color:#a8b1c2;background:#f1f3f6}.wt-scroll .wt-cell-error input{background:#fff0f2;color:#b32d45}.wt-scroll .wt-cell-saving{box-shadow:inset 0 -2px #c1b8f8}.wt-scroll .wt-total{position:sticky;right:0;z-index:2;min-width:85px;border-left:1px solid var(--wt-grid-color);border-right:0;font-size:13px}.wt-total b{font-weight:650}.wt-total small{display:block;color:#7a869a;font-size:11px;margin-top:3px}.wt-scroll tfoot th,.wt-scroll tfoot td{height:43px;font-size:11px;background:#f8f9fc;color:#6b7c93;font-weight:600}.wt-sheet-footer{padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border-top:1px solid #e8edf4;color:#7a869a;font-size:11px}.wt-weekend-swatch{display:inline-block;width:11px;height:11px;border:1px solid #e3e9f2;background:var(--wt-weekend-bg);border-radius:3px;margin-right:4px}.wt-bottom-note{color:#6b7c93;font-size:12px;margin:17px 0}.wt-empty{text-align:center;padding:65px 20px;color:#6b7c93}.wt-empty>i{font-size:32px;color:#9c90df}.wt-empty h2{font-size:20px;color:#344158;margin:18px 0 10px}.wt-empty p{font-size:13px;margin:0}.wt-fields label,.wt-field{font-size:12px;font-weight:600;color:#68768d;display:block;min-width:0}.wt-fields .form-control,.wt-fields .form-select,.wt-field .form-control{margin-top:7px;border-color:#e3e9f2;border-radius:8px;font-size:14px;min-height:42px;color:#24314a}.wt-full{grid-column:1/-1}button:focus-visible{outline:3px solid #a5b4fc;outline-offset:2px}button:disabled{opacity:.6;cursor:not-allowed}@media(max-width:700px){.wt-heading h1{font-size:24px}.wt-stats{gap:20px}.wt-stats>div:last-child{display:none}.wt-stats>div{min-width:115px}.wt-stats strong{font-size:22px}.wt-scroll .wt-person{min-width:154px;max-width:154px;padding:0 10px}.wt-avatar{display:none}.wt-person-label b{font-size:12px}.wt-scroll .wt-total{min-width:65px}.wt-period{gap:6px}.wt-period .form-select{width:135px}.wt-period .wt-year{width:90px}.wt-toolbar{padding:12px}.wt-sheet-footer{line-height:1.8}}@media(pointer:coarse){.wt-scroll input{width:44px;min-height:44px;font-size:16px}}
/* Спільна сітка не дозволяє сумам чи довгим іменам розсувати колонки дат. */
.work-time{container-type:inline-size;--wt-grid-color:#1a1a1a;--wt-weekend-bg:#e5e7eb;--wt-person-width:180px;--wt-total-width:90px;--wt-day-min:26px}
.wt-scroll .wt-calendar{table-layout:fixed;width:100%;min-width:calc(var(--wt-person-width) + var(--wt-total-width) + var(--wt-day-count) * var(--wt-day-min))}
/* Вихідні виділяємо сірим у всій колонці; сьогоднішня дата має пріоритет. */
.wt-scroll .wt-calendar .weekend{background:var(--wt-weekend-bg)}
/* Підсвічуємо сьогоднішню колонку повністю, зокрема у вихідні та в підсумках. */
.wt-scroll .wt-calendar .today{background:#f0edff}
/* Темні межі роблять кожну клітинку обох табелів чітко видимою. */
.wt-scroll .wt-calendar{border-left:1px solid var(--wt-grid-color);border-right:1px solid var(--wt-grid-color)}
.wt-calendar tfoot th,.wt-calendar tfoot td{border-bottom:1px solid var(--wt-grid-color)}
.wt-calendar .wt-person-col{width:var(--wt-person-width)}
.wt-calendar .wt-total-col{width:var(--wt-total-width)}
.wt-scroll .wt-calendar th,.wt-scroll .wt-calendar td{min-width:0;box-sizing:border-box}
.wt-scroll .wt-calendar .wt-person{min-width:0;max-width:none;padding:0 10px}
.wt-scroll .wt-calendar .wt-total{min-width:0;padding:0 3px;overflow-wrap:anywhere}
.wt-calendar .wt-total b,.wt-day-sum{display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.wt-calendar tbody td:not(.wt-total){position:relative}
.wt-money-value{display:block;height:25px;padding:3px 1px 0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-size:12px;color:#344158}
.wt-day-note{display:grid;place-items:center;width:24px;height:24px;margin:0 auto;padding:0;border:0;border-radius:6px;background:#f0f4f5;color:#8795a5;font-size:13px}
.wt-day-note.has-note{color:#24816f;background:#e5f4ee}.wt-day-note:hover{background:#d5eee3;color:#1d705d}
.wt-day-note[aria-invalid=true]{color:#b32d45;background:#fff0f2;outline:1px solid #c44758}
.wt-calendar tbody th,.wt-calendar tbody td{height:60px}
.wt-calendar .wt-person-label{gap:7px}
.wt-calendar .wt-person-label b{font-size:12px}
.wt-scroll .wt-calendar input{box-sizing:border-box;display:block;width:100%;min-width:0;padding:10px 0;font-size:12px;text-overflow:ellipsis}
.wt-calendar .wt-day-sum{padding:0 1px;font-size:10px}
/* Під час вводу показуємо довгу суму повністю, не змінюючи ширину таблиці. */
.wt-calendar tbody td:focus-within{z-index:4}
.wt-scroll .wt-calendar input:focus{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:66px;background:#fff;box-shadow:0 3px 14px #24304b26;outline:2px solid #8e80e9;outline-offset:-2px}
@container (max-width:1150px){
  .wt-scroll{--wt-person-width:140px;--wt-total-width:80px;--wt-day-min:24px}
  .wt-calendar .wt-avatar{display:none}
  .wt-scroll .wt-calendar input{font-size:11px}
  .wt-scroll .wt-calendar .wt-person{padding:0 8px}
  .wt-calendar .wt-person-label small{font-size:10px}
}
/* На телефоні не стискаємо 31 день до нечитабельних цифр. */
@container (max-width:900px){.wt-scroll{--wt-person-width:140px;--wt-total-width:80px;--wt-day-min:34px}.wt-scroll .wt-calendar input{font-size:12px}}
@media(pointer:coarse){.wt-scroll .wt-calendar input:focus{font-size:16px}}
/* Підсумки поруч із заголовком; коли місця бракує, весь блок переходить нижче. */
.wt-heading{column-gap:32px;row-gap:22px}
.wt-title{flex:1 1 420px;min-width:0}
.wt-heading .wt-stats{flex:0 1 auto;gap:24px;margin:0}
.wt-heading .wt-stats>div{min-width:0}
.wt-heading .wt-stats strong{white-space:nowrap}
@media(max-width:700px){
  .wt-heading .wt-stats{width:100%;gap:18px 24px}
  .wt-heading .wt-stats>div{flex:1 1 120px}
  .wt-heading .wt-stats>div:last-child{display:block}
}
</style>
