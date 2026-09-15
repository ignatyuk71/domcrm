<template>
  <div class="analytics-page">
    <header class="analytics-header">
      <div>
        <div class="eyebrow"><i class="bi bi-receipt"></i> Продажі за чеками Checkbox</div>
        <h1>Аналітика продажів</h1>
        <p>Фактична виручка за чеками та повернення посилок за статусами.</p>
      </div>
      <button type="button" class="btn-refresh" :disabled="loading" aria-label="Оновити аналітику" @click="load(true)">
        <i class="bi bi-arrow-clockwise" :class="{ spinning: loading }"></i> Оновити
      </button>
    </header>

    <section class="filter-shell" aria-label="Фільтри фіскальних продажів">
      <div class="preset-row">
        <button v-for="preset in presets" :key="preset.key" type="button" class="preset-btn"
          :class="{ active: activePreset === preset.key }" :aria-pressed="activePreset === preset.key"
          @click="applyPreset(preset.key)">{{ preset.label }}</button>
      </div>
      <form class="filter-grid" @submit.prevent="load()">
        <label class="filter-field"><span>Від</span><input v-model="filters.date_from" type="date" class="form-control" required @change="activePreset = 'custom'"></label>
        <label class="filter-field"><span>До</span><input v-model="filters.date_to" type="date" class="form-control" required :min="filters.date_from" @change="activePreset = 'custom'"></label>
        <label class="filter-field">
          <span>Тип продажу</span>
          <select v-model="filters.sale_type" class="form-select">
            <option value="">Опт + роздріб</option>
            <option v-for="option in filterOptions.sale_types" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </label>
        <label class="filter-field">
          <span>Джерело / код</span>
          <select v-model="filters.source_id" class="form-select">
            <option value="">Усі джерела</option>
            <option v-for="source in filterOptions.sources" :key="source.id" :value="String(source.id)">{{ source.code ? '[' + source.code + '] ' : '' }}{{ source.name }}</option>
          </select>
        </label>
        <label class="filter-field">
          <span>Менеджер</span>
          <select v-model="filters.manager_id" class="form-select">
            <option value="">Усі менеджери</option>
            <option v-for="manager in filterOptions.managers" :key="manager.id" :value="String(manager.id)">{{ manager.name }}</option>
          </select>
        </label>
        <div class="filter-actions">
          <button type="button" class="btn-reset" @click="resetFilters">Скинути</button>
          <button type="submit" class="btn-apply" :disabled="loading"><i class="bi bi-funnel"></i> Застосувати</button>
        </div>
      </form>
    </section>

    <div v-if="error" class="alert-error" role="alert">
      <i class="bi bi-exclamation-octagon"></i>
      <div><strong>Не вдалося завантажити аналітику.</strong><span>{{ error }}</span></div>
      <button type="button" @click="load()">Повторити</button>
    </div>
    <template v-if="hasLoaded">
      <div class="period-caption">
        <span><i class="bi bi-calendar3"></i> {{ formatPeriod(meta.date_from, meta.date_to) }}</span>
        <span>Порівняння: {{ formatPeriod(meta.comparison_from, meta.comparison_to) }}</span>
      </div>
      <FiscalSalesOverview :fiscal="fiscal" :currency="meta.currency" />
      <ShippingReturnsOverview v-if="shippingReturns" :data="shippingReturns" />
      <SenderPaidDeliveryOverview v-if="senderDelivery" :data="senderDelivery" :loading="loading" @page="load(false, $event)" />
    </template>
    <div v-if="loading && !hasLoaded" class="loading-grid" role="status" aria-label="Завантаження аналітики">
      <div v-for="index in 3" :key="index" class="skeleton"></div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { fetchSalesAnalytics } from '@/crm/services/salesAnalyticsApi';
import FiscalSalesOverview from './FiscalSalesOverview.vue';
import ShippingReturnsOverview from './ShippingReturnsOverview.vue';
import SenderPaidDeliveryOverview from './SenderPaidDeliveryOverview.vue';

const toDateInput = (date) => date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
const today = new Date();
const filters = reactive({
  date_from: toDateInput(new Date(today.getFullYear(), today.getMonth(), 1)), date_to: toDateInput(today),
  sale_type: '', source_id: '', manager_id: '',
});
const presets = [
  { key: 'today', label: 'Сьогодні' }, { key: '7days', label: '7 днів' },
  { key: 'month', label: 'Цей місяць' }, { key: 'previous_month', label: 'Минулий місяць' },
  { key: '90days', label: '90 днів' },
];
const activePreset = ref('month');
const loading = ref(false);
const hasLoaded = ref(false);
const error = ref('');
const meta = ref({});
const fiscal = ref({});
const shippingReturns = ref(null);
const senderDelivery = ref(null);
const filterOptions = reactive({ sources: [], managers: [], sale_types: [] });
let requestSequence = 0;

async function load(fresh = false, senderPage = 1) {
  const sequence = ++requestSequence;
  const selected = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value !== null));
  loading.value = true;
  error.value = '';
  try {
    // Старі розрахунки за замовленнями не завантажуємо; Checkbox ведеться в UAH.
    const { data } = await fetchSalesAnalytics({ ...selected, currency: 'UAH', fiscal_only: 1, ...(senderPage > 1 ? { sender_delivery_page: senderPage } : {}), ...(fresh ? { fresh: 1 } : {}) });
    if (sequence !== requestSequence) return;
    meta.value = data.meta || {};
    fiscal.value = data.fiscal || {};
    shippingReturns.value = data.shipping_returns || null;
    senderDelivery.value = data.sender_delivery || null;
    Object.assign(filterOptions, data.filters || {});
    hasLoaded.value = true;
    window.history.replaceState({}, '', window.location.pathname + '?' + new URLSearchParams(selected));
  } catch (e) {
    if (sequence !== requestSequence) return;
    error.value = e.response?.data?.message || 'Перевірте з’єднання та спробуйте ще раз.';
  } finally {
    if (sequence === requestSequence) loading.value = false;
  }
}

function applyPreset(key) {
  const end = new Date();
  let start = new Date(end);
  if (key === '7days') start.setDate(end.getDate() - 6);
  if (key === 'month') start = new Date(end.getFullYear(), end.getMonth(), 1);
  if (key === 'previous_month') {
    start = new Date(end.getFullYear(), end.getMonth() - 1, 1);
    end.setDate(0);
  }
  if (key === '90days') start.setDate(end.getDate() - 89);
  Object.assign(filters, { date_from: toDateInput(start), date_to: toDateInput(end) });
  activePreset.value = key;
  load();
}

function resetFilters() {
  Object.assign(filters, { sale_type: '', source_id: '', manager_id: '' });
  applyPreset('month');
}

function formatPeriod(from, to) {
  if (!from || !to) return '—';
  const options = { day: 'numeric', month: 'short', year: 'numeric' };
  return new Date(from + 'T00:00:00').toLocaleDateString('uk-UA', options) + ' — ' + new Date(to + 'T00:00:00').toLocaleDateString('uk-UA', options);
}

onMounted(() => {
  const query = new URLSearchParams(window.location.search);
  Object.keys(filters).forEach((key) => { if (query.has(key)) filters[key] = query.get(key); });
  if (query.has('date_from') || query.has('date_to')) activePreset.value = 'custom';
  load();
});
</script>

<style scoped>
.analytics-page{color:#0f172a;max-width:1800px;margin:0 auto}.analytics-header{display:flex;justify-content:space-between;align-items:flex-end;gap:24px;margin-bottom:22px}.eyebrow{display:flex;align-items:center;gap:7px;color:#0d9488;font-size:.72rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-bottom:8px}.analytics-header h1{font-size:clamp(1.65rem,2.5vw,2.25rem);line-height:1.08;letter-spacing:-.04em;font-weight:850;margin:0 0 7px}.analytics-header p{color:#64748b;margin:0;font-size:.92rem}
.btn-refresh,.btn-apply,.btn-reset{border:0;display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:11px;font-weight:700;font-size:.86rem;padding:10px 14px;white-space:nowrap}.btn-refresh{color:#475569;background:#fff;border:1px solid #e7ecf3}.btn-reset{background:#f1f5f9;color:#64748b}.btn-apply{background:#4f46e5;color:#fff}.btn-apply:hover{background:#4338ca}button:disabled{opacity:.65}.spinning{animation:spin .85s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
.filter-shell{background:#fff;border:1px solid #e7ecf3;border-radius:16px;padding:14px;margin-bottom:20px}.preset-row{display:flex;flex-wrap:wrap;gap:6px;padding-bottom:13px;margin-bottom:13px;border-bottom:1px solid #f1f4f8}.preset-btn{border:0;background:#f1f5f9;color:#64748b;padding:7px 12px;border-radius:9px;font-size:.78rem;font-weight:750}.preset-btn:hover,.preset-btn.active{background:#eef2ff;color:#4338ca}.filter-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr)) auto;gap:12px;align-items:end}.filter-field{min-width:0}.filter-field>span{display:block;color:#64748b;font-size:.7rem;font-weight:750;margin:0 0 5px 2px}.filter-field .form-control,.filter-field .form-select{min-height:40px;border-color:#dfe5ee;border-radius:10px;color:#334155;font-size:.8rem}.filter-actions{display:flex;gap:7px;justify-content:flex-end}
.alert-error{display:flex;align-items:center;gap:13px;background:#fff1f2;color:#be123c;border:1px solid #fecdd3;border-radius:14px;padding:14px 16px;margin-bottom:18px}.alert-error div{display:flex;flex-direction:column;flex:1}.alert-error span{font-size:.82rem}.alert-error button{border:0;background:#be123c;color:#fff;border-radius:8px;padding:7px 10px}.period-caption{display:flex;justify-content:space-between;gap:15px;color:#64748b;font-size:.78rem;margin:0 2px 12px}.period-caption span{display:flex;align-items:center;gap:7px}.loading-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:15px}.skeleton{height:170px;border-radius:16px;background:#eef2f7}
@media(max-width:1199.98px){.filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:767.98px){.analytics-header{align-items:flex-start;flex-direction:column;gap:12px}.filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.period-caption{flex-direction:column;gap:4px}.loading-grid{grid-template-columns:1fr}}
@media(max-width:479.98px){.filter-grid{grid-template-columns:1fr}.filter-actions{justify-content:stretch}.filter-actions button{flex:1}}
@media(prefers-reduced-motion:reduce){.spinning{animation:none}}
</style>
