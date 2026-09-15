<template>
  <section class="fiscal-overview" aria-label="Фіскальні продажі">
    <div class="section-heading">
      <div><span class="verified"><i class="bi bi-patch-check-fill"></i> За чеками Checkbox</span><h2>Фактична виручка</h2></div>
      <span>За датою фіскалізації · час Києва · UAH</span>
    </div>

    <div class="fiscal-kpis">
      <article v-for="card in cards" :key="card.key" class="fiscal-kpi">
        <i :class="card.icon"></i>
        <span>{{ card.label }}</span>
        <strong>{{ card.value }}</strong>
        <p><span v-if="card.delta != null" :class="card.delta >= 0 ? 'positive' : 'negative'">{{ card.delta >= 0 ? '↑' : '↓' }} {{ Math.abs(card.delta) }}%</span> {{ card.delta == null ? 'Немає бази для порівняння' : 'до попереднього періоду' }}</p>
        <small>{{ card.note }}</small>
      </article>
    </div>

    <div class="reconciliation">
      <div><span>Продажі за чеками</span><strong>{{ money(fiscal.totals?.sales) }}</strong></div>
      <span class="operator">−</span>
      <div><span>Повернення коштів · {{ fiscal.totals?.refund_receipts || 0 }} чеків</span><strong class="negative">{{ money(fiscal.totals?.refunds) }}</strong></div>
      <span class="operator">=</span>
      <div><span>Виручка після повернень</span><strong>{{ money(fiscal.totals?.revenue) }}</strong></div>
    </div>

    <div class="fiscal-note">
      <i class="bi bi-info-circle"></i>
      <div>
        <strong>Замовлення без успішного фіскального чека сюди не потрапляють.</strong>
        <p>Продаж додається в день чека, повернення коштів віднімається в день чека повернення — навіть якщо замовлення з іншого місяця. Скасування чи повернення посилки саме по собі не є поверненням грошей.</p>
        <p>Чек передоплати враховує лише сплачену суму, а не все замовлення. Фіскальний чек підтверджує оплату, але не завжди отримання посилки. Виручка — не прибуток: собівартість, доставка й комісії тут не віднімаються.</p>
        <p>Джерело: успішні нетестові чеки за відповідями Checkbox, збережені в CRM, зокрема прийняті зі статусом CREATED. Остаточний статус ДПС тут не перевіряється; для фінансової звітності використовуйте звіти Checkbox. Чеки поза CRM можуть бути відсутні.</p>
      </div>
    </div>
    <p v-if="currency !== 'UAH'" class="data-warning">Checkbox обліковується у гривнях. Виберіть UAH, щоб побачити фіскальні продажі.</p>
    <p v-if="fiscal.quality?.fallback_date_receipts" class="data-warning">Для {{ fiscal.quality.fallback_date_receipts }} чеків немає дати у відповіді Checkbox: використано дату створення запису чека в CRM.</p>
    <p v-if="fiscal.quality?.unknown_payment_receipts" class="data-warning">Для {{ fiscal.quality.unknown_payment_receipts }} чеків спосіб оплати неповний або не зіставлений. Ці чеки враховано в сумах продажів і повернень; невідомий спосіб оплати не змінює виручку.</p>

    <article class="fiscal-chart" aria-label="Продажі, повернення та виручка за днями">
      <div class="chart-heading"><h3>Продажі та повернення</h3><span>Стовпчики — продажі й повернення; лінія — виручка після повернень</span></div>
      <ApexChart type="line" height="370" :options="revenueOptions" :series="revenueSeries" />
      <p v-if="!fiscal.totals?.receipts && !fiscal.totals?.refund_receipts" class="empty-note">За вибраний період і фільтри фіскальних чеків немає.</p>
    </article>
    <div class="small-charts">
      <article class="fiscal-chart">
        <div class="chart-heading"><h3>Чеки продажу</h3><span>Без чеків повернення</span></div>
        <ApexChart type="bar" height="240" :options="receiptOptions" :series="[{ name: 'Чеки продажу', data: fiscal.trend?.receipts || [] }]" />
      </article>
      <article class="fiscal-chart">
        <div class="chart-heading"><h3>Середній чек</h3><span>Сума продажів / кількість чеків продажу</span></div>
        <ApexChart type="line" height="240" :options="averageOptions" :series="[{ name: 'Середній чек', data: fiscal.trend?.average_check || [] }]" />
      </article>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import ApexChart from 'vue3-apexcharts';

const props = defineProps({ fiscal: { type: Object, required: true }, currency: { type: String, default: 'UAH' } });
const money = (value) => new Intl.NumberFormat('uk-UA', { style: 'currency', currency: 'UAH', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0));
const integer = (value) => new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 0 }).format(Number(value || 0));
const cards = computed(() => [
  { key: 'revenue', label: 'Виручка після повернень', value: money(props.fiscal.kpis?.revenue?.value), delta: props.fiscal.kpis?.revenue?.delta, icon: 'bi bi-cash-stack', note: 'Успішні продажі мінус фіскальні повернення' },
  { key: 'receipts', label: 'Чеки продажу', value: integer(props.fiscal.kpis?.receipts?.value), delta: props.fiscal.kpis?.receipts?.delta, icon: 'bi bi-receipt', note: 'Окремі чеки, не кількість замовлень' },
  { key: 'average_check', label: 'Середній чек', value: money(props.fiscal.kpis?.average_check?.value), delta: props.fiscal.kpis?.average_check?.delta, icon: 'bi bi-percent', note: 'Середня сума одного чека продажу' },
]);
const revenueSeries = computed(() => {
  const trend = props.fiscal.trend || {};
  return [
    { name: 'Продажі', type: 'column', data: trend.sales || [] },
    // Знак змінюємо лише для відображення нижче нуля; майбутні дні залишаються null.
    { name: 'Повернення', type: 'column', data: (trend.refunds || []).map(value => value === null ? null : value === 0 ? 0 : -value) },
    { name: 'Виручка після повернень', type: 'line', data: trend.revenue || [] },
  ];
});
function options(colors, count = false, legend = false, type = 'line') {
  return {
    chart: { type, fontFamily: 'inherit', toolbar: { show: false }, zoom: { enabled: false }, animations: { enabled: false }, stacked: false },
    colors, dataLabels: { enabled: false },
    stroke: { curve: 'straight', width: type === 'bar' ? 0 : 2.8 },
    fill: { type: 'solid', opacity: 1 },
    markers: { size: 0, hover: { sizeOffset: 4 } },
    plotOptions: { bar: { columnWidth: '58%', borderRadius: 3, borderRadiusApplication: 'end' } },
    grid: { borderColor: '#e6edf0', strokeDashArray: 4, padding: { left: 14, right: 18 } },
    legend: { show: legend, position: 'top', horizontalAlign: 'right', fontSize: '12px', markers: { size: 5 } },
    xaxis: { categories: props.fiscal.trend?.dates || [], tickAmount: Math.min(15, props.fiscal.trend?.dates?.length || 1), axisBorder: { show: false }, axisTicks: { show: false }, labels: { rotate: 0, hideOverlappingLabels: true, formatter: (value) => typeof value === 'string' ? `${value.slice(8, 10)}.${value.slice(5, 7)}` : '', style: { colors: '#9399a3', fontSize: '11px' } } },
    yaxis: { forceNiceScale: true, decimalsInFloat: count ? 0 : 2, title: { text: count ? 'Чеків' : 'грн', style: { color: '#667788', fontWeight: 400 } }, labels: { formatter: count ? integer : (value) => new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 0 }).format(value), style: { colors: '#9399a3' } } },
    tooltip: { shared: true, intersect: false, x: { formatter: (value, context) => props.fiscal.trend?.dates?.[context.dataPointIndex] || value }, y: { formatter: count ? integer : money } },
    noData: { text: 'Немає фіскальних чеків' },
    responsive: [{ breakpoint: 600, options: { xaxis: { tickAmount: Math.min(4, props.fiscal.trend?.dates?.length || 1) }, legend: { position: 'bottom', horizontalAlign: 'center' } } }],
  };
}
// Прямі відрізки не вигадують проміжних сум і не згладжують від’ємні повернення.
const revenueOptions = computed(() => ({
  ...options(['#b7c9d5', '#e44b6c', '#0eaa99'], false, true),
  stroke: { curve: 'straight', width: [0, 0, 2.8] },
  plotOptions: { bar: { columnWidth: '65%', borderRadius: 3, borderRadiusApplication: 'end' } },
  annotations: { yaxis: [{ y: 0, borderColor: '#cbd5e1', strokeDashArray: 0 }] },
}));
const receiptOptions = computed(() => options(['#0eaa99'], true, false, 'bar'));
const averageOptions = computed(() => options(['#0eaa99']));
</script>

<style scoped>
.fiscal-overview{margin-bottom:24px}.section-heading{display:flex;align-items:end;justify-content:space-between;gap:16px;margin:22px 0 16px}.section-heading h2{font-size:1.3rem;font-weight:800;margin:7px 0 0}.section-heading>span{font-size:.75rem;color:#64748b}.verified{color:#0d9488;font-size:.74rem;font-weight:700}.fiscal-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px}.fiscal-kpi{background:#f0f2f3;border-radius:14px;padding:23px;display:flex;flex-direction:column;align-items:flex-start}.fiscal-kpi>i{font-size:1.5rem;margin-bottom:16px}.fiscal-kpi>span{color:#7b8290;font-size:.86rem}.fiscal-kpi>strong{font-size:clamp(1.5rem,2.3vw,2rem);letter-spacing:-.035em;margin:3px 0 12px;line-height:1.2}.fiscal-kpi p{font-size:.75rem;color:#888e98;margin:0 0 5px}.fiscal-kpi p span{font-weight:750;margin-right:5px}.fiscal-kpi small{font-size:.7rem;color:#6b7280}.positive{color:#0d9488}.negative{color:#ed2450}.reconciliation{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:20px 24px;margin:16px 0;border:1px solid #e4e9ed;border-radius:13px;background:#fff}.reconciliation div{display:flex;flex-direction:column;gap:5px}.reconciliation div>span{font-size:.76rem;color:#64748b}.reconciliation strong{font-size:1rem}.operator{color:#94a3b8;font-size:1.4rem}.fiscal-note{display:flex;align-items:flex-start;gap:12px;background:#f0fdfa;border:1px solid #ccfbf1;border-radius:12px;padding:16px 20px;margin-bottom:18px;color:#31534e;font-size:.77rem;line-height:1.55}.fiscal-note>i{color:#0d9488;font-size:1.1rem}.fiscal-note p{margin:5px 0 0}.data-warning{font-size:.77rem;padding:12px 16px;border-radius:10px;background:#fffbeb;color:#92400e}.fiscal-chart{background:#fff;border:1px solid #e7ecf3;border-radius:14px;overflow:hidden;margin-bottom:16px;min-width:0}.chart-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:22px 22px 6px}.chart-heading h3{font-size:1.08rem;font-weight:750;margin:0}.chart-heading span{font-size:.73rem;color:#8b929e}.small-charts{display:grid;grid-template-columns:1fr 1fr;gap:16px}.empty-note{text-align:center;color:#64748b;font-size:.8rem;padding:0 16px 14px}
@media(max-width:991.98px){.fiscal-kpis{gap:12px}.fiscal-kpi{padding:18px}.chart-heading{align-items:flex-start;flex-direction:column}}
@media(max-width:767.98px){.fiscal-kpis,.small-charts{grid-template-columns:1fr}.section-heading{align-items:flex-start;flex-direction:column}.reconciliation{flex-direction:column;align-items:flex-start;gap:8px}.operator{display:none}.fiscal-note{padding:14px}.fiscal-kpi>strong{font-size:1.85rem}}
</style>
