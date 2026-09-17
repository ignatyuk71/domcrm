<template>
  <aside ref="root" class="pair-summary" aria-label="Собівартість однієї пари" :aria-busy="loading">
    <div class="pair-summary-top"><span class="pair-summary-icon"><i class="bi bi-calculator" aria-hidden="true"></i></span><span>Собівартість 1 пари</span><span class="pair-summary-badge">{{ result.dirty ? 'Чернетка' : 'Неповна' }}</span></div>
    <div class="pair-summary-main" aria-live="polite" aria-atomic="true"><strong data-testid="pair-total">{{ loading || error ? '—' : money(result.total) }}</strong><span>грн / пара</span></div>
    <div class="pair-summary-bottom"><span>{{ loading ? 'Підтягуємо матеріали…' : error ? 'Не вдалося оновити суму' : result.known ? `Враховано ${result.known} із ${result.rows.length} складових` : 'Додайте перший розрахунок' }}</span><button v-if="error" type="button" @click="load">Повторити <i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button><button v-else ref="toggle" type="button" :disabled="loading" :aria-expanded="expanded" :aria-controls="panelId" @click="expanded = !expanded">Деталі <i :class="`bi bi-chevron-${expanded ? 'up' : 'down'}`" aria-hidden="true"></i></button></div>
    <p class="pair-summary-warning">{{ result.dirty ? 'Попередня сума · є незбережені зміни' : profile === 'outdoor' ? 'Матеріали · без роботи' : 'Матеріали · без ниток і роботи' }}</p>

    <section v-if="expanded && !loading && !error" :id="panelId" class="pair-summary-details" aria-label="Складові собівартості">
      <header><div><h2>Що входить у суму</h2><p>На одну пару капців</p></div><button type="button" class="pair-close" aria-label="Закрити деталі" @click="close(true)"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
      <div class="pair-summary-rows">
        <button v-for="row in result.rows" :key="row.key" type="button" class="pair-summary-row" :class="{ missing: row.amount === null }" :disabled="busy" @click="selectPart(row.component)">
          <i :class="`bi bi-${row.icon}`" aria-hidden="true"></i><span class="pair-summary-label"><span>{{ row.label }}</span><small>{{ row.amount === null ? 'Ще не враховано' : row.name }}<em v-if="row.dirty"> · чернетка</em></small></span><strong>{{ money(row.amount) }}<small v-if="row.amount !== null"> грн</small></strong><i class="bi bi-chevron-right pair-row-arrow" aria-hidden="true"></i>
        </button>
      </div>
      <footer><div><span>Враховані витрати</span><strong>{{ money(result.total) }}<small v-if="result.total !== null"> грн</small></strong></div><p v-if="result.missing.length">Це ще не повна собівартість. Не враховано: {{ result.missing.map(row => row.label.toLocaleLowerCase('uk-UA')).join(', ') }}.</p><p>Спочатку беремо останню створену партію кожного матеріалу. Натисніть складову, щоб вибрати іншу партію або змінити розрахунок. Вибір діє до виходу з категорії.</p><p>Доставка врахована лише там, де її суму вказано.</p></footer>
    </section>
  </aside>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, useId } from 'vue';
import { fetchCostSummary } from '@/crm/services/productionCostsApi';
import { summarizeCostParts } from '@/crm/utils/costSummary';

const props = defineProps({ modelId: { type: Number, required: true }, profile: { type: String, default: 'sewn' }, snapshots: { type: Object, default: () => ({}) }, busy: Boolean });
const emit = defineEmits(['select-part']);
const saved = ref({}), loading = ref(true), error = ref(false), expanded = ref(false), root = ref(null), toggle = ref(null);
const panelId = `cost-summary-${useId()}`;
const result = computed(() => summarizeCostParts({ ...saved.value, ...props.snapshots }, props.profile));
const money = value => value === null ? '—' : value.toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
let disposed = false;
async function load() {
  loading.value = true; error.value = false;
  try {
    const { data } = await fetchCostSummary(props.modelId);
    if (disposed) return;
    if (data.model_id !== props.modelId) throw new Error('Невідповідна категорія');
    saved.value = data.components;
  } catch { if (!disposed) error.value = true; }
  finally { if (!disposed) loading.value = false; }
}
function close(focus = false) { expanded.value = false; if (focus) toggle.value?.focus(); }
function selectPart(component) { if (props.busy) return; close(); emit('select-part', component); }
function outside(event) { if (expanded.value && !root.value?.contains(event.target)) close(); }
function escape(event) { if (event.key === 'Escape' && expanded.value) { event.stopPropagation(); close(true); } }
onMounted(() => { load(); document.addEventListener('pointerdown', outside); document.addEventListener('keydown', escape); });
onUnmounted(() => { disposed = true; document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', escape); });
</script>

<style scoped>
.pair-summary{position:relative;flex:0 0 370px;min-width:0;padding:18px 20px 14px;border:1px solid #dcd9f4;border-radius:16px;background:linear-gradient(120deg,#fbfaff,#f1f0fd);color:#242241;box-shadow:0 3px 12px #4a3d8206}.pair-summary-top{display:flex;align-items:center;gap:9px;font-size:12px;font-weight:650}.pair-summary-icon{display:grid;place-items:center;width:29px;height:29px;border:1px solid #e3dff8;background:#fff;border-radius:8px;color:#6a58d5;font-size:15px}.pair-summary-badge{margin-left:auto;font-size:10px;line-height:1.4;padding:4px 7px;border-radius:5px;color:#996719;background:#fff4df}.pair-summary-main{display:flex;align-items:baseline;gap:9px;margin:9px 0 7px;font-variant-numeric:tabular-nums;flex-wrap:wrap}.pair-summary-main strong{font-size:35px;line-height:1.12;font-weight:800;letter-spacing:-.045em}.pair-summary-main>span{font-size:12px;color:#817994}.pair-summary-bottom{display:flex;justify-content:space-between;align-items:center;gap:9px;font-size:11px;color:#746c8b}.pair-summary-bottom button{border:0;background:none;color:#6752cd;font-size:11px;font-weight:650;padding:6px 0 6px 8px;white-space:nowrap}.pair-summary-bottom button i{margin-left:5px;font-size:10px}.pair-summary-warning{font-size:10px!important;color:#8b6b36!important;margin:3px 0 0!important}.pair-summary-details{position:absolute;right:0;top:calc(100% + 10px);z-index:30;width:470px;max-width:calc(100vw - 36px);border:1px solid #e1e5ef;border-radius:16px;background:#fff;box-shadow:0 16px 48px #29365426;overflow:auto;max-height:min(740px,75vh)}.pair-summary-details header{display:flex;justify-content:space-between;align-items:center;padding:19px 20px 14px;border-bottom:1px solid #edf0f6}.pair-summary-details h2{font-size:15px;font-weight:750;margin:0}.pair-summary-details header p{font-size:11px;color:#8590a3;margin:5px 0 0}.pair-close{border:0;background:#f4f5f9;color:#708096;border-radius:7px;width:29px;height:29px;font-size:11px;flex-shrink:0}.pair-summary-rows{padding:5px 10px}.pair-summary-row{display:flex;align-items:center;gap:10px;width:100%;padding:10px;border:0;border-radius:8px;background:none;text-align:left;color:#344056}.pair-summary-row:hover{background:#f7f6fe}.pair-summary-row>i:first-child{width:27px;color:#8d80c4;font-size:15px;flex-shrink:0}.pair-summary-label{display:flex;flex-direction:column;gap:3px;flex:1;min-width:0;font-size:12px;font-weight:550}.pair-summary-label>small{font-size:10px;color:#8a93a5;font-weight:400;overflow-wrap:anywhere}.pair-summary-label em{font-style:normal;color:#b08039}.pair-summary-row>strong{font-size:12px;font-variant-numeric:tabular-nums;white-space:nowrap}.pair-summary-row>strong small{font-size:10px;font-weight:400;color:#8992a1}.pair-row-arrow{font-size:9px;color:#b2b7c3}.pair-summary-row.missing{color:#9a93a2}.pair-summary-row.missing .pair-summary-label small{color:#b08b4b}.pair-summary-details footer{border-top:1px solid #edf0f6;padding:15px 20px 17px;background:#fcfcfe}.pair-summary-details footer>div{display:flex;justify-content:space-between;align-items:center;gap:10px;font-size:12px;font-weight:650;margin-bottom:10px}.pair-summary-details footer strong{font-size:18px;font-variant-numeric:tabular-nums}.pair-summary-details footer strong small{font-size:11px}.pair-summary-details footer p{font-size:10px;color:#8790a2;line-height:1.65;margin:7px 0 0}.pair-summary-details footer p:first-of-type{color:#a67b3a}button:focus-visible{outline:3px solid #a5b4fc;outline-offset:2px}button:disabled{opacity:.55;cursor:not-allowed}@media(max-width:900px){.pair-summary{flex-basis:auto;width:100%}.pair-summary-details{width:100%;max-width:100%}}
</style>
