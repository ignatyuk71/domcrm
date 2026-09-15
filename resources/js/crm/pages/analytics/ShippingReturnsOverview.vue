<template>
  <section class="shipping-returns" aria-label="Повернення посилок">
    <article class="returns-chart">
      <header class="returns-heading">
        <div><span class="returns-eyebrow"><i class="bi bi-box-arrow-in-left" aria-hidden="true"></i> За статусами CRM</span><h2>Повернення посилок</h2></div>
        <span>За днями · посилок</span>
      </header>
      <ApexChart type="area" height="300" :options="chartOptions" :series="series" />
      <p v-if="totals.returned === 0" class="empty-note">За вибраний період і фільтри повернень із відомою датою немає.</p>
    </article>

    <article class="returns-summary" aria-label="Показники та витрати на повернення">
      <header><h3>Підсумок повернень</h3><span class="estimate-badge">Орієнтовні витрати</span></header>
      <dl class="returns-metrics">
        <div><dt>Повернено посилок</dt><dd>{{ integer(totals.returned) }}</dd></div>
        <div><dt>Частка повернень</dt><dd>{{ percentage(totals.return_rate) }}</dd></div>
      </dl>
      <p class="rate-base">Отримано: {{ integer(totals.received) }} · Повернено: {{ integer(totals.returned) }}. Частка серед цих {{ integer(totals.completed) }} посилок.</p>
      <div class="estimated-total"><span>Орієнтовні витрати на повернення</span><strong>≈ {{ money(totals.estimated_cost) }}</strong><small>{{ integer(totals.returned) }} × {{ money(data.estimated_cost_per_return) }} за посилку</small></div>
      <p class="average-cost">Середні витрати на повернення: <strong>{{ totals.average_estimated_cost == null ? '—' : '≈ ' + money(totals.average_estimated_cost) }}</strong></p>
      <div class="returns-explanation">
        <p>Рахуємо поточні статуси «Повернення» та «Успішно завершено», лише з ТТН. Одна ТТН — одна посилка. У дорозі, на відділенні та скасовані не входять.</p>
        <p>Період — за першою фіксацією відповідного результату в історії доставки, а за її відсутності — за датою зміни статусу CRM. Це не дата створення замовлення й не порівняння посилок однієї дати відправлення.</p>
        <p>{{ money(data.estimated_cost_per_return) }} — погоджена оцінка загальних витрат на одну повернену посилку, а не фактичний тариф з API. Ця сума не віднімається від виручки Checkbox.</p>
      </div>
      <p v-if="data.quality?.undated_shipments || data.quality?.missing_tracking_orders" class="returns-warning">
        Не включено у розподіл за періодами: без дати статусу — {{ integer(data.quality?.undated_shipments) }} посилок; без ТТН — {{ integer(data.quality?.missing_tracking_orders) }} замовлень. Це всі такі записи за вибраними бізнес-фільтрами, незалежно від періоду.
      </p>
    </article>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import ApexChart from 'vue3-apexcharts';

const props = defineProps({ data: { type: Object, required: true } });
const totals = computed(() => props.data.totals || {});
const integer = (value) => new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 0 }).format(Number(value || 0));
const money = (value) => new Intl.NumberFormat('uk-UA', { style: 'currency', currency: 'UAH', maximumFractionDigits: 0 }).format(Number(value || 0));
const percentage = (value) => value == null ? '—' : new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 1 }).format(value) + '%';
const series = computed(() => [{ name: 'Повернено посилок', data: props.data.trend?.returned || [] }]);
const chartOptions = computed(() => ({
  chart: { type: 'area', fontFamily: 'inherit', toolbar: { show: false }, zoom: { enabled: false }, animations: { enabled: false } },
  colors: ['#e05c78'],
  stroke: { curve: 'monotoneCubic', width: 2.8 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.28, opacityTo: 0.015, stops: [0, 100] } },
  dataLabels: { enabled: false },
  markers: { size: 0, hover: { sizeOffset: 4 } },
  legend: { show: false },
  grid: { borderColor: '#e6edf0', strokeDashArray: 4, padding: { left: 12, right: 18 } },
  xaxis: {
    categories: props.data.trend?.dates || [],
    tickAmount: Math.min(10, props.data.trend?.dates?.length || 1),
    axisBorder: { show: false }, axisTicks: { show: false },
    labels: { rotate: 0, hideOverlappingLabels: true, formatter: (value) => typeof value === 'string' ? `${value.slice(8, 10)}.${value.slice(5, 7)}` : '', style: { colors: '#9399a3', fontSize: '11px' } },
  },
  yaxis: { min: 0, forceNiceScale: true, decimalsInFloat: 0, title: { text: 'Посилок', style: { color: '#667788', fontWeight: 400 } }, labels: { formatter: integer, style: { colors: '#9399a3' } } },
  tooltip: {
    shared: true, intersect: false,
    x: { formatter: (value, context) => props.data.trend?.dates?.[context.dataPointIndex] || value },
    y: { formatter: (value, context) => value == null ? 'Немає даних' : `${integer(value)} · витрати ≈ ${money(props.data.trend?.estimated_cost?.[context.dataPointIndex])}` },
  },
  noData: { text: 'Немає даних про повернення' },
  responsive: [{ breakpoint: 600, options: { xaxis: { tickAmount: Math.min(4, props.data.trend?.dates?.length || 1) } } }],
}));
</script>

<style scoped>
.shipping-returns{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(320px,1fr);gap:16px;margin:8px 0 24px}
.returns-chart,.returns-summary{min-width:0;background:#fff;border:1px solid #e7ecf3;border-radius:14px;overflow:hidden}
.returns-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:20px 22px 8px}.returns-heading h2{font-size:1.08rem;font-weight:750;margin:6px 0 0}.returns-eyebrow{font-size:.7rem;font-weight:700;color:#be4560}.returns-heading>span{font-size:.73rem;color:#8b929e;white-space:nowrap}.empty-note{font-size:.78rem;text-align:center;color:#64748b;padding:0 20px 16px;margin:0}
.returns-summary{padding:20px}.returns-summary header{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:18px}.returns-summary h3{font-size:.95rem;font-weight:750;margin:0}.estimate-badge{font-size:.65rem;line-height:1.3;background:#fff7e6;color:#94600f;border-radius:6px;padding:5px 7px}
.returns-metrics{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:0}.returns-metrics dt{font-size:.75rem;color:#64748b;font-weight:400}.returns-metrics dd{font-size:1.6rem;font-weight:750;line-height:1.2;letter-spacing:-.025em;margin:5px 0 0}.rate-base{font-size:.7rem;color:#64748b;line-height:1.5;margin:10px 0 14px}
.estimated-total{display:flex;flex-direction:column;gap:5px;border-radius:10px;background:#fff4f6;padding:14px 16px}.estimated-total>span{font-size:.75rem;color:#9d4054}.estimated-total strong{font-size:1.6rem;line-height:1.2;color:#be4560;overflow-wrap:anywhere}.estimated-total small{font-size:.7rem;color:#9d4054}.average-cost{font-size:.72rem;color:#64748b;margin:10px 0 14px}.average-cost strong{font-weight:650;color:#334155}
.returns-explanation{border-top:1px solid #edf0f4;padding-top:10px}.returns-explanation p{font-size:.7rem;color:#64748b;line-height:1.5;margin:0 0 6px}.returns-explanation p:last-child{margin-bottom:0}.returns-warning{font-size:.7rem;line-height:1.5;color:#94600f;background:#fffbeb;border-radius:8px;padding:10px;margin:12px 0 0}
@media(max-width:991.98px){.shipping-returns{grid-template-columns:1fr}.returns-heading{padding:18px 16px 8px}.returns-summary{padding:16px}}
@media(max-width:479.98px){.returns-summary header,.returns-heading{align-items:flex-start;flex-direction:column}}
</style>
