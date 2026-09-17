<template>
  <section class="sheet-material-panel" :data-material="material" :aria-busy="loading || saving">
    <header class="panel-heading">
      <div><h2>{{ ui.title }}</h2><p>{{ ui.intro }}</p></div>
      <button class="btn btn-primary" :disabled="!ready || loading || saving" @click="newBatch"><i class="bi bi-plus-lg" aria-hidden="true"></i> {{ ui.newLabel }}</button>
    </header>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }} <button class="link-button" :disabled="loading || saving" @click="load(page)">Оновити список</button></div>
    <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
    <p v-if="loading && !form" role="status">Завантажуємо розрахунок…</p>

    <div v-if="form" class="panel-workspace">
      <form class="panel-card panel-form" @submit.prevent="save">
        <fieldset :disabled="saving">
          <legend>{{ selectedId ? 'Дані розрахунку' : ui.newTitle }}<span class="state" :class="{ pending: dirty }">{{ dirty ? 'Не збережено' : 'Збережено' }}</span></legend>
          <section class="form-section">
            <h3><span>1</span>{{ ui.purchaseTitle }}</h3>
            <div v-if="isFoam" class="fields">
              <label>Ціна одного листа<div class="input-group"><input v-model="form.sheet_price_usd" name="sheet_price_usd" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" required class="form-control" /><span class="input-group-text">$</span></div></label>
              <label>Курс долара<div class="input-group"><input v-model="form.usd_rate" name="usd_rate" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,4})?" required class="form-control" /><span class="input-group-text">грн / $</span></div></label>
            </div>
            <div v-else class="fields">
              <label>Кількість листів<div class="input-group"><input v-model="form.quantity" name="quantity" type="number" min="1" max="10000000" step="1" required class="form-control" /><span class="input-group-text">шт.</span></div></label>
              <label>Сума за всі листи<div class="input-group"><input v-model="form.goods_uah" name="goods_uah" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" required class="form-control" /><span class="input-group-text">грн</span></div></label>
            </div>
            <p class="hint">{{ ui.purchaseHint }}</p>
          </section>
          <section v-for="group in dimensionGroups" :key="group.title" class="form-section">
            <h3><span>{{ group.step }}</span>{{ group.title }}</h3>
            <div class="fields">
              <label v-for="field in group.fields" :key="field.key">{{ field.label }}<div class="input-group"><input v-model="form[field.key]" :name="field.key" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" required class="form-control" /><span class="input-group-text">см</span></div></label>
            </div>
            <p class="hint">{{ group.hint }}</p>
          </section>
          <details class="extra-fields">
            <summary>Назва, дата та доставка</summary>
            <div class="fields">
              <label class="full-width">{{ isFoam ? 'Назва розрахунку' : 'Назва партії' }}<input v-model="form.name" name="name" maxlength="160" required class="form-control" /></label>
              <label>{{ isFoam ? 'Дата ціни' : 'Дата рахунку' }} <small>· необов’язково</small><input v-model="form.purchased_on" name="purchased_on" type="date" class="form-control" /></label>
              <label>{{ ui.deliveryLabel }}<div class="input-group"><input v-model="form.shipping_uah" name="shipping_uah" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" placeholder="Не врахована" class="form-control" /><span class="input-group-text">грн</span></div></label>
              <p class="hint full-width">{{ ui.deliveryHint }}</p>
              <label class="full-width">Примітка<textarea v-model="form.note" name="note" rows="2" maxlength="2000" class="form-control"></textarea></label>
            </div>
          </details>
          <div v-if="formError" class="alert alert-danger mt-3" role="alert">{{ formError }}</div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary" :disabled="!dirty || !canCalculate || !form.name.trim()">{{ saving ? 'Зберігаємо…' : selectedId ? 'Зберегти зміни' : ui.saveNewLabel }}</button>
            <button v-if="dirty && selectedId" type="button" class="btn secondary-button" @click="applyBatch(savedBatch)">Скасувати зміни</button>
          </div>
          <p class="hint mb-0">Результат змінюється одразу. Зберігаємо тільки після натискання кнопки.</p>
        </fieldset>
      </form>

      <aside class="panel-result" :class="{ 'with-cut-layout': !isFoam }">
        <div class="pair-result">
          <div class="result-label"><span>{{ ui.resultTitle }}</span><i :class="`bi bi-${isFoam ? 'square' : 'file-earmark'}`" aria-hidden="true"></i></div>
          <strong :data-testid="`${material}-unit-cost`" role="status">{{ preview ? money(preview.unit_cost_uah) : '—' }} <small>грн</small></strong>
          <p>{{ isFoam ? 'Орієнтовно за площею · 2 вставки на пару' : 'За виходом повних пар із листа · обрізки враховані' }}</p>
          <span v-if="dirty" class="draft-label">Попередній розрахунок · ще не збережено</span>
        </div>
        <CardboardCutLayout v-if="!isFoam && preview" :length="numeric('sheet_length_cm')" :width="numeric('sheet_width_cm')" :blank-length="numeric('blank_length_cm')" :blank-width="numeric('blank_width_cm')" :layout="preview.layout" />
        <div v-if="preview" class="panel-card calculation">
          <h3>Як пораховано</h3>
          <dl>
            <div v-if="isFoam"><dt>{{ number(decimalInput(form.sheet_price_usd)) }} $ × {{ number(decimalInput(form.usd_rate)) }} грн</dt><dd>{{ money(preview.purchase_sheet_uah) }} грн</dd></div>
            <div v-else><dt>Сума за партію{{ preview.shipping_included ? ' з доставкою' : '' }}</dt><dd>{{ money(preview.total_uah) }} грн</dd></div>
            <div><dt>Один лист · {{ number(preview.sheet_area_m2) }} м²{{ isFoam && preview.shipping_included ? ' · з доставкою' : '' }}</dt><dd :data-testid="`${material}-sheet-cost`">{{ money(preview.sheet_cost_uah) }} грн</dd></div>
            <template v-if="isFoam"><div><dt>Один квадратний метр</dt><dd>{{ money(preview.square_metre_cost_uah) }} грн</dd></div><div><dt>Дві вставки на пару</dt><dd>{{ number(preview.pair_area_m2) }} м²</dd></div></template>
            <div v-else><dt>Повних пар із одного листа</dt><dd>{{ number(preview.layout.pairs) }}</dd></div>
          </dl>
          <p v-if="isFoam" class="formula">{{ money(preview.square_metre_cost_uah) }} грн/м² × {{ number(preview.pair_area_m2) }} м² ≈ <b>{{ money(preview.unit_cost_uah) }} грн</b></p>
          <p v-else-if="preview.layout.pairs" class="formula">{{ money(preview.sheet_cost_uah) }} грн за лист ÷ {{ number(preview.layout.pairs) }} пар = <b>{{ money(preview.unit_cost_uah) }} грн</b></p>
          <p class="hint mb-0">Рахуємо без проміжного округлення; суми показуємо до копійок.</p>
        </div>
        <p v-else class="calculation-hint">{{ ui.invalidHint }}</p>
        <div class="scope-note"><i class="bi bi-info-circle" aria-hidden="true"></i><div>{{ ui.scope }}<p v-if="preview && !preview.shipping_included">{{ ui.deliveryUnknown }}</p></div></div>
      </aside>
    </div>

    <section v-if="ready" class="panel-card history">
      <header><div><h3>{{ ui.historyTitle }}</h3><p>{{ ui.historyHint }}</p></div><button class="btn secondary-button" :disabled="loading || saving" @click="load(page)">Оновити</button></header>
      <p v-if="!batches.length" class="history-empty">Збережіть перший розрахунок, щоб він залишився у CRM.</p>
      <div v-else class="table-scroll"><table><caption class="visually-hidden">{{ ui.historyTitle }}, {{ total }} записів</caption><thead><tr><th scope="col">{{ isFoam ? 'Розрахунок' : 'Партія' }}</th><th v-if="!isFoam" scope="col">Листів</th><th scope="col">{{ isFoam ? 'Лист, грн' : 'Сума, грн' }}</th><th scope="col">На пару, грн ≈</th><th scope="col"><span class="visually-hidden">Дії</span></th></tr></thead><tbody><tr v-for="batch in batches" :key="batch.id" :class="{ selected: selectedId === batch.id }"><th scope="row">{{ batch.name }}<small>{{ batch.purchased_on ? batch.purchased_on.split('-').reverse().join('.') : 'Дата не вказана' }}</small></th><td v-if="!isFoam">{{ number(batch.quantity) }}</td><td>{{ money(batch.calculation.total_uah) }}</td><td><b>{{ money(batch.calculation.unit_cost_uah) }}</b></td><td><button class="link-button" :disabled="saving" :aria-label="`Відкрити: ${batch.name}`" @click="chooseBatch(batch)">Відкрити</button></td></tr></tbody></table></div>
      <footer v-if="lastPage > 1"><button class="btn secondary-button" :disabled="page <= 1 || loading || saving" @click="load(page - 1)">Назад</button><span>{{ page }} / {{ lastPage }}</span><button class="btn secondary-button" :disabled="page >= lastPage || loading || saving" @click="load(page + 1)">Далі</button></footer>
    </section>
  </section>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { fetchCardboardBatches, createCardboardBatch, updateCardboardBatch, fetchFoamCosts, createFoamCost, updateFoamCost, costError } from '@/crm/services/productionCostsApi';
import { calculateCardboardCost, cardboardFields, emptyCardboardForm } from '@/crm/utils/cardboardCosts';
import { calculateFoamCost, foamFields, emptyFoamForm } from '@/crm/utils/foamCosts';
import { decimalInput } from '@/crm/utils/soleCosts';
import CardboardCutLayout from './CardboardCutLayout.vue';
import { useCostModelApi } from '@/crm/composables/useCostModelApi';

const emit = defineEmits(['saving']);
const props = defineProps({ material: { type: String, default: 'cardboard', validator: value => ['cardboard', 'foam'].includes(value) } });
// Кожен матеріал має окремий екземпляр форми: чернетки та запити не змішуються.
const isFoam = props.material === 'foam';
const api = useCostModelApi(isFoam ? { fetch: fetchFoamCosts, create: createFoamCost, update: updateFoamCost } : { fetch: fetchCardboardBatches, create: createCardboardBatch, update: updateCardboardBatch });
const fields = isFoam ? foamFields : cardboardFields;
const calculate = isFoam ? calculateFoamCost : calculateCardboardCost;
const emptyForm = isFoam ? emptyFoamForm : emptyCardboardForm;
const ui = isFoam ? {
  title: 'Поролон-вставка · 5 мм', intro: 'Окрема вставка на картон, без плюшу та клейової павутинки.',
  newLabel: 'Новий розрахунок', newTitle: 'Новий розрахунок вставки', saveNewLabel: 'Зберегти розрахунок',
  purchaseTitle: 'Ціна одного листа', purchaseHint: 'Ціна в доларах × ваш курс = вартість листа у гривнях. Кількість закуплених листів не потрібна.',
  resultTitle: 'Поролонові вставки на 1 пару', deliveryLabel: 'Доставка на один лист',
  deliveryHint: 'Якщо доставка оплачена окремо, поділіть її суму на кількість листів. Невідому суму залиште порожньою.',
  scope: 'Лише окрема вставка без плюшу. Поролон, склеєний із плюшем, сюди не входить. Рахуємо площу прямокутних заготовок; крайові відходи та додаткові проміжки між ними не враховані.',
  deliveryUnknown: 'Доставка поролону не врахована — суму ще не вказано.',
  historyTitle: 'Збережені розрахунки вставки', historyHint: 'Для нової ціни створіть окремий розрахунок. Попередні ціни та курси зберігаються.',
  invalidHint: 'Вкажіть ціну листа, курс і розміри у сантиметрах. Вставка має поміщатися в лист.',
} : {
  title: 'Картон', intro: 'Дані закупівлі та розмір заготовки → вартість картону на пару.',
  newLabel: 'Нова партія', newTitle: 'Нова партія картону', saveNewLabel: 'Зберегти партію',
  purchaseTitle: 'Купівля картону', purchaseHint: 'Лише сума за картон із рахунку, без поролону та інших матеріалів.',
  resultTitle: 'Картон на 1 пару капців', deliveryLabel: 'Доставка цієї партії',
  deliveryHint: 'Невідому доставку залиште порожньою. Якщо доставляли кілька матеріалів, указуйте лише частину витрат на картон.',
  scope: 'Рахуємо цілий лист і весь прямокутник заготовки, включно з тим, що обрізається після пошиття. Крайові обрізки листа включені у ціну пари; додаткові проміжки та брак не задані й не враховані.',
  deliveryUnknown: 'Доставка картону не врахована — суму ще не вказано.',
  historyTitle: 'Збережені партії картону', historyHint: 'Новий рахунок — окремий розрахунок. Попередні дані зберігаються.',
  invalidHint: 'Вкажіть кількість листів, суму та розміри у сантиметрах. Із листа має виходити хоча б дві цілі заготовки.',
};
const dimensionGroups = [
  { step: 2, title: 'Розмір цілого листа', hint: 'Розміри придбаного листа, не вирізаної устілки.', fields: [{ key: 'sheet_length_cm', label: 'Довжина листа' }, { key: 'sheet_width_cm', label: 'Ширина листа' }] },
  { step: 3, title: isFoam ? 'Вставка на одну капцю' : 'Заготовка на одну капцю', hint: 'Прямокутник для середнього розміру. На пару автоматично беремо 2 такі заготовки.', fields: [{ key: 'blank_length_cm', label: isFoam ? 'Довжина вставки' : 'Довжина заготовки' }, { key: 'blank_width_cm', label: isFoam ? 'Ширина вставки' : 'Ширина заготовки' }] },
];
const batches = ref([]), page = ref(1), lastPage = ref(1), total = ref(0), ready = ref(false), loading = ref(false), saving = ref(false);
const form = ref(null), selectedId = ref(null), version = ref(null), savedBatch = ref(null), baseline = ref(''), requestKey = ref('');
const error = ref(''), formError = ref(''), notice = ref('');
const dirty = computed(() => form.value !== null && JSON.stringify(form.value) !== baseline.value);
defineExpose({ dirty });
const preview = computed(() => form.value ? calculate(form.value) : null);
const canCalculate = computed(() => preview.value?.unit_cost_uah != null);
const money = value => value == null ? '—' : Number(value).toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const number = value => Number(value).toLocaleString('uk-UA', { maximumFractionDigits: 8 });
const numeric = field => Number(decimalInput(form.value[field]));
watch(saving, value => emit('saving', value));

function applyBatch(batch) {
  selectedId.value = batch.id; version.value = batch.version; savedBatch.value = batch;
  form.value = { name: batch.name, purchased_on: batch.purchased_on || '', ...(!isFoam ? { quantity: String(batch.quantity) } : {}), note: batch.note || '', ...batch.inputs, shipping_uah: batch.inputs.shipping_uah ?? '' };
  baseline.value = JSON.stringify(form.value); requestKey.value = ''; formError.value = '';
}
function canDiscard() { return !dirty.value || window.confirm('Є незбережені зміни розрахунку. Перейти без їх збереження?'); }
function chooseBatch(batch) { if (canDiscard()) { applyBatch(batch); notice.value = ''; } }
function newBatch() {
  if (!canDiscard()) return;
  // Для нової закупівлі переносимо лише геометрію, не старі ціни чи кількість.
  form.value = emptyForm(form.value || {}); selectedId.value = null; version.value = null; savedBatch.value = null; baseline.value = '';
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
  if (!canCalculate.value || !form.value.name.trim()) { formError.value = `Укажіть назву розрахунку. ${ui.invalidHint}`; return; }
  saving.value = true; formError.value = ''; notice.value = '';
  const payload = { name: form.value.name.trim(), purchased_on: form.value.purchased_on || null, ...(!isFoam ? { quantity: Number(form.value.quantity) } : {}), note: form.value.note.trim() || null,
    ...Object.fromEntries(fields.map(field => [field, field === 'shipping_uah' && decimalInput(form.value[field]) === '' ? null : decimalInput(form.value[field])])),
    ...(selectedId.value ? { version: version.value } : { request_key: requestKey.value }) };
  try {
    const { data } = selectedId.value ? await api.update(selectedId.value, payload) : await api.create(payload);
    applyBatch(data); notice.value = 'Розрахунок збережено. Інші складові та складські залишки не змінені.';
    await load(1);
    if (error.value) notice.value = `${isFoam ? 'Розрахунок' : 'Партію'} збережено, але список не оновився. Натисніть «Оновити список».`;
  } catch (err) { formError.value = costError(err); }
  finally { saving.value = false; }
}
function guardUnload(event) { if (dirty.value) { event.preventDefault(); event.returnValue = ''; } }
onMounted(() => { load(); window.addEventListener('beforeunload', guardUnload); });
onUnmounted(() => window.removeEventListener('beforeunload', guardUnload));
</script>

<style scoped src="./materialCostPanel.css"></style>
<style scoped>
.with-cut-layout{position:static}
</style>
