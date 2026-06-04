<template>
  <div class="dash">
    <!-- Header -->
    <div class="dash-header d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
      <div>
        <h3 class="fw-black mb-1 dash-title">Дашборд</h3>
        <p class="text-muted mb-0 small">Динаміка замовлень та виторгу</p>
      </div>
      <div class="d-flex align-items-center gap-2">
        <div class="period-switch">
          <button
            v-for="p in periods"
            :key="p.value"
            type="button"
            class="period-btn"
            :class="{ active: days === p.value }"
            @click="setDays(p.value)"
          >{{ p.label }}</button>
        </div>
        <button type="button" class="btn-refresh" :class="{ spinning: loading }" @click="load" title="Оновити">
          <i class="bi bi-arrow-clockwise"></i>
        </button>
      </div>
    </div>

    <!-- KPI cards -->
    <div class="row g-3 g-xl-4 mb-4">
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card kpi-indigo">
          <div class="kpi-top">
            <span class="kpi-icon"><i class="bi bi-cart-plus-fill"></i></span>
            <span v-if="kpis.created?.delta != null" class="kpi-delta" :class="deltaClass(kpis.created.delta)">
              <i :class="deltaIcon(kpis.created.delta)"></i> {{ Math.abs(kpis.created.delta) }}%
            </span>
          </div>
          <div class="kpi-value">{{ formatNumber(kpis.created?.period) }}</div>
          <div class="kpi-label">Замовлень створено</div>
          <div class="kpi-sub">Сьогодні: <b>{{ formatNumber(kpis.created?.today) }}</b></div>
        </div>
      </div>

      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card kpi-green">
          <div class="kpi-top">
            <span class="kpi-icon"><i class="bi bi-truck"></i></span>
          </div>
          <div class="kpi-value">{{ formatNumber(kpis.shipped?.period) }}</div>
          <div class="kpi-label">Відправлено</div>
          <div class="kpi-sub">Сьогодні: <b>{{ formatNumber(kpis.shipped?.today) }}</b></div>
        </div>
      </div>

      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card kpi-violet">
          <div class="kpi-top">
            <span class="kpi-icon"><i class="bi bi-wallet-fill"></i></span>
            <span v-if="kpis.revenue?.delta != null" class="kpi-delta" :class="deltaClass(kpis.revenue.delta)">
              <i :class="deltaIcon(kpis.revenue.delta)"></i> {{ Math.abs(kpis.revenue.delta) }}%
            </span>
          </div>
          <div class="kpi-value">{{ formatMoney(kpis.revenue?.period) }}</div>
          <div class="kpi-label">Виторг за період</div>
          <div class="kpi-sub">Сьогодні: <b>{{ formatMoney(kpis.revenue?.today) }}</b></div>
        </div>
      </div>

      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card kpi-amber">
          <div class="kpi-top">
            <span class="kpi-icon"><i class="bi bi-receipt"></i></span>
          </div>
          <div class="kpi-value">{{ formatMoney(kpis.avg_check) }}</div>
          <div class="kpi-label">Середній чек</div>
          <div class="kpi-sub">за {{ days }} днів</div>
        </div>
      </div>
    </div>

    <!-- Revenue + Status -->
    <div class="row g-3 g-xl-4 mb-4">
      <div class="col-lg-8">
        <div class="panel h-100">
          <div class="panel-head">
            <div>
              <h5 class="panel-title">Динаміка виторгу</h5>
              <p class="panel-sub">Сума замовлень по днях</p>
            </div>
            <div class="panel-badge">{{ formatMoney(kpis.revenue?.period) }}</div>
          </div>
          <ApexChart type="area" height="320" :options="revenueOptions" :series="revenueSeries" />
        </div>
      </div>
      <div class="col-lg-4">
        <div class="panel h-100">
          <div class="panel-head">
            <div>
              <h5 class="panel-title">Замовлення за статусом</h5>
              <p class="panel-sub">Поточний розподіл</p>
            </div>
          </div>
          <ApexChart v-if="statusSeries.length" type="donut" height="300" :options="statusOptions" :series="statusSeries" />
          <div v-else class="empty-block">Немає даних</div>
        </div>
      </div>
    </div>

    <!-- Orders dynamics + Top products -->
    <div class="row g-3 g-xl-4 mb-4">
      <div class="col-lg-8">
        <div class="panel h-100">
          <div class="panel-head">
            <div>
              <h5 class="panel-title">Динаміка замовлень</h5>
              <p class="panel-sub">Створено та відправлено по днях</p>
            </div>
            <div class="legend">
              <span class="legend-item"><i class="dot dot-indigo"></i> Створено</span>
              <span class="legend-item"><i class="dot dot-green"></i> Відправлено</span>
            </div>
          </div>
          <ApexChart type="bar" height="300" :options="ordersOptions" :series="ordersSeries" />
        </div>
      </div>
      <div class="col-lg-4">
        <div class="panel h-100">
          <div class="panel-head">
            <div>
              <h5 class="panel-title">Топ товарів</h5>
              <p class="panel-sub">За виторгом, {{ days }} днів</p>
            </div>
          </div>
          <div v-if="topProducts.length" class="top-list">
            <div v-for="(p, idx) in topProducts" :key="idx" class="top-item">
              <div class="top-rank">{{ idx + 1 }}</div>
              <div class="top-info">
                <div class="top-name" :title="p.title">{{ p.title }}</div>
                <div class="top-meta">{{ formatNumber(p.qty) }} шт</div>
              </div>
              <div class="top-total">{{ formatMoney(p.total) }}</div>
            </div>
          </div>
          <div v-else class="empty-block">Немає даних</div>
        </div>
      </div>
    </div>

    <!-- Recent orders -->
    <div class="panel">
      <div class="panel-head">
        <div>
          <h5 class="panel-title">Останні замовлення</h5>
          <p class="panel-sub">8 найновіших</p>
        </div>
        <a href="/orders" class="btn-all">Всі замовлення <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="table-responsive">
        <table class="dash-table">
          <thead>
            <tr>
              <th>Замовлення</th>
              <th>Клієнт</th>
              <th>Статус</th>
              <th>Дата</th>
              <th class="text-end">Сума</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="o in recentOrders" :key="o.id">
              <td><span class="ord-num">#{{ o.number }}</span></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <span class="avatar" :style="avatarStyle(o.customer)">{{ initials(o.customer) }}</span>
                  <span class="fw-semibold">{{ o.customer }}</span>
                </div>
              </td>
              <td><span class="st-pill" :style="statusPillStyle(o.status_color)">{{ o.status }}</span></td>
              <td class="text-muted small">{{ formatDate(o.created_at) }}</td>
              <td class="text-end fw-bold">{{ formatMoney(o.total) }}</td>
            </tr>
            <tr v-if="!recentOrders.length">
              <td colspan="5" class="text-center text-muted py-4">Немає замовлень</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import ApexChart from 'vue3-apexcharts';
import { fetchDashboardData } from '@/crm/services/dashboardApi';

const periods = [
  { value: 7, label: '7 днів' },
  { value: 30, label: '30 днів' },
  { value: 90, label: '90 днів' },
];

const days = ref(30);
const loading = ref(false);
const series = ref({ labels: [], created: [], shipped: [], revenue: [] });
const kpis = ref({});
const recentOrders = ref([]);
const topProducts = ref([]);
const statusBreakdown = ref([]);

const palette = ['#6366f1', '#22c55e', '#f59e0b', '#06b6d4', '#a855f7', '#ef4444', '#ec4899', '#14b8a6'];

// --- Formatters ---
const formatNumber = (v) => new Intl.NumberFormat('uk-UA').format(Number(v || 0));
const formatMoney = (v) => {
  const n = Number(v || 0);
  return new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 0 }).format(n) + ' ₴';
};
const formatDate = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleString('uk-UA', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
};

const deltaClass = (d) => (Number(d) >= 0 ? 'up' : 'down');
const deltaIcon = (d) => (Number(d) >= 0 ? 'bi bi-arrow-up-right' : 'bi bi-arrow-down-right');

const initials = (name) => {
  if (!name) return '?';
  return name.trim().split(/\s+/).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('');
};
const avatarStyle = (name) => {
  const idx = (name || '').length % palette.length;
  return { background: palette[idx] };
};
const statusPillStyle = (color) => {
  const c = color || '#6366f1';
  return { color: c, background: hexToSoft(c) };
};
const hexToSoft = (hex) => {
  const h = String(hex || '#6366f1').replace('#', '');
  if (h.length !== 6) return 'rgba(99,102,241,0.12)';
  const r = parseInt(h.slice(0, 2), 16);
  const g = parseInt(h.slice(2, 4), 16);
  const b = parseInt(h.slice(4, 6), 16);
  return `rgba(${r},${g},${b},0.12)`;
};

// --- Charts ---
const revenueSeries = computed(() => [{ name: 'Виторг', data: series.value.revenue }]);
const revenueOptions = computed(() => ({
  chart: { type: 'area', fontFamily: 'inherit', toolbar: { show: false }, zoom: { enabled: false } },
  stroke: { curve: 'smooth', width: 3 },
  fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.03, stops: [0, 90, 100] } },
  colors: ['#6366f1'],
  dataLabels: { enabled: false },
  xaxis: {
    categories: series.value.labels,
    axisBorder: { show: false },
    axisTicks: { show: false },
    labels: { style: { colors: '#9ca3af', fontSize: '11px' }, rotate: 0, hideOverlappingLabels: true },
    tickAmount: Math.min(series.value.labels.length, 10),
  },
  yaxis: { labels: { formatter: (v) => `${Math.round(v).toLocaleString('uk-UA')} ₴`, style: { colors: '#9ca3af', fontSize: '11px' } } },
  grid: { borderColor: '#f1f5f9', strokeDashArray: 4, padding: { left: 10, right: 10 } },
  tooltip: { theme: 'light', y: { formatter: (v) => `${Math.round(v).toLocaleString('uk-UA')} ₴` } },
}));

const ordersSeries = computed(() => [
  { name: 'Створено', data: series.value.created },
  { name: 'Відправлено', data: series.value.shipped },
]);
const ordersOptions = computed(() => ({
  chart: { type: 'bar', fontFamily: 'inherit', toolbar: { show: false }, stacked: false },
  colors: ['#6366f1', '#22c55e'],
  plotOptions: { bar: { columnWidth: '60%', borderRadius: 4, borderRadiusApplication: 'end' } },
  dataLabels: { enabled: false },
  legend: { show: false },
  xaxis: {
    categories: series.value.labels,
    axisBorder: { show: false },
    axisTicks: { show: false },
    labels: { style: { colors: '#9ca3af', fontSize: '11px' }, hideOverlappingLabels: true },
    tickAmount: Math.min(series.value.labels.length, 10),
  },
  yaxis: { labels: { formatter: (v) => Math.round(v), style: { colors: '#9ca3af', fontSize: '11px' } } },
  grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
  tooltip: { theme: 'light' },
}));

const statusSeries = computed(() => statusBreakdown.value.map((s) => s.count));
const statusOptions = computed(() => ({
  chart: { type: 'donut', fontFamily: 'inherit' },
  labels: statusBreakdown.value.map((s) => s.label),
  colors: statusBreakdown.value.map((s, i) => s.color || palette[i % palette.length]),
  stroke: { width: 0 },
  legend: { position: 'bottom', fontSize: '12px', markers: { radius: 12 } },
  dataLabels: { enabled: false },
  plotOptions: { pie: { donut: { size: '68%', labels: { show: true, total: { show: true, label: 'Всього', formatter: (w) => w.globals.seriesTotals.reduce((a, b) => a + b, 0) } } } } },
  tooltip: { theme: 'light' },
}));

// --- Load ---
const load = async () => {
  loading.value = true;
  try {
    const { data } = await fetchDashboardData(days.value);
    series.value = data.series || { labels: [], created: [], shipped: [], revenue: [] };
    kpis.value = data.kpis || {};
    recentOrders.value = data.recent_orders || [];
    topProducts.value = data.top_products || [];
    statusBreakdown.value = data.status_breakdown || [];
  } catch (e) {
    console.error('Не вдалося завантажити дашборд', e);
  } finally {
    loading.value = false;
  }
};

const setDays = (v) => {
  if (days.value === v) return;
  days.value = v;
  load();
};

onMounted(load);
</script>

<style scoped>
.dash { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; }
.dash-title { font-weight: 800; color: #111827; letter-spacing: -0.02em; }

/* Period switch */
.period-switch { display: inline-flex; background: #fff; border: 1px solid #eef0f4; border-radius: 999px; padding: 4px; box-shadow: 0 2px 10px rgba(0,0,0,0.04); }
.period-btn { border: none; background: transparent; color: #6b7280; font-weight: 600; font-size: 0.8rem; padding: 6px 14px; border-radius: 999px; cursor: pointer; transition: all .2s; }
.period-btn.active { background: linear-gradient(135deg,#6366f1,#4f46e5); color: #fff; box-shadow: 0 4px 12px rgba(79,70,229,.35); }
.btn-refresh { width: 38px; height: 38px; border-radius: 12px; border: 1px solid #eef0f4; background: #fff; color: #6b7280; cursor: pointer; transition: all .2s; box-shadow: 0 2px 10px rgba(0,0,0,0.04); }
.btn-refresh:hover { color: #4f46e5; border-color: #c7d2fe; }
.btn-refresh.spinning i { animation: spin 0.8s linear infinite; display: inline-block; }
@keyframes spin { to { transform: rotate(360deg); } }

/* KPI cards */
.kpi-card { background: #fff; border-radius: 20px; padding: 1.3rem 1.4rem; border: 1px solid #f1f2f6; box-shadow: 0 8px 30px -12px rgba(0,0,0,.08); position: relative; overflow: hidden; transition: transform .3s, box-shadow .3s; height: 100%; }
.kpi-card:hover { transform: translateY(-4px); box-shadow: 0 18px 40px -14px rgba(79,70,229,.22); }
.kpi-card::after { content: ''; position: absolute; top: -40px; right: -40px; width: 110px; height: 110px; border-radius: 50%; opacity: .12; }
.kpi-indigo::after { background: #6366f1; }
.kpi-green::after { background: #22c55e; }
.kpi-violet::after { background: #a855f7; }
.kpi-amber::after { background: #f59e0b; }
.kpi-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: .9rem; }
.kpi-icon { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; color: #fff; }
.kpi-indigo .kpi-icon { background: linear-gradient(135deg,#6366f1,#4f46e5); }
.kpi-green .kpi-icon { background: linear-gradient(135deg,#22c55e,#16a34a); }
.kpi-violet .kpi-icon { background: linear-gradient(135deg,#a855f7,#7c3aed); }
.kpi-amber .kpi-icon { background: linear-gradient(135deg,#f59e0b,#d97706); }
.kpi-delta { font-size: .72rem; font-weight: 700; padding: 3px 8px; border-radius: 999px; display: inline-flex; align-items: center; gap: 3px; }
.kpi-delta.up { color: #16a34a; background: #dcfce7; }
.kpi-delta.down { color: #dc2626; background: #fee2e2; }
.kpi-value { font-size: 1.8rem; font-weight: 800; color: #111827; line-height: 1.1; letter-spacing: -0.02em; }
.kpi-label { font-size: .85rem; color: #6b7280; font-weight: 600; margin-top: 2px; }
.kpi-sub { font-size: .75rem; color: #9ca3af; margin-top: .5rem; }
.kpi-sub b { color: #4b5563; }

/* Panels */
.panel { background: #fff; border-radius: 20px; padding: 1.3rem 1.4rem; border: 1px solid #f1f2f6; box-shadow: 0 8px 30px -12px rgba(0,0,0,.07); }
.panel-head { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: .6rem; gap: 1rem; }
.panel-title { font-weight: 700; color: #111827; margin: 0; font-size: 1.05rem; }
.panel-sub { font-size: .78rem; color: #9ca3af; margin: 2px 0 0; }
.panel-badge { background: #eef2ff; color: #4f46e5; font-weight: 700; font-size: .85rem; padding: 6px 12px; border-radius: 999px; white-space: nowrap; }

.legend { display: flex; gap: 14px; }
.legend-item { font-size: .78rem; color: #6b7280; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
.dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
.dot-indigo { background: #6366f1; }
.dot-green { background: #22c55e; }

.empty-block { display: flex; align-items: center; justify-content: center; height: 260px; color: #9ca3af; font-size: .9rem; }

/* Top products */
.top-list { display: flex; flex-direction: column; gap: .5rem; margin-top: .6rem; }
.top-item { display: flex; align-items: center; gap: .8rem; padding: .6rem; border-radius: 12px; transition: background .2s; }
.top-item:hover { background: #f9fafb; }
.top-rank { width: 28px; height: 28px; flex-shrink: 0; border-radius: 8px; background: #eef2ff; color: #4f46e5; font-weight: 700; font-size: .8rem; display: flex; align-items: center; justify-content: center; }
.top-info { flex: 1; min-width: 0; }
.top-name { font-weight: 600; color: #1f2937; font-size: .85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.top-meta { font-size: .72rem; color: #9ca3af; }
.top-total { font-weight: 700; color: #111827; font-size: .85rem; white-space: nowrap; }

/* Table */
.dash-table { width: 100%; border-collapse: collapse; }
.dash-table thead th { font-size: .72rem; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; font-weight: 700; padding: .9rem 1rem; text-align: left; border-bottom: 1px solid #f1f2f6; }
.dash-table tbody td { padding: .85rem 1rem; border-bottom: 1px solid #f6f7f9; color: #1f2937; font-size: .88rem; }
.dash-table tbody tr:last-child td { border-bottom: none; }
.dash-table tbody tr { transition: background .15s; }
.dash-table tbody tr:hover { background: #f9fafb; }
.ord-num { font-weight: 700; color: #4f46e5; }
.avatar { width: 30px; height: 30px; border-radius: 50%; color: #fff; font-size: .72rem; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.st-pill { padding: 4px 11px; border-radius: 999px; font-size: .74rem; font-weight: 700; display: inline-block; }
.btn-all { font-size: .8rem; font-weight: 700; color: #6b7280; text-decoration: none; white-space: nowrap; }
.btn-all:hover { color: #4f46e5; }
</style>
