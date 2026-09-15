<template>
  <div class="analytics-page">
    <header class="analytics-header">
      <h1>Аналітика продажів</h1>
      <button type="button" class="btn-refresh" :disabled="loading" aria-label="Оновити аналітику" title="Оновити аналітику" @click="load(true)">
        <i class="bi bi-arrow-clockwise" :class="{ spinning: loading }" aria-hidden="true"></i>
      </button>
    </header>

    <section class="filter-shell" aria-label="Фільтри фіскальних продажів">
      <form @submit.prevent="load()">
        <div class="filter-toolbar">
          <div class="preset-row" role="group" aria-label="Швидкий вибір періоду">
            <button v-for="preset in presets" :key="preset.key" type="button" class="preset-btn"
              :class="{ active: activePreset === preset.key }" :aria-pressed="activePreset === preset.key"
              @click="applyPreset(preset.key)">{{ preset.label }}</button>
          </div>
          <div class="date-range" role="group" aria-label="Період аналітики">
            <label class="date-field"><span class="visually-hidden">Початок періоду</span><input v-model="filters.date_from" name="date_from" type="date" class="form-control" required @change="activePreset = 'custom'"></label>
            <span class="date-separator" aria-hidden="true">—</span>
            <label class="date-field"><span class="visually-hidden">Кінець періоду</span><input v-model="filters.date_to" name="date_to" type="date" class="form-control" required :min="filters.date_from" @change="activePreset = 'custom'"></label>
          </div>
          <div class="filter-actions">
            <button type="button" class="filter-toggle" :class="{ active: additionalFilterCount > 0 }" :aria-expanded="showAdditionalFilters" aria-controls="analytics-additional-filters" @click="showAdditionalFilters = !showAdditionalFilters">
              <i class="bi bi-sliders" aria-hidden="true"></i> Додаткові фільтри
              <span v-if="additionalFilterCount" class="filter-count">{{ additionalFilterCount }}</span>
              <i :class="showAdditionalFilters ? 'bi bi-chevron-up' : 'bi bi-chevron-down'" aria-hidden="true"></i>
            </button>
            <button type="submit" class="btn-apply" :disabled="loading">Застосувати</button>
          </div>
        </div>
        <div v-show="showAdditionalFilters" id="analytics-additional-filters" class="additional-filters">
          <label class="filter-field">
            <span>Тип продажу</span>
            <select v-model="filters.sale_type" name="sale_type" class="form-select">
              <option value="">Опт + роздріб</option>
              <option v-for="option in filterOptions.sale_types" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </label>
          <label class="filter-field">
            <span>Джерело / код</span>
            <select v-model="filters.source_id" name="source_id" class="form-select">
              <option value="">Усі джерела</option>
              <option v-for="source in filterOptions.sources" :key="source.id" :value="String(source.id)">{{ source.code ? '[' + source.code + '] ' : '' }}{{ source.name }}</option>
            </select>
          </label>
          <label class="filter-field">
            <span>Менеджер</span>
            <select v-model="filters.manager_id" name="manager_id" class="form-select">
              <option value="">Усі менеджери</option>
              <option v-for="manager in filterOptions.managers" :key="manager.id" :value="String(manager.id)">{{ manager.name }}</option>
            </select>
          </label>
          <button v-if="additionalFilterCount" type="button" class="btn-reset" :disabled="loading" @click="resetFilters">Скинути фільтри</button>
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
import { computed, onMounted, reactive, ref } from 'vue';
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
];
const activePreset = ref('month');
const showAdditionalFilters = ref(false);
const additionalFilterCount = computed(() => [filters.sale_type, filters.source_id, filters.manager_id].filter(Boolean).length);
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
  Object.assign(filters, { date_from: toDateInput(start), date_to: toDateInput(end) });
  activePreset.value = key;
  load();
}

function resetFilters() {
  // Скидаємо лише додаткові умови, не змінюючи вибраний користувачем період.
  Object.assign(filters, { sale_type: '', source_id: '', manager_id: '' });
  load();
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
  // Збережене посилання з умовами відкриваємо без прихованих активних фільтрів.
  showAdditionalFilters.value = additionalFilterCount.value > 0;
  load();
});
</script>

<style scoped>
.analytics-page{color:#0f172a;max-width:1800px;margin:0 auto}.analytics-header{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:14px}.analytics-header h1{font-size:1.5rem;line-height:1.2;letter-spacing:-.025em;font-weight:750;margin:0}
.btn-refresh,.btn-apply,.btn-reset,.filter-toggle{border:0;display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:8px;font-weight:650;font-size:.78rem;padding:8px 11px;min-height:36px;white-space:nowrap}.btn-refresh{color:#64748b;background:#fff;border:1px solid #e7ecf3;width:36px;padding:0;font-size:1rem;flex-shrink:0}.btn-refresh:hover,.btn-reset:hover{background:#f1f5f9}.btn-reset{background:transparent;color:#64748b}.btn-apply{background:#4f46e5;color:#fff}.btn-apply:hover{background:#4338ca}button:disabled{opacity:.65}button:focus-visible{outline:2px solid #6366f1;outline-offset:3px}.spinning{animation:spin .85s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
.filter-shell{background:#fff;border:1px solid #e7ecf3;border-radius:12px;padding:10px 12px;margin-bottom:16px}.filter-toolbar{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:center}.preset-row{display:flex;flex-wrap:wrap;gap:4px;flex:1}.preset-btn{border:0;background:transparent;color:#64748b;padding:8px 10px;min-height:36px;border-radius:7px;font-size:.76rem;font-weight:650;white-space:nowrap}.preset-btn:hover,.preset-btn.active{background:#eef2ff;color:#4338ca}.date-range{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center;gap:5px;width:310px;min-width:0;padding:0 5px;border:1px solid #dfe5ee;border-radius:8px}.date-field{min-width:0}.date-range .form-control{width:100%;min-width:0;min-height:34px;padding:5px;border:0;border-radius:5px;color:#334155;font-size:.78rem}.date-range:focus-within{border-color:#818cf8}.date-range .form-control:focus{box-shadow:none;background:#f5f7ff}.date-separator{font-size:.8rem;color:#94a3b8}.filter-actions{display:flex;gap:7px;justify-content:flex-end}.filter-toggle{background:#f8fafc;color:#64748b}.filter-toggle:hover,.filter-toggle.active{background:#eef2ff;color:#4338ca}.filter-toggle>.bi-chevron-up,.filter-toggle>.bi-chevron-down{font-size:.65rem}.filter-count{display:inline-grid;place-items:center;min-width:18px;height:18px;border-radius:5px;background:#4f46e5;color:#fff;font-size:.65rem;padding:0 4px}
.additional-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:12px;align-items:end;margin-top:12px;padding-top:12px;border-top:1px solid #edf0f4}.filter-field{min-width:0}.filter-field>span{display:block;color:#64748b;font-size:.7rem;font-weight:650;margin:0 0 5px 2px}.filter-field .form-select{min-height:36px;border-color:#dfe5ee;border-radius:8px;color:#334155;font-size:.8rem}
.alert-error{display:flex;align-items:center;gap:13px;background:#fff1f2;color:#be123c;border:1px solid #fecdd3;border-radius:14px;padding:14px 16px;margin-bottom:18px}.alert-error div{display:flex;flex-direction:column;flex:1}.alert-error span{font-size:.82rem}.alert-error button{border:0;background:#be123c;color:#fff;border-radius:8px;padding:7px 10px}.period-caption{display:flex;justify-content:space-between;gap:15px;color:#64748b;font-size:.78rem;margin:0 2px 12px}.period-caption span{display:flex;align-items:center;gap:7px}.loading-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:15px}.skeleton{height:170px;border-radius:16px;background:#eef2f7}
@media(max-width:1199.98px){.preset-row{flex-basis:100%}.date-range{flex:1}.additional-filters{grid-template-columns:repeat(3,minmax(0,1fr))}.additional-filters .btn-reset{grid-column:1/-1;justify-self:end}}
@media(max-width:767.98px){.analytics-header h1{font-size:1.3rem}.date-range{flex-basis:100%}.filter-actions{width:100%;justify-content:space-between}.additional-filters{grid-template-columns:1fr}.period-caption{flex-direction:column;gap:4px}.loading-grid{grid-template-columns:1fr}}
@media(max-width:479.98px){.preset-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.filter-actions{flex-wrap:wrap}}
@media(prefers-reduced-motion:reduce){.spinning{animation:none}}
</style>
