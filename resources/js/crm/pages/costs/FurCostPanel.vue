<template>
  <section class="fur-panel" data-material="fur" :aria-busy="loading || saving">
    <header class="panel-heading"><div><h2>Хутро</h2><p>Вартість із доставкою · дві зовнішні деталі верху на пару.</p></div><button class="btn btn-primary" :disabled="!ready || loading || saving" @click="newBatch"><i class="bi bi-plus-lg" aria-hidden="true"></i> Нова партія</button></header>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }} <button class="link-button" :disabled="loading || saving" @click="load(page)">Оновити список</button></div>
    <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
    <p v-if="loading && !form" role="status">Завантажуємо дані хутра…</p>

    <div v-if="form" class="panel-workspace">
      <form class="panel-card panel-form" @submit.prevent="save">
        <fieldset :disabled="saving">
          <legend>{{ selectedId ? 'Дані розрахунку' : 'Нова партія хутра' }}<span class="state" :class="{ pending: dirty }">{{ dirty ? 'Не збережено' : 'Збережено' }}</span></legend>
          <div class="purchase-source" role="group" aria-labelledby="fur-source-label">
            <h3 id="fur-source-label">Де купуєте хутро?</h3>
            <div class="source-options">
              <label :class="{ active: !isLocal }"><input v-model="form.purchase_source" type="radio" name="purchase_source" value="china" @change="sourceChanged" /><span><strong>Китай</strong><small>Викуп, валюта та міжнародна доставка</small></span></label>
              <label :class="{ active: isLocal }"><input v-model="form.purchase_source" type="radio" name="purchase_source" value="ukraine" @change="sourceChanged" /><span><strong>Україна</strong><small>Одеса чи інший місцевий постачальник</small></span></label>
            </div>
          </div>
          <section class="form-section">
            <h3><span>1</span> Скільки хутра придбали</h3>
            <div class="fields">
              <label>Загальна довжина<input v-model="form.fabric_length" name="fabric_length" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,4})?" required class="form-control" /></label>
              <label>Одиниця в рахунку<select v-model="form.length_unit" name="length_unit" class="form-select"><option value="yard">Ярди · 1 ярд = 0,9144 м</option><option value="metre">Погонні метри</option></select></label>
              <label>Ширина полотна<div class="input-group"><input v-model="form.fabric_width_cm" name="fabric_width_cm" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" required class="form-control" /><span class="input-group-text">см</span></div></label>
              <div v-if="preview" class="conversion" data-testid="fur-length"><strong>{{ number(preview.length_metres) }} пог. м</strong><span>{{ number(preview.total_area_m2) }} м² у цій партії</span></div>
            </div>
            <p class="hint">Укажіть загальну довжину всіх кольорів із цього рахунку. Ярди не дорівнюють метрам — перевірте одиницю продавця.</p>
            <p class="hint">1 погонний метр — відріз 100 см × ширина полотна. Ширина не змінюється при переведенні ярдів у метри.</p>
          </section>
          <section class="form-section">
            <h3><span>2</span> {{ isLocal ? 'Закупівля та доставка у гривнях' : 'Закупівля, доставка та курси' }}</h3>
            <div class="fields">
              <label v-for="field in purchaseFields" :key="field.key">{{ field.label }}<div class="input-group"><input v-model="form[field.key]" :name="field.key" inputmode="decimal" :pattern="field.rate ? '[0-9]+([.,][0-9]{1,4})?' : '[0-9]+([.,][0-9]{1,2})?'" required class="form-control" /><span class="input-group-text">{{ field.unit }}</span></div></label>
              <label>Доставка по Україні <small>· якщо відома</small><div class="input-group"><input v-model="form.ukraine_shipping_uah" name="ukraine_shipping_uah" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" placeholder="Не врахована" class="form-control" /><span class="input-group-text">грн</span></div></label>
            </div>
            <p v-if="isLocal" class="hint">Укажіть сплачену суму за всю партію хутра, не ціну одного метра. Додамо доставку від постачальника та інші гривневі витрати. Курси валют і китайська комісія тут не потрібні.</p>
            <p v-else class="hint">Сума лише за хутро, без підошви. Комісія — від хутра та доставки Китаєм. Митне оформлення вже в міжнародній доставці, вдруге не додаємо.</p>
            <p class="hint">Доставка лише цієї партії. Невідому суму залиште порожньою; 0 — доставка без доплати або вже включена в ціну.</p>
            <p v-if="preview && !isLocal" class="commission">Комісія: ({{ money(decimalInput(form.goods_cny)) }} + {{ money(decimalInput(form.china_shipping_cny)) }}) × {{ number(decimalInput(form.commission_percent)) }}% = <b>{{ money(preview.commission_cny) }} ¥</b></p>
          </section>
          <section class="form-section">
            <h3><span>3</span> Трапеція на одну капцю</h3>
            <div class="fields trapezoid-fields">
              <label v-for="field in shapeFields" :key="field.key">{{ field.label }}<div class="input-group"><input v-model="form[field.key]" :name="field.key" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" required class="form-control" /><span class="input-group-text">см</span></div></label>
            </div>
            <div class="fields mt-3"><label>Довжина робочого відрізу<div class="input-group"><input v-model="form.cut_length_cm" name="cut_length_cm" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" required class="form-control" /><span class="input-group-text">см</span></div></label></div>
            <p class="hint">За замовчуванням ріжемо відрізами по 100 см завдовжки на всю ширину полотна. Трапеції в ряду перевертаємо через одну; ряди йдуть по ширині полотна. Якщо кладете на стіл іншу довжину — змініть її тут.</p>
            <p class="hint">На пару беремо 2 трапеції. Плюш і поролон внутрішньої частини сюди не входять.</p>
          </section>
          <details class="extra-fields"><summary>Назва, дата та додаткові витрати</summary><div class="fields">
            <label class="full-width">Назва партії<input v-model="form.name" name="name" required maxlength="160" class="form-control" /></label>
            <label>Дата рахунку <small>· необов’язково</small><input v-model="form.purchased_on" name="purchased_on" type="date" class="form-control" /></label>
            <label>Інші витрати на хутро<div class="input-group"><input v-model="form.other_costs_uah" name="other_costs_uah" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" required class="form-control" /><span class="input-group-text">грн</span></div></label>
            <label class="full-width">Примітка<textarea v-model="form.note" name="note" rows="2" maxlength="2000" class="form-control"></textarea></label>
          </div></details>
          <div v-if="formError" class="alert alert-danger mt-3" role="alert">{{ formError }}</div>
          <div class="form-actions"><button class="btn btn-primary" type="submit" :disabled="!dirty || !preview || !form.name.trim()">{{ saving ? 'Зберігаємо…' : selectedId ? 'Зберегти зміни' : 'Зберегти партію' }}</button><button v-if="dirty && selectedId" class="btn secondary-button" type="button" @click="applyBatch(savedBatch)">Скасувати зміни</button></div>
          <p class="hint mb-0">Зміна полів одразу оновлює результат. Збереження — лише за кнопкою.</p>
        </fieldset>
      </form>

      <aside class="panel-result">
        <div class="pair-result"><div class="result-label"><span>Хутро на 1 пару капців</span><i class="bi bi-scissors" aria-hidden="true"></i></div><strong data-testid="fur-unit-cost" role="status">{{ preview ? money(preview.unit_cost_uah) : '—' }} <small>грн</small></strong><p>За розкроєм по рядах · обрізки враховані</p><span v-if="dirty" class="draft-label">Попередній розрахунок · ще не збережено</span></div>
        <div v-if="preview" class="panel-card cutting-layout" data-testid="fur-layout">
          <h3>Розкрій по рядах</h3>
          <p class="cut-size">Відріз <b>{{ number(displayCut.length) }} × {{ number(decimalInput(form.fabric_width_cm)) }} см</b><span>довжина × ширина полотна</span></p>
          <svg v-if="rowPolygons.length" :viewBox="`0 0 ${displayCut.length} ${decimalInput(form.height_cm)}`" class="cutting-row" role="img" :aria-label="`Один ряд: ${displayCut.perRow} трапецій із почерговим перевертанням. Сіре поле — обрізки.`">
            <rect width="100%" height="100%" fill="#edf0f5" />
            <polygon v-for="(points, index) in rowPolygons" :key="index" :points="points" :fill="index % 2 ? '#bcb3f6' : '#ded9fb'" stroke="#7361ed" stroke-width="1" vector-effect="non-scaling-stroke" />
          </svg>
          <p class="hint">Один ряд із перевертанням сусідніх деталей. Сіре — обрізки.</p>
          <p class="row-equation"><b>{{ number(displayCut.perRow) }}</b> у ряду × <b>{{ number(preview.layout.rows_per_cut) }}</b> ряди = <strong>{{ number(displayCut.perRow * preview.layout.rows_per_cut) }} деталей</strong></p>
          <dl>
            <div v-if="preview.layout.full_cuts"><dt>Повних робочих відрізів</dt><dd>{{ number(preview.layout.full_cuts) }} × {{ number(preview.layout.pieces_per_cut) }} деталей</dd></div>
            <div v-if="preview.layout.remainder_length_cm"><dt>Залишок {{ number(preview.layout.remainder_length_cm) }} × {{ number(decimalInput(form.fabric_width_cm)) }} см</dt><dd>{{ number(preview.layout.remainder_pieces_per_row) }} × {{ number(preview.layout.rows_per_cut) }} = {{ number(preview.layout.remainder_pieces) }} деталей</dd></div>
            <div><dt>Усього з партії</dt><dd data-testid="fur-pieces">{{ number(preview.layout.total_pieces) }} деталей</dd></div>
            <div><dt>По 2 деталі на пару</dt><dd data-testid="fur-pairs">{{ number(preview.layout.pairs) }} пар</dd></div>
          </dl>
          <p v-if="preview.layout.unpaired_pieces" class="hint">Ще 1 деталь залишається без пари. Вартість партії розподілена лише на повні пари.</p>
        </div>
        <div v-if="preview" class="panel-card calculation">
          <h3>Як пораховано</h3><dl>
            <div><dt>Уся партія з відомими витратами</dt><dd data-testid="fur-total">{{ money(preview.total_uah) }} грн</dd></div>
            <div><dt>Один погонний метр · не ярд</dt><dd>{{ money(preview.linear_metre_cost_uah) }} грн</dd></div>
            <div><dt>Один квадратний метр</dt><dd>{{ money(preview.square_metre_cost_uah) }} грн</dd></div>
            <div><dt>Одна трапеція</dt><dd data-testid="fur-piece-cost">{{ money(preview.piece_cost_uah) }} грн</dd></div>
            <div><dt>Обрізки всього полотна</dt><dd>{{ number(preview.layout.offcut_area_m2) }} м² · {{ number(preview.layout.offcut_percent) }}%</dd></div>
          </dl>
          <p class="formula">{{ money(preview.total_uah) }} грн ÷ {{ number(preview.layout.pairs) }} пар = <b>{{ money(preview.unit_cost_uah) }} грн/пара</b></p>
          <p class="hint">У ряду: перша трапеція займає більшу основу, кожна наступна — півсуми основ. Беремо лише цілі деталі та цілі ряди. Ціну однієї деталі не округлюємо перед розрахунком пари.</p>
          <details class="extra-fields"><summary>Склад вартості партії</summary><dl><div v-for="row in preview.breakdown" :key="row.key"><dt>{{ row.label }}</dt><dd>{{ row.key === 'ukraine_shipping' && !preview.ukraine_shipping_included ? 'Не врахована' : `${money(row.total_uah)} грн` }}</dd></div></dl></details>
        </div>
        <p v-else class="calculation-hint">{{ isLocal ? 'Заповніть довжину, одиницю, ширину полотна, суму в гривнях та розміри трапеції.' : 'Заповніть довжину, одиницю, ширину полотна, суми, курси та розміри трапеції.' }} З робочих відрізів має виходити щонайменше 2 цілі деталі. Перевірте довжину відрізу й напрям рядів.</p>
        <div class="scope-note"><i class="bi bi-info-circle" aria-hidden="true"></i><div>Розрахунковий вихід за вказаною розкладкою, не фактичний облік виробництва. Крайові обрізки та залишок рулону включені у вартість; додаткових проміжків між деталями й браку не додаємо. Кольори рахуються за однаковою схемою розкрою.<p v-if="preview && !preview.ukraine_shipping_included">Окрема доставка Україною не врахована — суму ще не вказано.</p></div></div>
      </aside>
    </div>

    <section v-if="ready" class="panel-card history"><header><div><h3>Збережені партії хутра</h3><p>Китай та Україна — окремі розрахунки для кожної закупівлі.</p></div><button class="btn secondary-button" :disabled="loading || saving" @click="load(page)">Оновити</button></header>
      <p v-if="!batches.length" class="history-empty">Збережіть перший розрахунок, щоб він залишився у CRM.</p>
      <div v-else class="table-scroll"><table><caption class="visually-hidden">Розрахунки хутра: {{ total }} записів</caption><thead><tr><th scope="col">Партія</th><th scope="col">Закупівля</th><th scope="col">Довжина</th><th scope="col">Усього, грн</th><th scope="col">На пару, грн ≈</th><th scope="col"><span class="visually-hidden">Дії</span></th></tr></thead><tbody><tr v-for="batch in batches" :key="batch.id" :class="{ selected: selectedId === batch.id }"><th scope="row">{{ batch.name }}<small>{{ batch.purchased_on ? batch.purchased_on.split('-').reverse().join('.') : 'Дата не вказана' }}</small></th><td>{{ batch.inputs.purchase_source === 'ukraine' ? 'Україна' : 'Китай' }}</td><td>{{ number(batch.inputs.fabric_length) }} {{ batch.inputs.length_unit === 'yard' ? 'ярд.' : 'пог. м' }}</td><td>{{ money(batch.calculation.total_uah) }}</td><td><b>{{ money(batch.calculation.unit_cost_uah) }}</b></td><td><button class="link-button" :disabled="saving" :aria-label="`Відкрити: ${batch.name}`" @click="chooseBatch(batch)">Відкрити</button></td></tr></tbody></table></div>
      <footer v-if="lastPage > 1"><button class="btn secondary-button" :disabled="page <= 1 || loading || saving" @click="load(page - 1)">Назад</button><span>{{ page }} / {{ lastPage }}</span><button class="btn secondary-button" :disabled="page >= lastPage || loading || saving" @click="load(page + 1)">Далі</button></footer>
    </section>
  </section>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { fetchFurBatches, createFurBatch, updateFurBatch, costError } from '@/crm/services/productionCostsApi';
import { calculateFurCost, activeFurFields, emptyFurForm } from '@/crm/utils/furCosts';
import { decimalInput } from '@/crm/utils/soleCosts';

const emit = defineEmits(['saving']);
const chinaPurchaseFields = [
  { key: 'goods_cny', label: 'Сума лише за хутро', unit: '¥' }, { key: 'china_shipping_cny', label: 'Доставка по Китаю', unit: '¥' },
  { key: 'commission_percent', label: 'Комісія за викуп', unit: '%' }, { key: 'international_shipping_usd', label: 'Доставка в Україну + митне', unit: '$' },
  { key: 'cny_rate', label: 'Курс юаня', unit: 'грн / ¥', rate: true }, { key: 'usd_rate', label: 'Курс долара', unit: 'грн / $', rate: true },
];
const shapeFields = [{ key: 'top_width_cm', label: 'Верхня основа' }, { key: 'bottom_width_cm', label: 'Нижня основа' }, { key: 'height_cm', label: 'Висота' }];
const batches = ref([]), page = ref(1), lastPage = ref(1), total = ref(0), ready = ref(false), loading = ref(false), saving = ref(false);
const form = ref(null), selectedId = ref(null), version = ref(null), savedBatch = ref(null), baseline = ref(''), requestKey = ref('');
const error = ref(''), formError = ref(''), notice = ref('');
const isLocal = computed(() => form.value?.purchase_source === 'ukraine');
const purchaseFields = computed(() => isLocal.value ? [{ key: 'goods_uah', label: 'Сплачено за всю партію хутра', unit: 'грн' }] : chinaPurchaseFields);
const dirty = computed(() => form.value !== null && JSON.stringify(form.value) !== baseline.value);
const preview = computed(() => form.value ? calculateFurCost(form.value) : null);
const money = value => value === null ? '—' : Number(value).toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const number = value => Number(value).toLocaleString('uk-UA', { maximumFractionDigits: 8 });
const displayCut = computed(() => {
  const layout = preview.value?.layout;
  return layout ? { length: layout.full_cuts ? layout.cut_length_cm : layout.remainder_length_cm, perRow: layout.full_cuts ? layout.pieces_per_row : layout.remainder_pieces_per_row } : null;
});
const rowPolygons = computed(() => {
  if (!displayCut.value || displayCut.value.perRow > 60) return [];
  const a = Number(decimalInput(form.value.top_width_cm)), b = Number(decimalInput(form.value.bottom_width_cm));
  const wide = Math.max(a, b), narrow = Math.min(a, b), inset = (wide - narrow) / 2, step = (a + b) / 2;
  const height = Number(decimalInput(form.value.height_cm));
  return Array.from({ length: displayCut.value.perRow }, (_, index) => {
    const x = index * step;
    return index % 2 ? `${x + inset},0 ${x + inset + narrow},0 ${x + wide},${height} ${x},${height}` : `${x},0 ${x + wide},0 ${x + inset + narrow},${height} ${x + inset},${height}`;
  });
});
watch(saving, value => emit('saving', value));

function applyBatch(batch) {
  selectedId.value = batch.id; version.value = batch.version; savedBatch.value = batch;
  form.value = { ...emptyFurForm(batch.inputs), name: batch.name, purchased_on: batch.purchased_on || '', note: batch.note || '', ...batch.inputs, ukraine_shipping_uah: batch.inputs.ukraine_shipping_uah ?? '' };
  baseline.value = JSON.stringify(form.value); requestKey.value = ''; formError.value = '';
}
function sourceChanged() {
  // Заповнену довжину не переосмислюємо автоматично при зміні постачальника.
  if (!decimalInput(form.value.fabric_length)) form.value.length_unit = isLocal.value ? 'metre' : 'yard';
  formError.value = ''; notice.value = '';
}
function canDiscard() { return !dirty.value || window.confirm('Є незбережені зміни хутра. Перейти без їх збереження?'); }
function chooseBatch(batch) { if (canDiscard()) { applyBatch(batch); notice.value = ''; } }
function newBatch() {
  if (!canDiscard()) return;
  form.value = emptyFurForm(form.value || {}); selectedId.value = null; version.value = null; savedBatch.value = null; baseline.value = '';
  requestKey.value = crypto.randomUUID(); formError.value = ''; notice.value = '';
}
async function load(nextPage = 1) {
  if (loading.value) return;
  loading.value = true; error.value = '';
  try {
    const { data } = await fetchFurBatches(nextPage);
    batches.value = data.data; page.value = data.current_page; lastPage.value = data.last_page; total.value = data.total; ready.value = true;
    if (!form.value) { if (batches.value.length) applyBatch(batches.value[0]); else newBatch(); }
  } catch (err) { error.value = costError(err); }
  finally { loading.value = false; }
}
async function save() {
  if (saving.value || !dirty.value) return;
  if (!preview.value || !form.value.name.trim()) { formError.value = isLocal.value ? 'Перевірте назву, довжину, одиницю, суму в гривнях та розміри деталі.' : 'Перевірте назву, довжину, одиницю, суми, курси та розміри деталі.'; return; }
  saving.value = true; formError.value = ''; notice.value = '';
  const payload = { name: form.value.name.trim(), purchased_on: form.value.purchased_on || null, note: form.value.note.trim() || null, length_unit: form.value.length_unit, purchase_source: form.value.purchase_source,
    ...Object.fromEntries(activeFurFields(form.value).map(field => [field, field === 'ukraine_shipping_uah' && decimalInput(form.value[field]) === '' ? null : decimalInput(form.value[field])])),
    ...(selectedId.value ? { version: version.value } : { request_key: requestKey.value }) };
  try {
    const { data } = selectedId.value ? await updateFurBatch(selectedId.value, payload) : await createFurBatch(payload);
    applyBatch(data); notice.value = 'Партію хутра збережено. Інші матеріали й складські залишки не змінені.';
    await load(1);
    if (error.value) notice.value = 'Партію збережено, але список не оновився. Натисніть «Оновити список».';
  } catch (err) { formError.value = costError(err); }
  finally { saving.value = false; }
}
function guardUnload(event) { if (dirty.value) { event.preventDefault(); event.returnValue = ''; } }
onMounted(() => { load(); window.addEventListener('beforeunload', guardUnload); });
onUnmounted(() => window.removeEventListener('beforeunload', guardUnload));
</script>

<style scoped src="./materialCostPanel.css"></style>
<style scoped>
.cutting-layout{padding:22px}@media(max-width:600px){.cutting-layout{padding:18px}}
.cutting-layout h3{font-size:16px;font-weight:750;margin:0 0 16px}.cut-size{font-size:14px;color:#26314a}.cut-size span{display:block;font-size:12px;color:#6b7c93;margin-top:4px}.cutting-row{display:block;width:100%;max-height:120px;min-height:40px;margin:14px 0}.row-equation{background:#f5f3ff;border-radius:10px;padding:14px;line-height:1.8;font-size:14px;color:#5141bf}.cutting-layout dl{margin:0}.cutting-layout dl>div{display:flex;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid #edf0f5;font-size:13px}.cutting-layout dt{font-weight:400;color:#6b7c93}.cutting-layout dd{text-align:right;margin:0;font-weight:650}
.purchase-source{margin:4px 0 24px}.purchase-source h3{font-size:14px;font-weight:700;margin:0 0 10px}.source-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.source-options label{display:flex;align-items:center;gap:10px;border:1px solid #dfe5ef;border-radius:10px;padding:14px;cursor:pointer;background:#fff}.source-options label.active{border-color:#7361ed;background:#f5f3ff}.source-options label:focus-within{outline:2px solid #7361ed;outline-offset:2px}.source-options input{accent-color:#6252d9;flex-shrink:0}.source-options strong,.source-options small{display:block}.source-options strong{font-size:14px;color:#26314a}.source-options small{font-size:11px;font-weight:400;color:#6b7c93;margin-top:4px;line-height:1.5}@media(max-width:600px){.source-options{grid-template-columns:1fr}}
.fields .form-select{margin-top:7px;min-height:44px;font-size:13px;border-color:#dfe5ef;border-radius:9px}.conversion{display:flex;flex-direction:column;justify-content:center;gap:5px;background:#f5f3ff;border-radius:10px;padding:12px 15px;align-self:end;min-height:68px}.conversion strong{color:#6252d9;font-size:16px}.conversion span{font-size:12px;color:#6b7c93}.commission{font-size:12px;color:#6252d9;line-height:1.8;margin:10px 0 0}.trapezoid-fields{grid-template-columns:repeat(3,minmax(0,1fr))}@media(max-width:600px){.trapezoid-fields{grid-template-columns:1fr}}
</style>
