<template>
  <div class="analytics-page">
    <header class="analytics-header">
      <div>
        <div class="eyebrow"><i class="bi bi-stars"></i> Центр управління продажами</div>
        <h1>Аналітика продажів</h1>
        <p>Виторг, реальний валовий прибуток, маржа та аудит кожного замовлення.</p>
      </div>
      <div class="header-actions">
        <a :href="exportUrl" class="btn-export">
          <i class="bi bi-download"></i><span>Експорт CSV</span>
        </a>
        <button type="button" class="btn-refresh" :disabled="loading" @click="load(filters.page, true)">
          <i class="bi bi-arrow-clockwise" :class="{ spinning: loading }"></i>
        </button>
      </div>
    </header>

    <section class="filter-shell">
      <div class="preset-row">
        <button
          v-for="preset in presets"
          :key="preset.key"
          type="button"
          class="preset-btn"
          :class="{ active: activePreset === preset.key }"
          @click="applyPreset(preset.key)"
        >{{ preset.label }}</button>
      </div>

      <div class="filter-grid">
        <label class="filter-field">
          <span>Від</span>
          <input v-model="filters.date_from" type="date" class="form-control" @change="activePreset = 'custom'">
        </label>
        <label class="filter-field">
          <span>До</span>
          <input v-model="filters.date_to" type="date" class="form-control" @change="activePreset = 'custom'">
        </label>
        <label class="filter-field">
          <span>Залік продажів</span>
          <select v-model="filters.scope" class="form-select">
            <option v-for="option in filterOptions.scopes" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </label>
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
            <option v-for="source in filterOptions.sources" :key="source.id" :value="String(source.id)">
              {{ source.code ? `[${source.code}] ` : '' }}{{ source.name }}
            </option>
          </select>
        </label>
        <label class="filter-field">
          <span>Менеджер</span>
          <select v-model="filters.manager_id" class="form-select">
            <option value="">Усі менеджери</option>
            <option v-for="manager in filterOptions.managers" :key="manager.id" :value="String(manager.id)">{{ manager.name }}</option>
          </select>
        </label>
        <label class="filter-field">
          <span>Статус</span>
          <select v-model="filters.status_id" class="form-select">
            <option value="">Усі статуси</option>
            <option v-for="status in filterOptions.statuses" :key="status.id" :value="String(status.id)">{{ status.name }}</option>
          </select>
        </label>
        <label class="filter-field">
          <span>Оплата</span>
          <select v-model="filters.payment_status" class="form-select">
            <option value="">Усі статуси оплати</option>
            <option v-for="option in filterOptions.payment_statuses" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </label>
        <label class="filter-field currency-field">
          <span>Валюта</span>
          <select v-model="filters.currency" class="form-select">
            <option v-for="currency in filterOptions.currencies" :key="currency" :value="currency">{{ currency }}</option>
          </select>
        </label>
        <div class="filter-actions">
          <button type="button" class="btn-reset" @click="resetFilters"><i class="bi bi-arrow-counterclockwise"></i> Скинути</button>
          <button type="button" class="btn-apply" :disabled="loading" @click="applyFilters">
            <span v-if="loading" class="spinner-border spinner-border-sm"></span>
            <i v-else class="bi bi-funnel-fill"></i>
            Застосувати
          </button>
        </div>
      </div>
    </section>

    <div v-if="error" class="alert-error">
      <i class="bi bi-exclamation-octagon"></i>
      <div><strong>Не вдалося завантажити аналітику.</strong><span>{{ error }}</span></div>
      <button type="button" @click="load(filters.page)">Повторити</button>
    </div>

    <template v-if="hasLoaded">
      <div class="period-caption">
        <span><i class="bi bi-calendar3"></i> {{ formatPeriod(meta.date_from, meta.date_to) }}</span>
        <span>Порівняння: {{ formatPeriod(meta.comparison_from, meta.comparison_to) }}</span>
      </div>

      <section class="kpi-grid">
        <article v-for="card in primaryKpis" :key="card.key" class="kpi-card" :class="`tone-${card.tone}`">
          <div class="kpi-top">
            <span class="kpi-icon"><i :class="card.icon"></i></span>
            <span v-if="card.delta !== null" class="delta" :class="card.delta >= 0 ? 'positive' : 'negative'">
              <i :class="card.delta >= 0 ? 'bi bi-arrow-up-right' : 'bi bi-arrow-down-right'"></i>
              {{ Math.abs(card.delta) }}{{ card.deltaSuffix || '%' }}
            </span>
          </div>
          <div class="kpi-label">{{ card.label }}</div>
          <div class="kpi-value">{{ card.value }}</div>
          <div class="kpi-note">{{ card.note }}</div>
        </article>
      </section>

      <section class="mini-kpi-grid">
        <article v-for="item in secondaryKpis" :key="item.label" class="mini-kpi">
          <span class="mini-icon" :class="item.tone"><i :class="item.icon"></i></span>
          <div><span>{{ item.label }}</span><strong>{{ item.value }}</strong><small>{{ item.note }}</small></div>
        </article>
      </section>

      <div v-if="costCoverageWarning" class="quality-warning">
        <span class="quality-icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
        <div>
          <strong>Маржинальність розрахована для {{ kpis.cost_coverage?.value ?? 0 }}% виторгу</strong>
          <p>У {{ kpis.cost_coverage?.missing_lines ?? 0 }} позиціях немає закупівельної ціни. Заповніть собівартість товарів, щоб прибуток був повним.</p>
        </div>
        <a href="/products">Перейти до товарів <i class="bi bi-arrow-right"></i></a>
      </div>

      <section class="insights-grid">
        <article v-for="(insight, index) in insights" :key="index" class="insight-card" :class="`insight-${insight.type}`">
          <span><i :class="`bi ${insight.icon}`"></i></span>
          <div><strong>{{ insight.title }}</strong><p>{{ insight.description }}</p></div>
        </article>
      </section>

      <section class="content-grid chart-grid">
        <article class="data-card revenue-card">
          <div class="card-head">
            <div><h2>Динаміка продажів</h2><p>Виторг, собівартість та валовий прибуток за днями</p></div>
            <div class="chart-legend">
              <span><i class="legend-dot revenue"></i>Виторг</span>
              <span><i class="legend-dot profit"></i>Прибуток</span>
              <span><i class="legend-dot cost"></i>Собівартість</span>
            </div>
          </div>
          <ApexChart type="area" height="350" :options="trendOptions" :series="trendSeries" />
        </article>

        <article class="data-card split-card">
          <div class="card-head"><div><h2>Опт і роздріб</h2><p>Частка у виторгу</p></div></div>
          <div v-if="saleTypes.length" class="split-body">
            <ApexChart type="donut" height="230" :options="saleTypeOptions" :series="saleTypeSeries" />
            <div class="split-list">
              <div v-for="item in saleTypes" :key="item.key" class="split-row">
                <span class="split-dot" :class="item.key"></span>
                <div><strong>{{ item.label }}</strong><small>{{ item.orders }} зам. · {{ item.units }} од.</small></div>
                <span>{{ formatMoney(item.revenue) }}</span>
              </div>
            </div>
          </div>
          <div v-else class="empty-state"><i class="bi bi-pie-chart"></i><span>Немає даних</span></div>
        </article>
      </section>

      <section class="content-grid performance-grid">
        <article class="data-card">
          <div class="card-head">
            <div><h2>Ефективність джерел</h2><p>Коди каналів, виторг і прибуток</p></div>
          </div>
          <div v-if="sources.length" class="source-list">
            <div v-for="source in sources" :key="source.id || source.code" class="source-row">
              <span class="source-icon" :style="sourceIconStyle(source)"><i :class="sourceIcon(source.code)"></i></span>
              <div class="source-main">
                <div class="source-title"><strong>{{ source.source_name }}</strong><code>{{ source.code || 'other' }}</code></div>
                <div class="progress-track"><span :style="{ width: `${source.share}%`, backgroundColor: source.color }"></span></div>
              </div>
              <div class="source-stat"><span>{{ source.orders }} зам.</span><strong>{{ formatMoney(source.revenue) }}</strong></div>
              <div class="source-profit"><span>Прибуток</span><strong :class="numberClass(source.profit)">{{ formatMoney(source.profit) }}</strong><small>{{ formatPercent(source.margin) }}</small></div>
            </div>
          </div>
          <div v-else class="empty-state"><i class="bi bi-diagram-3"></i><span>Немає джерел за період</span></div>
        </article>

        <article class="data-card">
          <div class="card-head"><div><h2>Топ товарів</h2><p>За виторгом у вибраному періоді</p></div><a href="/products">Каталог <i class="bi bi-arrow-right"></i></a></div>
          <div class="table-responsive">
            <table class="analytics-table products-table">
              <thead><tr><th>Товар</th><th class="text-end">Продано</th><th class="text-end">Виторг</th><th class="text-end">Прибуток</th><th class="text-end">Маржа</th></tr></thead>
              <tbody>
                <tr v-for="(product, index) in topProducts" :key="product.id || product.title">
                  <td><div class="product-cell"><span>{{ index + 1 }}</span><div><strong>{{ product.title }}</strong><small>{{ product.sku || 'Без SKU' }} · {{ product.orders }} зам.</small></div></div></td>
                  <td class="text-end">{{ formatNumber(product.units) }}</td>
                  <td class="text-end fw-bold">{{ formatMoney(product.revenue) }}</td>
                  <td class="text-end" :class="numberClass(product.profit)">{{ product.has_missing_cost ? 'частково · ' : '' }}{{ formatMoney(product.profit) }}</td>
                  <td class="text-end"><span class="margin-pill" :class="marginClass(product.margin)">{{ formatPercent(product.margin) }}</span></td>
                </tr>
                <tr v-if="!topProducts.length"><td colspan="5"><div class="empty-table">Немає даних про товари</div></td></tr>
              </tbody>
            </table>
          </div>
        </article>
      </section>

      <section class="content-grid team-grid">
        <article class="data-card">
          <div class="card-head"><div><h2>Команда продажів</h2><p>Результат менеджерів</p></div></div>
          <div class="manager-list">
            <div v-for="manager in managers" :key="manager.id || manager.name" class="manager-row">
              <span class="manager-avatar">{{ initials(manager.name) }}</span>
              <div><strong>{{ manager.name }}</strong><small>{{ manager.orders }} зам. · {{ manager.units }} од.</small></div>
              <div><strong>{{ formatMoney(manager.revenue) }}</strong><small>прибуток {{ formatMoney(manager.profit) }}</small></div>
              <span class="margin-pill" :class="marginClass(manager.margin)">{{ formatPercent(manager.margin) }}</span>
            </div>
            <div v-if="!managers.length" class="empty-state compact"><span>Немає даних по менеджерах</span></div>
          </div>
        </article>

        <article class="data-card">
          <div class="card-head"><div><h2>Стани замовлень</h2><p>Повна воронка, включно з проблемними</p></div></div>
          <div class="status-cloud">
            <button v-for="status in statuses" :key="status.id || status.code" type="button" class="status-item" @click="filterByStatus(status.id)">
              <span class="status-mark" :style="{ backgroundColor: status.color }"></span>
              <span><strong>{{ status.name }}</strong><small>{{ formatMoney(status.revenue) }}</small></span>
              <b>{{ status.orders }}</b>
            </button>
            <div v-if="!statuses.length" class="empty-state compact"><span>Немає статусів за період</span></div>
          </div>
        </article>
      </section>

      <section class="data-card audit-card">
        <div class="card-head audit-head">
          <div><h2>Детальний аудит замовлень</h2><p>{{ formatNumber(audit.total) }} записів за поточними фільтрами</p></div>
          <label class="page-size">Рядків
            <select v-model.number="filters.per_page" class="form-select form-select-sm" @change="changePage(1)">
              <option :value="20">20</option><option :value="50">50</option><option :value="100">100</option>
            </select>
          </label>
        </div>
        <div class="table-responsive audit-scroll">
          <table class="analytics-table audit-table">
            <thead><tr><th>Дата / замовлення</th><th>Клієнт</th><th>Тип</th><th>Джерело</th><th>Менеджер</th><th>Статус</th><th class="text-end">Од.</th><th class="text-end">Виторг</th><th class="text-end">Собівартість</th><th class="text-end">Прибуток / маржа</th></tr></thead>
            <tbody>
              <tr v-for="order in audit.data" :key="order.id">
                <td><a :href="`/orders/${order.id}`" class="order-link">#{{ order.number }}</a><small>{{ formatDateTime(order.created_at) }}</small></td>
                <td><strong>{{ order.customer }}</strong><small>{{ paymentLabel(order.payment_status) }}</small></td>
                <td><span class="sale-type-pill" :class="order.sale_type">{{ order.sale_type === 'wholesale' ? 'Опт' : 'Роздріб' }}</span></td>
                <td><div class="source-audit"><code>{{ order.source.code || 'other' }}</code><span>{{ order.source.name }}</span></div></td>
                <td>{{ order.manager }}</td>
                <td><span class="status-pill" :style="statusStyle(order.status.color)">{{ order.status.name }}</span></td>
                <td class="text-end">{{ formatNumber(order.units) }}</td>
                <td class="text-end fw-bold">{{ formatMoney(order.revenue) }}</td>
                <td class="text-end">{{ order.has_missing_cost ? 'неповна · ' : '' }}{{ formatMoney(order.cogs) }}</td>
                <td class="text-end"><strong :class="numberClass(order.profit)">{{ formatMoney(order.profit) }}</strong><small>{{ formatPercent(order.margin) }}</small></td>
              </tr>
              <tr v-if="!audit.data?.length"><td colspan="10"><div class="empty-table">Замовлень за вибраними умовами немає</div></td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="audit.last_page > 1" class="pagination-row">
          <span>Показано {{ audit.from }}–{{ audit.to }} із {{ audit.total }}</span>
          <div>
            <button type="button" :disabled="audit.current_page <= 1 || loading" @click="changePage(audit.current_page - 1)"><i class="bi bi-chevron-left"></i></button>
            <button v-for="page in visiblePages" :key="page" type="button" :class="{ active: page === audit.current_page }" :disabled="loading" @click="changePage(page)">{{ page }}</button>
            <button type="button" :disabled="audit.current_page >= audit.last_page || loading" @click="changePage(audit.current_page + 1)"><i class="bi bi-chevron-right"></i></button>
          </div>
        </div>
      </section>

      <footer class="formula-note">
        <i class="bi bi-calculator"></i>
        <span><strong>Формула:</strong> валовий прибуток = виторг позицій із відомою собівартістю − їхня собівартість. Маржа = валовий прибуток / покритий виторг × 100%.</span>
      </footer>
    </template>

    <div v-if="loading && !hasLoaded" class="loading-grid">
      <div v-for="index in 12" :key="index" class="skeleton"></div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import ApexChart from 'vue3-apexcharts';
import { fetchSalesAnalytics } from '@/crm/services/salesAnalyticsApi';

const today = new Date();
const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
const toDateInput = (date) => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
};

const filters = reactive({
  date_from: toDateInput(firstDay), date_to: toDateInput(today), scope: 'valid',
  sale_type: '', source_id: '', manager_id: '', status_id: '', payment_status: '',
  currency: 'UAH', page: 1, per_page: 20,
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
let requestSequence = 0;

const meta = ref({});
const kpis = ref({});
const trend = ref({ labels: [], revenue: [], cogs: [], profit: [], orders: [] });
const saleTypes = ref([]);
const sources = ref([]);
const topProducts = ref([]);
const managers = ref([]);
const statuses = ref([]);
const insights = ref([]);
const audit = ref({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filterOptions = reactive({ sources: [], managers: [], statuses: [], currencies: ['UAH'], sale_types: [], scopes: [], payment_statuses: [] });

const params = () => Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value !== null));

async function load(page = 1, fresh = false) {
  const sequence = ++requestSequence;
  filters.page = page;
  loading.value = true;
  error.value = '';
  try {
    const requestParams = params();
    if (fresh) requestParams.fresh = 1;
    const { data } = await fetchSalesAnalytics(requestParams);
    if (sequence !== requestSequence) return;
    meta.value = data.meta || {};
    kpis.value = data.kpis || {};
    trend.value = data.trend || trend.value;
    saleTypes.value = data.sale_types || [];
    sources.value = data.sources || [];
    topProducts.value = data.top_products || [];
    managers.value = data.managers || [];
    statuses.value = data.statuses || [];
    insights.value = data.insights || [];
    audit.value = data.audit || audit.value;
    Object.assign(filterOptions, data.filters || {});
    hasLoaded.value = true;
    syncUrl();
  } catch (e) {
    if (sequence !== requestSequence) return;
    error.value = e.response?.data?.message || 'Перевірте з’єднання та спробуйте ще раз.';
  } finally {
    if (sequence === requestSequence) loading.value = false;
  }
}

function applyFilters() { load(1); }
function changePage(page) { load(page); document.querySelector('.audit-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
function filterByStatus(id) { filters.status_id = id ? String(id) : ''; filters.scope = 'all'; load(1); }

function applyPreset(key) {
  const end = new Date();
  let start = new Date(end);
  if (key === 'today') start = new Date(end);
  if (key === '7days') start.setDate(end.getDate() - 6);
  if (key === 'month') start = new Date(end.getFullYear(), end.getMonth(), 1);
  if (key === 'previous_month') {
    start = new Date(end.getFullYear(), end.getMonth() - 1, 1);
    end.setDate(0);
  }
  if (key === '90days') start.setDate(end.getDate() - 89);
  filters.date_from = toDateInput(start);
  filters.date_to = toDateInput(end);
  activePreset.value = key;
  load(1);
}

function resetFilters() {
  Object.assign(filters, { scope: 'valid', sale_type: '', source_id: '', manager_id: '', status_id: '', payment_status: '', currency: 'UAH', page: 1, per_page: 20 });
  applyPreset('month');
}

function syncUrl() {
  const query = new URLSearchParams(params());
  window.history.replaceState({}, '', `${window.location.pathname}?${query.toString()}`);
}

const exportUrl = computed(() => `/analytics/export?${new URLSearchParams(params()).toString()}`);
const costCoverageWarning = computed(() => kpis.value.cost_coverage?.value !== null && Number(kpis.value.cost_coverage?.value) < 99.9);
const scopeNote = computed(() => ({
  valid: 'без скасованих і повернень',
  completed: 'лише завершені або оплачені',
  all: 'усі замовлення',
}[filters.scope] || 'за вибраним режимом'));

const primaryKpis = computed(() => [
  { key: 'revenue', label: 'Виторг', value: formatMoney(kpis.value.revenue?.value), delta: kpis.value.revenue?.delta ?? null, note: scopeNote.value, icon: 'bi bi-cash-stack', tone: 'indigo' },
  { key: 'profit', label: 'Валовий прибуток', value: formatMoney(kpis.value.gross_profit?.value), delta: kpis.value.gross_profit?.delta ?? null, note: costCoverageWarning.value ? 'за позиціями з відомою закупкою' : 'виторг мінус собівартість', icon: 'bi bi-graph-up-arrow', tone: 'green' },
  { key: 'margin', label: 'Маржинальність', value: formatPercent(kpis.value.gross_margin?.value), delta: kpis.value.gross_margin?.delta_pp ?? null, deltaSuffix: ' в.п.', note: kpis.value.gross_margin?.delta_pp == null ? 'частка прибутку у виторгу' : `${signed(kpis.value.gross_margin.delta_pp)} в.п. до попереднього`, icon: 'bi bi-percent', tone: 'violet' },
  { key: 'orders', label: 'Замовлень', value: formatNumber(kpis.value.orders?.value), delta: kpis.value.orders?.delta ?? null, note: `середній чек ${formatMoney(kpis.value.average_check?.value)}`, icon: 'bi bi-bag-check-fill', tone: 'amber' },
]);

const secondaryKpis = computed(() => [
  { label: 'Отримано / оплачено', value: formatMoney(kpis.value.paid_revenue?.value), note: 'за статусом оплати', icon: 'bi bi-check2-circle', tone: 'green' },
  { label: 'Собівартість', value: formatMoney(kpis.value.cogs?.value), note: `покриття ${formatPercent(kpis.value.cost_coverage?.value)}`, icon: 'bi bi-box-seam', tone: 'blue' },
  { label: 'Продано одиниць', value: formatNumber(kpis.value.units?.value), note: 'товарних позицій', icon: 'bi bi-boxes', tone: 'violet' },
  { label: 'Повернення', value: formatPercent(kpis.value.returns?.rate), note: `${kpis.value.returns?.count || 0} зам. · ${formatMoney(kpis.value.returns?.revenue)}`, icon: 'bi bi-arrow-return-left', tone: 'red' },
  { label: 'Скасування', value: formatPercent(kpis.value.cancellations?.rate), note: `${kpis.value.cancellations?.count || 0} зам. · ${formatMoney(kpis.value.cancellations?.revenue)}`, icon: 'bi bi-x-octagon', tone: 'slate' },
]);

const trendSeries = computed(() => [
  { name: 'Виторг', data: trend.value.revenue || [] },
  { name: 'Валовий прибуток', data: trend.value.profit || [] },
  { name: 'Собівартість', data: trend.value.cogs || [] },
]);
const trendOptions = computed(() => ({
  chart: { type: 'area', fontFamily: 'inherit', toolbar: { show: false }, zoom: { enabled: false }, animations: { speed: 450 } },
  colors: ['#4f46e5', '#16a34a', '#f59e0b'], stroke: { curve: 'smooth', width: [3, 3, 2] }, dataLabels: { enabled: false }, legend: { show: false },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.28, opacityTo: 0.01, stops: [0, 92, 100] } },
  grid: { borderColor: '#eef2f7', strokeDashArray: 4, padding: { left: 8, right: 12 } },
  xaxis: { categories: trend.value.labels || [], axisBorder: { show: false }, axisTicks: { show: false }, tickAmount: Math.min(10, trend.value.labels?.length || 0), labels: { style: { colors: '#94a3b8', fontSize: '11px' }, hideOverlappingLabels: true } },
  yaxis: { labels: { formatter: compactMoney, style: { colors: '#94a3b8', fontSize: '11px' } } },
  tooltip: { shared: true, intersect: false, theme: 'light', y: { formatter: (value) => formatMoney(value) } },
}));

const saleTypeSeries = computed(() => saleTypes.value.map((item) => Number(item.revenue || 0)));
const saleTypeOptions = computed(() => ({
  chart: { type: 'donut', fontFamily: 'inherit' }, labels: saleTypes.value.map((item) => item.label), colors: saleTypes.value.map((item) => item.key === 'wholesale' ? '#0f766e' : '#7c3aed'),
  stroke: { width: 4, colors: ['#fff'] }, dataLabels: { enabled: false }, legend: { show: false },
  plotOptions: { pie: { donut: { size: '72%', labels: { show: true, name: { show: true, color: '#64748b' }, value: { show: true, fontSize: '20px', fontWeight: 800, formatter: compactMoney }, total: { show: true, label: 'Разом', color: '#64748b', formatter: () => compactMoney(saleTypeSeries.value.reduce((sum, value) => sum + value, 0)) } } } } },
  tooltip: { y: { formatter: (value) => formatMoney(value) } },
}));

const visiblePages = computed(() => {
  const current = Number(audit.value.current_page || 1); const last = Number(audit.value.last_page || 1);
  const start = Math.max(1, Math.min(current - 2, last - 4)); const end = Math.min(last, start + 4);
  return Array.from({ length: Math.max(0, end - start + 1) }, (_, index) => start + index);
});

const currencyLocale = computed(() => filters.currency === 'PLN' ? 'pl-PL' : 'uk-UA');
function formatMoney(value) { return new Intl.NumberFormat(currencyLocale.value, { style: 'currency', currency: filters.currency || 'UAH', maximumFractionDigits: 0 }).format(Number(value || 0)); }
function compactMoney(value) { return new Intl.NumberFormat('uk-UA', { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value || 0)); }
function formatNumber(value) { return new Intl.NumberFormat('uk-UA').format(Number(value || 0)); }
function formatPercent(value) { return value === null || value === undefined ? '—' : `${new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 1 }).format(Number(value))}%`; }
function signed(value) { const number = Number(value || 0); return `${number > 0 ? '+' : ''}${number.toLocaleString('uk-UA')}`; }
function formatPeriod(from, to) { if (!from || !to) return '—'; const options = { day: 'numeric', month: 'short', year: 'numeric' }; return `${new Date(`${from}T00:00:00`).toLocaleDateString('uk-UA', options)} — ${new Date(`${to}T00:00:00`).toLocaleDateString('uk-UA', options)}`; }
function formatDateTime(value) { return value ? new Date(value).toLocaleString('uk-UA', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'; }
function initials(name) { return String(name || '?').split(/\s+/).slice(0, 2).map((part) => part[0]?.toUpperCase()).join(''); }
function numberClass(value) { return Number(value || 0) < 0 ? 'number-negative' : 'number-positive'; }
function marginClass(value) { if (value == null) return 'unknown'; if (value < 0) return 'bad'; if (value < 25) return 'low'; return 'good'; }
function paymentLabel(value) { return { unpaid: 'Не оплачено', prepayment: 'Передоплата', paid: 'Оплачено', refund: 'Повернення коштів' }[value] || value || '—'; }
function statusStyle(color) { const value = /^#[0-9a-f]{6}$/i.test(color || '') ? color : '#64748b'; return { color: value, backgroundColor: `${value}16`, borderColor: `${value}35` }; }

const sourceMap = { facebook: ['bi-facebook', '#1877f2'], fb: ['bi-facebook', '#1877f2'], instagram: ['bi-instagram', '#e1306c'], ig: ['bi-instagram', '#e1306c'], google: ['bi-google', '#ea4335'], tiktok: ['bi-tiktok', '#111827'], site: ['bi-globe2', '#4f46e5'], website: ['bi-globe2', '#4f46e5'], phone: ['bi-telephone-fill', '#0284c7'], prom: ['bi-shop', '#7c3aed'], rozetka: ['bi-bag-fill', '#16a34a'] };
function sourceMeta(code) { const normalized = String(code || '').toLowerCase(); return sourceMap[normalized] || ['bi-diagram-3-fill', '#64748b']; }
function sourceIcon(code) { return `bi ${sourceMeta(code)[0]}`; }
function sourceIconStyle(source) { const candidate = source.color || sourceMeta(source.code)[1]; const color = /^#[0-9a-f]{6}$/i.test(candidate) ? candidate : sourceMeta(source.code)[1]; return { color, backgroundColor: `${color}18` }; }

function hydrateFromUrl() {
  const query = new URLSearchParams(window.location.search);
  Object.keys(filters).forEach((key) => { if (query.has(key)) filters[key] = key === 'page' || key === 'per_page' ? Number(query.get(key)) : query.get(key); });
  if (query.has('date_from') || query.has('date_to')) activePreset.value = 'custom';
}

onMounted(() => { hydrateFromUrl(); load(filters.page); });
</script>

<style scoped>
.analytics-page { --ink:#0f172a; --muted:#64748b; --line:#e7ecf3; --canvas:#f5f7fb; color:var(--ink); max-width:1800px; margin:0 auto; }
.analytics-header { display:flex; justify-content:space-between; align-items:flex-end; gap:24px; margin-bottom:22px; }
.eyebrow { display:flex; align-items:center; gap:7px; color:#4f46e5; font-size:.72rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase; margin-bottom:8px; }
.analytics-header h1 { font-size:clamp(1.65rem,2.5vw,2.25rem); line-height:1.08; letter-spacing:-.04em; font-weight:850; margin:0 0 7px; }
.analytics-header p { color:var(--muted); margin:0; font-size:.92rem; }
.header-actions { display:flex; gap:10px; }
.btn-export,.btn-refresh,.btn-apply,.btn-reset { border:0; display:inline-flex; align-items:center; justify-content:center; gap:8px; border-radius:11px; font-weight:700; font-size:.86rem; text-decoration:none; transition:.18s ease; }
.btn-export { color:#fff; background:#0f172a; padding:11px 15px; box-shadow:0 6px 14px rgba(15,23,42,.12); }
.btn-export:hover { background:#1e293b; color:#fff; transform:translateY(-1px); }
.btn-refresh { width:42px; height:42px; color:#475569; background:#fff; border:1px solid var(--line); }
.spinning { animation:spin .85s linear infinite; } @keyframes spin{to{transform:rotate(360deg)}}
.filter-shell { background:#fff; border:1px solid var(--line); border-radius:16px; padding:14px; box-shadow:0 3px 16px rgba(15,23,42,.035); margin-bottom:20px; }
.preset-row { display:flex; flex-wrap:wrap; gap:6px; padding-bottom:13px; margin-bottom:13px; border-bottom:1px solid #f1f4f8; }
.preset-btn { border:0; background:#f1f5f9; color:#64748b; padding:7px 12px; border-radius:9px; font-size:.78rem; font-weight:750; }
.preset-btn:hover,.preset-btn.active { background:#eef2ff; color:#4338ca; }
.filter-grid { display:grid; grid-template-columns:repeat(10,minmax(105px,1fr)); gap:11px; align-items:end; }
.filter-field { grid-column:span 1; min-width:0; }
.filter-field>span { display:block; color:#64748b; font-size:.68rem; font-weight:750; text-transform:uppercase; letter-spacing:.045em; margin:0 0 5px 2px; }
.filter-field .form-control,.filter-field .form-select { min-height:40px; border-color:#dfe5ee; border-radius:10px; color:#334155; font-size:.78rem; box-shadow:none; }
.filter-field .form-control:focus,.filter-field .form-select:focus { border-color:#818cf8; box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.currency-field { max-width:110px; }
.filter-actions { display:flex; gap:7px; justify-content:flex-end; }
.btn-reset { background:#f1f5f9; color:#64748b; padding:10px 12px; }
.btn-apply { background:#4f46e5; color:#fff; padding:10px 14px; min-width:116px; box-shadow:0 6px 14px rgba(79,70,229,.17); }
.btn-apply:hover { background:#4338ca; }.btn-apply:disabled{opacity:.65}
.alert-error { display:flex; align-items:center; gap:13px; background:#fff1f2; color:#be123c; border:1px solid #fecdd3; border-radius:14px; padding:14px 16px; margin-bottom:18px; }
.alert-error>i{font-size:1.3rem}.alert-error div{display:flex;flex-direction:column;flex:1}.alert-error span{font-size:.82rem}.alert-error button{border:0;background:#be123c;color:#fff;border-radius:8px;padding:7px 10px;font-weight:700}
.period-caption { display:flex; justify-content:space-between; gap:15px; color:#64748b; font-size:.78rem; margin:0 2px 12px; }.period-caption span{display:flex;align-items:center;gap:7px}
.kpi-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:15px; margin-bottom:15px; }
.kpi-card { --tone:#4f46e5; --soft:#eef2ff; min-height:172px; padding:19px; border:1px solid var(--line); border-radius:16px; background:#fff; box-shadow:0 3px 16px rgba(15,23,42,.035); position:relative; overflow:hidden; }
.kpi-card:after{content:"";position:absolute;right:-22px;bottom:-35px;width:105px;height:105px;border-radius:50%;background:var(--soft);opacity:.7}.tone-green{--tone:#16a34a;--soft:#dcfce7}.tone-violet{--tone:#7c3aed;--soft:#ede9fe}.tone-amber{--tone:#d97706;--soft:#fef3c7}
.kpi-top { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }.kpi-icon{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;background:var(--soft);color:var(--tone);font-size:1rem}
.delta { display:inline-flex;align-items:center;gap:3px;border-radius:999px;padding:4px 8px;font-size:.72rem;font-weight:800}.delta.positive{color:#15803d;background:#dcfce7}.delta.negative{color:#be123c;background:#ffe4e6}
.kpi-label{font-size:.74rem;color:#64748b;font-weight:750;text-transform:uppercase;letter-spacing:.035em}.kpi-value{font-size:clamp(1.55rem,2.3vw,2.15rem);font-weight:850;letter-spacing:-.04em;margin:4px 0;position:relative;z-index:1}.kpi-note{color:#94a3b8;font-size:.72rem;position:relative;z-index:1}
.mini-kpi-grid { display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:11px;margin-bottom:15px}.mini-kpi{display:flex;align-items:center;gap:11px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:13px 14px;min-width:0}.mini-icon{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;flex:0 0 auto}.mini-icon.green{background:#dcfce7;color:#15803d}.mini-icon.blue{background:#e0f2fe;color:#0369a1}.mini-icon.violet{background:#ede9fe;color:#7c3aed}.mini-icon.red{background:#ffe4e6;color:#be123c}.mini-icon.slate{background:#f1f5f9;color:#475569}.mini-kpi div{display:flex;flex-direction:column;min-width:0}.mini-kpi span:not(.mini-icon){font-size:.67rem;color:#64748b;font-weight:700}.mini-kpi strong{font-size:.99rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mini-kpi small{font-size:.66rem;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.quality-warning{display:flex;align-items:center;gap:13px;background:#fffbeb;border:1px solid #fde68a;border-radius:14px;padding:13px 15px;margin-bottom:15px}.quality-icon{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:#fef3c7;color:#d97706;flex:0 0 auto}.quality-warning div{flex:1}.quality-warning strong{font-size:.82rem}.quality-warning p{font-size:.73rem;color:#78716c;margin:2px 0 0}.quality-warning a{font-size:.75rem;color:#92400e;font-weight:800;text-decoration:none;white-space:nowrap}
.insights-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin-bottom:15px}.insight-card{display:flex;gap:10px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:13px}.insight-card>span{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;flex:0 0 auto;background:#f1f5f9;color:#475569}.insight-card strong{display:block;font-size:.76rem}.insight-card p{color:#64748b;font-size:.69rem;line-height:1.4;margin:2px 0 0}.insight-positive>span{background:#dcfce7;color:#15803d}.insight-warning>span{background:#fef3c7;color:#d97706}.insight-info>span{background:#eef2ff;color:#4f46e5}
.content-grid{display:grid;gap:15px;margin-bottom:15px}.chart-grid{grid-template-columns:minmax(0,2fr) minmax(310px,.8fr)}.performance-grid{grid-template-columns:minmax(340px,.85fr) minmax(0,1.35fr)}.team-grid{grid-template-columns:1fr 1fr}.data-card{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 3px 16px rgba(15,23,42,.03);overflow:hidden}.card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:15px;padding:18px 19px 10px}.card-head h2{font-size:.93rem;font-weight:800;margin:0 0 3px}.card-head p{font-size:.71rem;color:#94a3b8;margin:0}.card-head>a{font-size:.73rem;font-weight:750;color:#4f46e5;text-decoration:none}.chart-legend{display:flex;gap:12px;flex-wrap:wrap;color:#64748b;font-size:.68rem}.chart-legend span{display:flex;align-items:center;gap:5px}.legend-dot{width:7px;height:7px;border-radius:50%;display:block}.legend-dot.revenue{background:#4f46e5}.legend-dot.profit{background:#16a34a}.legend-dot.cost{background:#f59e0b}
.split-body{padding:0 15px 15px}.split-list{border-top:1px solid #f1f5f9;padding-top:8px}.split-row{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:8px;padding:8px 2px}.split-dot{width:8px;height:8px;border-radius:50%;background:#7c3aed}.split-dot.wholesale{background:#0f766e}.split-row div{display:flex;flex-direction:column}.split-row strong,.split-row>span:last-child{font-size:.74rem}.split-row small{font-size:.65rem;color:#94a3b8}
.source-list{padding:2px 18px 16px}.source-row{display:grid;grid-template-columns:auto minmax(100px,1fr) auto auto;align-items:center;gap:11px;padding:12px 0;border-bottom:1px solid #f1f5f9}.source-row:last-child{border-bottom:0}.source-icon{width:35px;height:35px;border-radius:10px;display:grid;place-items:center}.source-title{display:flex;align-items:center;gap:7px;margin-bottom:6px}.source-title strong{font-size:.76rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.source-title code,.source-audit code{font-size:.61rem;background:#f1f5f9;color:#475569;border-radius:5px;padding:2px 5px}.progress-track{height:5px;background:#eef2f7;border-radius:99px;overflow:hidden}.progress-track span{display:block;height:100%;border-radius:inherit}.source-stat,.source-profit{display:flex;flex-direction:column;text-align:right}.source-stat span,.source-profit span,.source-profit small{font-size:.64rem;color:#94a3b8}.source-stat strong,.source-profit strong{font-size:.73rem}.number-positive{color:#15803d!important}.number-negative{color:#be123c!important}
.analytics-table{width:100%;border-collapse:separate;border-spacing:0}.analytics-table th{background:#f8fafc;color:#64748b;font-size:.65rem;text-transform:uppercase;letter-spacing:.035em;font-weight:800;padding:10px 12px;border-top:1px solid #f1f5f9;border-bottom:1px solid #e9eef5;white-space:nowrap}.analytics-table td{font-size:.73rem;color:#334155;padding:11px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}.analytics-table tbody tr:last-child td{border-bottom:0}.analytics-table tbody tr:hover{background:#fbfcfe}.product-cell{display:flex;align-items:center;gap:9px;min-width:180px}.product-cell>span{width:25px;height:25px;border-radius:8px;background:#eef2ff;color:#4f46e5;display:grid;place-items:center;font-size:.67rem;font-weight:850}.product-cell div{display:flex;flex-direction:column;min-width:0}.product-cell strong{max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.73rem}.product-cell small{color:#94a3b8;font-size:.64rem}.margin-pill{display:inline-flex;border-radius:999px;padding:3px 7px;font-size:.64rem;font-weight:800}.margin-pill.good{background:#dcfce7;color:#15803d}.margin-pill.low{background:#fef3c7;color:#b45309}.margin-pill.bad{background:#ffe4e6;color:#be123c}.margin-pill.unknown{background:#f1f5f9;color:#64748b}
.manager-list,.status-cloud{padding:2px 18px 16px}.manager-row{display:grid;grid-template-columns:auto minmax(100px,1fr) auto auto;gap:10px;align-items:center;padding:11px 0;border-bottom:1px solid #f1f5f9}.manager-row:last-child{border-bottom:0}.manager-avatar{width:34px;height:34px;display:grid;place-items:center;border-radius:10px;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;font-size:.67rem;font-weight:850}.manager-row>div{display:flex;flex-direction:column}.manager-row strong{font-size:.73rem}.manager-row small{font-size:.64rem;color:#94a3b8}.manager-row>div:nth-child(3){text-align:right}.status-cloud{display:grid;grid-template-columns:1fr 1fr;gap:8px}.status-item{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:8px;background:#fafbfc;border:1px solid #eef2f7;border-radius:11px;padding:10px;text-align:left}.status-item:hover{border-color:#c7d2fe;background:#f8faff}.status-mark{width:8px;height:32px;border-radius:99px}.status-item>span:nth-child(2){display:flex;flex-direction:column}.status-item strong,.status-item b{font-size:.71rem}.status-item small{font-size:.62rem;color:#94a3b8}
.audit-card{margin-bottom:15px}.audit-head{align-items:center}.page-size{display:flex;align-items:center;gap:7px;font-size:.68rem;color:#64748b}.page-size select{width:73px}.audit-scroll{max-height:650px}.audit-table{min-width:1320px}.audit-table thead{position:sticky;top:0;z-index:2}.audit-table td>small,.audit-table td>strong+small,.order-link+small{display:block;font-size:.62rem;color:#94a3b8;margin-top:2px}.order-link{color:#4338ca;font-weight:850;text-decoration:none}.sale-type-pill,.status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:.64rem;font-weight:800;white-space:nowrap}.sale-type-pill.retail{color:#6d28d9;background:#f3e8ff}.sale-type-pill.wholesale{color:#0f766e;background:#ccfbf1}.status-pill{border:1px solid}.source-audit{display:flex;flex-direction:column;align-items:flex-start;gap:3px}.source-audit span{font-size:.66rem;color:#64748b}.pagination-row{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-top:1px solid #eef2f7;color:#64748b;font-size:.69rem}.pagination-row>div{display:flex;gap:5px}.pagination-row button{min-width:31px;height:31px;border:1px solid #e2e8f0;background:#fff;color:#64748b;border-radius:8px;font-size:.7rem;font-weight:750}.pagination-row button:hover:not(:disabled),.pagination-row button.active{color:#fff;background:#4f46e5;border-color:#4f46e5}.pagination-row button:disabled{opacity:.4}.formula-note{display:flex;gap:9px;align-items:flex-start;color:#64748b;font-size:.7rem;padding:4px 3px 15px}.formula-note i{color:#4f46e5}.empty-state{min-height:230px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:#94a3b8;font-size:.75rem}.empty-state i{font-size:1.5rem}.empty-state.compact{min-height:90px}.empty-table{text-align:center;color:#94a3b8;padding:25px}.loading-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:15px}.skeleton{height:170px;border-radius:16px;background:linear-gradient(90deg,#eef2f7 25%,#f8fafc 50%,#eef2f7 75%);background-size:200% 100%;animation:shimmer 1.4s infinite}@keyframes shimmer{to{background-position:-200% 0}}
@media(max-width:1399.98px){.filter-grid{grid-template-columns:repeat(5,1fr)}.filter-actions{grid-column:span 2}.mini-kpi-grid{grid-template-columns:repeat(3,1fr)}.insights-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:1199.98px){.kpi-grid{grid-template-columns:repeat(2,1fr)}.chart-grid,.performance-grid{grid-template-columns:1fr}.source-row{grid-template-columns:auto 1fr auto auto}}
@media(max-width:991.98px){.filter-grid{grid-template-columns:repeat(3,1fr)}.team-grid{grid-template-columns:1fr}.mini-kpi-grid{grid-template-columns:repeat(2,1fr)}.currency-field{max-width:none}.filter-actions{grid-column:span 2}}
@media(max-width:767.98px){.analytics-header{align-items:flex-start;flex-direction:column}.header-actions{width:100%}.btn-export{flex:1}.filter-grid{grid-template-columns:repeat(2,1fr)}.filter-actions{grid-column:span 2}.kpi-grid,.mini-kpi-grid,.insights-grid{grid-template-columns:1fr}.period-caption{flex-direction:column;gap:4px}.chart-legend{display:none}.quality-warning{align-items:flex-start;flex-wrap:wrap}.quality-warning a{margin-left:49px}.source-row{grid-template-columns:auto 1fr auto}.source-profit{grid-column:2/4;display:grid;grid-template-columns:1fr auto auto;gap:8px}.status-cloud{grid-template-columns:1fr}.pagination-row{align-items:flex-start;gap:10px;flex-direction:column}.pagination-row>div{width:100%;justify-content:flex-end}.loading-grid{grid-template-columns:1fr 1fr}}
@media(max-width:479.98px){.filter-grid{grid-template-columns:1fr}.filter-actions{grid-column:span 1}.currency-field{max-width:none}.kpi-card{min-height:155px}.source-row{grid-template-columns:auto 1fr}.source-stat{grid-column:2}.source-profit{grid-column:2}.manager-row{grid-template-columns:auto 1fr auto}.manager-row>div:nth-child(3){grid-column:2;text-align:left}.manager-row>.margin-pill{grid-column:3;grid-row:1/3}.loading-grid{grid-template-columns:1fr}}
</style>
