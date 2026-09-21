<template>
  <section class="wp-page" :aria-busy="loading">
    <header class="wp-heading">
      <div><span class="wp-eyebrow">НАЛАШТУВАННЯ · ЛИШЕ ВЛАСНИК</span><h1>Працівники та зарплата</h1><p>Команда, ставки та місячні нарахування в одному місці</p></div>
      <button class="btn btn-primary" :disabled="loading || busy" @click="openEmployee()"><i class="bi bi-plus-lg" aria-hidden="true"></i> Додати працівника</button>
    </header>
    <div class="wp-toolbar">
      <div class="wp-tabs" aria-label="Розділ"><button :aria-pressed="tab === 'report'" @click="tab = 'report'">Нарахування за місяць</button><button :aria-pressed="tab === 'employees'" @click="tab = 'employees'">Працівники <span>{{ employees.length }}</span></button></div>
      <div class="wp-period"><select class="form-select" aria-label="Місяць" :value="month" :disabled="loading" @change="changePeriod($event, 'month')"><option v-for="(name, i) in workMonths" :key="name" :value="i + 1">{{ name }}</option></select><select class="form-select wp-year" aria-label="Рік" :value="year" :disabled="loading" @change="changePeriod($event, 'year')"><option v-for="y in years" :key="y">{{ y }}</option></select><button class="btn btn-outline-secondary" aria-label="Оновити дані" :disabled="loading" @click="load(true)"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button></div>
    </div>

    <div v-show="tab === 'report'">
      <PayrollLedger v-if="report" ref="ledger" :report="report" :label="periodLabel" :disabled="loading || busy || !!kind" @notify="ledgerNotice" @reload="confirmReload" @details="openPayroll" />
      <div v-else class="wp-card wp-empty">{{ loading ? 'Завантажуємо відомість…' : 'Звіт не завантажено. Спробуйте оновити дані.' }}</div>
    </div>

    <div v-if="tab === 'employees'" class="wp-card">
      <div class="wp-card-heading"><div><h2>Працівники</h2><p>Це працівники виробництва, не облікові записи для входу в CRM.</p></div><label class="form-check"><input v-model="showArchived" class="form-check-input" type="checkbox" /> Показати архів</label></div>
      <div v-if="visibleEmployees.length" class="wp-table-scroll"><table class="wp-table wp-team" aria-label="Працівники виробництва">
        <thead><tr><th>Ім’я та прізвище</th><th>Посада</th><th>Тип обліку</th><th>Статус</th><th>Дії</th></tr></thead>
        <tbody><tr v-for="employee in visibleEmployees" :key="employee.id"><th scope="row">{{ employee.name }}</th><td>{{ employee.position || '—' }}</td><td><span class="wp-badge">{{ typeLabel(employee.payment_type) }}</span></td><td>{{ employee.archived_on ? 'Архів' : 'Працює' }}</td><td class="wp-actions"><button class="wp-icon" :aria-label="`Редагувати працівника ${employee.name}`" @click="openEmployee(employee)"><i class="bi bi-pencil" aria-hidden="true"></i></button><button class="wp-icon wp-danger" :aria-label="`Видалити працівника ${employee.name}`" @click="openDelete(employee)"><i class="bi bi-trash3" aria-hidden="true"></i></button></td></tr></tbody>
      </table></div>
      <div v-else class="wp-empty">{{ loading ? 'Завантажуємо команду…' : 'Працівників ще немає. Додайте першого працівника кнопкою зверху.' }}</div>
    </div>

    <dialog ref="modal" class="wp-dialog" :class="{ 'wp-dialog--payroll': kind === 'payroll' }" aria-labelledby="wp-dialog-title" @cancel.prevent="closeDialog()">
      <header class="wp-dialog-header"><div><span class="wp-eyebrow">{{ kind === 'payroll' ? periodLabel : 'ПРАЦІВНИКИ' }}</span><h2 id="wp-dialog-title">{{ kind === 'delete' ? 'Видалити назавжди?' : kind === 'payroll' ? currentRow?.employee.name : form.id ? 'Редагувати працівника' : 'Новий працівник' }}</h2></div><button class="wp-icon" aria-label="Закрити вікно" :disabled="busy" @click="closeDialog()"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
      <form v-if="kind === 'employee'" @submit.prevent="submitEmployee">
        <fieldset :disabled="busy">
          <label class="wp-field">Ім’я та прізвище<input v-model="form.name" name="employee_name" class="form-control" required maxlength="100" :aria-invalid="!!errors.name" /></label>
          <label class="wp-field">Посада<input v-model="form.position" name="position" class="form-control" maxlength="100" :aria-invalid="!!errors.position" /></label>
          <label class="wp-field">Тип обліку<select v-model="form.payment_type" name="payment_type" class="form-select" :disabled="!!form.id" :aria-invalid="!!errors.payment_type"><option value="hourly">За годинами — верхня таблиця табеля</option><option value="piecework">За виконану роботу — суми в нижній таблиці</option></select></label>
          <p class="wp-help">Кожен працівник — лише в одній таблиці. Тип наявного працівника незмінний. Оклад і ставки задаються у «Нарахування за місяць». Додаткову деньовку для відрядного працівника внесіть сумою з поясненням у «За виконану роботу».</p>
          <label v-if="form.id" class="form-check mb-3"><input v-model="form.archived" type="checkbox" class="form-check-input" /> В архіві — зберігати історію, не додавати нові дні</label>
          <footer class="wp-dialog-footer"><button type="button" class="btn btn-outline-secondary" @click="closeDialog()">Скасувати</button><button class="btn btn-primary" type="submit">{{ busy ? 'Зберігаємо…' : form.id ? 'Зберегти зміни' : 'Додати працівника' }}</button></footer>
        </fieldset>
      </form>
      <form v-else-if="kind === 'payroll'" @submit.prevent="submitPayroll">
        <fieldset :disabled="busy">
          <div class="wp-payroll-grid">
            <section class="wp-payroll-block" aria-labelledby="wp-base-title">
              <h3 id="wp-base-title"><span>1</span> Оклад і ставка</h3>
              <div class="wp-source"><span>З табеля</span><strong>{{ currentRow.employee.payment_type === 'hourly' ? `${currentRow.days} дн. · ${number(currentRow.hours)} год` : money(currentRow.piecework_pay ?? currentRow.base_pay) }}</strong></div>
              <label class="wp-field">Місячний оклад, грн<input v-model="form.monthly_salary" name="monthly_salary" class="form-control" inputmode="decimal" required :aria-invalid="!!errors.monthly_salary" /><small>Переноситься вперед до зміни суми; 0 — без окладу. Збережені оклади інших місяців не змінюються.</small></label>
              <template v-if="currentRow.employee.payment_type === 'hourly'">
                <label class="wp-field">Спосіб розрахунку<select v-model="form.rate_mode" name="rate_mode" class="form-select"><option value="hourly">Ставка за годину</option><option value="daily">Ставка за день · {{ currentRow.daily_hours }} годин</option></select></label>
                <label class="wp-field">{{ form.rate_mode === 'daily' ? `Ставка за ${currentRow.daily_hours} годин, грн` : 'Ставка за годину, грн' }}<input v-model="form.rate" name="rate" class="form-control" inputmode="decimal" placeholder="Не вказано" :aria-invalid="!!errors.rate" /></label>
                <p class="wp-help">{{ form.rate_mode === 'daily' ? `Оплата годин = години ÷ ${currentRow.daily_hours} × денна ставка.` : 'Оплата годин = години × ставка.' }} Без неоплачуваної перерви. Ставка — лише для цього місяця, додається до окладу.</p>
              </template>
              <p v-else class="wp-help">Оклад + суми з табеля. Не дублюйте оклад у денних сумах. Додаткову деньовку внесіть у «За виконану роботу»: суму й пояснення, наприклад «Деньовка, 7 годин».</p>
            </section>
            <section class="wp-payroll-block" aria-labelledby="wp-extras-title">
              <h3 id="wp-extras-title"><span>2</span> Доплати й виплати</h3>
              <label class="wp-field">Премія, грн<input v-model="form.bonus" name="bonus" class="form-control" inputmode="decimal" required :aria-invalid="!!errors.bonus" /></label>
              <label class="wp-field">Відшкодування витрат, грн<input v-model="form.expenses" name="expenses" class="form-control" inputmode="decimal" required :aria-invalid="!!errors.expenses" /></label>
              <label class="wp-field">Коригування зарплати, грн<input v-model="form.adjustment" name="adjustment" class="form-control" inputmode="decimal" required :aria-invalid="!!errors.adjustment" /><small>Можна зі знаком мінус</small></label>
              <label v-if="Number(String(form.adjustment).replace(',', '.')) !== 0" class="wp-field">Причина коригування<textarea v-model="form.adjustment_reason" name="adjustment_reason" rows="2" class="form-control" maxlength="500" required :aria-invalid="!!errors.adjustment_reason"></textarea></label>
              <label class="wp-field">Усього виплачено за місяць, грн<input v-model="form.paid" name="paid" class="form-control" inputmode="decimal" required :aria-invalid="!!errors.paid" /><small>Загальна сума, не нова окрема виплата</small></label>
            </section>
            <section class="wp-payroll-block wp-payroll-summary" aria-labelledby="wp-total-title">
              <h3 id="wp-total-title"><span>3</span> Підсумок за місяць</h3>
              <div class="wp-preview"><div><span>Місячний оклад</span><b>{{ money(preview.monthly) }}</b></div><div><span>{{ currentRow.employee.payment_type === 'hourly' ? 'За робочі години' : 'За виконану роботу' }}</span><b>{{ money(currentRow.employee.payment_type === 'hourly' ? preview.time : preview.work) }}</b></div><div><span>Зарплата з коригуванням</span><b>{{ money(preview.salary) }}</b></div><div><span>Нараховано з доплатами</span><b>{{ money(preview.accrued) }}</b></div><div class="wp-preview-balance"><span>Залишок до виплати</span><strong>{{ money(preview.balance) }}</strong></div><small>Попередній розрахунок за даними табеля. Мінус у залишку — переплата.</small></div>
              <label class="wp-field">Примітка<textarea v-model="form.note" name="note" rows="2" class="form-control" maxlength="500" placeholder="Коментар до нарахування" :aria-invalid="!!errors.note"></textarea></label>
            </section>
          </div>
          <footer class="wp-dialog-footer"><button class="btn btn-outline-secondary" type="button" @click="closeDialog()">Скасувати</button><button class="btn btn-primary" type="submit">{{ busy ? 'Зберігаємо…' : 'Зберегти нарахування' }}</button></footer>
        </fieldset>
      </form>
      <template v-else-if="kind === 'delete'">
        <p class="wp-delete-name">{{ deleteTarget?.name }}</p><p>Працівника, усі години, нарахування, суми за виконану роботу й історію змін буде видалено <strong>за всі місяці</strong>.</p><p class="wp-help">Відновити через CRM неможливо. Якщо працівник більше не працює — краще перенести його в архів. Інших працівників це не зачепить.</p>
        <footer class="wp-dialog-footer"><button ref="deleteCancel" class="btn btn-outline-secondary" :disabled="busy" @click="closeDialog(true)">Скасувати</button><button class="btn btn-danger" :disabled="busy" data-testid="confirm-delete" @click="submitDelete">{{ busy ? 'Видаляємо…' : 'Видалити назавжди' }}</button></footer>
      </template>
    </dialog>
    <Toast v-bind="toast" :teleport-to="kind && modal ? modal : 'body'" @close="closeToast" @action="runAction" @secondary="runSecondary" />
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import Toast from '../../components/ui/Toast.vue';
import PayrollLedger from './PayrollLedger.vue';
import { useToast } from '../../composables/useToast';
import { workMonths, periodKey, workError } from '../../utils/workTime';
import { createWorkEmployee, updateWorkEmployee, deleteWorkEmployee } from '../../services/workTimeApi';
import { fetchWorkEmployees, fetchPayrollReport, saveMonthlyPayroll } from '../../services/workPayrollApi';

const tab = ref('report'), employees = ref([]), report = ref(null), loading = ref(false), busy = ref(false), showArchived = ref(false);
const now = new Date(), period = ref(periodKey(now.getFullYear(), now.getMonth() + 1));
const year = computed(() => Number(period.value.slice(0, 4))), month = computed(() => Number(period.value.slice(5)));
const years = Array.from({ length: 101 }, (_, i) => 2000 + i);
const periodLabel = computed(() => `${workMonths[month.value - 1]} ${year.value}`);
const visibleEmployees = computed(() => employees.value.filter(e => showArchived.value || !e.archived_on));
const ledger = ref();
const { toast, showToast, closeToast, runAction, runSecondary } = useToast();
const modal = ref(), kind = ref(''), form = reactive({}), original = ref(''), errors = ref({}), currentRow = ref(null), deleteTarget = ref(null), deleteCancel = ref();
const dirty = computed(() => ['employee', 'payroll'].includes(kind.value) && JSON.stringify(form) !== original.value);
let focusBefore = null, alive = true;
const number = value => new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 2 }).format(Number(value));
const money = value => value === null || value === undefined || !Number.isFinite(Number(value)) ? '—' : `${number(value)} грн`;
const typeLabel = type => type === 'piecework' ? 'За виконану роботу' : 'За годинами';
function ledgerNotice(event) {
  // Відкладений успіх таблиці не перекриває повідомлення відкритої форми.
  if (event.type === 'success' && (kind.value || loading.value)) return;
  showToast(event);
}
function showError(error, retry = null) {
  errors.value = error?.response?.data?.errors || {};
  const messages = Object.values(errors.value).flat();
  showToast({ type: 'error', title: 'Не вдалося виконати дію', messages: messages.length ? messages : [workError(error)],
    actionLabel: retry ? 'Повторити' : '', onAction: retry });
}
async function load(notify = false, target = period.value, discard = false) {
  if (loading.value) return false;
  loading.value = true;
  try {
    const clean = await ledger.value?.flush();
    if (clean === false && !discard) return false;
    const [team, payroll] = await Promise.all([fetchWorkEmployees(), fetchPayrollReport(target)]);
    if (!alive) return false;
    employees.value = team.data.employees; report.value = payroll.data; period.value = target;
    if (notify) showToast({ type: 'success', title: 'Дані оновлено', messages: ['Звіт перераховано за актуальним табелем.'] });
    return true;
  } catch (error) { if (alive) showError(error, () => load(notify, target)); return false; }
  finally { if (alive) loading.value = false; }
}
function confirmReload() {
  showToast({ type: 'warning', title: 'Оновити відомість?', messages: ['Незбережені зміни в клітинках цього місяця буде відкинуто після успішного завантаження. Скопіюйте потрібні значення перед оновленням.'],
    actionLabel: 'Відкинути й оновити', onAction: () => load(true, period.value, true), secondaryLabel: 'Залишити зміни' });
}
async function changePeriod(event, field) {
  const value = Number(event.target.value);
  await load(false, periodKey(field === 'year' ? value : year.value, field === 'month' ? value : month.value));
  event.target.value = String(field === 'year' ? year.value : month.value);
}
async function openDialog(type, values = {}) {
  if (busy.value || loading.value || await ledger.value?.flush() === false) return;
  focusBefore = document.activeElement; closeToast(); errors.value = {};
  Object.keys(form).forEach(k => delete form[k]); Object.assign(form, values); original.value = JSON.stringify(form);
  kind.value = type; await nextTick(); modal.value.showModal();
  if (type === 'delete') deleteCancel.value?.focus();
}
function closeDialog(discard = false) {
  if (busy.value) return;
  if (!discard && dirty.value) {
    showToast({ type: 'warning', title: 'Є незбережені зміни', messages: ['Закрити форму без збереження?'],
      actionLabel: 'Відкинути зміни', onAction: () => closeDialog(true), secondaryLabel: 'Продовжити редагування' });
    return;
  }
  closeToast(); modal.value.close(); kind.value = ''; focusBefore?.focus();
}
function openEmployee(employee = null) {
  return openDialog('employee', { id: employee?.id ?? null, name: employee?.name ?? '', position: employee?.position ?? '',
    payment_type: employee?.payment_type ?? 'hourly', archived: !!employee?.archived_on, version: employee?.version ?? 0, request_key: crypto.randomUUID() });
}
function openPayroll(row) {
  currentRow.value = row;
  return openDialog('payroll', { id: row.employee_id, month: period.value, version: row.version, rate_mode: row.rate_mode,
    rate: (row.rate_mode === 'daily' ? row.daily_rate : row.hourly_rate) ?? '', monthly_salary: row.monthly_salary ?? '0.00', bonus: row.bonus, expenses: row.expenses,
    adjustment: row.adjustment, adjustment_reason: row.adjustment_reason ?? '', paid: row.paid, note: row.note ?? '' });
}
function openDelete(employee) { deleteTarget.value = { ...employee }; return openDialog('delete'); }
async function success(message) {
  busy.value = false; closeDialog(true);
  if (await load()) showToast({ type: 'success', title: 'Готово', messages: [message] });
  else showToast({ type: 'warning', title: 'Зміни збережені, але звіт не оновився', messages: [message, 'Оновіть дані, щоб побачити актуальний результат.'], actionLabel: 'Оновити', onAction: () => load(true) });
}
async function submitEmployee() {
  if (busy.value) return;
  busy.value = true; errors.value = {};
  try {
    const values = { name: form.name.trim(), position: form.position.trim() || null, payment_type: form.payment_type };
    if (form.id) await updateWorkEmployee(form.id, { ...values, archived: form.archived, version: form.version });
    else await createWorkEmployee({ ...values, request_key: form.request_key });
    await success(form.id ? 'Дані працівника оновлено.' : 'Працівника додано. Він з’явиться у відповідній таблиці табеля.');
  } catch (error) { showError(error); }
  finally { busy.value = false; }
}
async function submitPayroll() {
  if (busy.value) return;
  busy.value = true; errors.value = {};
  try {
    const { id, ...values } = form;
    await saveMonthlyPayroll(id, { ...values, rate: form.rate_mode === 'piecework' || form.rate === '' ? null : form.rate,
      adjustment_reason: form.adjustment_reason.trim() || null, note: form.note.trim() || null });
    await success('Нарахування за вибраний місяць збережено.');
  } catch (error) { showError(error); }
  finally { busy.value = false; }
}
async function submitDelete() {
  if (busy.value || !deleteTarget.value) return;
  busy.value = true;
  try { await deleteWorkEmployee(deleteTarget.value.id, deleteTarget.value.version); await success('Працівника та всі його записи видалено. Відновлення через CRM недоступне.'); }
  catch (error) { showError(error); }
  finally { busy.value = false; }
}
function cents(value, nullable = false) {
  const text = String(value ?? '').trim().replace(',', '.');
  if (nullable && !text) return null;
  if (!/^-?\d{1,7}(\.\d{1,2})?$/.test(text)) throw new Error('Некоректна сума');
  return Math.round(Number(text) * 100);
}
const preview = computed(() => {
  try {
    const rate = cents(form.rate, true), h = Math.round(Number(currentRow.value?.hours || 0) * 100);
    const denominator = form.rate_mode === 'daily' ? currentRow.value.daily_hours * 100 : 100;
    const time = form.rate_mode === 'piecework' ? 0 : rate === null ? null : Math.round(h * rate / denominator);
    const work = cents(currentRow.value?.piecework_pay ?? (form.rate_mode === 'piecework' ? currentRow.value?.base_pay : '0'));
    const monthly = cents(form.monthly_salary);
    if (time === null) return { monthly: monthly / 100, time: null, work: work / 100, salary: null, accrued: null, balance: null };
    const base = monthly + time + work;
    const salary = base + cents(form.adjustment), accrued = salary + cents(form.bonus) + cents(form.expenses);
    return { monthly: monthly / 100, time: time / 100, work: work / 100, salary: salary / 100, accrued: accrued / 100, balance: (accrued - cents(form.paid)) / 100 };
  } catch { return { salary: null, accrued: null, balance: null }; }
});
const protect = event => { if (dirty.value || busy.value || ledger.value?.hasUnsaved) { event.preventDefault(); event.returnValue = ''; } };
onMounted(() => { load(); window.addEventListener('beforeunload', protect); });
onBeforeUnmount(() => { alive = false; window.removeEventListener('beforeunload', protect); });
</script>

<style scoped>
.wp-page{max-width:1800px;margin:auto;color:#24314a}.wp-heading{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:26px}.wp-eyebrow{color:#7666be;letter-spacing:.1em;font-size:10px;font-weight:750}.wp-heading h1{font-size:28px;letter-spacing:-.035em;font-weight:800;margin:8px 0}.wp-heading p,.wp-card-heading p{color:#7b879a;font-size:13px;margin:0}.wp-page .btn{border-radius:9px;font-size:13px;padding:9px 14px}.wp-page .btn-primary{background:#6250df;border-color:#6250df}.wp-toolbar,.wp-period{display:flex;align-items:center;gap:10px}.wp-toolbar{justify-content:space-between;margin-bottom:24px;flex-wrap:wrap}.wp-tabs{display:flex;background:#eceef6;padding:5px;border-radius:11px;gap:4px}.wp-tabs button{border:0;background:none;padding:10px 17px;border-radius:8px;font-size:13px;color:#6c7890;font-weight:650}.wp-tabs button[aria-pressed=true]{background:white;color:#6250df;box-shadow:0 2px 7px #202e4910}.wp-tabs span{margin-left:5px;font-size:11px}.wp-period .form-select{font-size:13px;border-color:#e0e5ef;border-radius:8px;min-height:39px;width:145px}.wp-period .wp-year{width:95px}.wp-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:24px}.wp-stats>div{background:#fff;border:1px solid #e3e9f2;border-radius:13px;padding:20px 24px}.wp-stats>div:last-child{background:#f1faf7;border-color:#c8e8dd}.wp-stats span{display:block;color:#728097;font-size:12px}.wp-stats strong{font-size:26px;display:block;margin:7px 0;font-variant-numeric:tabular-nums}.wp-stats small{font-size:11px;color:#7c889b}.wp-card{background:#fff;border:1px solid #e3e9f2;border-radius:14px;overflow:hidden}.wp-card-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:22px}.wp-card-heading h2{font-size:18px;font-weight:750;margin:0 0 7px}.wp-link{font-size:12px;color:#6250df;white-space:nowrap;text-decoration:none}.wp-table-scroll{overflow:auto}.wp-table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums;font-size:12px;min-width:1080px}.wp-table th,.wp-table td{padding:16px 12px;border-top:1px solid #e9edf5;text-align:right;vertical-align:middle;white-space:nowrap}.wp-table thead{background:#f8f9fd;color:#78859b;font-size:11px}.wp-table th:first-child,.wp-table td:first-child{text-align:left;padding-left:22px;min-width:160px;white-space:normal}.wp-table tbody th{font-weight:600}.wp-table small{display:block;color:#8b96a7;font-size:10px;margin-top:4px;font-weight:400}.wp-name{background:none;border:0;color:#24314a;padding:0;text-align:left;font-weight:650}.wp-name:hover{color:#6250df}.wp-emphasis{font-weight:700}.wp-positive{color:#217e69;font-weight:650}.wp-table tfoot{background:#f7f8fc;font-weight:700}.wp-missing{color:#9b6717;background:#fff5df;border-radius:5px;padding:4px 6px;font-size:11px}.wp-note{padding:16px 22px;color:#8190a6;font-size:11px;border-top:1px solid #e9edf5;line-height:1.6}.wp-empty{padding:60px 25px;text-align:center;color:#7b879a;font-size:14px}.wp-icon{display:inline-grid;place-items:center;border:1px solid #e2e7f0;background:#fff;color:#7a869c;border-radius:8px;width:34px;height:34px}.wp-icon:hover{background:#f3f0ff;color:#6250df}.wp-danger:hover{background:#fff0f2;color:#c4314c;border-color:#efc4cb}.wp-actions{display:flex;justify-content:flex-end;gap:8px}.wp-badge{padding:5px 9px;border-radius:6px;background:#f2efff;color:#705cb8;font-size:11px}.wp-team{min-width:680px}.wp-team td,.wp-team th{text-align:left}.form-check{font-size:12px;color:#75829a}.wp-dialog{width:min(620px,calc(100vw - 28px));max-height:calc(100dvh - 32px);padding:25px;border:1px solid #e3e9f2;border-radius:16px;box-shadow:0 25px 80px #19253c35;color:#24314a;overflow:auto}.wp-dialog::backdrop{background:#17213966;backdrop-filter:blur(2px)}.wp-dialog-header{display:flex;justify-content:space-between;align-items:flex-start;gap:15px;margin-bottom:20px}.wp-dialog-header h2{font-size:22px;font-weight:750;margin:7px 0;overflow-wrap:anywhere}.wp-field{display:block;font-size:12px;color:#6c7a90;font-weight:600;margin-bottom:16px;min-width:0}.wp-field .form-control,.wp-field .form-select{margin-top:7px;font-size:14px;border-color:#e0e6ef;border-radius:8px;min-height:41px}.wp-field small{font-size:10px;font-weight:400;display:block;margin-top:5px}.wp-field [aria-invalid=true]{border-color:#c4314c;background:#fff7f8}.wp-help{font-size:12px;color:#8190a4;line-height:1.7}.wp-dialog-footer{display:flex;justify-content:flex-end;gap:10px;padding-top:16px;border-top:1px solid #e9edf5;margin-top:7px}.wp-fields{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}.wp-full{grid-column:1/-1}.wp-source{background:#f4f1ff;border-radius:10px;display:flex;justify-content:space-between;align-items:center;padding:15px 18px;margin-bottom:20px;color:#716498;font-size:12px;gap:12px}.wp-source strong{font-size:18px;color:#6651ba}.wp-preview{background:#f6faf9;border:1px solid #deeee8;border-radius:11px;padding:14px 18px;margin:5px 0 16px}.wp-preview>div{display:flex;justify-content:space-between;gap:15px;padding:7px 0;font-size:12px;color:#6b7d81}.wp-preview strong{color:#24816f;font-size:19px}.wp-preview b{color:#344557}.wp-preview>small{font-size:10px;color:#82918d;display:block;margin-top:10px;line-height:1.6}.wp-delete-name{font-weight:750;font-size:18px;overflow-wrap:anywhere}button:disabled{opacity:.55;cursor:not-allowed}button:focus-visible,a:focus-visible{outline:3px solid #c4b9fa;outline-offset:3px}.wp-dialog textarea{resize:vertical}@media(max-width:800px){.wp-heading{align-items:flex-start;flex-direction:column}.wp-heading h1{font-size:24px}.wp-stats{gap:10px}.wp-stats>div{padding:15px}.wp-stats strong{font-size:19px}.wp-card-heading{align-items:flex-start;flex-direction:column}.wp-period{width:100%}.wp-fields{grid-template-columns:1fr}.wp-dialog{padding:20px}.wp-source{align-items:flex-start;flex-direction:column}}@media(max-width:500px){.wp-stats{grid-template-columns:1fr}.wp-tabs{width:100%}.wp-tabs button{flex:1;padding:10px;font-size:12px}}
.wp-dialog--payroll{width:min(1120px,calc(100vw - 32px));padding:22px}
.wp-dialog--payroll .wp-dialog-header{margin-bottom:16px}
.wp-payroll-grid{display:grid;grid-template-columns:1fr 1fr 1.08fr;gap:16px;align-items:stretch}
.wp-payroll-block{min-width:0;border:1px solid #e4e8f1;border-radius:12px;padding:16px}
.wp-payroll-block h3{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:750;margin:0 0 16px;color:#34415b}
.wp-payroll-block h3>span{display:grid;place-items:center;width:25px;height:25px;border-radius:7px;background:#f0edff;color:#6b57cc;font-size:12px}
.wp-dialog--payroll .wp-field{margin-bottom:12px;font-size:11px}
.wp-dialog--payroll .wp-field:last-child{margin-bottom:0}
.wp-dialog--payroll .form-control,.wp-dialog--payroll .form-select{min-height:36px;font-size:13px;margin-top:5px;padding:7px 10px}
.wp-dialog--payroll .wp-field small{line-height:1.45}
.wp-dialog--payroll .wp-help{font-size:11px;line-height:1.55;margin:0}
.wp-dialog--payroll .wp-source{padding:10px 12px;margin-bottom:14px;font-size:11px}
.wp-dialog--payroll .wp-source strong{font-size:14px}
.wp-payroll-summary{background:#f8fbfa;border-color:#e0eee8}
.wp-dialog--payroll .wp-preview{background:none;border:0;padding:0;margin:0 0 18px}
.wp-dialog--payroll .wp-preview>div{padding:9px 0;font-size:11px}
.wp-dialog--payroll .wp-preview b,.wp-dialog--payroll .wp-preview strong{white-space:nowrap;font-variant-numeric:tabular-nums}
.wp-dialog--payroll .wp-preview-balance{border-top:1px solid #dce9e3;margin-top:7px;align-items:center}
.wp-dialog--payroll .wp-dialog-footer{margin-top:16px}
@media(max-width:900px){.wp-payroll-grid{grid-template-columns:1fr}.wp-dialog--payroll{width:min(620px,calc(100vw - 24px));padding:18px}.wp-payroll-block{padding:14px}.wp-dialog--payroll .wp-source{flex-direction:row;align-items:center}}
</style>
