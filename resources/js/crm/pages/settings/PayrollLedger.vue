<template>
  <div class="ledger">
    <header class="ledger-heading"><div><h2>{{ label }}</h2><p>Білі клітинки — для введення. Години та сірі підсумки рахуються автоматично.</p></div><a href="/work-time">Відкрити табель ↗</a></header>
    <div class="ledger-scroll">
      <table aria-label="Розрахункова відомість" class="ledger-table">
        <colgroup><col class="col-index" /><col class="col-person" /><col class="col-time" /><col class="col-rate" /><col v-for="i in 7" :key="i" /><col class="col-action" /></colgroup>
        <thead><tr class="ledger-groups"><th rowspan="2" scope="col">№</th><th rowspan="2" scope="col">Працівник</th><th scope="colgroup" colspan="2">Облік часу та ставка</th><th scope="colgroup" colspan="5">Нарахування</th><th scope="colgroup" colspan="2">Виплати</th><th rowspan="2" scope="col">Дії</th></tr>
          <tr><th scope="col">Дні / години</th><th scope="col">Ставка, грн</th><th scope="col">Місячний оклад</th><th scope="col">Зарплата</th><th scope="col">Премія</th><th scope="col">Витрати</th><th scope="col">Нараховано</th><th scope="col">Виплачено</th><th scope="col">Залишок</th></tr></thead>
        <tbody v-for="group in groups" :key="group.type">
          <tr class="ledger-section"><th colspan="12" scope="rowgroup">{{ group.label }} <span>{{ group.rows.length }}</span></th></tr>
          <tr v-for="state in group.rows" :key="state.server.employee_id" :data-testid="`payroll-row-${state.server.employee_id}`" :class="{ 'ledger-error': state.error }">
            <td class="ledger-index">{{ rows.indexOf(state) + 1 }}</td>
            <th scope="row" class="ledger-person"><button :disabled="disabled" @click="editDetails(state)">{{ state.server.employee.name }}</button><small v-if="state.server.employee.archived_on">Архів</small><small v-if="state.saving"><i class="bi bi-arrow-repeat"></i> Зберігаємо…</small><button v-if="state.error" class="ledger-error-link" aria-label="Показати помилку збереження" @click="showErrors">Не збережено <i class="bi bi-exclamation-circle"></i></button></th>
            <td class="ledger-readonly ledger-time"><template v-if="group.type !== 'piecework'">{{ state.server.days }} дн.<small>{{ number(state.server.hours) }} год</small></template><span v-else>—</span></td>
            <td v-if="group.type !== 'piecework'" class="ledger-editable ledger-rate">
              <input :value="state.values.rate" :aria-label="`Ставка — ${state.server.employee.name}`" :aria-invalid="!!state.invalid.rate" :disabled="disabled" :data-row="state.server.employee_id" data-field="rate" inputmode="decimal" placeholder="Не вказано" @input="edit(state, 'rate', $event.target.value)" @blur="save(state)" @focus="state.error && showErrors()" @keydown="navigate($event)" />
              <select :value="state.values.rate_mode" :aria-label="`Одиниця ставки — ${state.server.employee.name}`" :disabled="disabled" @change="edit(state, 'rate_mode', $event.target.value); save(state)"><option value="hourly">за годину</option><option value="daily">за {{ state.server.daily_hours }} годин</option></select>
            </td><td v-else class="ledger-readonly ledger-source">Сума з табеля</td>
            <td class="ledger-editable"><input :value="state.values.monthly_salary" :aria-label="`Місячний оклад — ${state.server.employee.name}`" :aria-invalid="!!state.invalid.monthly_salary" :disabled="disabled" :data-row="state.server.employee_id" data-field="monthly_salary" inputmode="decimal" title="Оклад за місяць. Переноситься на наступні місяці, доки не задасте іншу суму або 0." @input="edit(state, 'monthly_salary', $event.target.value)" @blur="save(state)" @focus="state.error && showErrors()" @keydown="navigate($event)" /></td>
            <td class="ledger-readonly">{{ money(state.server.salary) }}<small v-if="group.type === 'mixed' || Number(state.server.monthly_salary)">Оклад {{ money(state.server.monthly_salary) }}<br />Години {{ money(state.server.time_pay) }}<br />Роботи {{ money(state.server.piecework_pay) }}</small><small v-if="Number(state.server.adjustment)" :title="state.server.adjustment_reason">Кориг. {{ money(state.server.adjustment) }}</small></td>
            <td v-for="field in ['bonus', 'expenses']" :key="field" class="ledger-editable"><input :value="state.values[field]" :aria-label="`${labels[field]} — ${state.server.employee.name}`" :aria-invalid="!!state.invalid[field]" :disabled="disabled" :data-row="state.server.employee_id" :data-field="field" inputmode="decimal" @input="edit(state, field, $event.target.value)" @blur="save(state)" @focus="state.error && showErrors()" @keydown="navigate($event)" /></td>
            <td class="ledger-readonly ledger-total">{{ money(state.server.accrued) }}</td>
            <td class="ledger-editable"><input :value="state.values.paid" :aria-label="`Виплачено — ${state.server.employee.name}`" :aria-invalid="!!state.invalid.paid" :disabled="disabled" :data-row="state.server.employee_id" data-field="paid" inputmode="decimal" @input="edit(state, 'paid', $event.target.value)" @blur="save(state)" @focus="state.error && showErrors()" @keydown="navigate($event)" /></td>
            <td class="ledger-readonly ledger-balance">{{ money(state.server.balance) }}</td>
            <td><button class="ledger-pencil" :disabled="disabled" :aria-label="`Редагувати нарахування ${state.server.employee.name}`" title="Деталі, коригування та примітка" @click="editDetails(state)"><i class="bi bi-pencil"></i></button></td>
          </tr>
        </tbody>
        <tbody v-if="!rows.length"><tr><td colspan="12" class="ledger-empty">За цей місяць ще немає працівників.</td></tr></tbody>
        <tfoot><tr><th colspan="4">Разом <small v-if="incomplete">Підсумок неповний: без ставки {{ incomplete }}</small></th><td>{{ money(totals.monthly_salary) }}</td><td>{{ money(totals.salary) }}</td><td>{{ money(totals.bonus) }}</td><td>{{ money(totals.expenses) }}</td><td>{{ money(totals.accrued) }}</td><td>{{ money(totals.paid) }}</td><td class="ledger-balance">{{ money(totals.balance) }}</td><td></td></tr></tfoot>
      </table>
    </div>
    <footer class="ledger-footer"><div><p><i class="bi bi-info-circle"></i> Автозбереження після введення · Tab — наступне поле · Enter — наступний працівник</p><small>Усі суми в грн. Витрати додаються до зарплати. «Виплачено» — усього за місяць, не нова виплата. Підсумки оновлюються після збереження.</small></div><button v-if="errors.length" class="btn btn-outline-danger btn-sm" @click="showErrors">Помилки: {{ errors.length }}</button><button class="btn btn-primary btn-sm" :disabled="disabled || !hasUnsaved || saving" @click="flush(true)">{{ saving ? 'Зберігаємо…' : 'Зберегти зміни' }}</button></footer>
  </div>
</template>

<script setup>
import { computed, watch } from 'vue';
import { usePayrollLedger } from '../../composables/usePayrollLedger';
const props = defineProps({ report: { type: Object, required: true }, label: String, disabled: Boolean });
const emit = defineEmits(['notify', 'reload', 'details']);
const { rows, hasUnsaved, errors, saving, reset, edit, save, flush, showErrors } = usePayrollLedger(event => emit('notify', event), () => emit('reload'));
watch(() => props.report, reset, { immediate: true });
const labels = { bonus: 'Премія', expenses: 'Витрати' };
const number = value => new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 2 }).format(Number(value));
const money = value => value == null ? '—' : number(value);
const groups = computed(() => [{ type: 'hourly', label: 'За годинами' }, { type: 'mixed', label: 'Змішана оплата · години + роботи' }, { type: 'piecework', label: 'За виконану роботу / місячний оклад' }]
  .map(group => ({ ...group, rows: rows.value.filter(s => s.server.employee.payment_type === group.type) })).filter(group => group.rows.length));
const incomplete = computed(() => rows.value.filter(s => s.server.accrued === null).length);
// Показуємо лише підтверджені сервером суми, а не припущення щодо незбережених клітинок.
const totals = computed(() => Object.fromEntries(['monthly_salary', 'salary', 'bonus', 'expenses', 'accrued', 'paid', 'balance'].map(field =>
  [field, rows.value.reduce((sum, s) => sum + Math.round(Number(s.server[field] ?? 0) * 100), 0) / 100])));
async function editDetails(state) { if (await flush()) emit('details', state.server); }
function navigate(event) {
  if (event.key !== 'Enter' || event.isComposing) return;
  event.preventDefault();
  const inputs = [...event.target.closest('table').querySelectorAll(`input[data-field="${event.target.dataset.field}"]:not(:disabled)`)];
  inputs[inputs.indexOf(event.target) + (event.shiftKey ? -1 : 1)]?.focus();
}
defineExpose({ flush, hasUnsaved });
</script>

<style scoped>
.ledger{background:#fff;border:1px solid #e1e6ef;border-radius:12px;overflow:hidden;color:#24314a}.ledger-heading{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;gap:16px}.ledger-heading h2{font-size:16px;font-weight:750;margin:0 0 5px}.ledger-heading p{font-size:12px;color:#7b879a;margin:0}.ledger-heading a{font-size:12px;white-space:nowrap;color:#6250df;text-decoration:none}.ledger-scroll{overflow:auto;max-height:65vh}.ledger-table{width:100%;min-width:1060px;table-layout:fixed;border-collapse:separate;border-spacing:0;font-size:12px;font-variant-numeric:tabular-nums}.col-index{width:34px}.col-person{width:16%}.col-time{width:8%}.col-rate{width:11%}.col-action{width:46px}.ledger-table th,.ledger-table td{border-right:1px solid #e6eaf2;border-bottom:1px solid #e6eaf2;padding:8px;text-align:right;height:52px;vertical-align:middle}.ledger-table tr>*:last-child{border-right:0}.ledger-table thead{position:sticky;top:0;z-index:2;background:#f8f9fc}.ledger-table thead th{text-align:center;font-size:11px;font-weight:650;color:#687996;height:34px;padding:8px 4px}.ledger-groups th{border-top:1px solid #e6eaf2}.ledger-table .ledger-section th{text-align:left;background:#edf0f7;height:32px;padding:7px 14px;font-weight:650;color:#526079}.ledger-section span{font-weight:400;margin-left:7px;color:#8894a7}.ledger-table .ledger-person{text-align:left;font-weight:650;overflow-wrap:anywhere}.ledger-person button{border:0;background:transparent;padding:0;text-align:left;color:inherit;font-weight:inherit}.ledger-table small{display:block;font-size:10px;color:#8491a5;font-weight:400;margin-top:4px}.ledger-table .ledger-index{text-align:center;color:#8e98a9;font-size:11px}.ledger-table .ledger-time{text-align:center}.ledger-readonly{background:#f8f9fc}.ledger-total{font-weight:750}.ledger-balance{color:#24816f;font-weight:700}.ledger-source{font-size:11px;text-align:center!important;color:#8190a5}.ledger-table .ledger-editable{padding:0;background:#fff}.ledger-editable input{display:block;width:100%;height:50px;min-width:0;background:transparent;border:2px solid transparent;border-radius:0;text-align:right;padding:8px;color:#24314a;font:inherit;font-variant-numeric:tabular-nums}.ledger-editable input:hover{background:#faf8ff}.ledger-editable input:focus{outline:none;border-color:#8774ef;background:#fbfaff}.ledger-editable input[aria-invalid=true]{background:#fff4f4;border-color:#d45465}.ledger-editable input::placeholder{font-size:10px;color:#a07335}.ledger-rate input{height:30px;padding:4px 8px}.ledger-rate select{width:100%;height:24px;padding:0 8px;border:0;background:#f7f5fe;color:#7969aa;font-size:10px;text-align:right}.ledger-pencil{display:grid;place-items:center;width:28px;height:28px;border:1px solid #e2e6ef;border-radius:6px;background:#fff;color:#8693a8;margin:auto}.ledger-pencil:hover{color:#6250df;background:#f3f0ff}.ledger-table tfoot{position:sticky;bottom:0;z-index:1;background:#f0edf9;font-weight:750}.ledger-table tfoot th{text-align:left;padding-left:14px}.ledger-table tfoot td,.ledger-table tfoot th{border-bottom:0;height:52px}.ledger-footer{display:flex;align-items:center;gap:12px;padding:14px 18px;border-top:1px solid #e6eaf2}.ledger-footer>div{flex:1}.ledger-footer p{font-size:11px;margin:0 0 5px;color:#6d7b91}.ledger-footer small{font-size:10px;color:#8994a5;line-height:1.5;display:block}.ledger-footer button{white-space:nowrap}.ledger-error .ledger-person{box-shadow:inset 3px 0 #d45465}.ledger-person .ledger-error-link{display:block;font-size:10px;color:#ba4051;margin-top:5px}.ledger-empty{text-align:center!important;color:#8491a5;height:140px!important}button:focus-visible,select:focus-visible{outline:2px solid #8774ef;outline-offset:2px}button:disabled,input:disabled,select:disabled{opacity:.6;cursor:wait}@media(max-width:800px){.ledger-heading,.ledger-footer{align-items:flex-start;flex-wrap:wrap}.ledger-footer>div{flex-basis:100%}.ledger-scroll{max-height:70vh}}
</style>
