<template>
  <section class="sender-delivery" aria-label="Доставка за наш рахунок">
    <header class="sender-heading"><div><span><i class="bi bi-truck" aria-hidden="true"></i> Нова пошта · платник відправник</span><h2>Доставка за наш рахунок</h2></div><small>Період за датою ТТН</small></header>
    <div class="sender-kpis">
      <div><span>{{ totals.unknown_cost ? 'Відома частина вартості' : 'Вартість відправлених посилок' }}</span><strong>{{ money(totals.known_cost) }}</strong><small>Сума DocumentCost із API Нової пошти</small></div>
      <div><span>Відправлено за наш рахунок</span><strong>{{ totals.sent || 0 }}</strong><small>Із ціною від НП: {{ totals.priced || 0 }} · без суми: {{ totals.unknown_cost || 0 }}</small></div>
      <div><span>Усього накладних відправника</span><strong>{{ totals.shipments || 0 }}</strong><small>Відправлення ще не підтверджене: {{ totals.not_sent || 0 }}</small></div>
    </div>
    <p class="sender-note">Ціна береться з API НП за ТТН — без фіксованої суми. У підсумок входять лише підтверджені відправлення з відомою ціною. Це вартість послуги, не підтвердження списання з банку.</p>
    <p v-if="data.quality?.unverified_payer || data.quality?.fallback_dates || data.quality?.missing_prices" class="sender-warning">Без ціни від НП: {{ data.quality?.missing_prices || 0 }}. Очікують підтвердження платника: {{ data.quality?.unverified_payer || 0 }}. Для {{ data.quality?.fallback_dates || 0 }} ТТН ще немає дати НП — тимчасово використана дата створення запису доставки в CRM.</p>
    <p v-if="totals.returned" class="sender-note">Серед них повернень: {{ totals.returned }}. Вони також можуть входити до окремого блоку повернень — ці два підсумки не слід додавати.</p>
    <div class="sender-table-wrap">
      <table class="sender-table">
        <caption class="visually-hidden">Накладні, за доставку яких платить відправник</caption>
        <thead><tr><th scope="col">Дата ТТН</th><th scope="col">Замовлення</th><th scope="col">ТТН</th><th scope="col">Статус</th><th scope="col">Вартість НП</th><th scope="col">Перевірено</th></tr></thead>
        <tbody>
          <tr v-for="row in rows.data" :key="row.ttn">
            <td>{{ date(row.document_date) }}<small v-if="!row.date_verified">Дата CRM</small></td>
            <td><a :href="row.order_url" target="_blank" rel="noopener noreferrer">№{{ row.order_number }} <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a></td>
            <td class="ttn">{{ row.ttn }}<small v-if="!row.payer_verified">Платник за даними CRM</small></td>
            <td>{{ row.status }}<small v-if="!row.is_sent">Не включено в суму відправлених</small></td>
            <td :class="{ 'missing-cost': row.cost == null }">{{ money(row.cost) }}</td>
            <td>{{ date(row.checked_at, true) }}</td>
          </tr>
          <tr v-if="!rows.data?.length"><td colspan="6" class="sender-empty">За вибраний період і фільтри таких накладних немає.</td></tr>
        </tbody>
      </table>
    </div>
    <nav v-if="rows.last_page > 1" class="sender-pagination" aria-label="Сторінки доставок за наш рахунок">
      <button type="button" :disabled="loading || rows.current_page <= 1" @click="$emit('page', rows.current_page - 1)">Назад</button>
      <span>{{ rows.current_page }} / {{ rows.last_page }} · {{ rows.total }} накладних</span>
      <button type="button" :disabled="loading || rows.current_page >= rows.last_page" @click="$emit('page', rows.current_page + 1)">Далі</button>
    </nav>
  </section>
</template>

<script setup>
import { computed } from 'vue';
const props = defineProps({ data: { type: Object, required: true }, loading: { type: Boolean, default: false } });
defineEmits(['page']);
const totals = computed(() => props.data.totals || {});
const rows = computed(() => props.data.rows || {});
const money = (value) => value == null ? 'Сума не отримана' : new Intl.NumberFormat('uk-UA', { style: 'currency', currency: 'UAH', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
// Серверні дати вже в часовому поясі Києва: не зсуваємо їх браузером.
function date(value, time = false) {
  if (!value) return '—';
  const day = `${value.slice(8, 10)}.${value.slice(5, 7)}.${value.slice(0, 4)}`;
  return time ? `${day} ${value.slice(11, 16)}` : day;
}
</script>

<style scoped>
.sender-delivery{background:#fff;border:1px solid #e7ecf3;border-radius:14px;padding:22px;margin:8px 0 24px;min-width:0}.sender-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}.sender-heading span{font-size:.72rem;font-weight:700;color:#4f46e5}.sender-heading h2{font-size:1.15rem;font-weight:750;margin:6px 0 0}.sender-heading>small{font-size:.73rem;color:#64748b}.sender-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:14px}.sender-kpis>div{display:flex;flex-direction:column;gap:6px;background:#f6f7fb;border-radius:10px;padding:15px 17px;min-width:0}.sender-kpis span{font-size:.75rem;color:#64748b}.sender-kpis strong{font-size:1.5rem;line-height:1.2;overflow-wrap:anywhere}.sender-kpis small{font-size:.7rem;color:#64748b}.sender-note,.sender-warning{font-size:.75rem;line-height:1.5;color:#64748b;margin:10px 0}.sender-warning{padding:10px 13px;background:#fffbeb;color:#94600f;border-radius:8px}.sender-table-wrap{overflow-x:auto;margin-top:16px}.sender-table{width:100%;border-collapse:collapse;text-align:left;font-size:.8rem}.sender-table th{font-size:.72rem;font-weight:650;color:#64748b;background:#f8fafc;white-space:nowrap}.sender-table th,.sender-table td{padding:12px;border-bottom:1px solid #edf0f4;vertical-align:middle}.sender-table td small{display:block;font-size:.67rem;color:#8a93a2;margin-top:3px}.sender-table a{color:#4f46e5;text-decoration:none;white-space:nowrap;font-weight:650}.sender-table a:hover{text-decoration:underline}.sender-table a i{font-size:.65rem}.sender-table .ttn{font-variant-numeric:tabular-nums;white-space:nowrap}.sender-table .missing-cost{color:#94600f;font-size:.75rem}.sender-table .sender-empty{text-align:center;color:#64748b;padding:25px}.sender-pagination{display:flex;align-items:center;justify-content:flex-end;gap:12px;margin-top:16px;font-size:.76rem;color:#64748b}.sender-pagination button{border:1px solid #e2e8f0;background:#fff;border-radius:8px;padding:7px 12px;color:#334155}.sender-pagination button:disabled{opacity:.45;cursor:default}
@media(max-width:767.98px){.sender-delivery{padding:15px}.sender-kpis{grid-template-columns:1fr}.sender-heading{align-items:flex-start;flex-direction:column}.sender-pagination{justify-content:center}}
</style>
