<template>
  <section data-material="laminate" :aria-busy="loading || saving">
    <header class="panel-heading"><div><h2>Плюш + поролон</h2><p>Три шари полотна · устілка та внутрішня частина верху.</p></div><button class="btn btn-primary" :disabled="!ready || loading || saving" @click="newCalculation"><i class="bi bi-plus-lg" aria-hidden="true"></i> Новий розрахунок</button></header>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }} <button class="link-button" :disabled="loading || saving" @click="load(page)">Оновити список</button></div>
    <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
    <p v-if="loading && !form" role="status">Завантажуємо розрахунок полотна…</p>
    <div v-if="form" class="panel-workspace">
      <form class="panel-card panel-form" @submit.prevent="save">
        <fieldset :disabled="saving">
          <legend>Ціни та витрата матеріалів<span class="state" :class="{ pending: dirty }">{{ dirty ? 'Не збережено' : 'Збережено' }}</span></legend>
          <section v-for="(group, index) in groups" :key="group.key" class="form-section">
            <h3><span>{{ index + 1 }}</span> {{ group.title }}</h3>
            <div class="fields"><label v-for="field in group.fields" :key="field.key">{{ field.label }}<div class="input-group"><input v-model="form[field.key]" :name="field.key" inputmode="decimal" :pattern="pattern(field.key)" required class="form-control" /><span class="input-group-text">{{ field.unit }}</span></div></label></div>
            <p class="hint">{{ group.hint }}</p>
            <p v-if="preview" class="layer-rate">{{ money(preview.breakdown[index].square_metre_cost_uah) }} грн/м²<span v-if="group.key === 'foam'"> · лист {{ money(preview.foam_sheet_uah) }} грн без доставки</span></p>
          </section>
          <section class="form-section">
            <h3><span>4</span> Заготовки на одну капцю</h3>
            <h4>Устілка · прямокутник</h4>
            <div class="fields"><label v-for="field in insoleFields" :key="field.key">{{ field.label }}<div class="input-group"><input v-model="form[field.key]" :name="field.key" inputmode="decimal" :pattern="pattern(field.key)" required class="form-control" /><span class="input-group-text">см</span></div></label></div>
            <p class="hint">Беремо весь відрізаний прямокутник, включно з тим, що обрізається після шиття.</p>
            <h4 class="upper-heading">Внутрішній верх · трапеція</h4>
            <div class="fields trapezoid-fields"><label v-for="field in upperFields" :key="field.key">{{ field.label }}<div class="input-group"><input v-model="form[field.key]" :name="field.key" inputmode="decimal" :pattern="pattern(field.key)" required class="form-control" /><span class="input-group-text">см</span></div></label></div>
            <p class="hint">Те саме склеєне полотно, трапеції розкладені щільно. На пару автоматично рахуємо 2 устілки та 2 внутрішні деталі верху.</p>
          </section>
          <details class="extra-fields"><summary>Доставка матеріалів · необов’язково</summary><p class="hint">Розподіліть доставку на вказану одиницю матеріалу. Порожньо — не врахована; 0 — уже включена або без доплати.</p><div class="fields"><label v-for="field in shippingFields" :key="field.key">{{ field.label }}<div class="input-group"><input v-model="form[field.key]" :name="field.key" inputmode="decimal" :pattern="pattern(field.key)" placeholder="Не врахована" class="form-control" /><span class="input-group-text">грн</span></div></label></div></details>
          <details class="extra-fields"><summary>Назва, дата та примітка</summary><div class="fields">
            <label class="full-width">Назва розрахунку<input v-model="form.name" name="name" required maxlength="160" class="form-control" /></label>
            <label>Дата розцінки <small>· необов’язково</small><input v-model="form.purchased_on" name="purchased_on" type="date" class="form-control" /></label>
            <label class="full-width">Примітка<textarea v-model="form.note" name="note" rows="2" maxlength="2000" class="form-control"></textarea></label>
          </div></details>
          <div v-if="formError" class="alert alert-danger mt-3" role="alert">{{ formError }}</div>
          <div class="form-actions"><button class="btn btn-primary" type="submit" :disabled="!dirty || !preview || !form.name.trim()">{{ saving ? 'Зберігаємо…' : selectedId ? 'Зберегти зміни' : 'Зберегти розрахунок' }}</button><button v-if="dirty && selectedId" type="button" class="btn secondary-button" @click="applyCalculation(savedCalculation)">Скасувати зміни</button></div>
          <p class="hint mb-0">Результат змінюється одразу. Збереження — лише за кнопкою.</p>
        </fieldset>
      </form>
      <aside class="panel-result">
        <div class="pair-result"><div class="result-label"><span>Склеєне полотно на 1 пару капців</span><i class="bi bi-layers" aria-hidden="true"></i></div><strong data-testid="laminate-unit-cost" role="status">{{ preview ? money(preview.unit_cost_uah) : '—' }} <small>грн</small></strong><p>Плюш + клейова павутинка + поролон 5 мм</p><span v-if="dirty" class="draft-label">Попередній розрахунок · ще не збережено</span></div>
        <div v-if="preview" class="panel-card calculation">
          <h3>Куди витрачається полотно</h3><dl>
            <div><dt>2 устілки<small>{{ number(preview.insole_pair_area_m2) }} м² · прямокутники</small></dt><dd data-testid="laminate-insole-cost">{{ money(preview.insole_pair_cost_uah) }} грн</dd></div>
            <div><dt>2 внутрішні деталі верху<small>{{ number(preview.upper_pair_area_m2) }} м² · трапеції</small></dt><dd data-testid="laminate-upper-cost">{{ money(preview.upper_pair_cost_uah) }} грн</dd></div>
            <div><dt>Разом на пару</dt><dd data-testid="laminate-area">{{ number(preview.pair_area_m2) }} м²</dd></div>
            <div><dt>Ціна 1 м² склеєного полотна</dt><dd>{{ money(preview.square_metre_cost_uah) }} грн</dd></div>
          </dl><p class="formula">{{ money(preview.square_metre_cost_uah) }} грн/м² × {{ number(preview.pair_area_m2) }} м² ≈ <b>{{ money(preview.unit_cost_uah) }} грн</b></p>
          <p class="hint">2 прямокутники: 2 × довжина × ширина. 2 трапеції: (верхня + нижня основа) × висота. Сантиметри переводимо в м²; ціну м² не округлюємо перед множенням.</p>
          <details class="extra-fields"><summary>Вартість кожного шару</summary><div class="table-scroll"><table class="layer-table"><caption class="visually-hidden">Вартість шарів склеєного полотна у гривнях</caption><thead><tr><th scope="col">Шар</th><th scope="col">1 м²</th><th scope="col">На пару</th></tr></thead><tbody><tr v-for="row in preview.breakdown" :key="row.key"><th scope="row">{{ row.label }}</th><td>{{ money(row.square_metre_cost_uah) }}</td><td>{{ money(row.unit_cost_uah) }}</td></tr></tbody></table></div><p class="hint">Рядки показані до копійок. Підсумок обчислено з неокруглених значень.</p></details>
        </div>
        <p v-else class="calculation-hint">Заповніть ціни, розміри трьох матеріалів і заготовок. Кожна заготовка має поміщатися в полотно.</p>
        <div class="scope-note"><i class="bi bi-info-circle" aria-hidden="true"></i><div>Це матеріали склеєного полотна. Менша поролонова вставка рахується окремо й тут не дублюється. Робота зі склеювання, крайові відходи та брак не включені.<p v-if="missingShipping.length">Доставка не врахована: {{ missingShipping.join(', ') }}.</p></div></div>
      </aside>
    </div>
    <section v-if="ready" class="panel-card history"><header><div><h3>Збережені розрахунки полотна</h3><p>Нові ціни можна зберегти окремо, не змінюючи попередні.</p></div><button class="btn secondary-button" :disabled="loading || saving" @click="load(page)">Оновити</button></header>
      <p v-if="!calculations.length" class="history-empty">Збережіть перший розрахунок, щоб він залишився у CRM.</p>
      <div v-else class="table-scroll"><table><caption class="visually-hidden">Розрахунки склеєного полотна: {{ total }} записів</caption><thead><tr><th scope="col">Розрахунок</th><th scope="col">1 м², грн</th><th scope="col">На пару, грн ≈</th><th scope="col"><span class="visually-hidden">Дії</span></th></tr></thead><tbody><tr v-for="item in calculations" :key="item.id" :class="{ selected: selectedId === item.id }"><th scope="row">{{ item.name }}<small>{{ item.purchased_on ? item.purchased_on.split('-').reverse().join('.') : 'Дата не вказана' }}</small></th><td>{{ money(item.calculation.square_metre_cost_uah) }}</td><td><b>{{ money(item.calculation.unit_cost_uah) }}</b></td><td><button class="link-button" :disabled="saving" :aria-label="`Відкрити: ${item.name}`" @click="chooseCalculation(item)">Відкрити</button></td></tr></tbody></table></div>
      <footer v-if="lastPage > 1"><button class="btn secondary-button" :disabled="page <= 1 || loading || saving" @click="load(page - 1)">Назад</button><span>{{ page }} / {{ lastPage }}</span><button class="btn secondary-button" :disabled="page >= lastPage || loading || saving" @click="load(page + 1)">Далі</button></footer>
    </section>
  </section>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { fetchLaminateCosts, createLaminateCost, updateLaminateCost, costError } from '@/crm/services/productionCostsApi';
import { calculateLaminateCost, laminateFields, laminatePrecision, laminateShipping, emptyLaminateForm } from '@/crm/utils/laminateCosts';
import { decimalInput } from '@/crm/utils/soleCosts';

const emit = defineEmits(['saving']);
const groups = [
  { key: 'plush', title: 'Плюш вельбо', hint: 'Ціна одного погонного метра, не квадратного. Площу рахуємо за шириною рулону.', fields: [{ key: 'plush_price_metre_uah', label: 'Ціна за 1 погонний метр', unit: 'грн' }, { key: 'plush_width_cm', label: 'Ширина плюшу', unit: 'см' }] },
  { key: 'web', title: 'Клейова павутинка', hint: 'Ціна за цілий рулон. Довжина — у метрах, ширина — у сантиметрах.', fields: [{ key: 'web_roll_price_uah', label: 'Ціна цілого рулону', unit: 'грн' }, { key: 'web_roll_length_m', label: 'Довжина рулону', unit: 'м' }, { key: 'web_width_cm', label: 'Ширина павутинки', unit: 'см' }] },
  { key: 'foam', title: 'Поролон 5 мм для склеювання', hint: 'Один шар поролону в полотні. Розцінку фіксуємо тут; окрему вставку не змінюємо.', fields: [{ key: 'foam_sheet_price_usd', label: 'Ціна одного листа', unit: '$' }, { key: 'usd_rate', label: 'Курс долара', unit: 'грн / $' }, { key: 'foam_sheet_length_cm', label: 'Довжина листа', unit: 'см' }, { key: 'foam_sheet_width_cm', label: 'Ширина листа', unit: 'см' }] },
];
const insoleFields = [{ key: 'insole_length_cm', label: 'Довжина прямокутника' }, { key: 'insole_width_cm', label: 'Ширина прямокутника' }];
const upperFields = [{ key: 'upper_top_cm', label: 'Верхня основа' }, { key: 'upper_bottom_cm', label: 'Нижня основа' }, { key: 'upper_height_cm', label: 'Висота' }];
const shippingFields = [{ key: 'plush_shipping_metre_uah', label: 'Плюш · доставка на 1 погонний метр' }, { key: 'web_shipping_roll_uah', label: 'Павутинка · доставка цілого рулону' }, { key: 'foam_shipping_sheet_uah', label: 'Поролон · доставка на 1 лист' }];
const calculations = ref([]), page = ref(1), lastPage = ref(1), total = ref(0), ready = ref(false), loading = ref(false), saving = ref(false);
const form = ref(null), selectedId = ref(null), version = ref(null), savedCalculation = ref(null), baseline = ref(''), requestKey = ref('');
const error = ref(''), formError = ref(''), notice = ref('');
const dirty = computed(() => form.value !== null && JSON.stringify(form.value) !== baseline.value);
const preview = computed(() => form.value ? calculateLaminateCost(form.value) : null);
const missingShipping = computed(() => preview.value?.breakdown.filter(row => !row.shipping_included).map(row => row.label.toLocaleLowerCase('uk-UA')) || []);
const pattern = key => `[0-9]+([.,][0-9]{1,${laminatePrecision[key]}})?`;
const money = value => Number(value).toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const number = value => Number(value).toLocaleString('uk-UA', { maximumFractionDigits: 8 });
watch(saving, value => emit('saving', value));

function applyCalculation(item) {
  selectedId.value = item.id; version.value = item.version; savedCalculation.value = item;
  form.value = { name: item.name, purchased_on: item.purchased_on || '', note: item.note || '', ...item.inputs,
    ...Object.fromEntries(laminateShipping.map(field => [field, item.inputs[field] ?? ''])) };
  baseline.value = JSON.stringify(form.value); requestKey.value = ''; formError.value = '';
}
function canDiscard() { return !dirty.value || window.confirm('Є незбережені зміни полотна. Перейти без їх збереження?'); }
function chooseCalculation(item) { if (canDiscard()) { applyCalculation(item); notice.value = ''; } }
function newCalculation() {
  if (!canDiscard()) return;
  form.value = emptyLaminateForm(form.value || {}); selectedId.value = null; version.value = null; savedCalculation.value = null; baseline.value = '';
  requestKey.value = crypto.randomUUID(); formError.value = ''; notice.value = '';
}
async function load(nextPage = 1) {
  if (loading.value) return;
  loading.value = true; error.value = '';
  try {
    const { data } = await fetchLaminateCosts(nextPage);
    calculations.value = data.data; page.value = data.current_page; lastPage.value = data.last_page; total.value = data.total; ready.value = true;
    if (!form.value) { if (calculations.value.length) applyCalculation(calculations.value[0]); else newCalculation(); }
  } catch (err) { error.value = costError(err); }
  finally { loading.value = false; }
}
async function save() {
  if (saving.value || !dirty.value) return;
  if (!preview.value || !form.value.name.trim()) { formError.value = 'Перевірте назву, ціни, курс і розміри матеріалів та заготовок.'; return; }
  saving.value = true; formError.value = ''; notice.value = '';
  const payload = { name: form.value.name.trim(), purchased_on: form.value.purchased_on || null, note: form.value.note.trim() || null,
    ...Object.fromEntries(laminateFields.map(field => [field, laminateShipping.includes(field) && decimalInput(form.value[field]) === '' ? null : decimalInput(form.value[field])])),
    ...(selectedId.value ? { version: version.value } : { request_key: requestKey.value }) };
  try {
    const { data } = selectedId.value ? await updateLaminateCost(selectedId.value, payload) : await createLaminateCost(payload);
    applyCalculation(data); notice.value = 'Розрахунок полотна збережено. Інші матеріали й залишки не змінені.';
    await load(1);
    if (error.value) notice.value = 'Розрахунок збережено, але список не оновився. Натисніть «Оновити список».';
  } catch (err) { formError.value = costError(err); }
  finally { saving.value = false; }
}
function guardUnload(event) { if (dirty.value) { event.preventDefault(); event.returnValue = ''; } }
onMounted(() => { load(); window.addEventListener('beforeunload', guardUnload); });
onUnmounted(() => window.removeEventListener('beforeunload', guardUnload));
</script>

<style scoped src="./materialCostPanel.css"></style>
<style scoped>
.layer-rate{color:#6252d9;font-weight:650;font-size:12px;margin:8px 0 0}.layer-rate span{font-weight:400;color:#6b7c93}.form-section h4{font-size:12px;font-weight:650;color:#52617b;margin:0 0 12px}.form-section h4.upper-heading{margin-top:20px}.trapezoid-fields{grid-template-columns:repeat(3,minmax(0,1fr))}.calculation dt small{display:block;font-size:11px;color:#8b97a8;margin-top:4px}.layer-table{width:100%;font-size:12px;margin-top:12px;border-collapse:collapse}.layer-table td,.layer-table th{padding:10px 6px;border-bottom:1px solid #edf0f5}.layer-table td{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.layer-table thead th{color:#6b7c93;font-weight:500}.layer-table thead th:not(:first-child){text-align:right}.layer-table tbody th{font-weight:500}@media(max-width:600px){.trapezoid-fields{grid-template-columns:1fr}}
</style>
