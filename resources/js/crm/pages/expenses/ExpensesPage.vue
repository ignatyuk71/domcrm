<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import DateRangePicker from '@/crm/components/ui/DateRangePicker.vue';
import Toast from '@/crm/components/ui/Toast.vue';
import { useToast } from '@/crm/composables/useToast';
import { todayInKyiv, parseDate, dateISO } from '@/crm/utils/dateRange';
import ExpenseDialog from './ExpenseDialog.vue';
import ExpenseEditor from './ExpenseEditor.vue';
import { money, shortDate } from './expenseUtils';
import { fetchExpenseMeta, fetchExpenses, fetchExpense, deleteExpense, deleteExpensePayment, deleteExpenseReceipt, exportExpenses, expenseError } from '@/crm/services/expensesApi';
import './expenses.css';

const today = todayInKyiv(), end = parseDate(today); end.setMonth(end.getMonth() + 1, 0);
const filters = reactive({ view: 'payments', from: today.slice(0, 8) + '01', to: dateISO(end), q: '', category_id: '', account_id: '', group_id: '', missing_receipts: false, page: 1 });
const query = ref(''), meta = ref({ categories: [], accounts: [], groups: [], currencies: ['UAH', 'USD', 'EUR', 'PLN', 'CNY'], today });
const report = ref(null), loading = ref(true), ready = ref(false), busy = ref(false), detail = ref(null), editor = ref(null), chartMode = ref('categories');
const { toast, showToast, closeToast, runAction, runSecondary } = useToast();
let requestSequence = 0, queryTimer, errorScope = '';
const summary = computed(() => report.value?.summary || {});
const rows = computed(() => report.value?.data || []);
const pagination = computed(() => report.value?.meta || { current_page: 1, last_page: 1, total: 0 });
const chartItems = computed(() => [...(report.value?.breakdown?.[chartMode.value] || [])].sort((a, b) => Number(b.amount) - Number(a.amount)));
const accounts = computed(() => [...(report.value?.breakdown?.accounts || [])].sort((a, b) => Number(b.amount) - Number(a.amount)));
const topCategory = computed(() => [...(report.value?.breakdown?.categories || [])].sort((a, b) => Number(b.amount) - Number(a.amount))[0]);
const chartNames = { categories: 'Категорії', recipients: 'Отримувачі', groups: 'Групи' };
const tableNames = { payments: 'Оплати', planned: 'До оплати', groups: 'Групи платежів' };
const chartPercent = value => Number(summary.value.paid_amount) > 0 ? new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 1 }).format(Number(value) / Number(summary.value.paid_amount) * 100) : 0;
const chartWidth = value => chartItems.value[0] ? Math.max(1, Number(value) / Number(chartItems.value[0].amount) * 100) + '%' : '0%';
const params = () => ({ ...filters, missing_receipts: filters.view === 'payments' && filters.missing_receipts ? 1 : 0 });
function fail(error, retry, scope = 'mutation') {
  errorScope = scope;
  showToast({ type: 'error', title: 'Не вдалося виконати дію', messages: [expenseError(error)], actionLabel: 'Повторити', onAction: retry });
}
function success(message) {
  if (toast.show && toast.type === 'error') return;
  showToast({ type: 'success', title: 'Готово', messages: [message] });
}
async function load() {
  const sequence = ++requestSequence; loading.value = true;
  try {
    const response = await fetchExpenses(params());
    if (sequence !== requestSequence) return;
    report.value = response.data;
    if (errorScope === 'load') { closeToast(); errorScope = ''; }
  } catch (error) { if (sequence === requestSequence) fail(error, load, 'load'); }
  finally { if (sequence === requestSequence) loading.value = false; }
}
async function initialize() {
  loading.value = true;
  try {
    const response = await fetchExpenseMeta(); meta.value = response.data; ready.value = true;
    if (errorScope === 'meta') { closeToast(); errorScope = ''; }
    await load();
  } catch (error) { loading.value = false; fail(error, initialize, 'meta'); }
}
watch(() => [filters.view, filters.from, filters.to, filters.q, filters.category_id, filters.account_id, filters.group_id, filters.missing_receipts], () => { filters.page = 1; if (ready.value) load(); });
watch(query, value => { clearTimeout(queryTimer); queryTimer = setTimeout(() => { filters.q = value.trim(); }, 300); });
function applyPeriod(period) { filters.from = period.from; filters.to = period.to; }
function setPage(page) { filters.page = page; load(); }
function clearFilters() { query.value = ''; Object.assign(filters, { q: '', category_id: '', account_id: '', group_id: '', missing_receipts: false }); }
function create(mode) { detail.value = null; editor.value = { mode }; }
async function openExpense(id) {
  if (busy.value) return;
  busy.value = true;
  try { detail.value = (await fetchExpense(id)).data.data; }
  catch (error) { fail(error, () => openExpense(id)); }
  finally { busy.value = false; }
}
function edit(mode, payment = null) { editor.value = { mode, expense: detail.value, payment }; }
function dictionaryAdded(kind, item) { meta.value[kind] = [...meta.value[kind], item]; }
function changed(expense) { if (detail.value?.id === expense.id) detail.value = expense; load(); }
function saved(expense) { detail.value = expense; editor.value = null; load(); success('Дані збережено.'); }
function filterChart(item) {
  if (chartMode.value === 'categories') filters.category_id = item.id;
  else if (chartMode.value === 'groups' && item.id) filters.group_id = item.id;
  else if (chartMode.value === 'recipients') { query.value = item.name; filters.q = item.name; }
  filters.view = 'payments';
}
function filterAccount(account) { filters.account_id = account.id; filters.view = 'payments'; }
function groupPayments(group) { filters.group_id = group.id; filters.view = 'payments'; }
async function removeExpense() {
  const expense = detail.value;
  if (!expense || busy.value || !window.confirm(`Видалити «${expense.title}», усі її оплати та квитанції? Цю дію не можна скасувати.`)) return;
  busy.value = true;
  try { await deleteExpense(expense.id, expense.version); detail.value = null; await load(); success('Витрату видалено.'); }
  catch (error) { fail(error, error.response?.status === 409 ? () => openExpense(expense.id) : removeExpense); }
  finally { busy.value = false; }
}
async function removePayment(payment) {
  if (busy.value || !window.confirm(`Видалити оплату ${money(payment.amount, payment.currency)} та її квитанції? Неоплачений залишок збільшиться.`)) return;
  busy.value = true;
  try { detail.value = (await deleteExpensePayment(detail.value.id, payment.id, detail.value.version)).data.data; await load(); success('Оплату видалено.'); }
  catch (error) { fail(error, error.response?.status === 409 ? () => openExpense(detail.value.id) : () => removePayment(payment)); }
  finally { busy.value = false; }
}
async function removeReceipt(receipt) {
  if (busy.value || !window.confirm(`Видалити квитанцію «${receipt.name}»? Цю дію не можна скасувати.`)) return;
  busy.value = true;
  let deleted = false;
  const expenseId = detail.value.id;
  try {
    await deleteExpenseReceipt(receipt.id); deleted = true;
    // Локальне вилучення після відповіді сервера не залежить від наступного GET.
    detail.value.payments.forEach(payment => { payment.receipts = payment.receipts.filter(item => item.id !== receipt.id); });
    detail.value = (await fetchExpense(detail.value.id)).data.data;
    await load(); success('Квитанцію видалено.');
  } catch (error) {
    if (deleted) showToast({ type: 'error', title: 'Квитанцію видалено, дані не оновлено', messages: [expenseError(error)], actionLabel: 'Оновити дані', onAction: async () => { await openExpense(expenseId); await load(); } });
    else fail(error, () => removeReceipt(receipt));
  }
  finally { busy.value = false; }
}
async function downloadExport() {
  if (busy.value) return;
  busy.value = true;
  try {
    const response = await exportExpenses(params());
    const url = URL.createObjectURL(response.data), anchor = document.createElement('a');
    anchor.href = url; anchor.download = `oplaty-${filters.from}-${filters.to}.csv`; document.body.append(anchor); anchor.click(); anchor.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (error) {
    if (error.response?.data instanceof Blob) {
      try { error.response.data = JSON.parse(await error.response.data.text()); } catch { /* Сервер може повернути звичайний текст помилки. */ }
    }
    fail(error, downloadExport);
  } finally { busy.value = false; }
}
onMounted(initialize);
onBeforeUnmount(() => { clearTimeout(queryTimer); requestSequence++; });
</script>

<template>
  <div class="expenses-page">
    <header class="expenses-heading">
      <div><div class="expenses-eyebrow">ФІНАНСИ БІЗНЕСУ</div><h1>Витрати <span v-if="summary.demo_count > 0" class="expense-demo">Демодані</span></h1><p>Усі оплати, плани та квитанції в одному місці</p></div>
      <div class="expense-button-row"><button class="expense-button" :disabled="!ready || busy" @click="create('new-plan')"><i class="bi bi-calendar-plus" aria-hidden="true"></i> Запланувати</button><button class="expense-button primary" :disabled="!ready || busy" @click="create('new-paid')"><i class="bi bi-plus-lg" aria-hidden="true"></i> Додати оплату</button></div>
    </header>
    <div class="expenses-period"><DateRangePicker :from="filters.from" :to="filters.to" @apply="applyPeriod"/><span>Підсумки у гривні · за курсом кожної оплати</span><button class="expense-button export-button" :disabled="!report || busy" @click="downloadExport"><i class="bi bi-download" aria-hidden="true"></i> Експорт оплат</button></div>
    <section class="expenses-kpis" aria-label="Підсумки за вибраний період" :aria-busy="loading">
      <article class="expense-card expense-kpi featured"><div class="expense-kpi-label"><span>Оплачено за період</span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></div><strong>{{ report ? money(summary.paid_amount) : '—' }}</strong><small>{{ report ? summary.paid_count + ' оплат' : 'Завантаження даних' }}</small></article>
      <article class="expense-card expense-kpi"><div class="expense-kpi-label"><span>Залишилося оплатити</span><i class="bi bi-clock" aria-hidden="true"></i></div><strong>{{ report ? money(summary.pending_amount) : '—' }}</strong><small>{{ report ? summary.pending_count + ' витрат з терміном у цьому періоді' : 'За обраний період' }}</small></article>
      <article class="expense-card expense-kpi"><div class="expense-kpi-label"><span>Найбільша категорія</span><i class="bi bi-bar-chart" aria-hidden="true"></i></div><strong>{{ topCategory ? money(topCategory.amount) : '—' }}</strong><small>{{ topCategory ? topCategory.name + ' · ' + chartPercent(topCategory.amount) + '% оплат' : 'З’явиться після першої оплати' }}</small></article>
    </section>
    <section class="expenses-analytics" :aria-busy="loading">
      <article class="expense-card expense-breakdown"><div class="expense-section-title"><div><h2>На що йдуть гроші</h2><p>Фактичні оплати за вибраний період</p></div><div class="expense-segmented" role="group" aria-label="Групування аналітики"><button v-for="(name, value) in chartNames" :key="value" :class="{ active: chartMode === value }" :aria-pressed="chartMode === value" @click="chartMode = value">{{ name }}</button></div></div>
        <div v-if="chartItems.length" class="expense-bars"><button type="button" v-for="(item, index) in chartItems" :key="item.id ?? item.name" class="expense-bar-row" :aria-label="'Показати оплати: ' + item.name" :disabled="chartMode === 'groups' && !item.id" @click="filterChart(item)"><div class="expense-bar-title"><span><i :style="{ background: item.color || ['#6954df', '#9380ec', '#ada0ed', '#c0b7ef'][index % 4] }"></i>{{ item.name }}</span><strong>{{ money(item.amount) }} <small>{{ chartPercent(item.amount) }}%</small></strong></div><div class="expense-bar-track"><div :style="{ width: chartWidth(item.amount), background: item.color || '#9380ec' }"></div></div></button></div>
        <div v-else class="expense-empty-chart"><i class="bi bi-bar-chart" aria-hidden="true"></i><span>{{ loading ? 'Завантаження…' : 'За цими умовами оплат ще немає' }}</span></div>
      </article>
      <article class="expense-card expense-sources"><div class="expense-section-title"><div><h2>Звідки оплачено</h2><p>Рахунки та готівка</p></div><i class="bi bi-wallet2" aria-hidden="true"></i></div><div v-if="accounts.length" class="expense-account-list"><button type="button" v-for="account in accounts" :key="account.id" class="expense-account" :aria-label="'Показати оплати з рахунку ' + account.name" @click="filterAccount(account)"><span class="expense-account-icon"><i class="bi bi-bank" aria-hidden="true"></i></span><div><span>{{ account.name }}</span><small>{{ chartPercent(account.amount) }}% усіх оплат</small></div><strong>{{ money(account.amount) }}</strong></button></div><div v-else class="expense-empty-chart"><i class="bi bi-wallet2" aria-hidden="true"></i><span>{{ loading ? 'Завантаження…' : 'Рахунки з’являться після оплати' }}</span></div><p class="expense-account-note">Показано витрати з рахунків, а не їхні залишки.</p></article>
    </section>
    <section class="expense-card expense-register" aria-label="Реєстр витрат" :aria-busy="loading">
      <div class="expense-register-head"><div class="expense-tabs" role="group" aria-label="Вид реєстру"><button v-for="(name, value) in tableNames" :key="value" :class="{ active: filters.view === value }" :aria-pressed="filters.view === value" @click="filters.view = value">{{ name }}</button></div><span class="expense-record-count">{{ pagination.total }} записів</span></div>
      <div class="expense-filters"><label class="expense-search"><i class="bi bi-search" aria-hidden="true"></i><input v-model="query" type="search" placeholder="Назва, отримувач, коментар…" aria-label="Пошук витрат"></label><select v-model="filters.category_id" aria-label="Фільтр категорії"><option value="">Усі категорії</option><option v-for="item in meta.categories" :key="item.id" :value="item.id">{{ item.name }}</option></select><select v-model="filters.account_id" aria-label="Фільтр рахунку"><option value="">Усі рахунки</option><option v-for="item in meta.accounts" :key="item.id" :value="item.id">{{ item.name }}</option></select><select v-model="filters.group_id" aria-label="Фільтр групи"><option value="">Усі групи</option><option v-for="item in meta.groups" :key="item.id" :value="item.id">{{ item.name }}</option></select><label v-if="filters.view === 'payments'" class="expense-receipts-filter"><input v-model="filters.missing_receipts" type="checkbox">Без квитанції <span>{{ summary.missing_receipts_count || 0 }}</span></label><button v-if="query || filters.category_id || filters.account_id || filters.group_id || filters.missing_receipts" class="expense-icon-button" aria-label="Скинути фільтри" @click="clearFilters"><i class="bi bi-x-circle" aria-hidden="true"></i></button></div>
      <div class="expense-table-scroll"><table class="expense-table" :class="{ 'is-loading': loading }"><thead><tr v-if="filters.view === 'groups'"><th>Група платежів</th><th class="numeric">Оплачено</th><th class="numeric">До оплати</th><th class="numeric">Кількість оплат</th><th><span class="expense-sr-only">Дії</span></th></tr><tr v-else><th>{{ filters.view === 'payments' ? 'Дата' : 'Оплатити до' }}</th><th>За що / отримувач</th><th>Категорія</th><th>{{ filters.view === 'payments' ? 'Звідки оплачено' : 'Очікуване джерело' }}</th><th class="numeric">{{ filters.view === 'payments' ? 'Сума' : 'Залишок' }}</th><th>{{ filters.view === 'payments' ? 'Квитанції' : 'Статус' }}</th><th><span class="expense-sr-only">Дії</span></th></tr></thead>
        <tbody v-if="rows.length && filters.view === 'groups'"><tr v-for="row in rows" :key="row.id"><td><button class="expense-title-link" @click="groupPayments(row)">{{ row.name }}</button><small>{{ row.note }}</small></td><td class="numeric"><strong>{{ money(row.paid_amount) }}</strong></td><td class="numeric">{{ money(row.pending_amount) }}</td><td class="numeric">{{ row.payment_count }}</td><td><button class="expense-button small" @click="groupPayments(row)">Переглянути</button></td></tr></tbody>
        <tbody v-else-if="rows.length"><tr v-for="row in rows" :key="row.id"><td class="expense-nowrap">{{ shortDate(filters.view === 'payments' ? row.paid_on : row.due_on) }}<span v-if="row.is_demo" class="expense-demo">Демо</span></td><td class="expense-title-cell"><button class="expense-title-link" :disabled="busy" @click="openExpense(filters.view === 'payments' ? row.expense_id : row.id)">{{ row.title }}</button><small>{{ row.recipient || 'Отримувача не вказано' }}</small><small v-if="row.group" class="expense-group-label"><i class="bi bi-folder2" aria-hidden="true"></i> {{ row.group.name }}</small></td><td><span class="expense-category"><i :style="{ background: row.category?.color || '#9380ec' }"></i>{{ row.category?.name || '—' }}</span></td><td>{{ row.account?.name || 'Не визначено' }}</td><td class="numeric"><strong>{{ money(filters.view === 'payments' ? row.amount_uah : row.remaining_amount_uah) }}</strong><small v-if="row.currency !== 'UAH'">{{ money(filters.view === 'payments' ? row.amount : row.remaining_amount, row.currency) }}</small><small v-if="filters.view === 'planned' && row.status === 'partial'">З {{ money(row.amount, row.currency) }}</small></td><td><button v-if="filters.view === 'payments'" class="expense-receipt-pill" :class="{ missing: !row.receipts.length }" :disabled="busy" @click="openExpense(row.expense_id)"><i class="bi" :class="row.receipts.length ? 'bi-paperclip' : 'bi-file-earmark-plus'" aria-hidden="true"></i>{{ row.receipts.length ? row.receipts.length + ' файли' : 'Додати' }}</button><span v-else class="expense-status" :class="{ partial: row.status === 'partial' }">{{ row.status === 'partial' ? 'Частково' : 'Очікує оплати' }}</span></td><td><button class="expense-icon-button" :disabled="busy" :aria-label="'Деталі: ' + row.title" @click="openExpense(filters.view === 'payments' ? row.expense_id : row.id)"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></td></tr></tbody>
        <tbody v-else><tr><td :colspan="filters.view === 'groups' ? 5 : 7"><div class="expense-empty"><i class="bi bi-receipt" aria-hidden="true"></i><strong>{{ loading ? 'Завантаження…' : !report ? 'Дані не завантажено' : 'За цими умовами записів немає' }}</strong><p v-if="!loading">Оберіть інший період або додайте першу витрату.</p><button v-if="!loading && ready" class="expense-button" @click="create(filters.view === 'planned' ? 'new-plan' : 'new-paid')">{{ filters.view === 'planned' ? 'Запланувати витрату' : 'Додати оплату' }}</button></div></td></tr></tbody>
      </table></div><footer class="expense-pagination"><span>Сторінка {{ pagination.current_page }} з {{ pagination.last_page }}</span><div class="expense-button-row"><button class="expense-button small" :disabled="loading || pagination.current_page <= 1" @click="setPage(pagination.current_page - 1)">Назад</button><button class="expense-button small" :disabled="loading || pagination.current_page >= pagination.last_page" @click="setPage(pagination.current_page + 1)">Далі</button></div></footer>
    </section>
    <p class="expense-footnote">У підсумках оплат враховані фактичні платежі. Несплачений залишок показаний окремо; повторно він не додається.</p>
  </div>
  <ExpenseDialog v-if="detail && !editor" :title="detail.title" wide :busy="busy" @close="detail = null">
    <div class="expense-detail"><div class="expense-detail-top"><span v-if="detail.is_demo" class="expense-demo">Демонстраційний запис</span><div class="expense-button-row"><button class="expense-button" :disabled="busy" @click="edit('edit-expense')">Редагувати витрату</button><button v-if="Number(detail.remaining_amount) > 0" class="expense-button primary" :disabled="busy" @click="edit('add-payment')">Внести оплату</button></div></div>
      <dl class="expense-detail-summary"><div><dt>Загальна сума</dt><dd>{{ money(detail.amount, detail.currency) }}</dd></div><div><dt>Оплачено</dt><dd>{{ money(detail.paid_amount, detail.currency) }}</dd></div><div><dt>Залишилося</dt><dd>{{ money(detail.remaining_amount, detail.currency) }}</dd></div><div><dt>Оплатити до</dt><dd>{{ shortDate(detail.due_on) }}</dd></div><div><dt>Отримувач</dt><dd>{{ detail.recipient || 'Не вказано' }}</dd></div><div><dt>Категорія</dt><dd>{{ meta.categories.find(item => item.id === detail.category_id)?.name || '—' }}</dd></div><div><dt>Група</dt><dd>{{ meta.groups.find(item => item.id === detail.group_id)?.name || 'Без групи' }}</dd></div></dl>
      <p v-if="detail.note" class="expense-detail-note">{{ detail.note }}</p><h3>Оплати та квитанції</h3><div v-if="!detail.payments.length" class="expense-detail-empty">Платежів ще немає. Можна внести всю суму або оплатити частково.</div>
      <article v-for="payment in detail.payments" :key="payment.id" class="expense-payment-detail"><header><div><strong>{{ money(payment.amount, payment.currency) }}</strong><small>{{ shortDate(payment.paid_on) }} · {{ payment.account.name }}<template v-if="payment.currency !== 'UAH'"> · {{ money(payment.amount_uah) }} · курс {{ payment.exchange_rate }}</template></small></div><div class="expense-button-row"><button class="expense-button small" :disabled="busy" @click="edit('edit-payment', payment)">Редагувати / додати квитанцію</button><button class="expense-icon-button danger" :disabled="busy" aria-label="Видалити оплату" @click="removePayment(payment)"><i class="bi bi-trash" aria-hidden="true"></i></button></div></header><p v-if="payment.note" class="expense-detail-note">{{ payment.note }}</p>
        <div v-if="payment.receipts.length" class="expense-receipt-grid"><div v-for="receipt in payment.receipts" :key="receipt.id" class="expense-receipt"><a :href="receipt.url" target="_blank" rel="noopener noreferrer" :aria-label="'Відкрити ' + receipt.name"><img v-if="receipt.mime_type.startsWith('image/')" :src="receipt.url" :alt="receipt.name" loading="lazy"><span v-else class="expense-pdf-icon"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>PDF</span><span class="expense-receipt-name">{{ receipt.name }}</span></a><div><a :href="receipt.download_url" class="expense-receipt-download">Завантажити</a><button class="expense-icon-button danger" :disabled="busy" :aria-label="'Видалити квитанцію ' + receipt.name" @click="removeReceipt(receipt)"><i class="bi bi-trash" aria-hidden="true"></i></button></div></div></div><button v-else class="expense-add-receipt" :disabled="busy" @click="edit('edit-payment', payment)"><i class="bi bi-paperclip" aria-hidden="true"></i> Прикріпити фото або PDF квитанції</button>
      </article><footer class="expense-detail-footer"><button class="expense-button danger" :disabled="busy" @click="removeExpense">Видалити витрату</button><button class="expense-button" :disabled="busy" @click="detail = null">Закрити</button></footer>
    </div>
  </ExpenseDialog>
  <ExpenseEditor v-if="editor" :key="editor.mode + '-' + (editor.payment?.id || editor.expense?.id || 'new')" v-bind="editor" :meta="meta" @close="editor = null" @saved="saved" @changed="changed" @dictionary="dictionaryAdded" />
  <Toast v-bind="toast" @close="closeToast" @action="runAction" @secondary="runSecondary" />
</template>
