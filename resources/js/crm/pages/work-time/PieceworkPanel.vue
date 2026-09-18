<template>
  <section class="pw-panel" aria-label="Облік виконаних робіт" :aria-busy="loading">
    <header class="pw-heading"><div><h2>Робота за результат</h2><p>Без годин: кількість × розцінка або домовлена сума за всю роботу.</p></div><button class="btn btn-primary" :disabled="loading || !available.length" @click="openForm()">+ Додати роботу</button></header>
    <p class="pw-private"><i class="bi bi-lock" aria-hidden="true"></i> Суми доступні лише власнику CRM</p>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }} <button class="btn btn-sm btn-outline-danger" @click="load(page)">Повторити</button></div>
    <div v-if="result && !error && !loading" class="pw-summary">
      <div><span>Нараховано за місяць</span><strong>{{ money(result.summary.total) }}</strong></div>
      <div><span>Виплачено за ці роботи</span><strong>{{ money(result.summary.paid) }}</strong></div>
      <div class="pw-outstanding"><span>{{ Number(result.summary.balance) < 0 ? 'Переплата за ці роботи' : 'Залишилось виплатити' }}</span><strong>{{ money(Math.abs(Number(result.summary.balance))) }}</strong></div>
    </div>
    <div v-if="result" class="pw-people"><button v-for="employee in result.employees" :key="employee.id" class="pw-person" type="button" @click="$emit('edit-employee', employee)"><i class="bi bi-person" aria-hidden="true"></i><span>{{ employee.name }}<small>{{ employee.archived_on ? 'В архіві' : employee.position || 'За виконану роботу' }}</small></span><i class="bi bi-pencil" aria-hidden="true"></i></button></div>
    <div v-if="loading" class="pw-empty" role="status">Завантажуємо роботи…</div>
    <div v-else-if="result && !error && !result.entries.length" class="pw-empty"><i class="bi bi-clipboard-check" aria-hidden="true"></i><h3>Ще немає виконаних робіт</h3><p>{{ available.length ? 'Додайте першу роботу: що зроблено, скільки та за яку суму.' : 'Додайте працівника з типом оплати «За виконану роботу».' }}</p></div>
    <div v-else-if="result && !error" class="pw-table-scroll"><table class="table align-middle"><caption class="visually-hidden">Виконані роботи за вибраний місяць</caption><thead><tr><th>Дата / працівник</th><th>Виконана робота</th><th>Кількість</th><th>Нараховано</th><th>Виплачено</th><th>Залишок</th><th><span class="visually-hidden">Дії</span></th></tr></thead><tbody><tr v-for="entry in result.entries" :key="entry.id"><td><b>{{ name(entry.employee_id) }}</b><small>{{ dateLabel(entry.date) }}</small></td><td>{{ entry.description }}<small>{{ entry.pricing_mode === 'unit' ? `${money(entry.unit_rate)} / ${entry.unit === 'pair' ? 'пара' : 'шт.'}` : 'Домовлена сума за роботу' }}</small></td><td>{{ entry.quantity }} {{ entry.unit === 'pair' ? 'пар' : 'шт.' }}</td><td>{{ money(entry.total) }}</td><td>{{ money(entry.paid) }}</td><td><span class="pw-badge" :class="{ settled: Number(entry.balance) === 0 }">{{ Number(entry.balance) < 0 ? 'Переплата ' : '' }}{{ money(Math.abs(Number(entry.balance))) }}</span></td><td><button class="btn btn-sm btn-light" :aria-label="`Редагувати: ${entry.description}`" @click="openForm(entry)"><i class="bi bi-pencil" aria-hidden="true"></i></button></td></tr></tbody></table></div>
    <footer v-if="result && !error" class="pw-footer"><span>{{ result.count }} записів · Підсумки за всі роботи вибраного місяця</span><div v-if="result.last_page > 1"><button class="btn btn-sm btn-light" :disabled="loading || page === 1" @click="load(page - 1)">Назад</button><span>{{ page }} / {{ result.last_page }}</span><button class="btn btn-sm btn-light" :disabled="loading || page >= result.last_page" @click="load(page + 1)">Далі</button></div></footer>
    <dialog ref="dialog" class="pw-dialog" aria-labelledby="pw-title" @cancel.prevent="closeForm">
      <form v-if="form" @submit.prevent="save">
        <header class="pw-heading"><div><h2 id="pw-title">{{ form.id ? 'Виконана робота' : 'Нова робота' }}</h2><p>Оплата за результат, без обліку годин</p></div><button class="btn btn-light" type="button" aria-label="Закрити роботу" :disabled="saving" @click="closeForm">×</button></header>
        <div v-if="formError" class="alert alert-danger" role="alert">{{ formError }}</div>
        <fieldset :disabled="saving"><div class="pw-fields">
          <label class="pw-full">Працівник<select v-model="form.employee_id" name="piece_employee" class="form-select" required :disabled="!!form.id"><option value="" disabled>Оберіть працівника</option><option v-for="employee in formEmployees" :key="employee.id" :value="employee.id">{{ employee.name }}</option></select></label>
          <label>Дата виконання<input v-model="form.date" name="piece_date" type="date" class="form-control" min="2000-01-01" max="2100-12-31" required /></label>
          <label>Одиниця<select v-model="form.unit" name="piece_unit" class="form-select"><option value="piece">Штуки</option><option value="pair">Пари</option></select></label>
          <label class="pw-full">Що зроблено<input v-model="form.description" name="piece_description" class="form-control" placeholder="Наприклад, вирізала устілки" maxlength="200" required /></label>
          <label>Кількість<input v-model="form.quantity" name="piece_quantity" class="form-control" type="number" min="1" max="1000000" step="1" required /></label>
          <label>Як рахуємо<select v-model="form.pricing_mode" name="pricing_mode" class="form-select"><option value="unit">Ціна за одиницю</option><option value="fixed">Сума за всю роботу</option></select></label>
          <label v-if="form.pricing_mode === 'unit'">Ціна за {{ form.unit === 'pair' ? 'пару' : 'штуку' }}, грн<input v-model="form.unit_rate" name="unit_rate" class="form-control" inputmode="decimal" maxlength="10" required /></label>
          <label v-else>Домовлена сума, грн<input v-model="form.agreed_total" name="agreed_total" class="form-control" inputmode="decimal" maxlength="10" required /></label>
          <label>Уже виплачено, грн<input v-model="form.paid" name="piece_paid" class="form-control" inputmode="decimal" maxlength="10" required /></label>
          <label class="pw-full">Примітка<textarea v-model="form.note" name="piece_note" class="form-control" maxlength="500" rows="2" placeholder="Наприклад, частину оплати вже передали"></textarea></label>
        </div></fieldset>
        <div class="pw-preview" aria-live="polite"><div><span>Нараховано за роботу</span><strong data-testid="piece-total">{{ preview ? money(preview.total / 100) : '—' }}</strong></div><div><span>{{ preview?.balance < 0 ? 'Переплата' : 'Залишилось виплатити' }}</span><strong>{{ preview ? money(Math.abs(preview.balance) / 100) : '—' }}</strong></div></div>
        <p class="pw-private">«Уже виплачено» — загальна сплачена сума за цю роботу, а не новий платіж. Внутрішній облік без податків.</p>
        <footer class="pw-footer"><button type="button" class="btn btn-light" :disabled="saving" @click="closeForm">Скасувати</button><button class="btn btn-primary" type="submit" :disabled="saving || conflict">{{ saving ? 'Зберігаємо…' : 'Зберегти роботу' }}</button></footer>
      </form>
    </dialog>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { createPiecework, fetchPiecework, updatePiecework } from '../../services/pieceworkApi';
import { workError } from '../../utils/workTime';

const props = defineProps({ month: { type: String, required: true }, refreshKey: { type: Array, default: () => [] } });
defineEmits(['edit-employee']);
const result = ref(null), loading = ref(false), error = ref(''), page = ref(1);
const dialog = ref(), form = ref(null), original = ref(''), saving = ref(false), formError = ref(''), conflict = ref(false);
let sequence = 0, alive = true, lastFocus;
const money = value => new Intl.NumberFormat('uk-UA', { style: 'currency', currency: 'UAH' }).format(Number(value));
const dateLabel = value => value.split('-').reverse().join('.');
const name = id => result.value?.employees.find(employee => employee.id === id)?.name || 'Працівник';
const available = computed(() => (result.value?.employees || []).filter(employee => !employee.archived_on));
const formEmployees = computed(() => (result.value?.employees || []).filter(employee => !employee.archived_on || employee.id === form.value?.employee_id));
const dirty = computed(() => !!form.value && JSON.stringify(form.value) !== original.value);
function cents(value) {
  const text = String(value ?? '').trim().replace(',', '.');
  if (!/^\d{1,7}(\.\d{1,2})?$/.test(text) || Number(text) > 1000000) throw new Error('Вкажіть суму від 0 до 1 000 000 грн, до двох знаків після коми.');
  return Math.round(Number(text) * 100);
}
function calculation() {
  const quantity = Number(form.value.quantity);
  if (!Number.isInteger(quantity) || quantity < 1 || quantity > 1000000) throw new Error('Кількість має бути цілим числом від 1 до 1 000 000.');
  const total = form.value.pricing_mode === 'unit' ? quantity * cents(form.value.unit_rate) : cents(form.value.agreed_total);
  if (total > 100000000) throw new Error('Сума однієї роботи не може перевищувати 1 000 000 грн.');
  return { total, balance: total - cents(form.value.paid) };
}
const preview = computed(() => { try { return form.value ? calculation() : null; } catch { return null; } });
async function load(target = 1) {
  const token = ++sequence; loading.value = true; error.value = '';
  try {
    const { data } = await fetchPiecework(props.month, target);
    if (!alive || token !== sequence) return;
    result.value = data; page.value = data.page;
  } catch (e) { if (alive && token === sequence) { error.value = workError(e); result.value = null; } }
  finally { if (alive && token === sequence) loading.value = false; }
}
async function openForm(entry = null) {
  lastFocus = document.activeElement;
  const now = new Date(), today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  form.value = { id: entry?.id || null, request_key: crypto.randomUUID(), employee_id: entry?.employee_id ?? available.value[0]?.id ?? '',
    date: entry?.date || (today.startsWith(props.month) ? today : `${props.month}-01`), description: entry?.description || '',
    quantity: entry?.quantity ?? '', unit: entry?.unit || 'piece', pricing_mode: entry?.pricing_mode || 'unit',
    unit_rate: entry?.unit_rate ?? '', agreed_total: entry?.pricing_mode === 'fixed' ? entry.total : '', paid: entry?.paid || '0', note: entry?.note || '', version: entry?.version };
  original.value = JSON.stringify(form.value); formError.value = ''; conflict.value = false;
  await nextTick(); dialog.value.showModal();
}
function closeForm() {
  if (saving.value || (dirty.value && !window.confirm('Закрити без збереження роботи?'))) return;
  dialog.value.close(); form.value = null; lastFocus?.focus();
}
async function save() {
  if (saving.value || conflict.value) return;
  let payload;
  try {
    calculation();
    payload = { request_key: form.value.request_key, employee_id: Number(form.value.employee_id), date: form.value.date,
      description: form.value.description.trim(), quantity: Number(form.value.quantity), unit: form.value.unit,
      pricing_mode: form.value.pricing_mode, paid: (cents(form.value.paid) / 100).toFixed(2), note: form.value.note.trim() || null };
    const priceField = form.value.pricing_mode === 'unit' ? 'unit_rate' : 'agreed_total';
    payload[priceField] = (cents(form.value[priceField]) / 100).toFixed(2);
    if (form.value.id) payload.version = form.value.version;
  } catch (e) { formError.value = e.message; return; }
  saving.value = true; formError.value = '';
  try {
    if (form.value.id) await updatePiecework(form.value.id, payload); else await createPiecework(payload);
    dialog.value.close(); form.value = null; lastFocus?.focus(); await load(1);
  } catch (e) { conflict.value = e?.response?.status === 409; formError.value = conflict.value ? 'Запис уже змінився. Закрийте вікно, оновіть список і відкрийте роботу знову.' : workError(e); }
  finally { saving.value = false; }
}
const unload = event => { if (dirty.value || saving.value) { event.preventDefault(); event.returnValue = ''; } };
watch(() => [props.month, props.refreshKey], () => load(1), { immediate: true });
onMounted(() => window.addEventListener('beforeunload', unload));
onBeforeUnmount(() => { alive = false; sequence++; window.removeEventListener('beforeunload', unload); });
</script>

<style scoped>
.pw-panel{padding:24px;color:#24314a}.pw-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px}.pw-heading h2{font-size:21px;font-weight:750;margin:0 0 6px;letter-spacing:-.025em}.pw-heading p,.pw-private{font-size:12px;color:#76859b;line-height:1.6;margin:0}.pw-private{margin-bottom:18px}.pw-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:20px 0}.pw-summary>div{border:1px solid #e6eaf2;border-radius:13px;padding:18px;background:#fafbfe}.pw-summary span{display:block;font-size:12px;color:#758398;margin-bottom:8px}.pw-summary strong{font-size:25px;font-variant-numeric:tabular-nums}.pw-summary .pw-outstanding{background:#f0faf7;border-color:#d0ebe3;color:#21816b}.pw-people{display:flex;flex-wrap:wrap;gap:10px;margin:20px 0}.pw-person{display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid #e7eaf3;border-radius:10px;background:white;color:#38465c;font-size:13px;text-align:left}.pw-person:hover{border-color:#a99ce8;background:#faf8ff}.pw-person small{display:block;font-size:11px;color:#8791a3;margin-top:3px}.pw-person>i:first-child{color:#7965d1;background:#f3efff;padding:8px;border-radius:8px}.pw-person>i:last-child{font-size:11px;color:#9b92b5}.pw-table-scroll{overflow:auto}.pw-table-scroll table{min-width:790px;font-size:13px;margin-bottom:0}.pw-table-scroll th{color:#8390a4;font-size:11px;font-weight:650;background:#fafbfe;padding:13px 10px}.pw-table-scroll td{padding:16px 10px;border-color:#edf0f6}.pw-table-scroll small{display:block;color:#8491a5;margin-top:5px;font-size:11px}.pw-table-scroll b{font-weight:650}.pw-badge{white-space:nowrap;background:#fff4e3;color:#9b6a27;padding:6px 8px;border-radius:6px;font-size:12px}.pw-badge.settled{background:#edf8f3;color:#27846c}.pw-empty{text-align:center;padding:48px 15px;color:#8190a5}.pw-empty>i{font-size:30px;color:#a298cc}.pw-empty h3{font-size:18px;color:#4b5870;margin:16px 0 8px}.pw-empty p{font-size:13px}.pw-footer{display:flex;align-items:center;justify-content:space-between;gap:15px;padding-top:18px;font-size:11px;color:#8190a5}.pw-footer>div{display:flex;align-items:center;gap:12px}.pw-dialog{width:min(580px,calc(100% - 24px));max-height:calc(100dvh - 36px);overflow:auto;border:1px solid #e3e9f2;border-radius:18px;padding:26px;box-shadow:0 24px 80px #18223930;color:#24314a}.pw-dialog::backdrop{background:#17213966;backdrop-filter:blur(2px)}.pw-fields{display:grid;grid-template-columns:1fr 1fr;gap:16px}.pw-fields label{font-size:12px;font-weight:600;color:#6c7a90}.pw-fields .form-control,.pw-fields .form-select{margin-top:7px;border-color:#e3e9f2;min-height:42px;font-size:14px;border-radius:8px}.pw-full{grid-column:1/-1}.pw-preview{margin:20px 0;background:#f3f0ff;border-radius:12px;padding:15px 18px}.pw-preview>div{display:flex;justify-content:space-between;gap:12px;padding:5px 0;font-size:13px;color:#7a709b}.pw-preview strong{color:#6651bf}.pw-dialog .alert{font-size:12px}button:focus-visible{outline:3px solid #a5b4fc;outline-offset:2px}@media(max-width:700px){.pw-panel{padding:16px}.pw-heading{flex-wrap:wrap}.pw-summary{grid-template-columns:1fr;gap:8px}.pw-summary>div{display:flex;justify-content:space-between;align-items:center;padding:12px}.pw-summary span{margin:0}.pw-summary strong{font-size:20px}.pw-dialog{padding:20px}.pw-footer{flex-wrap:wrap}}
</style>
