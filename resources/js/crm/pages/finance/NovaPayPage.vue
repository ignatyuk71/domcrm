<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import http from '@/crm/api/http';
import Toast from '@/crm/components/ui/Toast.vue';
import { useToast } from '@/crm/composables/useToast';
import { money, period } from './novapayUtils';

const { toast, showToast, closeToast, runAction, runSecondary } = useToast();
const query = new URLSearchParams(window.location.search);
const initial = period('today');
const filters = reactive({
    account_id: query.get('account_id') || '', from: query.get('from') || initial.from,
    to: query.get('to') || initial.to, direction: query.get('direction') || 'in', search: query.get('search') || '',
    page: Number(query.get('page')) || 1,
});
const data = ref(null);
const loading = ref(true);
const busy = ref('');
const tab = ref('operations');
const settingsOpen = ref(false);
const customPeriodOpen = ref(false);
const connection = reactive({ connection_id: '', login: '', refresh_token: '', public_certificate: '' });
const errors = ref({});
const pendingIds = ref([]);
const observedFailures = new Set();
const account = computed(() => data.value?.accounts.find(item => item.id === data.value.account_id));
const pending = computed(() => data.value?.sync.some(run => ['queued', 'running', 'retrying'].includes(run.status)) || false);
const now = ref(Date.now());
const lastBalanceRun = computed(() => data.value?.sync.find(run => run.source === 'balance'));
const balanceStale = computed(() => Boolean(data.value?.balance && (
    account.value?.requires_auth || !account.value?.enabled || lastBalanceRun.value?.status === 'failed'
    || now.value - new Date(data.value.balance.received_at).getTime() > Math.max(10, (data.value.refresh_minutes || 5) * 2) * 60000
)));
const hasDraft = computed(() => Boolean(connection.login || connection.refresh_token || connection.public_certificate));
const timestamp = value => value ? new Intl.DateTimeFormat('uk-UA', {
    timeZone: account.value?.timezone || 'Europe/Kyiv', dateStyle: 'short', timeStyle: 'short',
}).format(new Date(value)) : 'Ще не отримано';
const calendar = value => value ? value.split('-').reverse().join('.') : '—';
const statusLabels = { posted: 'Проведено', pending: 'Очікує підтвердження', cancelled: 'Скасовано' };
const runLabels = { queued: 'У черзі', running: 'Оновлюється', retrying: 'Повторна спроба', success: 'Завершено', failed: 'Дані не оновлено' };
const sourceLabels = { balance: 'Баланс', operations: 'Операції', discovery: 'Підключення' };
const periods = [['today', 'Сьогодні'], ['yesterday', 'Вчора'], ['week', '7 днів'], ['month', 'Місяць']];
const selectedPeriod = computed(() => periods.find(([key]) => {
    const range = period(key, account.value?.timezone);
    return range.from === filters.from && range.to === filters.to;
})?.[0] || 'custom');
const accountState = computed(() => account.value?.requires_auth ? 'Потрібна авторизація'
    : pending.value ? 'Оновлюється' : !account.value?.enabled ? 'Оновлення на паузі'
    : !data.value?.balance ? 'Очікуємо дані' : balanceStale.value ? 'Дані не оновлено' : 'Дані актуальні');
const latestOperationError = computed(() => data.value?.sync.find(run => run.source === 'operations')?.status === 'failed');
const counterpartyInitial = value => Array.from((value || 'Операція').trim())[0]?.toUpperCase() || 'О';
const recordCount = count => {
    const remainder = count % 100;
    const ending = remainder >= 11 && remainder <= 14 ? 'записів' : count % 10 === 1 ? 'запис' : count % 10 >= 2 && count % 10 <= 4 ? 'записи' : 'записів';
    return `${count} ${ending}`;
};

function navigateTab(event, current) {
    const tabs = ['operations', 'payments', 'sync'];
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const position = tabs.indexOf(current);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
        : (position + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
    tab.value = tabs[next];
    event.currentTarget.parentElement.querySelector(`#np-tab-${tabs[next]}`)?.focus();
}
let interval, searchTimer, requestId = 0, controller, disposed = false;

function success(message) {
    // Фоновий успіх не перекриває невиправлену помилку.
    if (toast.show && ['error', 'warning'].includes(toast.type)) return;
    showToast({ title: 'NovaPay', messages: [message], type: 'success' });
}

function fail(error, retry = load) {
    errors.value = error.response?.data?.errors || {};
    showToast({
        title: 'Не вдалося виконати дію', type: 'error',
        messages: Object.values(errors.value).flat().length ? Object.values(errors.value).flat() : [error.response?.data?.message || 'Помилка з’єднання з CRM. Повторіть спробу.'],
        actionLabel: 'Повторити', onAction: retry,
    });
}

function acceptSync() {
    for (const run of data.value.sync) {
        if (run.status === 'failed' && !observedFailures.has(run.id)) {
            observedFailures.add(run.id);
            showToast({ title: `${sourceLabels[run.source]}: дані не оновлено`, type: 'error',
                messages: [run.error_message || 'Перевірте підключення та повторіть оновлення.'],
                actionLabel: account.value?.requires_auth ? 'Відновити доступ' : 'Повторити',
                onAction: () => account.value?.requires_auth ? openSettings(account.value.connection_id) : run.source === 'discovery' ? discover() : refresh(),
            });
        }
    }
    if (!pendingIds.value.length) return;
    const runs = pendingIds.value.map(id => data.value.sync.find(run => run.id === id));
    if (runs.every(run => run && ['success', 'failed'].includes(run.status))) {
        if (runs.every(run => run.status === 'success')) success('Оновлення підтверджено сервером.');
        pendingIds.value = [];
    }
}

async function load(silent = false) {
    const id = ++requestId;
    controller?.abort();
    controller = new AbortController();
    if (!silent) loading.value = true;
    try {
        const response = await http.get('/finance/novapay/data', { params: filters, signal: controller.signal });
        if (id !== requestId || disposed) return;
        data.value = response.data;
        if (!data.value.accounts.length) settingsOpen.value = true;
        acceptSync();
    } catch (error) {
        if (error.code !== 'ERR_CANCELED' && id === requestId && !disposed) fail(error);
    } finally {
        if (id === requestId) loading.value = false;
    }
}

async function perform(name, action) {
    if (busy.value) return;
    busy.value = name;
    try { await action(); } catch (error) { fail(error, () => perform(name, action)); }
    finally { busy.value = ''; }
}

function openSettings(id = '') {
    connection.connection_id = id;
    settingsOpen.value = true;
}

function saveConnection() {
    return perform('connect', async () => {
        const response = await http.post('/finance/novapay/connections', connection);
        errors.value = {};
        connection.connection_id = response.data.connection_id;
        connection.login = ''; connection.refresh_token = ''; connection.public_certificate = '';
        if (response.data.run_id) pendingIds.value = [response.data.run_id];
        success('Параметри збережено. Перевірка підключення виконується у фоні.');
        await load(true);
    });
}

function discover() {
    const id = connection.connection_id || account.value?.connection_id || data.value?.connections[0]?.id;
    if (!id) { openSettings(); return; }
    return perform('discover', async () => {
        const response = await http.post(`/finance/novapay/connections/${id}/discover`);
        pendingIds.value = response.data.run_id ? [response.data.run_id] : [];
        success(response.data.run_id ? 'Перевірку підключення поставлено у чергу.' : 'Перевірка вже виконується.');
        await load(true);
    });
}

function toggleAccount() {
    if (!account.value) return;
    return perform('account', async () => {
        const enabled = !account.value.enabled;
        await http.put(`/finance/novapay/accounts/${account.value.id}`, { enabled });
        success(enabled ? 'Оновлення рахунку увімкнено.' : 'Оновлення рахунку призупинено.');
        await load(true);
    });
}

function refresh() {
    if (!account.value) return discover();
    return perform('refresh', async () => {
        const response = await http.post(`/finance/novapay/accounts/${account.value.id}/refresh`);
        await load(true);
        pendingIds.value = data.value.sync.filter(run => ['queued', 'running', 'retrying'].includes(run.status)).map(run => run.id);
        if (response.data.queued === 0) success('Оновлення вже виконується.');
    });
}

function applyFilters() {
    if (disposed) return;
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) if (value !== '') params.set(key, String(value));
    window.history.replaceState(null, '', `${window.location.pathname}?${params}`);
    load();
}

function selectPeriod(key) {
    customPeriodOpen.value = false;
    Object.assign(filters, period(key, account.value?.timezone), { page: 1 });
}
function beforeUnload(event) { if (hasDraft.value) { event.preventDefault(); event.returnValue = ''; } }
watch(() => [filters.account_id, filters.from, filters.to, filters.direction], () => { filters.page = 1; applyFilters(); });
watch(() => filters.page, applyFilters);
watch(() => filters.search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { filters.page = 1; applyFilters(); }, 350); });
onMounted(() => {
    load();
    window.addEventListener('beforeunload', beforeUnload);
    interval = setInterval(() => { now.value = Date.now(); if (!busy.value && !document.hidden) load(true); }, 15000);
});
onBeforeUnmount(() => { disposed = true; controller?.abort(); clearInterval(interval); clearTimeout(searchTimer); window.removeEventListener('beforeunload', beforeUnload); });
</script>

<template>
    <div class="novapay-page" :aria-busy="loading">
        <header class="page-heading">
            <div class="heading-identity">
                <span class="brand-icon" aria-hidden="true"><i class="bi bi-bank"></i></span>
                <div>
                    <div class="eyebrow">Фінанси <span>/</span> Бізнес-рахунок</div>
                    <h1>NovaPay <span class="heading-currency">UAH</span></h1>
                    <p>Усі кошти — під контролем.</p>
                </div>
            </div>
            <div class="heading-actions">
                <a href="/finance" class="btn action-btn action-btn--quiet"><i class="bi bi-wallet2" aria-hidden="true"></i><span>Каса</span></a>
                <button class="btn action-btn action-btn--quiet" type="button" :aria-expanded="settingsOpen" aria-controls="np-settings" @click="settingsOpen = !settingsOpen"><i class="bi bi-sliders" aria-hidden="true"></i><span>Підключення</span></button>
                <button class="btn action-btn action-btn--primary" type="button" :disabled="Boolean(busy) || pending || !account?.enabled || account?.requires_auth" @click="refresh"><i class="bi bi-arrow-repeat" :class="{ spinning: pending }" aria-hidden="true"></i><span>{{ pending ? 'Оновлюється…' : 'Оновити дані' }}</span></button>
            </div>
        </header>

        <section v-if="settingsOpen" id="np-settings" class="connection-panel" aria-labelledby="np-settings-title">
            <div class="panel-heading">
                <div class="panel-heading-copy"><span class="section-icon"><i class="bi bi-link-45deg" aria-hidden="true"></i></span><div><h2 id="np-settings-title">Підключення бізнес-рахунку</h2><p>Безпечний доступ до балансу та виписки.</p></div></div>
                <button v-if="data?.accounts.length" class="icon-button" aria-label="Згорнути підключення" @click="settingsOpen = false"><i class="bi bi-chevron-up" aria-hidden="true"></i></button>
            </div>
            <div class="connection-content">
                <p class="setup-help">У бізнес-кабінеті NovaPay відкрийте <strong>Система → Налаштування → API</strong>, увімкніть доступ для підприємства та згенеруйте параметри підключення.</p>
                <form @submit.prevent="saveConnection">
                    <fieldset :disabled="Boolean(busy)">
                        <div class="row g-3">
                            <div v-if="data?.connections.length" class="col-md-6"><label for="np-connection" class="form-label">Підключення</label><select id="np-connection" v-model="connection.connection_id" class="form-select"><option value="">Нове підключення</option><option v-for="item in data.connections" :key="item.id" :value="item.id">Підключення №{{ item.id }}{{ item.requires_auth ? ' — відновити доступ' : '' }}</option></select></div>
                            <div v-if="!connection.connection_id" class="col-md-6"><label for="np-login" class="form-label">Логін NovaPay</label><input id="np-login" v-model.trim="connection.login" class="form-control" :class="{ 'is-invalid': errors.login }" :aria-invalid="Boolean(errors.login)" autocomplete="off" placeholder="Логін бізнес-кабінету" required></div>
                            <div class="col-md-6"><label for="np-token" class="form-label">Новий Refresh Token</label><input id="np-token" v-model.trim="connection.refresh_token" class="form-control" :class="{ 'is-invalid': errors.refresh_token }" :aria-invalid="Boolean(errors.refresh_token)" type="password" autocomplete="new-password" placeholder="Вставте згенерований токен" required></div>
                            <div class="col-md-6"><label for="np-certificate" class="form-label">Відкритий сертифікат</label><textarea id="np-certificate" v-model.trim="connection.public_certificate" class="form-control certificate-field" :class="{ 'is-invalid': errors.public_certificate }" :aria-invalid="Boolean(errors.public_certificate)" rows="3" spellcheck="false" autocomplete="off" placeholder="Вставте сертифікат із NovaPay" required></textarea></div>
                        </div>
                        <div class="connection-footer"><div class="d-flex flex-wrap gap-2"><button class="btn action-btn action-btn--primary" type="submit"><i class="bi bi-link-45deg" aria-hidden="true"></i>{{ busy === 'connect' ? 'Зберігаємо…' : 'Зберегти й перевірити' }}</button><button v-if="data?.connections.length" class="btn action-btn action-btn--quiet" type="button" @click="discover">Повторити перевірку</button></div><span class="secure-note"><i class="bi bi-shield-lock" aria-hidden="true"></i>Токен зашифровано після збереження</span></div>
                    </fieldset>
                </form>
            </div>
        </section>

        <div v-if="loading && !data" class="loading-state" role="status"><span class="spinner-border spinner-border-sm"></span>Завантажуємо рахунок…</div>
        <button v-else-if="!data" class="btn action-btn action-btn--primary" @click="load()">Повторити завантаження</button>
        <template v-if="data">
            <div v-if="data.accounts.length" class="account-bar">
                <div class="account-identity"><span class="account-bank-icon"><i class="bi bi-building" aria-hidden="true"></i></span><div><label for="np-account" class="account-label">Бізнес-рахунок</label><select id="np-account" :value="filters.account_id || data.account_id || ''" class="form-select account-select" @change="filters.account_id = $event.target.value"><option value="" disabled>Оберіть рахунок</option><option v-for="item in data.accounts" :key="item.id" :value="item.id">{{ item.client_name }} · {{ item.iban }}</option></select></div></div>
                <div class="account-actions"><span class="state-pill" :class="{ 'state-pill--ok': data.balance && !balanceStale && account?.enabled && !pending, 'state-pill--attention': balanceStale || account?.requires_auth }"><span class="state-dot"></span>{{ accountState }}</span><button v-if="account?.requires_auth" class="text-action text-action--danger" @click="openSettings(account.connection_id)">Відновити доступ<i class="bi bi-arrow-up-right" aria-hidden="true"></i></button><button v-else class="text-action" :disabled="Boolean(busy)" @click="toggleAccount"><i class="bi" :class="account?.enabled ? 'bi-pause-circle' : 'bi-play-circle'" aria-hidden="true"></i>{{ account?.enabled ? 'Призупинити' : 'Увімкнути' }}</button></div>
            </div>

            <div class="summary-grid">
                <section class="balance-card" aria-label="Доступний залишок на рахунку">
                    <div class="metric-heading"><span>На рахунку</span><span class="balance-card-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></span></div>
                    <div class="balance-value" :class="{ 'balance-value--empty': !data.balance }" data-testid="balance">{{ data.balance ? money(data.balance.amount_minor) : 'Баланс недоступний' }}</div>
                    <div class="balance-caption">Доступний залишок <span>·</span> {{ account?.currency || 'UAH' }}</div>
                    <div class="balance-footer"><span class="iban"><i class="bi bi-credit-card-2-front" aria-hidden="true"></i>{{ account?.iban || 'Рахунок ще не підключено' }}</span><span class="balance-provider">NovaPay</span></div>
                </section>
                <section class="metric-card" aria-label="Надходження за вибраний період">
                    <div class="metric-heading"><span>Надійшло за період</span><span class="metric-icon metric-icon--green"><i class="bi bi-arrow-down-left" aria-hidden="true"></i></span></div>
                    <div class="metric-value" data-testid="incoming">{{ money(data.incoming_minor) }}</div>
                    <div class="metric-description">{{ data.coverage_complete ? 'Проведені надходження на рахунок' : 'Дані за період ще не підтверджено' }}</div>
                    <div class="metric-footer"><i class="bi bi-calendar3" aria-hidden="true"></i>{{ calendar(filters.from) }}<span v-if="filters.from !== filters.to"> — {{ calendar(filters.to) }}</span><span v-if="!data.coverage_complete" class="metric-tag">Очікуємо дані</span></div>
                </section>
                <section class="metric-card" aria-label="Замовлення з отриманою оплатою">
                    <div class="metric-heading"><span>Оплачені замовлення</span><span class="metric-icon metric-icon--violet"><i class="bi bi-box-seam" aria-hidden="true"></i></span></div>
                    <div class="metric-value">—</div>
                    <div class="metric-description">Замовлення з підтвердженою виплатою</div>
                    <div class="metric-footer"><span class="metric-tag"><i class="bi bi-hourglass-split" aria-hidden="true"></i>Деталізація ще не підключена</span></div>
                </section>
            </div>

            <div class="freshness-row"><span><i class="bi bi-clock-history" aria-hidden="true"></i>Баланс: <strong>{{ timestamp(data.balance?.received_at) }}</strong></span><span>Операції: <strong>{{ timestamp(data.operations_updated_at) }}</strong></span><span class="timezone-label"><i class="bi bi-globe2" aria-hidden="true"></i>Час за Києвом</span></div>

            <section class="ledger-panel">
                <div class="ledger-heading"><div><h2>{{ tab === 'operations' ? 'Рух коштів' : tab === 'payments' ? 'Оплати замовлень' : 'Історія оновлень' }}<span v-if="tab === 'operations' && data.operations?.total" class="count-badge">{{ data.operations.total }}</span></h2><p>{{ tab === 'operations' ? 'Фактичні операції вашого бізнес-рахунку' : tab === 'payments' ? 'Деталізація післяплати та зв’язки із замовленнями' : 'Стан отримання даних із NovaPay' }}</p></div><span class="ledger-source"><span class="state-dot"></span>Дані NovaPay</span></div>
                <div class="ledger-tabs" role="tablist" aria-label="Дані рахунку"><button v-for="item in [['operations', 'bi-arrow-left-right', 'Операції рахунку'], ['payments', 'bi-receipt', 'Оплати замовлень'], ['sync', 'bi-arrow-repeat', 'Синхронізація']]" :id="`np-tab-${item[0]}`" :key="item[0]" class="ledger-tab" :class="{ active: tab === item[0] }" type="button" role="tab" :aria-selected="tab === item[0]" :tabindex="tab === item[0] ? 0 : -1" @keydown="navigateTab($event, item[0])" :aria-controls="`np-panel-${item[0]}`" @click="tab = item[0]"><i class="bi" :class="item[1]" aria-hidden="true"></i>{{ item[2] }}</button></div>
                <div class="period-toolbar" aria-label="Період">
                    <div class="period-switch"><button v-for="item in periods" :key="item[0]" :class="{ active: selectedPeriod === item[0] && !customPeriodOpen }" :aria-pressed="selectedPeriod === item[0] && !customPeriodOpen" type="button" @click="selectPeriod(item[0])">{{ item[1] }}</button><button :class="{ active: selectedPeriod === 'custom' || customPeriodOpen }" :aria-pressed="selectedPeriod === 'custom' || customPeriodOpen" type="button" @click="customPeriodOpen = !customPeriodOpen"><i class="bi bi-calendar3" aria-hidden="true"></i>Свій період</button></div>
                    <span class="period-caption">{{ calendar(filters.from) }}<template v-if="filters.from !== filters.to"> — {{ calendar(filters.to) }}</template></span>
                    <div v-if="customPeriodOpen || selectedPeriod === 'custom'" class="custom-dates"><label>Від<input v-model="filters.from" class="form-control form-control-sm" :class="{ 'is-invalid': errors.from }" :aria-invalid="Boolean(errors.from)" type="date" aria-label="Початок періоду"></label><span>—</span><label>До<input v-model="filters.to" class="form-control form-control-sm" :class="{ 'is-invalid': errors.to }" :aria-invalid="Boolean(errors.to)" type="date" aria-label="Кінець періоду"></label></div>
                </div>
                <div v-if="tab === 'operations'" id="np-panel-operations" class="ledger-content" role="tabpanel" aria-labelledby="np-tab-operations">
                    <div class="operations-toolbar"><div class="search-field"><i class="bi bi-search" aria-hidden="true"></i><input v-model="filters.search" class="form-control" placeholder="Знайти контрагента або платіж…" aria-label="Пошук операцій" maxlength="120"><button v-if="filters.search" class="search-clear" aria-label="Очистити пошук" @click="filters.search = ''"><i class="bi bi-x" aria-hidden="true"></i></button></div><select v-model="filters.direction" class="form-select direction-select" aria-label="Напрям операцій"><option value="in">Надходження</option><option value="out">Витрати</option><option value="all">Усі операції</option></select></div>
                    <div v-if="!data.coverage_complete || latestOperationError" class="data-context"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ latestOperationError ? 'Операції не оновлено. Відображаємо останні отримані дані.' : data.operations_verified ? 'Дані за вибраний період ще надходять. Підсумок буде доступний після завершення оновлення.' : 'Виписка стане доступна після перевірки підключення. Баланс оновлюється окремо.' }}</span></div>
                    <div v-if="data.operations?.data.length" class="table-responsive"><table class="table operations-table align-middle"><thead><tr><th>Дата</th><th>Контрагент</th><th>Призначення</th><th class="text-end">Сума</th><th>Напрям</th><th>Статус</th></tr></thead><tbody><tr v-for="operation in data.operations.data" :key="operation.id"><td class="operation-date">{{ calendar(operation.booked_on) }}</td><td class="operation-counterparty"><div class="counterparty-cell"><span class="counterparty-avatar">{{ counterpartyInitial(operation.counterparty) }}</span><strong>{{ operation.counterparty || 'Контрагент не зазначений' }}</strong></div></td><td class="operation-purpose" :title="operation.purpose || ''">{{ operation.purpose || 'Без призначення' }}</td><td class="operation-amount" :class="{ 'operation-amount--in': operation.direction === 'in' }">{{ operation.direction === 'in' ? '+' : '−' }}{{ money(operation.amount_minor) }}</td><td class="operation-direction"><i class="bi" :class="operation.direction === 'in' ? 'bi-arrow-down-left' : 'bi-arrow-up-right'" aria-hidden="true"></i>{{ operation.direction === 'in' ? 'Надходження' : 'Витрата' }}</td><td class="operation-status"><span class="status-chip" :class="`status-chip--${operation.status}`"><span class="state-dot"></span>{{ statusLabels[operation.status] || 'Потрібна перевірка' }}</span></td></tr></tbody></table></div>
                    <div v-else class="empty-state"><span class="empty-state-icon"><i class="bi" :class="!account ? 'bi-bank' : filters.search ? 'bi-search' : 'bi-inbox'" aria-hidden="true"></i></span><h3>{{ !account ? 'Підключіть свій рахунок' : filters.search ? 'За цим пошуком нічого не знайдено' : !data.coverage_complete ? 'Очікуємо операції' : 'Операцій поки немає' }}</h3><p>{{ !account ? 'Баланс і рух коштів будуть доступні після підключення NovaPay.' : !data.coverage_complete ? 'Записи ще не отримані повністю. Відсутність рядків не підтверджує відсутність оплат.' : 'Спробуйте інший період або змініть фільтри.' }}</p><button v-if="!account" class="btn action-btn action-btn--primary" @click="openSettings()">Підключити NovaPay<i class="bi bi-arrow-right" aria-hidden="true"></i></button><button v-else-if="filters.search" class="text-action" @click="filters.search = ''">Очистити пошук<i class="bi bi-arrow-right" aria-hidden="true"></i></button></div>
                    <div class="ledger-footer"><span>{{ recordCount(data.operations?.total || 0) }}<span v-if="data.operations?.total"> · по 25 на сторінці</span></span><div v-if="data.operations?.last_page > 1" class="pagination-controls"><button class="icon-button" aria-label="Попередня сторінка" :disabled="filters.page <= 1 || loading" @click="filters.page--"><i class="bi bi-chevron-left" aria-hidden="true"></i></button><span>{{ data.operations.current_page }} <span class="text-secondary">/ {{ data.operations.last_page }}</span></span><button class="icon-button" aria-label="Наступна сторінка" :disabled="filters.page >= data.operations.last_page || loading" @click="filters.page++"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></div><span v-else class="footer-note"><i class="bi bi-shield-check" aria-hidden="true"></i>Лише перегляд операцій</span></div>
                </div>
                <div v-else-if="tab === 'payments'" id="np-panel-payments" class="empty-state payments-empty" role="tabpanel" aria-labelledby="np-tab-payments"><span class="empty-state-icon"><i class="bi bi-receipt" aria-hidden="true"></i></span><span class="empty-state-tag">Деталізація післяплати</span><h3>Кожна оплата — до свого замовлення</h3><p>Після підключення реєстрів тут з’являться виплати за ТТН, суми після комісій і пов’язані замовлення. Деталізація ще не отримана.</p><div class="payments-note"><i class="bi bi-info-circle" aria-hidden="true"></i>Баланс рахунку оновлюється незалежно від деталізації.</div></div>
                <div v-else id="np-panel-sync" class="sync-content" role="tabpanel" aria-labelledby="np-tab-sync"><div class="table-responsive"><table class="table sync-table align-middle"><thead><tr><th>Джерело</th><th>Період</th><th>Стан</th><th>Останній успіх</th><th>Діагностика</th></tr></thead><tbody><tr v-for="run in data.sync" :key="run.id"><td class="fw-semibold"><i class="bi bi-arrow-repeat me-2 text-secondary" aria-hidden="true"></i>{{ sourceLabels[run.source] }}</td><td>{{ run.date_from ? calendar(run.date_from) : '—' }}</td><td><span class="status-chip" :class="run.status === 'success' ? 'status-chip--posted' : run.status === 'failed' ? 'status-chip--failed' : 'status-chip--pending'"><span class="state-dot"></span>{{ runLabels[run.status] }}</span></td><td>{{ timestamp(run.succeeded_at) }}</td><td class="sync-diagnostic">{{ run.error_message || '—' }}</td></tr><tr v-if="!data.sync.length"><td colspan="5"><div class="empty-state"><span class="empty-state-icon"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></span><h3>Історія з’явиться після першого оновлення</h3><p>Тут можна перевірити стан отримання балансу та операцій.</p></div></td></tr></tbody></table></div></div>
            </section>
            <div class="page-footnote"><i class="bi bi-shield-lock" aria-hidden="true"></i>Захищене підключення<span>·</span>Доступ до фінансів лише для власника</div>
        </template>
        <Toast v-bind="toast" @close="closeToast" @action="runAction" @secondary="runSecondary" />
    </div>
</template>

<style scoped>
.novapay-page { --np-purple: #7254d6; --np-purple-dark: #5d40bc; --np-ink: #242438; --np-muted: #77778a; --np-border: #ececf3; max-width: 1400px; margin: 0 auto; color: var(--np-ink); font-family: 'Inter', system-ui, sans-serif; font-size: 14px; padding: 6px 4px 18px; }
.page-heading, .heading-identity, .heading-actions, .panel-heading, .panel-heading-copy, .account-bar, .account-identity, .account-actions, .metric-heading, .balance-footer, .metric-footer, .freshness-row, .ledger-heading, .period-toolbar, .operations-toolbar, .ledger-footer, .pagination-controls, .connection-footer { display: flex; align-items: center; }
.page-heading { justify-content: space-between; gap: 22px; margin-bottom: 26px; }
.heading-identity { gap: 15px; min-width: 0; }
.brand-icon { display: grid; place-items: center; flex-shrink: 0; width: 54px; height: 54px; background: #ece7fb; color: var(--np-purple); border: 1px solid #e2daf8; border-radius: 16px; font-size: 24px; }
.eyebrow { font-size: 11px; font-weight: 500; color: var(--np-muted); margin-bottom: 5px; }
.eyebrow span { color: #b9b9c8; margin: 0 6px; }
.page-heading h1 { display: flex; align-items: center; gap: 10px; margin: 0; font-size: 25px; line-height: 1.2; letter-spacing: -.8px; font-weight: 750; }
.heading-currency { border: 1px solid #e4e4ed; border-radius: 6px; padding: 4px 6px; font-size: 10px; letter-spacing: .4px; font-weight: 600; color: var(--np-muted); background: #fff; }
.page-heading p { margin: 5px 0 0; font-size: 12px; color: var(--np-muted); }
.heading-actions { gap: 8px; flex-shrink: 0; }
.action-btn { display: inline-flex; justify-content: center; align-items: center; gap: 8px; border-radius: 9px; padding: 10px 14px; font-size: 12px; font-weight: 600; line-height: 1.5; border: 1px solid transparent; transition: background .15s, box-shadow .15s; white-space: nowrap; }
.action-btn i { font-size: 15px; }
.action-btn--quiet { color: #59596d; background: white; border-color: #e3e3ec; }
.action-btn--quiet:hover { color: var(--np-purple); background: #f7f5fc; border-color: #d6cdef; }
.action-btn--primary { color: #fff; background: var(--np-purple); border-color: var(--np-purple); box-shadow: 0 3px 8px #7254d61a; }
.action-btn--primary:hover { color: #fff; background: var(--np-purple-dark); border-color: var(--np-purple-dark); }
.action-btn:disabled { color: #fff; background: #a496d3; border-color: #a496d3; opacity: .8; }
.novapay-page button:focus-visible, .novapay-page a:focus-visible { outline: 3px solid #c6b6f2; outline-offset: 3px; box-shadow: none; }
.novapay-page .form-control, .novapay-page .form-select { border-color: #e1e1ec; border-radius: 9px; font-size: 12px; min-height: 40px; }
.novapay-page .form-control:focus, .novapay-page .form-select:focus { border-color: #aa96e4; box-shadow: 0 0 0 3px #7254d610; }
.novapay-page .form-control.is-invalid, .novapay-page .form-select.is-invalid { border-color: #dc3545; }
.novapay-page .form-label { color: #545467; font-size: 12px; font-weight: 600; }
.connection-panel, .ledger-panel { border: 1px solid var(--np-border); background: #fff; border-radius: 16px; box-shadow: 0 3px 14px #24243803; overflow: hidden; }
.connection-panel { margin-bottom: 22px; }
.panel-heading { padding: 20px 24px; justify-content: space-between; border-bottom: 1px solid var(--np-border); }
.panel-heading-copy { gap: 12px; }
.section-icon { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 10px; color: var(--np-purple); background: #f1edfc; font-size: 20px; }
.panel-heading h2, .ledger-heading h2 { margin: 0; font-size: 16px; font-weight: 700; letter-spacing: -.35px; }
.panel-heading p, .ledger-heading p { margin: 5px 0 0; color: var(--np-muted); font-size: 12px; }
.connection-content { padding: 20px 24px 24px; }
.setup-help { font-size: 12px; color: #737386; line-height: 1.8; margin-bottom: 20px; }
.setup-help strong { color: #53536b; font-weight: 600; }
.certificate-field { font-family: ui-monospace, monospace; }
.connection-footer { flex-wrap: wrap; justify-content: space-between; gap: 16px; margin-top: 20px; }
.secure-note { display: flex; align-items: center; gap: 7px; color: var(--np-muted); font-size: 11px; }
.secure-note i { color: var(--np-purple); }
.loading-state { display: flex; justify-content: center; gap: 12px; padding: 80px 20px; color: var(--np-muted); }
.account-bar { justify-content: space-between; gap: 18px; padding: 13px 16px; margin-bottom: 20px; border: 1px solid var(--np-border); border-radius: 12px; background: #fff; }
.account-identity { gap: 11px; min-width: 0; flex: 1; }
.account-identity > div { min-width: 0; flex: 1; }
.account-bank-icon { display: grid; place-items: center; width: 35px; height: 35px; border: 1px solid #ebebf4; border-radius: 9px; color: #88889c; background: #fafafe; flex-shrink: 0; }
.account-label { display: block; font-size: 10px; color: var(--np-muted); margin-bottom: 2px; }
.novapay-page .account-select { padding: 0 28px 0 0; border: 0; min-height: 24px; font-size: 12px; font-weight: 600; color: #49495f; background-position: right 3px center; box-shadow: none; overflow: hidden; text-overflow: ellipsis; max-width: 650px; }
.account-actions { gap: 17px; flex-shrink: 0; }
.state-pill { display: inline-flex; align-items: center; gap: 6px; color: #79708f; background: #f2eff8; font-size: 10px; font-weight: 600; padding: 6px 9px; border-radius: 6px; white-space: nowrap; }
.state-dot { width: 5px; height: 5px; display: inline-block; background: currentColor; border-radius: 50%; flex-shrink: 0; }
.state-pill--ok { background: #eff8f3; color: #377b58; }
.state-pill--attention { background: #fff6e8; color: #906518; }
.text-action { display: inline-flex; align-items: center; justify-content: center; gap: 6px; background: transparent; border: 0; color: #7b7b8e; font-size: 11px; padding: 6px 0; }
.text-action:hover { color: var(--np-purple); }
.text-action--danger { color: #b54c55; }
.text-action:disabled { opacity: .5; }
.summary-grid { display: grid; grid-template-columns: 1.2fr 1fr 1fr; gap: 16px; }
.balance-card, .metric-card { padding: 21px 23px 0; border-radius: 15px; min-width: 0; display: flex; flex-direction: column; }
.balance-card { position: relative; background: linear-gradient(115deg, #6a4ac9 0%, #8060d9 100%); color: #fff; border: 1px solid #7251cf; box-shadow: 0 8px 20px #6a4ac918; overflow: hidden; }
.balance-card::after { content: ''; position: absolute; width: 160px; height: 160px; border: 1px solid #ffffff12; border-radius: 50%; right: -74px; top: 70px; pointer-events: none; }
.metric-heading { justify-content: space-between; gap: 10px; font-size: 12px; font-weight: 500; }
.balance-card .metric-heading { color: #e6dcff; }
.balance-card-icon { display: grid; place-items: center; width: 30px; height: 30px; color: #fff; background: #ffffff12; border: 1px solid #ffffff20; border-radius: 8px; font-size: 15px; }
.balance-value, .metric-value { margin-top: 15px; font-size: clamp(24px, 2.3vw, 34px); letter-spacing: -1.1px; font-weight: 650; line-height: 1.3; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.balance-value--empty { font-size: 24px; }
.balance-caption { margin-top: 7px; color: #e4d9ff; font-size: 10px; }
.balance-caption span { padding: 0 5px; opacity: .6; }
.balance-footer { justify-content: space-between; gap: 12px; margin-top: 21px; padding: 15px 0; border-top: 1px solid #ffffff22; font-size: 10px; color: #e9e0ff; }
.iban { display: flex; gap: 7px; align-items: center; font-variant-numeric: tabular-nums; letter-spacing: .4px; }
.iban i { font-size: 15px; }
.balance-provider { font-size: 12px; font-weight: 650; letter-spacing: -.2px; color: #fff; }
.metric-card { border: 1px solid var(--np-border); background: #fff; box-shadow: 0 3px 14px #24243803; }
.metric-card .metric-heading { color: #76768b; }
.metric-icon { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 9px; font-size: 15px; flex-shrink: 0; }
.metric-icon--green { color: #379477; background: #edf8f3; }
.metric-icon--violet { color: #9172cb; background: #f4effc; }
.metric-description { margin-top: 7px; color: #9393a4; font-size: 10px; line-height: 1.6; }
.metric-footer { gap: 7px; flex-wrap: wrap; border-top: 1px solid #f1f1f6; min-height: 46px; padding: 13px 0; margin-top: auto; color: #89899c; font-size: 10px; }
.metric-footer > i { font-size: 11px; }
.metric-tag { display: inline-flex; align-items: center; gap: 5px; color: #8c839d; background: #f5f3f9; padding: 3px 6px; border-radius: 4px; font-size: 9px; }
.freshness-row { flex-wrap: wrap; column-gap: 20px; row-gap: 6px; padding: 14px 2px 23px; color: #9696a6; font-size: 10px; }
.freshness-row > span { display: inline-flex; align-items: center; gap: 6px; }
.freshness-row strong { font-weight: 500; color: #858598; }
.timezone-label { margin-left: auto; }
.ledger-heading { justify-content: space-between; gap: 12px; padding: 24px 24px 20px; }
.ledger-heading h2 { display: flex; align-items: center; gap: 9px; }
.count-badge { display: inline-grid; place-items: center; padding: 3px 7px; border: 1px solid #ece8f6; color: #8a72b8; background: #f7f5fb; border-radius: 6px; font-size: 10px; font-weight: 600; letter-spacing: 0; }
.ledger-source { display: flex; align-items: center; gap: 5px; color: #9393a4; font-size: 10px; white-space: nowrap; }
.ledger-tabs { display: flex; gap: 26px; padding: 0 24px; border-bottom: 1px solid var(--np-border); overflow-x: auto; }
.ledger-tab { display: inline-flex; gap: 8px; align-items: center; white-space: nowrap; background: none; border: 0; border-bottom: 2px solid transparent; padding: 0 0 14px; font-size: 12px; color: #8b8b9b; font-weight: 500; }
.ledger-tab i { font-size: 13px; }
.ledger-tab.active { color: var(--np-purple); border-bottom-color: var(--np-purple); font-weight: 600; }
.ledger-tab:hover { color: var(--np-purple); }
.period-toolbar { justify-content: space-between; gap: 14px; flex-wrap: wrap; padding: 18px 24px; border-bottom: 1px solid #f2f2f7; }
.period-switch { display: flex; padding: 3px; gap: 2px; border: 1px solid #eeeef4; background: #f7f7fa; border-radius: 9px; }
.period-switch button { display: inline-flex; gap: 6px; align-items: center; justify-content: center; background: none; border: 1px solid transparent; color: #8b8b9e; border-radius: 6px; font-size: 11px; padding: 6px 12px; white-space: nowrap; }
.period-switch button.active { background: #fff; border-color: #ece9f4; color: #6951a2; font-weight: 600; box-shadow: 0 1px 4px #29213f08; }
.period-switch button:hover { color: var(--np-purple); }
.period-caption { color: #9696a6; font-size: 11px; }
.custom-dates { display: flex; align-items: flex-end; gap: 10px; flex-basis: 100%; }
.custom-dates label { color: #9696a6; font-size: 10px; }
.custom-dates input { width: 160px; margin-top: 5px; }
.custom-dates > span { padding-bottom: 10px; color: #a0a0b0; }
.operations-toolbar { gap: 12px; padding: 18px 24px; }
.search-field { flex: 1; max-width: 460px; position: relative; }
.search-field > i { position: absolute; left: 13px; top: 12px; color: #a3a3b2; font-size: 13px; }
.novapay-page .search-field input { padding-left: 36px; padding-right: 34px; background: #fcfcfe; border-color: #eaeaf2; }
.novapay-page input::placeholder, .novapay-page textarea::placeholder { color: #ababba; }
.search-clear { position: absolute; right: 7px; top: 6px; display: grid; place-items: center; width: 28px; height: 28px; color: #9999aa; border: 0; background: transparent; }
.novapay-page .direction-select { width: 165px; margin-left: auto; color: #737386; }
.data-context { margin: 0 24px 16px; display: flex; gap: 8px; align-items: flex-start; color: #89849b; background: #f8f6fc; border-radius: 8px; font-size: 11px; padding: 10px 12px; line-height: 1.6; }
.data-context > i { padding-top: 1px; }
.operations-table, .sync-table { margin-bottom: 0; font-size: 12px; }
.operations-table th, .sync-table th { padding: 12px 16px; background: #fafafd; color: #77778b; font-size: 11px; font-weight: 500; border-top: 1px solid #f1f1f6; border-bottom: 1px solid #efeff5; white-space: nowrap; }
.operations-table th:first-child, .operations-table td:first-child, .sync-table th:first-child, .sync-table td:first-child { padding-left: 24px; }
.operations-table th:last-child, .operations-table td:last-child, .sync-table th:last-child, .sync-table td:last-child { padding-right: 24px; }
.operations-table td, .sync-table td { padding: 20px 16px; border-bottom: 1px solid #f1f1f6; color: #77778c; }
.operations-table tr:last-child td { border-bottom: 0; }
.operations-table tbody tr:hover td { background-color: #fdfcfe; }
.operation-date { color: #85859a; white-space: nowrap; font-variant-numeric: tabular-nums; }
.counterparty-cell { display: flex; align-items: center; gap: 9px; }
.counterparty-avatar { display: grid; place-items: center; width: 30px; height: 30px; flex-shrink: 0; background: #f4f0fc; border: 1px solid #eee8f9; border-radius: 9px; color: #987fbd; font-size: 11px; font-weight: 600; }
.counterparty-cell strong { color: #595970; font-size: 12px; font-weight: 600; line-height: 1.7; max-width: 250px; overflow-wrap: anywhere; }
.operation-purpose { min-width: 150px; max-width: 330px; line-height: 1.8; overflow-wrap: anywhere; }
.operations-table td.operation-amount { color: #646477; font-weight: 650; font-size: 12px; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.operations-table td.operation-amount--in { color: #368367; }
.operation-direction { white-space: nowrap; font-size: 11px; }
.operation-direction i { margin-right: 5px; color: #a0a0af; }
.status-chip { display: inline-flex; align-items: center; gap: 5px; color: #92909d; background: #f4f4f7; border: 1px solid #eeeef3; padding: 4px 7px; border-radius: 5px; font-size: 10px; white-space: nowrap; }
.status-chip--posted { background: #f0f8f3; color: #448063; border-color: #e3f0e8; }
.status-chip--pending { background: #fff8ec; color: #b58c45; border-color: #f7edda; }
.status-chip--failed { background: #fff1f1; color: #b45d64; border-color: #f6e1e4; }
.status-chip .state-dot { width: 4px; height: 4px; }
.ledger-footer { justify-content: space-between; gap: 12px; padding: 15px 24px; border-top: 1px solid #efeff5; font-size: 10px; color: #a0a0b0; }
.pagination-controls { gap: 12px; font-variant-numeric: tabular-nums; color: #686880; }
.icon-button { display: inline-grid; place-items: center; background: #fff; color: #9999aa; border: 1px solid #e9e9f1; border-radius: 7px; width: 30px; height: 30px; font-size: 11px; }
.icon-button:hover { color: var(--np-purple); background: #f6f3fc; }
.icon-button:disabled { opacity: .4; cursor: default; }
.footer-note { display: flex; align-items: center; gap: 6px; }
.empty-state { display: flex; flex-direction: column; align-items: center; padding: 48px 20px 52px; text-align: center; }
.empty-state-icon { display: grid; place-items: center; width: 56px; height: 56px; border-radius: 17px; color: #ad9bce; background: #f5f1fc; border: 1px solid #eee7f9; font-size: 25px; margin-bottom: 18px; }
.empty-state h3 { font-size: 15px; font-weight: 600; color: #66667d; margin: 0 0 9px; }
.empty-state p { max-width: 420px; line-height: 1.9; color: #9898a9; font-size: 12px; margin: 0 0 16px; }
.empty-state-tag { font-size: 10px; color: #a28ac7; margin-bottom: 10px; }
.payments-empty { padding: 55px 20px; }
.payments-note { display: flex; gap: 7px; align-items: center; color: #9690a7; background: #f8f6fb; padding: 9px 12px; border-radius: 7px; font-size: 10px; }
.sync-diagnostic { max-width: 330px; line-height: 1.8; }
.page-footnote { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 7px; font-size: 10px; color: #adadbb; margin-top: 22px; }
.page-footnote > span { margin: 0 4px; }
.spinning { animation: np-spin 1.5s linear infinite; }
@keyframes np-spin { to { transform: rotate(360deg); } }
@media (min-width: 1500px) { .novapay-page { padding-top: 14px; } }
@media (max-width: 1100px) { .summary-grid { grid-template-columns: 1.1fr 1fr 1fr; gap: 12px; } .balance-card, .metric-card { padding: 18px 17px 0; } .metric-heading { font-size: 11px; } .account-actions { gap: 12px; } .heading-actions .action-btn { padding: 9px 11px; } }
@media (max-width: 900px) { .page-heading { flex-wrap: wrap; gap: 18px; } .heading-actions { margin-left: auto; } .account-bar { flex-wrap: wrap; gap: 12px; } .account-identity { flex-basis: 100%; } .account-actions { width: 100%; justify-content: space-between; } .summary-grid { grid-template-columns: 1fr 1fr; } .balance-card { grid-column: 1 / -1; } .balance-value { font-size: 34px; } .balance-footer { margin-top: 18px; } .metric-card { min-height: 196px; } .metric-value { font-size: 28px; } }
@media (max-width: 575px) {
    .novapay-page { padding: 0 0 12px; }
    .brand-icon { width: 44px; height: 44px; border-radius: 12px; font-size: 20px; }
    .heading-identity { gap: 11px; }
    .page-heading { margin-bottom: 20px; }
    .page-heading h1 { font-size: 23px; }
    .heading-actions { width: 100%; gap: 7px; margin: 0; }
    .heading-actions .action-btn { flex: 1; min-width: 0; padding: 9px 8px; font-size: 10px; gap: 5px; }
    .heading-actions .action-btn:first-child { flex: 0; }
    .heading-actions .action-btn:first-child span { display: none; }
    .account-bar { padding: 12px; }
    .summary-grid { gap: 10px; }
    .metric-card { padding: 15px 13px 0; min-height: 190px; }
    .metric-heading { align-items: flex-start; font-size: 10px; line-height: 1.6; }
    .metric-icon { width: 24px; height: 24px; font-size: 12px; border-radius: 7px; }
    .metric-value { font-size: 23px; letter-spacing: -.7px; }
    .metric-description { font-size: 10px; }
    .metric-footer { min-height: 47px; font-size: 9px; }
    .metric-tag { font-size: 8px; line-height: 1.5; }
    .balance-card { padding: 18px 20px 0; }
    .balance-card .metric-heading { align-items: center; font-size: 12px; }
    .balance-value { font-size: 33px; }
    .freshness-row { font-size: 9px; column-gap: 12px; padding-bottom: 20px; }
    .timezone-label { margin-left: 0; }
    .ledger-heading, .panel-heading { padding: 20px 16px 17px; }
    .ledger-heading h2 { font-size: 15px; }
    .ledger-heading p { font-size: 10px; }
    .ledger-source { display: none; }
    .ledger-tabs { gap: 20px; padding: 0 16px; }
    .ledger-tab { gap: 6px; font-size: 10px; }
    .period-toolbar { padding: 15px 16px; gap: 10px; }
    .period-switch { width: 100%; }
    .period-switch button { flex: 1; font-size: 9px; padding: 7px 5px; }
    .period-caption { font-size: 10px; }
    .custom-dates { gap: 8px; }
    .custom-dates label { min-width: 0; flex: 1; }
    .custom-dates input { width: 100%; min-width: 0; }
    .operations-toolbar { padding: 14px 16px; flex-wrap: wrap; gap: 10px; }
    .search-field { flex-basis: 100%; max-width: none; }
    .novapay-page .direction-select { width: 100%; margin: 0; }
    .data-context { margin: 0 16px 14px; font-size: 10px; }
    .operations-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
    .operations-table, .operations-table tbody { display: block; }
    .operations-table tbody tr { display: grid; grid-template-columns: 1fr auto; grid-template-areas: 'date status' 'counterparty amount' 'purpose purpose' 'direction direction'; gap: 10px 8px; padding: 18px 16px; border-bottom: 1px solid var(--np-border); }
    .operations-table td, .operations-table td:first-child, .operations-table td:last-child { display: block; border: 0; padding: 0; }
    .operation-date { grid-area: date; font-size: 10px; }
    .operation-counterparty { grid-area: counterparty; min-width: 0; }
    .operation-purpose { grid-area: purpose; max-width: none; min-width: 0; font-size: 10px; }
    .operation-amount { grid-area: amount; align-self: center; }
    .operations-table td.operation-amount { font-size: 11px; }
    .operation-direction { grid-area: direction; font-size: 9px; }
    .operation-status { grid-area: status; justify-self: end; }
    .counterparty-cell { gap: 7px; }
    .counterparty-cell strong { font-size: 10px; }
    .counterparty-avatar { width: 26px; height: 26px; border-radius: 7px; }
    .ledger-footer { padding: 14px 16px; }
    .footer-note { font-size: 9px; }
    .connection-content { padding: 16px; }
    .empty-state { padding: 36px 18px; }
    .empty-state p { font-size: 11px; }
    .payments-note { align-items: flex-start; text-align: left; font-size: 9px; }
    .page-footnote { font-size: 9px; gap: 5px; }
}
@media (prefers-reduced-motion: reduce) { .spinning { animation: none; } .action-btn { transition: none; } }
</style>
