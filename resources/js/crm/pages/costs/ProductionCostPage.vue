<template>
  <section class="cost-page" :aria-busy="loading || saving || cardboardSaving || foamSaving || furSaving || laminateSaving || tapeSaving">
    <header class="cost-heading">
      <div><h1>Собівартість виробництва</h1><p>Рахуємо складові однієї пари капців. Кожен матеріал — окремий розрахунок.</p></div>
    </header>
    <nav class="cost-components" aria-label="Складові собівартості">
      <button v-for="item in components" :key="item.key" type="button" :aria-pressed="activeComponent === item.key" :class="{ active: activeComponent === item.key }" :disabled="saving || cardboardSaving || foamSaving || furSaving || laminateSaving || tapeSaving" @click="activeComponent = item.key">
        <i :class="`bi bi-${item.icon}`" aria-hidden="true"></i>{{ item.label }}
      </button>
    </nav>

    <template v-if="activeComponent === 'soles'">
      <div class="cost-section-heading"><div><h2>Китайська підошва</h2><p>Розрахунок партії · суми змінюєте ви, ціну за пару рахуємо автоматично.</p></div><button class="btn btn-primary" :disabled="!ready || loading || saving" @click="newBatch"><i class="bi bi-plus-lg" aria-hidden="true"></i> Нова партія</button></div>
      <div v-if="error" class="alert alert-danger" role="alert">{{ error }} <button class="cost-link" :disabled="loading" @click="load(page)">Оновити список</button></div>
      <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
      <p v-if="loading && !form" class="cost-empty" role="status">Завантажуємо розрахунок…</p>

      <div v-if="form" class="cost-workspace">
        <form class="cost-card cost-form" @submit.prevent="save">
          <fieldset :disabled="saving">
            <legend>{{ selectedId ? 'Дані партії' : 'Нова партія підошви' }}<span class="cost-state" :class="{ pending: dirty }">{{ dirty ? 'Не збережено' : 'Збережено' }}</span></legend>
            <div class="cost-fields cost-fields-meta">
              <label class="cost-name">Назва партії<input v-model="form.name" name="name" class="form-control" maxlength="160" required /></label>
              <label>Кількість пар<input v-model="form.quantity" name="quantity" type="number" min="1" max="10000000" step="1" class="form-control" required /></label>
              <label>Дата рахунку <small>· необов’язково</small><input v-model="form.purchased_on" name="purchased_on" type="date" class="form-control" /></label>
            </div>
            <section v-for="group in groups" :key="group.title" class="cost-form-section">
              <h3>{{ group.title }}</h3>
              <div class="cost-fields">
                <label v-for="field in group.fields" :key="field.key">{{ field.label }}
                  <div class="input-group"><input v-model="form[field.key]" :name="field.key" inputmode="decimal" class="form-control" :placeholder="field.placeholder || '0'" :pattern="field.rate ? '[0-9]+([.,][0-9]{1,4})?' : '[0-9]+([.,][0-9]{1,2})?'" required /><span class="input-group-text">{{ field.unit }}</span></div>
                </label>
              </div>
              <p v-if="group.hint" class="cost-hint">{{ group.hint }}</p>
            </section>
            <p v-if="preview" class="cost-commission">Комісія: ({{ money(form.goods_cny) }} + {{ money(form.china_shipping_cny) }}) × {{ decimalInput(form.commission_percent) }}% = <strong>{{ money(preview.commission_cny) }} ¥</strong></p>
            <details class="cost-notes"><summary>Примітка до розрахунку</summary><textarea v-model="form.note" name="note" class="form-control" rows="3" maxlength="2000" placeholder="Що входить у рахунок, номер вантажу, розміри…"></textarea></details>
            <div v-if="formError" class="alert alert-danger" role="alert">{{ formError }}</div>
            <div class="cost-form-actions"><button class="btn btn-primary" type="submit" :disabled="!dirty || !preview || !form.name.trim()">{{ saving ? 'Зберігаємо…' : selectedId ? 'Зберегти зміни' : 'Зберегти партію' }}</button><button v-if="dirty && savedBatch" type="button" class="btn cost-button" @click="applyBatch(savedBatch)">Скасувати зміни</button><span>Зміна полів одразу оновлює розрахунок. Зберігаємо лише після натискання кнопки.</span></div>
          </fieldset>
        </form>

        <aside class="cost-result">
          <div class="cost-unit"><span>Собівартість підошви · 1 пара</span><strong data-testid="unit-cost">{{ preview ? money(preview.unit_cost_uah) : '—' }} <small>грн</small></strong><p>{{ preview ? `${money(preview.total_uah)} грн ÷ ${count(form.quantity)} пар` : 'Вкажіть кількість, суми та обидва курси валют.' }}</p><span v-if="dirty" class="cost-preview-label">Попередній розрахунок · ще не збережено</span></div>
          <div v-if="preview" class="cost-card cost-breakdown">
            <h3>Що входить у вартість</h3>
            <div class="cost-breakdown-scroll"><table><caption class="visually-hidden">Витрати на партію та на одну пару підошви у гривнях</caption><thead><tr><th scope="col">Витрата</th><th scope="col">На партію</th><th scope="col">На пару</th></tr></thead><tbody><tr v-for="row in preview.breakdown" :key="row.key"><th scope="row">{{ row.label }}</th><td>{{ money(row.total_uah) }}</td><td>{{ money(row.unit_uah) }}</td></tr></tbody><tfoot><tr><th scope="row">Разом, грн</th><td>{{ money(preview.total_uah) }}</td><td>{{ money(preview.unit_cost_uah) }}</td></tr></tfoot></table></div>
            <p>Ціна за пару — загальні витрати, поділені на кількість пар. Округлення рядків показане до копійок.</p>
          </div>
          <p class="cost-scope"><i class="bi bi-info-circle" aria-hidden="true"></i> Це лише підошва, не готові капці. Хутро й решту складових порахуємо окремо. Збереження тут не додає пар до складського залишку.</p>
        </aside>
      </div>

      <section v-if="ready" class="cost-card cost-history">
        <header><div><h2>Збережені партії</h2><p>Кожна партія має власні суми та курси. Нова партія не змінює попередні.</p></div><div class="cost-history-actions"><span>{{ count(total) }} записів</span><button class="btn cost-button" :disabled="loading || saving" @click="load(page)"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Оновити</button></div></header>
        <p v-if="!batches.length" class="cost-empty">Ще немає збережених партій. Заповніть поля вище та збережіть перший розрахунок.</p>
        <div v-else class="cost-history-scroll"><table><caption class="visually-hidden">Історія розрахунків підошви</caption><thead><tr><th scope="col">Партія</th><th scope="col">Пар</th><th scope="col">Усього, грн</th><th scope="col">За пару, грн</th><th scope="col"><span class="visually-hidden">Дії</span></th></tr></thead><tbody><tr v-for="batch in batches" :key="batch.id" :class="{ selected: selectedId === batch.id }"><th scope="row">{{ batch.name }}<small>{{ batch.purchased_on ? date(batch.purchased_on) : 'Дата рахунку не вказана' }}</small></th><td>{{ count(batch.quantity) }}</td><td>{{ money(batch.calculation.total_uah) }}</td><td><strong>{{ money(batch.calculation.unit_cost_uah) }}</strong></td><td><button class="cost-link" :disabled="saving" :aria-label="`Відкрити розрахунок: ${batch.name}`" @click="chooseBatch(batch)">Відкрити</button></td></tr></tbody></table></div>
        <footer v-if="lastPage > 1"><button class="btn cost-button" :disabled="page <= 1 || loading || saving" @click="load(page - 1)">Назад</button><span>{{ page }} / {{ lastPage }}</span><button class="btn cost-button" :disabled="page >= lastPage || loading || saving" @click="load(page + 1)">Далі</button></footer>
      </section>
    </template>
    <section v-else-if="!['cardboard', 'foam', 'fur', 'laminate', 'tape'].includes(activeComponent)" class="cost-card cost-placeholder">
      <span class="cost-placeholder-icon"><i :class="`bi bi-${component.icon}`" aria-hidden="true"></i></span>
      <h2>{{ component.label }}</h2><p>{{ component.description }}</p><span class="cost-state pending">Ще не пораховано</span><p class="cost-placeholder-note">Додамо розрахунок, коли узгодимо витрату та ціни цього матеріалу. Відсутні дані не вважаємо нульовою собівартістю.</p>
    </section>
    <SheetMaterialCostPanel v-if="cardboardOpened" v-show="activeComponent === 'cardboard'" material="cardboard" @saving="cardboardSaving = $event" />
    <SheetMaterialCostPanel v-if="foamOpened" v-show="activeComponent === 'foam'" material="foam" @saving="foamSaving = $event" />
    <FurCostPanel v-if="furOpened" v-show="activeComponent === 'fur'" @saving="furSaving = $event" />
    <LaminateCostPanel v-if="laminateOpened" v-show="activeComponent === 'laminate'" @saving="laminateSaving = $event" />
    <TapeCostPanel v-if="tapeOpened" v-show="activeComponent === 'tape'" @saving="tapeSaving = $event" />
  </section>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import SheetMaterialCostPanel from './SheetMaterialCostPanel.vue';
import FurCostPanel from './FurCostPanel.vue';
import LaminateCostPanel from './LaminateCostPanel.vue';
import TapeCostPanel from './TapeCostPanel.vue';
import { fetchCostBatches, createCostBatch, updateCostBatch, costError } from '@/crm/services/productionCostsApi';
import { calculateSoleCost, costFields, decimalInput, emptyCostForm } from '@/crm/utils/soleCosts';

const components = [
  { key: 'soles', label: 'Підошва', icon: 'box-seam' },
  { key: 'laminate', label: 'Плюш + поролон', icon: 'layers' },
  { key: 'cardboard', label: 'Картон', icon: 'file-earmark' },
  { key: 'foam', label: 'Поролон-вставка', icon: 'square' },
  { key: 'fur', label: 'Хутро', icon: 'scissors' },
  { key: 'tape', label: 'Окантовка', icon: 'bounding-box' },
  { key: 'thread', label: 'Нитки', icon: 'bezier', description: 'Нитки для зшивання деталей, окантування та пришивання до підошви. Потрібна фактична або погоджена норма витрати на пару.' },
  { key: 'labor', label: 'Робота', icon: 'tools', description: 'Розкрій, склеювання, зшивання, окантування та пришивання до підошви. Внесемо погоджені розцінки на одну пару.' },
];
const groups = [
  { title: 'Закупівля в Китаї', hint: 'Указуйте лише підошву. Комісія рахується від суми товару та доставки по Китаю.', fields: [
    { key: 'goods_cny', label: 'Підошва — сума за всю партію', unit: '¥' },
    { key: 'china_shipping_cny', label: 'Доставка по Китаю', unit: '¥' },
    { key: 'commission_percent', label: 'Комісія за викуп', unit: '%' },
  ] },
  { title: 'Доставка та інші витрати', hint: 'Якщо митне оформлення вже входить у міжнародну доставку, вдруге його не додавайте. Відсутні витрати залишайте 0.', fields: [
    { key: 'international_shipping_usd', label: 'Доставка в Україну + митне', unit: '$' },
    { key: 'ukraine_shipping_uah', label: 'Доставка по Україні', unit: 'грн' },
    { key: 'other_costs_uah', label: 'Інші витрати на цю партію', unit: 'грн' },
  ] },
  { title: 'Курси, за якими оплатили', hint: 'Гривень за 1 юань / 1 долар. Курси вводяться вручну та зберігаються окремо для кожної партії.', fields: [
    { key: 'cny_rate', label: 'Курс юаня', unit: 'грн / ¥', rate: true, placeholder: 'Наприклад, 7,10' },
    { key: 'usd_rate', label: 'Курс долара', unit: 'грн / $', rate: true, placeholder: 'Наприклад, 45' },
  ] },
];
const activeComponent = ref('soles');
const cardboardOpened = ref(false), cardboardSaving = ref(false);
const foamOpened = ref(false), foamSaving = ref(false);
const furOpened = ref(false), furSaving = ref(false);
const laminateOpened = ref(false), laminateSaving = ref(false);
const tapeOpened = ref(false), tapeSaving = ref(false);
watch(activeComponent, value => {
  if (value === 'cardboard') cardboardOpened.value = true;
  if (value === 'foam') foamOpened.value = true;
  if (value === 'fur') furOpened.value = true;
  if (value === 'laminate') laminateOpened.value = true;
  if (value === 'tape') tapeOpened.value = true;
});
const component = computed(() => components.find(item => item.key === activeComponent.value));
const batches = ref([]), page = ref(1), lastPage = ref(1), total = ref(0), ready = ref(false), loading = ref(false), saving = ref(false);
const form = ref(null), selectedId = ref(null), version = ref(null), savedBatch = ref(null), baseline = ref(''), requestKey = ref('');
const error = ref(''), formError = ref(''), notice = ref('');
const dirty = computed(() => form.value !== null && JSON.stringify(form.value) !== baseline.value);
const preview = computed(() => form.value ? calculateSoleCost(form.value) : null);
const money = value => Number(decimalInput(value)).toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const count = value => Number(value).toLocaleString('uk-UA');
const date = value => value.split('-').reverse().join('.');

function applyBatch(batch) {
  selectedId.value = batch.id; version.value = batch.version; savedBatch.value = batch;
  form.value = { name: batch.name, purchased_on: batch.purchased_on || '', quantity: String(batch.quantity), note: batch.note || '', ...batch.inputs };
  baseline.value = JSON.stringify(form.value); requestKey.value = ''; formError.value = '';
}
function canDiscard() {
  return !dirty.value || window.confirm('Є незбережені зміни. Перейти без їх збереження?');
}
function chooseBatch(batch) {
  if (!canDiscard()) return;
  applyBatch(batch); notice.value = '';
}
function newBatch() {
  if (!canDiscard()) return;
  form.value = emptyCostForm(); selectedId.value = null; version.value = null; baseline.value = '';
  requestKey.value = crypto.randomUUID(); formError.value = ''; notice.value = '';
}
async function load(nextPage = 1) {
  if (loading.value) return;
  loading.value = true; error.value = '';
  try {
    const { data } = await fetchCostBatches(nextPage);
    batches.value = data.data; page.value = data.current_page; lastPage.value = data.last_page; total.value = data.total; ready.value = true;
    if (!form.value) {
      if (batches.value.length) applyBatch(batches.value[0]);
      else newBatch();
    }
  } catch (err) { error.value = costError(err); }
  finally { loading.value = false; }
}
async function save() {
  if (saving.value || !dirty.value) return;
  if (!preview.value || !form.value.name.trim()) { formError.value = 'Укажіть назву, кількість пар, коректні суми та курси валют.'; return; }
  saving.value = true; formError.value = ''; notice.value = '';
  const payload = { name: form.value.name.trim(), purchased_on: form.value.purchased_on || null, quantity: Number(form.value.quantity), note: form.value.note.trim() || null,
    ...Object.fromEntries(Object.keys(costFields).map(field => [field, decimalInput(form.value[field])])),
    ...(selectedId.value ? { version: version.value } : { request_key: requestKey.value }) };
  try {
    const { data } = selectedId.value ? await updateCostBatch(selectedId.value, payload) : await createCostBatch(payload);
    applyBatch(data); notice.value = 'Розрахунок збережено. Інші партії та складські залишки не змінені.';
    await load(1);
    if (error.value) notice.value = 'Партію збережено, але список не оновився. Натисніть «Оновити список».';
  } catch (err) { formError.value = costError(err); }
  finally { saving.value = false; }
}
function guardUnload(event) {
  if (!dirty.value) return;
  event.preventDefault(); event.returnValue = '';
}
onMounted(() => { load(); window.addEventListener('beforeunload', guardUnload); });
onUnmounted(() => window.removeEventListener('beforeunload', guardUnload));
</script>

<style scoped>
.cost-history-actions{display:flex;align-items:center;gap:12px;flex-shrink:0}.cost-history-actions>span{font-size:12px;color:#6b7c93;white-space:nowrap}@media(max-width:600px){.cost-history-actions>span{display:none}}
.cost-page{max-width:1720px;margin:auto;color:#182239;font-size:14px;--cost-border:#e3e9f2;--cost-muted:#6b7c93}.cost-heading h1{font-size:26px;font-weight:800;letter-spacing:-.035em;margin:0 0 8px}.cost-heading p,.cost-section-heading p{color:var(--cost-muted);font-size:13px;margin:0}.cost-components{display:flex;gap:6px;overflow-x:auto;padding:5px;background:#edf0f7;border-radius:12px;margin:24px 0}.cost-components button{display:flex;align-items:center;gap:8px;flex-shrink:0;border:0;border-radius:8px;background:transparent;color:#64748b;padding:10px 13px;font-size:13px;font-weight:650;white-space:nowrap;min-height:42px}.cost-components button.active{background:white;color:#4f46e5;box-shadow:0 2px 7px #2434510c}.cost-section-heading{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:20px}.cost-section-heading h2{font-size:19px;font-weight:750;margin:0 0 6px}.btn-primary{background:#574ce8;border-color:#574ce8;border-radius:10px;font-size:13px;font-weight:650;padding:11px 16px;white-space:nowrap}.btn-primary:hover{background:#493ed0}.btn i{margin-right:5px}.cost-button{background:white;border:1px solid var(--cost-border);border-radius:10px;color:#475569;font-size:13px;font-weight:600;padding:10px 14px}.cost-link{border:0;background:none;color:#4f46e5;font-weight:600;font-size:12px;padding:6px}.cost-workspace{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(360px,1fr);gap:22px;align-items:start}.cost-card{background:white;border:1px solid var(--cost-border);border-radius:16px;min-width:0}.cost-form{padding:24px}.cost-form fieldset{min-width:0}.cost-form legend{float:none;display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:17px;font-weight:750;margin-bottom:20px;width:100%}.cost-state{display:inline-block;border-radius:6px;font-size:11px;font-weight:650;padding:5px 8px;background:#eaf7f1;color:#188369}.cost-state.pending{background:#fff7e7;color:#976624}.cost-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.cost-fields-meta{grid-template-columns:1fr 1fr}.cost-name{grid-column:1/-1}.cost-fields label{font-size:12px;font-weight:650;color:#52647c;min-width:0}.cost-fields label small{font-weight:400;color:#7c8ca3}.cost-form .form-control{font-size:14px;min-height:44px;border-color:#dce4ef;border-radius:9px;color:#24334a;margin-top:7px;background-color:#fff}.cost-form .form-control:focus{border-color:#a6a2ec;box-shadow:0 0 0 3px #635bdf13}.cost-fields .input-group{margin-top:7px;flex-wrap:nowrap}.cost-fields .input-group .form-control{margin-top:0;min-width:0;border-radius:9px 0 0 9px}.cost-fields .input-group-text{border-color:#dce4ef;background:#f6f8fc;color:#718096;border-radius:0 9px 9px 0;font-size:11px;padding:8px}.cost-form-section{margin-top:24px;padding-top:22px;border-top:1px solid #eef1f6}.cost-form-section h3{font-size:14px;font-weight:750;margin:0 0 16px}.cost-hint{color:var(--cost-muted);font-size:11px;line-height:1.7;margin:12px 0 0}.cost-commission{font-size:12px;color:#596b85;background:#f5f7fc;padding:12px;border-radius:8px;margin:18px 0}.cost-notes{margin:18px 0;font-size:12px;color:#62738c}.cost-notes summary{cursor:pointer;font-weight:650}.cost-notes textarea{line-height:1.6}.cost-form-actions{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-top:22px}.cost-form-actions>span{flex-basis:100%;font-size:11px;color:var(--cost-muted);line-height:1.7}.cost-result{display:grid;gap:16px;position:sticky;top:20px}.cost-unit{padding:26px;border:1px solid #c9e8e1;background:linear-gradient(135deg,#f1fbf7,#eef8fb);border-radius:16px}.cost-unit>span:first-child{font-size:13px;font-weight:650;color:#467567}.cost-unit>strong{display:block;font-size:48px;letter-spacing:-.05em;font-weight:800;line-height:1.2;color:#107b67;margin:14px 0}.cost-unit small{font-size:20px;font-weight:550;letter-spacing:0}.cost-unit p{font-size:12px;color:#5e7c73;margin:0}.cost-preview-label{display:inline-block;font-size:10px;color:#8b651c;background:#fff6df;border-radius:6px;padding:5px 8px;margin-top:13px}.cost-breakdown{padding:20px 0 14px}.cost-breakdown h3{font-size:15px;font-weight:750;margin:0 20px 15px}.cost-breakdown-scroll,.cost-history-scroll{overflow-x:auto}.cost-breakdown table,.cost-history table{width:100%;border-collapse:collapse}.cost-breakdown th,.cost-breakdown td{font-size:12px;padding:11px 16px;border-bottom:1px solid #edf1f6;text-align:right;font-variant-numeric:tabular-nums}.cost-breakdown th:first-child{text-align:left;font-weight:500;min-width:150px}.cost-breakdown td{white-space:nowrap}.cost-breakdown thead th{font-size:10px;color:var(--cost-muted);background:#f8fafc;white-space:nowrap}.cost-breakdown tfoot th,.cost-breakdown tfoot td{font-weight:750;background:#f5f8fc;border:0}.cost-breakdown>p{font-size:10px;color:var(--cost-muted);line-height:1.7;padding:0 20px;margin:13px 0 0}.cost-scope{font-size:11px;color:var(--cost-muted);line-height:1.8;padding:0 6px;margin:0}.cost-scope i{margin-right:4px}.cost-history{margin-top:24px;overflow:hidden}.cost-history header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:22px}.cost-history h2{font-size:17px;font-weight:750;margin:0 0 6px}.cost-history header p{font-size:12px;color:var(--cost-muted);margin:0}.cost-history header>span{font-size:12px;color:var(--cost-muted);white-space:nowrap}.cost-history th,.cost-history td{padding:14px 20px;border-top:1px solid #edf1f6;text-align:right;font-size:12px;white-space:nowrap}.cost-history th:first-child{text-align:left;font-weight:550;white-space:normal;min-width:200px}.cost-history th small{display:block;color:var(--cost-muted);font-size:10px;font-weight:400;margin-top:4px}.cost-history thead{background:#f8fafc;color:var(--cost-muted)}.cost-history thead th{font-size:11px;font-weight:600}.cost-history tr.selected{background:#f7f7ff}.cost-history footer{display:flex;align-items:center;justify-content:flex-end;gap:15px;padding:16px;font-size:12px}.cost-empty{padding:30px;text-align:center;color:var(--cost-muted)}.cost-placeholder{padding:44px 24px;text-align:center;max-width:780px;margin:36px auto}.cost-placeholder-icon{display:grid;place-items:center;background:#f0effe;color:#6656d9;border-radius:14px;width:56px;height:56px;font-size:25px;margin:0 auto 18px}.cost-placeholder h2{font-size:22px;font-weight:750}.cost-placeholder p{max-width:560px;margin:15px auto;font-size:13px;line-height:1.9;color:var(--cost-muted)}.cost-placeholder .cost-placeholder-note{font-size:12px;margin-bottom:0}button:focus-visible,summary:focus-visible{outline:3px solid #a5b4fc;outline-offset:3px}button:disabled{opacity:.55;cursor:not-allowed}
@media(max-width:1250px){.cost-workspace{grid-template-columns:minmax(0,1.2fr) minmax(330px,1fr)}.cost-fields:not(.cost-fields-meta){grid-template-columns:repeat(2,minmax(0,1fr))}.cost-form{padding:20px}.cost-breakdown th,.cost-breakdown td{padding:11px 12px}}
@media(max-width:991px){.cost-workspace{grid-template-columns:1fr}.cost-result{position:static;grid-row:1}.cost-breakdown{display:block}.cost-fields:not(.cost-fields-meta){grid-template-columns:repeat(3,minmax(0,1fr))}.cost-unit{padding:20px}.cost-unit>strong{font-size:40px}.cost-history header{align-items:flex-start}}
@media(max-width:600px){.cost-heading h1{font-size:23px}.cost-heading p{font-size:12px}.cost-components{margin:20px 0}.cost-section-heading{align-items:flex-start;flex-wrap:wrap}.cost-section-heading p{font-size:12px}.cost-fields,.cost-fields:not(.cost-fields-meta){grid-template-columns:1fr}.cost-name{grid-column:auto}.cost-form{padding:17px}.cost-result{gap:12px}.cost-history header{padding:17px}.cost-history header>span{display:none}.cost-history th,.cost-history td{padding:12px 15px}.cost-breakdown th:first-child{min-width:135px}.cost-form-actions>.btn{flex:1}.cost-unit>strong{font-size:42px}}
</style>
