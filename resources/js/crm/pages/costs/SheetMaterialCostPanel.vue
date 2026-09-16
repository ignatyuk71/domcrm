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
            <button type="submit" class="btn btn-primary" :disabled="!dirty || !preview || !form.name.trim()">{{ saving ? 'Зберігаємо…' : selectedId ? 'Зберегти зміни' : ui.saveNewLabel }}</button>
            <button v-if="dirty && selectedId" type="button" class="btn secondary-button" @click="applyBatch(savedBatch)">Скасувати зміни</button>
          </div>
          <p class="hint mb-0">Результат змінюється одразу. Зберігаємо тільки після натискання кнопки.</p>
        </fieldset>
      </form>

      <aside class="panel-result">
        <div class="pair-result">
          <div class="result-label"><span>{{ ui.resultTitle }}</span><i :class="`bi bi-${isFoam ? 'square' : 'file-earmark'}`" aria-hidden="true"></i></div>
          <strong :data-testid="`${material}-unit-cost`" role="status">{{ preview ? money(preview.unit_cost_uah) : '—' }} <small>грн</small></strong>
          <p>Орієнтовно за площею · {{ isFoam ? '2 вставки' : '2 заготовки' }} на пару</p>
          <span v-if="dirty" class="draft-label">Попередній розрахунок · ще не збережено</span>
        </div>
        <div v-if="preview" class="panel-card calculation">
          <h3>Як пораховано</h3>
          <dl>
            <div v-if="isFoam"><dt>{{ number(decimalInput(form.sheet_price_usd)) }} $ × {{ number(decimalInput(form.usd_rate)) }} грн</dt><dd>{{ money(preview.purchase_sheet_uah) }} грн</dd></div>
            <div v-else><dt>Сума за партію{{ preview.shipping_included ? ' з доставкою' : '' }}</dt><dd>{{ money(preview.total_uah) }} грн</dd></div>
            <div><dt>Один лист · {{ number(preview.sheet_area_m2) }} м²{{ isFoam && preview.shipping_included ? ' · з доставкою' : '' }}</dt><dd :data-testid="`${material}-sheet-cost`">{{ money(preview.sheet_cost_uah) }} грн</dd></div>
            <div><dt>Один квадратний метр</dt><dd>{{ money(preview.square_metre_cost_uah) }} грн</dd></div>
            <div><dt>{{ isFoam ? 'Дві вставки' : 'Дві заготовки' }} на пару</dt><dd>{{ number(preview.pair_area_m2) }} м²</dd></div>
          </dl>
          <p class="formula">{{ money(preview.square_metre_cost_uah) }} грн/м² × {{ number(preview.pair_area_m2) }} м² ≈ <b>{{ money(preview.unit_cost_uah) }} грн</b></p>
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

const emit = defineEmits(['saving']);
const props = defineProps({ material: { type: String, default: 'cardboard', validator: value => ['cardboard', 'foam'].includes(value) } });
// Кожен матеріал має окремий екземпляр форми: чернетки та запити не змішуються.
const isFoam = props.material === 'foam';
const api = isFoam ? { fetch: fetchFoamCosts, create: createFoamCost, update: updateFoamCost } : { fetch: fetchCardboardBatches, create: createCardboardBatch, update: updateCardboardBatch };
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
  scope: 'Рахуємо весь прямокутник заготовки, включно з тим, що обрізається після пошиття. Залишки по краях листа й додаткові проміжки між заготовками поки не враховані.',
  deliveryUnknown: 'Доставка картону не врахована — суму ще не вказано.',
  historyTitle: 'Збережені партії картону', historyHint: 'Новий рахунок — окремий розрахунок. Попередні дані зберігаються.',
  invalidHint: 'Вкажіть кількість листів, суму та розміри у сантиметрах. Заготовка має поміщатися в лист.',
};
const dimensionGroups = [
  { step: 2, title: 'Розмір цілого листа', hint: 'Розміри придбаного листа, не вирізаної устілки.', fields: [{ key: 'sheet_length_cm', label: 'Довжина листа' }, { key: 'sheet_width_cm', label: 'Ширина листа' }] },
  { step: 3, title: isFoam ? 'Вставка на одну капцю' : 'Заготовка на одну капцю', hint: 'Прямокутник для середнього розміру. На пару автоматично беремо 2 такі заготовки.', fields: [{ key: 'blank_length_cm', label: isFoam ? 'Довжина вставки' : 'Довжина заготовки' }, { key: 'blank_width_cm', label: isFoam ? 'Ширина вставки' : 'Ширина заготовки' }] },
];
const batches = ref([]), page = ref(1), lastPage = ref(1), total = ref(0), ready = ref(false), loading = ref(false), saving = ref(false);
const form = ref(null), selectedId = ref(null), version = ref(null), savedBatch = ref(null), baseline = ref(''), requestKey = ref('');
const error = ref(''), formError = ref(''), notice = ref('');
const dirty = computed(() => form.value !== null && JSON.stringify(form.value) !== baseline.value);
const preview = computed(() => form.value ? calculate(form.value) : null);
const money = value => Number(value).toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const number = value => Number(value).toLocaleString('uk-UA', { maximumFractionDigits: 8 });
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
  if (!preview.value || !form.value.name.trim()) { formError.value = `Укажіть назву розрахунку. ${ui.invalidHint}`; return; }
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

<style scoped>
.panel-heading,.history header{display:flex;justify-content:space-between;align-items:center;gap:18px}.panel-heading{margin-bottom:20px}.panel-heading h2{font-size:20px;font-weight:750;margin:0 0 6px}.panel-heading p,.history header p{font-size:13px;color:#6b7c93;margin:0}.panel-workspace{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(330px,1fr);gap:22px;align-items:start}.panel-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;min-width:0}.panel-form{padding:24px}.panel-form fieldset{min-width:0}.panel-form legend{float:none;display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:17px;font-weight:750;margin-bottom:22px}.state{font-size:11px;border-radius:6px;padding:5px 8px;background:#eaf7f1;color:#188369;white-space:nowrap}.state.pending{background:#fff7e7;color:#976624}.form-section+.form-section{margin-top:22px;padding-top:22px;border-top:1px solid #edf0f5}h3{font-size:14px;font-weight:700;margin:0 0 16px}.form-section h3{display:flex;align-items:center;gap:10px}.form-section h3>span{display:grid;place-items:center;width:25px;height:25px;border-radius:8px;background:#f0edff;color:#6252d9;font-size:12px}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.fields label{display:block;font-size:12px;font-weight:650;color:#52617b;min-width:0}.fields .form-control,.fields .input-group{margin-top:7px}.fields .input-group .form-control{margin-top:0}.form-control{min-height:44px;font-size:14px;border-color:#dfe5ef;border-radius:9px}.input-group-text{font-size:12px;background:#f8f9fc;border-color:#dfe5ef;color:#6b7c93;border-radius:9px}.full-width{grid-column:1/-1}.hint{font-size:12px;color:#6b7c93;line-height:1.7;margin:10px 0 0}.extra-fields{margin-top:24px;padding-top:18px;border-top:1px solid #edf0f5}.extra-fields summary{font-size:12px;font-weight:650;color:#6252d9;cursor:pointer}.extra-fields .fields{margin-top:18px}.form-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:24px}.btn{font-size:13px;font-weight:650;border-radius:10px;padding:10px 15px}.btn-primary{background:#574ce8;border-color:#574ce8}.btn-primary:hover{background:#493ed0}.secondary-button{border:1px solid #e3e9f2;background:white;color:#475569}.link-button{border:0;background:none;color:#4f46e5;font-weight:650;font-size:12px;padding:6px}.panel-result{display:grid;gap:16px;position:sticky;top:20px}.pair-result{padding:26px;border:1px solid #c7e7df;background:linear-gradient(135deg,#f0faf7,#f9fcfb);border-radius:16px}.result-label{display:flex;justify-content:space-between;align-items:center;gap:10px;font-size:13px;font-weight:650;color:#32786b}.result-label i{display:grid;place-items:center;width:36px;height:36px;border-radius:10px;background:#dff3ec;font-size:19px}.pair-result strong{display:block;font-size:48px;line-height:1.2;font-weight:800;letter-spacing:-.035em;margin:10px 0}.pair-result strong small{font-size:19px;letter-spacing:0;font-weight:600}.pair-result p{font-size:12px;color:#518478;margin:0}.draft-label{display:block;font-size:11px;margin-top:12px;color:#976624}.calculation{padding:22px}.calculation dl{margin:0}.calculation dl>div{display:flex;justify-content:space-between;gap:16px;padding:12px 0;border-bottom:1px solid #edf0f5;font-size:13px}.calculation dt{color:#6b7c93;font-weight:500}.calculation dd{margin:0;text-align:right;font-weight:650;font-variant-numeric:tabular-nums}.formula{margin:18px 0 0;font-size:13px;color:#32786b;line-height:1.8}.scope-note{display:flex;gap:10px;color:#6b7c93;font-size:12px;line-height:1.8;padding:0 4px}.scope-note>i{color:#7d8eaa}.scope-note p{margin:8px 0 0;color:#976624}.calculation-hint{color:#6b7c93;padding:10px;line-height:1.8}.history{margin-top:24px;overflow:hidden}.history header{padding:22px}.history h3{margin-bottom:6px}.history-empty{padding:0 22px 22px;color:#6b7c93;font-size:13px}.table-scroll{overflow-x:auto}.history table{width:100%;font-size:13px;border-collapse:collapse}.history th,.history td{padding:14px 22px;border-top:1px solid #edf0f5;white-space:nowrap}.history thead th{font-size:11px;color:#6b7c93;background:#f8f9fc;font-weight:650}.history tbody th{font-weight:600;white-space:normal;min-width:160px}.history th small{display:block;font-size:11px;color:#8b97a8;font-weight:400;margin-top:5px}.history td{font-variant-numeric:tabular-nums}.history tr.selected{background:#f8f7ff}.history footer{display:flex;justify-content:flex-end;align-items:center;gap:16px;padding:16px 22px;border-top:1px solid #edf0f5}button:focus-visible,summary:focus-visible{outline:3px solid #a5b4fc;outline-offset:3px}button:disabled{opacity:.55;cursor:not-allowed}
@media(max-width:991px){.panel-workspace{grid-template-columns:1fr}.panel-result{position:static;grid-row:1}.pair-result{padding:20px}.pair-result strong{font-size:40px}}
@media(max-width:600px){.panel-heading{align-items:flex-start;flex-wrap:wrap}.panel-form,.calculation{padding:18px}.fields{grid-template-columns:1fr}.full-width{grid-column:auto}.history header{padding:18px;align-items:flex-start}.history th,.history td{padding:12px 16px}.form-actions>.btn{flex:1}.panel-heading p,.history header p{font-size:12px}.panel-result{gap:12px}}
</style>
