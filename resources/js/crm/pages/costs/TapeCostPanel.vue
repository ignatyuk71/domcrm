<template>
  <section data-material="tape" :aria-busy="loading || saving">
    <header class="panel-heading"><div><h2>Окантовка</h2><p>Оксамитова стрічка · вартість метра та витрата на пару.</p></div><button class="btn btn-primary" :disabled="!ready || loading || saving" @click="newBatch"><i class="bi bi-plus-lg" aria-hidden="true"></i> Нова партія</button></header>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }} <button class="link-button" :disabled="loading || saving" @click="load(page)">Оновити список</button></div>
    <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
    <p v-if="loading && !form" role="status">Завантажуємо розрахунок стрічки…</p>
    <div v-if="form" class="panel-workspace">
      <form class="panel-card panel-form" @submit.prevent="save">
        <fieldset :disabled="saving">
          <legend>Дані партії<span class="state" :class="{ pending: dirty }">{{ dirty ? 'Не збережено' : 'Збережено' }}</span></legend>
          <section class="form-section">
            <h3><span>1</span> Закупівля стрічки</h3>
            <div class="fields">
              <label v-for="field in purchaseFields" :key="field.key" :class="{ 'full-width': field.key === 'length_m' }">{{ field.label }}<div class="input-group"><input v-model="form[field.key]" :name="field.key" inputmode="decimal" :pattern="pattern(field.key)" :required="field.key !== 'shipping_uah'" :placeholder="field.key === 'shipping_uah' ? 'Не врахована' : ''" class="form-control" /><span class="input-group-text">{{ field.unit }}</span></div></label>
            </div>
            <p class="hint">Викуп — фактично сплачена сума у гривнях, уже з комісією. Доставка — за всю партію. Порожнє поле доставки означає «не врахована», 0 — без доплати.</p>
          </section>
          <section class="form-section">
            <h3><span>2</span> Витрата на один капець</h3>
            <div class="fields"><label v-for="field in consumptionFields" :key="field.key">{{ field.label }}<div class="input-group"><input v-model="form[field.key]" :name="field.key" inputmode="decimal" :pattern="pattern(field.key)" required class="form-control" /><span class="input-group-text">см</span></div></label></div>
            <p class="hint">Запас додається до кожного капця. Для однієї пари множимо довжину із запасом на два.</p>
          </section>
          <details class="extra-fields"><summary>Назва, дата та примітка</summary><div class="fields">
            <label class="full-width">Назва партії<input v-model="form.name" name="name" required maxlength="160" class="form-control" /></label>
            <label>Дата рахунку <small>· необов’язково</small><input v-model="form.purchased_on" name="purchased_on" type="date" class="form-control" /></label>
            <label class="full-width">Примітка<textarea v-model="form.note" name="note" rows="2" maxlength="2000" class="form-control"></textarea></label>
          </div></details>
          <div v-if="formError" class="alert alert-danger mt-3" role="alert">{{ formError }}</div>
          <div class="form-actions"><button class="btn btn-primary" type="submit" :disabled="!dirty || !preview || !form.name.trim()">{{ saving ? 'Зберігаємо…' : selectedId ? 'Зберегти зміни' : 'Зберегти партію' }}</button><button v-if="dirty && selectedId" type="button" class="btn secondary-button" @click="applyBatch(savedBatch)">Скасувати зміни</button></div>
          <p class="hint mb-0">Перерахунок одразу під час введення. Збереження — лише за кнопкою.</p>
        </fieldset>
      </form>
      <aside class="panel-result">
        <div class="pair-result"><div class="result-label">Окантовка · одна пара<i class="bi bi-bounding-box" aria-hidden="true"></i></div><strong data-testid="tape-unit-cost" role="status">{{ money(preview?.unit_cost_uah) }} <small>грн</small></strong><p>{{ preview ? `${number(preview.pair_length_m)} м стрічки на пару, включно із запасом` : 'Укажіть довжину, суми й витрату стрічки.' }}</p><span v-if="dirty" class="draft-label">Попередній розрахунок · ще не збережено</span></div>
        <TapeInsoleIllustration />
        <div v-if="preview" class="panel-card calculation">
          <h3>Як рахуємо</h3>
          <dl>
            <div><dt>Вартість всієї партії</dt><dd data-testid="tape-total">{{ money(preview.total_uah) }} грн</dd></div>
            <div><dt>Кількість стрічки</dt><dd>{{ number(form.length_m) }} м</dd></div>
            <div><dt>Ціна 1 погонного метра</dt><dd data-testid="tape-metre-cost">{{ money(preview.metre_cost_uah) }} грн</dd></div>
            <div><dt>На один капець із запасом</dt><dd data-testid="tape-slipper-length">{{ number(preview.slipper_length_cm) }} см</dd></div>
            <div><dt>На пару капців</dt><dd data-testid="tape-pair-length">{{ number(preview.pair_length_m) }} м</dd></div>
          </dl>
          <p class="formula">({{ number(form.per_slipper_cm) }} + {{ number(form.allowance_cm) }}) см × 2 ÷ 100 = <b>{{ number(preview.pair_length_m) }} м на пару</b></p>
          <p class="hint">({{ money(preview.total_uah) }} грн ÷ {{ number(form.length_m) }} м) × {{ number(preview.pair_length_m) }} м = {{ money(preview.unit_cost_uah) }} грн. Ціну метра перед множенням не округлюємо.</p>
          <details class="extra-fields"><summary>Склад вартості та розрахунковий вихід</summary><dl><div v-for="row in preview.breakdown" :key="row.key"><dt>{{ row.label }}</dt><dd>{{ money(row.total_uah) }} грн</dd></div><div><dt>Вистачає на повних пар</dt><dd data-testid="tape-yield">{{ number(preview.whole_pairs) }}</dd></div><div><dt>Залишок довжини</dt><dd>{{ number(preview.remaining_length_m) }} м</dd></div></dl><p class="hint">Це розрахунок загальної довжини, не облік залишків на складі. Кінці окремих рулонів і додатковий брак не враховані.</p></details>
        </div>
        <div class="scope-note"><i class="bi bi-info-circle" aria-hidden="true"></i><div>Це лише стрічка. Нитки та робота рахуються окремо. Запас на обрізання вже включений у витрату.<p v-if="preview && !preview.shipping_included">Доставка не врахована — підсумок неповний.</p></div></div>
      </aside>
    </div>
    <section v-if="ready" class="panel-card history"><header><div><h3>Збережені партії стрічки</h3><p>Нова партія має власні ціни й не змінює попередні.</p></div><button class="btn secondary-button" :disabled="loading || saving" @click="load(page)">Оновити</button></header>
      <p v-if="!batches.length" class="history-empty">Заповніть поля та збережіть першу партію.</p>
      <div v-else class="table-scroll"><table><caption class="visually-hidden">Партії оксамитової стрічки: {{ total }} записів</caption><thead><tr><th scope="col">Партія</th><th scope="col">Метри</th><th scope="col">Усього, грн</th><th scope="col">За метр, грн</th><th scope="col">На пару, грн</th><th scope="col"><span class="visually-hidden">Дії</span></th></tr></thead><tbody><tr v-for="item in batches" :key="item.id" :class="{ selected: selectedId === item.id }"><th scope="row">{{ item.name }}<small>{{ item.purchased_on ? item.purchased_on.split('-').reverse().join('.') : 'Дата не вказана' }}</small></th><td>{{ number(item.inputs.length_m) }}</td><td>{{ money(item.calculation.total_uah) }}</td><td>{{ money(item.calculation.metre_cost_uah) }}</td><td><b>{{ money(item.calculation.unit_cost_uah) }}</b></td><td><button class="link-button" :disabled="saving" :aria-label="`Відкрити: ${item.name}`" @click="chooseBatch(item)">Відкрити</button></td></tr></tbody></table></div>
      <footer v-if="lastPage > 1"><button class="btn secondary-button" :disabled="page <= 1 || loading || saving" @click="load(page - 1)">Назад</button><span>{{ page }} / {{ lastPage }}</span><button class="btn secondary-button" :disabled="page >= lastPage || loading || saving" @click="load(page + 1)">Далі</button></footer>
    </section>
  </section>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { fetchTapeBatches, createTapeBatch, updateTapeBatch, costError } from '@/crm/services/productionCostsApi';
import { calculateTapeCost, tapeFields, tapePrecision, emptyTapeForm } from '@/crm/utils/tapeCosts';
import { decimalInput } from '@/crm/utils/soleCosts';
import { useCostModelApi } from '@/crm/composables/useCostModelApi';
import { useCostSummaryPart } from '@/crm/composables/useCostSummaryPart';
import TapeInsoleIllustration from './TapeInsoleIllustration.vue';
const api = useCostModelApi({ fetch: fetchTapeBatches, create: createTapeBatch, update: updateTapeBatch });

const emit = defineEmits(['saving']);
const purchaseFields = [{ key: 'length_m', label: 'Кількість стрічки в партії', unit: 'пог. м' }, { key: 'goods_uah', label: 'Викуп стрічки з комісією', unit: 'грн' }, { key: 'shipping_uah', label: 'Доставка всієї партії', unit: 'грн' }];
const consumptionFields = [{ key: 'per_slipper_cm', label: 'Довжина без запасу' }, { key: 'allowance_cm', label: 'Запас на один капець' }];
const batches = ref([]), page = ref(1), lastPage = ref(1), total = ref(0), ready = ref(false), loading = ref(false), saving = ref(false);
const form = ref(null), selectedId = ref(null), version = ref(null), savedBatch = ref(null), baseline = ref(''), requestKey = ref('');
const error = ref(''), formError = ref(''), notice = ref('');
const dirty = computed(() => form.value !== null && JSON.stringify(form.value) !== baseline.value);
defineExpose({ dirty });
const preview = computed(() => form.value ? calculateTapeCost(form.value) : null);
useCostSummaryPart('tape', { form, selectedId, preview, dirty });
const pattern = field => `[0-9]+([.,][0-9]{1,${tapePrecision[field]}})?`;
const money = value => value == null ? '—' : Number(value).toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const number = value => Number(decimalInput(value)).toLocaleString('uk-UA', { maximumFractionDigits: 4 });
watch(saving, value => emit('saving', value));

function applyBatch(item) {
  selectedId.value = item.id; version.value = item.version; savedBatch.value = item;
  form.value = { name: item.name, purchased_on: item.purchased_on || '', note: item.note || '', ...item.inputs, shipping_uah: item.inputs.shipping_uah ?? '' };
  baseline.value = JSON.stringify(form.value); requestKey.value = ''; formError.value = '';
}
function canDiscard() { return !dirty.value || window.confirm('Є незбережені зміни стрічки. Перейти без їх збереження?'); }
function chooseBatch(item) { if (canDiscard()) { applyBatch(item); notice.value = ''; } }
function newBatch() {
  if (!canDiscard()) return;
  form.value = emptyTapeForm(form.value || {}); selectedId.value = null; version.value = null; savedBatch.value = null; baseline.value = '';
  requestKey.value = crypto.randomUUID(); formError.value = ''; notice.value = '';
}
async function load(nextPage = 1) {
  if (loading.value) return;
  loading.value = true; error.value = '';
  try {
    const { data } = await api.fetch(nextPage);
    batches.value = data.data; page.value = data.current_page; lastPage.value = data.last_page; total.value = data.total; ready.value = true;
    if (!form.value) { if (batches.value.length) applyBatch(batches.value[0]); else newBatch(); }
  } catch (err) { error.value = costError(err); }
  finally { loading.value = false; }
}
async function save() {
  if (saving.value || !dirty.value) return;
  if (!preview.value || !form.value.name.trim()) { formError.value = 'Перевірте суми й довжину: партії має вистачати на пару капців із запасом.'; return; }
  saving.value = true; formError.value = ''; notice.value = '';
  const payload = { name: form.value.name.trim(), purchased_on: form.value.purchased_on || null, note: form.value.note.trim() || null,
    ...Object.fromEntries(tapeFields.map(field => [field, field === 'shipping_uah' && decimalInput(form.value[field]) === '' ? null : decimalInput(form.value[field])])),
    ...(selectedId.value ? { version: version.value } : { request_key: requestKey.value }) };
  try {
    const { data } = selectedId.value ? await api.update(selectedId.value, payload) : await api.create(payload);
    applyBatch(data); notice.value = 'Партію стрічки збережено. Інші матеріали й склад не змінені.';
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
