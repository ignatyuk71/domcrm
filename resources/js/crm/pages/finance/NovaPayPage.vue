<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import http from '@/crm/api/http';
import Toast from '@/crm/components/ui/Toast.vue';
import { useToast } from '@/crm/composables/useToast';
import { money, period } from './novapayUtils';

const { toast, showToast, closeToast, runAction, runSecondary } = useToast();
const query = new URLSearchParams(window.location.search);
const initial = period('month');
const filters = reactive({
    account_id: query.get('account_id') || '', from: query.get('from') || initial.from,
    to: query.get('to') || initial.to, direction: query.get('direction') || 'all', search: query.get('search') || '',
    page: Number(query.get('page')) || 1, payout_page: Number(query.get('payout_page')) || 1,
});
const data = ref(null);
const loading = ref(true);
const busy = ref('');
const tab = ref('operations');
const settingsOpen = ref(false);
const settingsDialog = ref(null);
const editingConnection = ref(false);
const customPeriodOpen = ref(false);
const connection = reactive({ connection_id: '', login: '', refresh_token: '', public_certificate: '' });
const errors = ref({});
const pendingIds = ref([]);
const observedFailures = new Set();
const account = computed(() => data.value?.accounts.find(item => item.id === data.value.account_id));
const pending = computed(() => data.value?.sync.some(run => ['queued', 'running', 'retrying'].includes(run.status)) || false);
const now = ref(Date.now());
const lastBalanceRun = computed(() => data.value?.sync.find(run => run.source === 'balance'));
const scheduledRefreshOverdue = computed(() => {
    const schedule = data.value?.refresh_schedule;
    if (!schedule?.previous_at || !data.value?.balance?.received_at) return false;
    const due = new Date(schedule.previous_at).getTime();
    // Між сеансами останній баланс лишається актуальним; даємо черзі час завершити оновлення.
    return now.value > due + schedule.grace_minutes * 60000
        && new Date(data.value.balance.received_at).getTime() < due;
});
const balanceStale = computed(() => Boolean(data.value?.balance && (
    account.value?.requires_auth || !account.value?.enabled || lastBalanceRun.value?.status === 'failed'
    || scheduledRefreshOverdue.value
)));
const hasDraft = computed(() => Boolean(connection.login || connection.refresh_token || connection.public_certificate));
const selectedConnection = computed(() => data.value?.connections.find(item => String(item.id) === String(connection.connection_id)));
const hasSavedCredentials = computed(() => Boolean(selectedConnection.value?.has_refresh_token && selectedConnection.value?.has_public_certificate));
const showConnectionEditor = computed(() => !selectedConnection.value || !hasSavedCredentials.value || editingConnection.value || hasDraft.value);
const canSaveConnection = computed(() => Boolean(showConnectionEditor.value && connection.refresh_token.trim()
    && connection.public_certificate.trim() && (connection.connection_id || connection.login.trim())));
const timestamp = value => value ? new Intl.DateTimeFormat('uk-UA', {
    timeZone: account.value?.timezone || 'Europe/Kyiv', dateStyle: 'short', timeStyle: 'short',
}).format(new Date(value)) : 'Ще не отримано';
const calendar = value => value ? value.split('-').reverse().join('.') : '—';
const statusTime = value => value ? new Intl.DateTimeFormat('uk-UA', {
    timeZone: account.value?.timezone || 'Europe/Kyiv', day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
}).format(new Date(value)).replace(',', '') : '';
const statusLabels = { posted: 'Проведено', pending: 'Очікує підтвердження', cancelled: 'Скасовано' };
const runLabels = { queued: 'У черзі', running: 'Оновлюється', retrying: 'Повторна спроба', success: 'Завершено', failed: 'Дані не оновлено' };
const sourceLabels = { balance: 'Баланс', operations: 'Операції', payouts: 'Реєстр післяплати', discovery: 'Підключення' };
const periods = [['today', 'Сьогодні'], ['yesterday', 'Вчора'], ['week', '7 днів'], ['month', 'Місяць']];
const selectedPeriod = computed(() => periods.find(([key]) => {
    const range = period(key, account.value?.timezone);
    return range.from === filters.from && range.to === filters.to;
})?.[0] || 'custom');
const accountState = computed(() => account.value?.requires_auth ? 'Потрібна авторизація'
    : pending.value ? 'Оновлюється' : !account.value?.enabled ? 'Оновлення на паузі'
    : !data.value?.balance ? 'Очікуємо дані' : balanceStale.value ? 'Дані не оновлено' : 'Дані актуальні');
const latestOperationError = computed(() => data.value?.sync.find(run => run.source === 'operations')?.status === 'failed');
const operationAmount = value => money(value).replace(/ грн$/, ' UAH');
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
            // Успішний повтор того самого періоду не повинен знову показувати стару помилку.
            if (data.value.sync.some(newer => newer.id > run.id && newer.status === 'success'
                && newer.source === run.source && newer.date_from === run.date_from && newer.date_to === run.date_to)) continue;
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
        acceptSync();
    } catch (error) {
        if (error.code !== 'ERR_CANCELED' && id === requestId && !disposed) fail(error);
    } finally {
        if (id === requestId) loading.value = false;
    }
}

async function perform(name, action, retry) {
    if (busy.value) return;
    busy.value = name;
    try { await action(); } catch (error) { fail(error, retry || (() => perform(name, action))); }
    finally { busy.value = ''; }
}

function openSettings(id) {
    // Повторне відкриття не стирає чернетку й не переносить її на інше підключення.
    if (!hasDraft.value) {
        connection.connection_id = id ?? account.value?.connection_id ?? data.value?.connections[0]?.id ?? '';
        editingConnection.value = false;
    }
    settingsOpen.value = true;
}

async function editConnection() {
    editingConnection.value = true;
    await nextTick();
    settingsDialog.value?.querySelector('#np-token')?.focus();
}

async function cancelReplacement() {
    connection.login = ''; connection.refresh_token = ''; connection.public_certificate = '';
    errors.value = {};
    editingConnection.value = false;
    await nextTick();
    settingsDialog.value?.querySelector('#np-replace')?.focus();
}

function closeSettings() {
    if (!busy.value) settingsOpen.value = false;
}

let settingsFocus, settingsOverflow;
function restoreSettingsPage() {
    if (settingsOverflow !== undefined) document.body.style.overflow = settingsOverflow;
    if (settingsFocus?.isConnected) settingsFocus.focus();
    settingsOverflow = undefined; settingsFocus = null;
}
watch(settingsOpen, async open => {
    await nextTick();
    const dialog = settingsDialog.value;
    if (!dialog || disposed || open !== settingsOpen.value) return;
    if (open && !dialog.open) {
        settingsFocus = document.activeElement;
        settingsOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        dialog.showModal();
        (dialog.querySelector('.is-invalid') || dialog.querySelector('select, input') || dialog).focus();
    } else if (!open) {
        dialog.close();
        restoreSettingsPage();
    }
});

function saveConnection() {
    // Маска збережених даних не є значенням форми; надсилаємо лише введену нову пару.
    if (!canSaveConnection.value) return;
    const id = String(connection.connection_id);
    return perform('connect', async () => {
        const response = await http.post('/finance/novapay/connections', connection);
        errors.value = {};
        connection.connection_id = response.data.connection_id;
        connection.login = ''; connection.refresh_token = ''; connection.public_certificate = '';
        editingConnection.value = false;
        settingsOpen.value = false;
        if (response.data.run_id) pendingIds.value = [response.data.run_id];
        success('Параметри збережено. Перевірка підключення виконується у фоні.');
        await load(true);
    }, () => { if (String(connection.connection_id) === id) saveConnection(); });
}

function discover() {
    const id = connection.connection_id;
    if (!id || selectedConnection.value?.requires_auth || !hasSavedCredentials.value || hasDraft.value) return;
    return perform('discover', async () => {
        const response = await http.post(`/finance/novapay/connections/${id}/discover`);
        pendingIds.value = response.data.run_id ? [response.data.run_id] : [];
        success(response.data.run_id ? 'Перевірку підключення поставлено у чергу.' : 'Перевірка вже виконується.');
        await load(true);
    }, () => { if (String(connection.connection_id) === String(id)) discover(); });
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
watch(() => String(connection.connection_id), () => { editingConnection.value = false; errors.value = {}; });
watch(() => [filters.account_id, filters.from, filters.to, filters.direction], () => { filters.page = 1; filters.payout_page = 1; applyFilters(); });
watch(() => filters.page, applyFilters);
watch(() => filters.payout_page, applyFilters);
watch(() => filters.search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { filters.page = 1; applyFilters(); }, 350); });
onMounted(() => {
    load();
    window.addEventListener('beforeunload', beforeUnload);
    interval = setInterval(() => { now.value = Date.now(); if (!busy.value && !document.hidden) load(true); }, 15000);
});
onBeforeUnmount(() => { disposed = true; settingsDialog.value?.close(); restoreSettingsPage(); controller?.abort(); clearInterval(interval); clearTimeout(searchTimer); window.removeEventListener('beforeunload', beforeUnload); });
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
                <button class="btn action-btn action-btn--quiet" type="button" aria-haspopup="dialog" aria-controls="np-settings" @click="openSettings()"><i class="bi bi-sliders" aria-hidden="true"></i><span>Підключення</span></button>
                <button class="btn action-btn action-btn--primary" type="button" :disabled="Boolean(busy) || pending || !account?.enabled || account?.requires_auth" @click="refresh"><i class="bi bi-arrow-repeat" :class="{ spinning: pending }" aria-hidden="true"></i><span>{{ pending ? 'Оновлюється…' : 'Оновити дані' }}</span></button>
            </div>
        </header>

        <dialog ref="settingsDialog" id="np-settings" class="connection-panel connection-dialog" aria-labelledby="np-settings-title" aria-describedby="np-settings-description" aria-modal="true" :aria-busy="Boolean(busy)" @cancel.prevent="closeSettings" @click.self="closeSettings">
            <div class="panel-heading">
                <div class="panel-heading-copy"><span class="section-icon"><i class="bi bi-link-45deg" aria-hidden="true"></i></span><div><h2 id="np-settings-title">Підключення бізнес-рахунку</h2><p id="np-settings-description">Безпечний доступ до балансу та виписки.</p></div></div>
                <button type="button" class="icon-button" aria-label="Закрити підключення" :disabled="Boolean(busy)" @click="closeSettings"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="connection-content">
                <p v-if="showConnectionEditor" class="setup-help">У бізнес-кабінеті NovaPay відкрийте <strong>Система → Налаштування → API</strong>, увімкніть доступ для підприємства та згенеруйте параметри підключення.</p>
                <p v-else class="setup-help">Параметри вже збережені та використовуються для оновлення даних. Повторно вводити їх не потрібно.</p>
                <form @submit.prevent="saveConnection">
                    <fieldset :disabled="Boolean(busy)">
                        <div class="row g-3">
                            <div v-if="data?.connections.length" :class="connection.connection_id ? 'col-12' : 'col-md-6'"><label for="np-connection" class="form-label">Підключення</label><select id="np-connection" v-model="connection.connection_id" class="form-select" :disabled="hasDraft"><option value="">Нове підключення</option><option v-for="item in data.connections" :key="item.id" :value="item.id">Підключення №{{ item.id }}{{ item.requires_auth ? ' — відновити доступ' : '' }}</option></select></div>
                            <div v-if="selectedConnection" class="col-12" data-testid="saved-credentials">
                                <dl class="saved-credentials">
                                    <div class="saved-credential" data-testid="saved-refresh-token"><dt><i class="bi bi-key" aria-hidden="true"></i>Refresh Token<span>{{ selectedConnection.has_refresh_token ? 'Збережено' : 'Не збережено' }}</span></dt><dd><span v-if="selectedConnection.has_refresh_token" class="credential-mask" aria-hidden="true">•••• •••• •••• ••••</span><span v-else>Токен відсутній</span><small>{{ selectedConnection.has_refresh_token ? 'Значення приховано' : 'Додайте параметри нижче' }}</small></dd></div>
                                    <div class="saved-credential" data-testid="saved-certificate"><dt><i class="bi bi-file-earmark-lock" aria-hidden="true"></i>Сертифікат<span>{{ selectedConnection.has_public_certificate ? 'Збережено' : 'Не збережено' }}</span></dt><dd><span>{{ selectedConnection.has_public_certificate ? 'У захищеному сховищі' : 'Сертифікат відсутній' }}</span><small>{{ selectedConnection.has_public_certificate ? 'Використовується для доступу' : 'Додайте параметри нижче' }}</small></dd></div>
                                </dl>
                                <p class="credential-check"><i class="bi bi-shield-lock" aria-hidden="true"></i>{{ selectedConnection.requires_auth ? 'Доступ потребує перевірки. Перегляньте діагностику синхронізації.' : selectedConnection.verified_at ? `Остання успішна перевірка: ${timestamp(selectedConnection.verified_at)}` : 'Успішну перевірку підключення ще не підтверджено.' }}</p>
                            </div>
                            <div v-if="!connection.connection_id" :class="data?.connections.length ? 'col-md-6' : 'col-12'"><label for="np-login" class="form-label">Логін NovaPay</label><input id="np-login" v-model.trim="connection.login" class="form-control" :class="{ 'is-invalid': errors.login }" :aria-invalid="Boolean(errors.login)" autocomplete="off" placeholder="Логін бізнес-кабінету" required></div>
                            <template v-if="showConnectionEditor">
                                <div class="col-12"><label for="np-token" class="form-label">Новий Refresh Token</label><input id="np-token" v-model.trim="connection.refresh_token" class="form-control" :class="{ 'is-invalid': errors.refresh_token }" :aria-invalid="Boolean(errors.refresh_token)" type="password" autocomplete="new-password" placeholder="Вставте новий згенерований токен" required></div>
                                <div class="col-12"><label for="np-certificate" class="form-label">Новий відкритий сертифікат</label><textarea id="np-certificate" v-model.trim="connection.public_certificate" class="form-control certificate-field" :class="{ 'is-invalid': errors.public_certificate }" :aria-invalid="Boolean(errors.public_certificate)" rows="5" spellcheck="false" autocomplete="off" placeholder="Вставте новий сертифікат із NovaPay" required></textarea></div>
                            </template>
                        </div>
                        <div class="connection-footer"><div class="d-flex flex-wrap gap-2"><button class="btn action-btn action-btn--quiet" type="button" @click="closeSettings">Закрити</button><button v-if="showConnectionEditor" class="btn action-btn action-btn--primary" type="submit" :disabled="!canSaveConnection"><i class="bi bi-link-45deg" aria-hidden="true"></i>{{ busy === 'connect' ? 'Зберігаємо…' : selectedConnection ? 'Зберегти нові параметри' : 'Зберегти й перевірити' }}</button><button v-else class="btn action-btn action-btn--primary" type="button" :disabled="selectedConnection.requires_auth" @click="discover"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Перевірити підключення</button><button v-if="selectedConnection && !showConnectionEditor" id="np-replace" class="btn action-btn action-btn--quiet" type="button" @click="editConnection">Замінити параметри</button><button v-else-if="selectedConnection && hasSavedCredentials" class="btn action-btn action-btn--quiet" type="button" @click="cancelReplacement">Скасувати заміну</button></div><span class="secure-note"><i class="bi bi-shield-lock" aria-hidden="true"></i>{{ selectedConnection ? 'Збережені значення приховано' : 'Токен шифрується під час збереження' }}</span></div>
                    </fieldset>
                </form>
            </div>
        </dialog>

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
                    <div class="metric-value" data-testid="paid-orders">{{ data.payouts?.paid_orders_count ?? (data.payouts?.confirmed_paid_orders_count || '—') }}</div>
                    <div class="metric-description">{{ data.payouts?.pending_incoming_count ? 'Деталізація надходжень ще не повна' : 'Замовлення з виплатою, звіреною з надходженням' }}</div>
                    <div class="metric-footer"><button class="btn btn-sm action-btn--quiet" type="button" @click="tab = 'payments'">Переглянути замовлення <i class="bi bi-arrow-right" aria-hidden="true"></i></button></div>
                </section>
            </div>

            <div class="freshness-row"><span><i class="bi bi-clock-history" aria-hidden="true"></i>Баланс: <strong>{{ timestamp(data.balance?.received_at) }}</strong></span><span>Операції: <strong>{{ timestamp(data.operations_updated_at) }}</strong></span><span v-if="data.refresh_schedule" data-testid="refresh-schedule" :title="`Наступне планове оновлення: ${timestamp(data.refresh_schedule.next_at)}`"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Автооновлення: {{ data.refresh_schedule.times.join(', ') }} · також вручну</span><span class="timezone-label"><i class="bi bi-globe2" aria-hidden="true"></i>Час за Києвом</span></div>

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
                    <div v-if="data.operations?.data.length" class="table-responsive payment-list-scroll">
                        <table class="table operations-table payment-list align-middle">
                            <thead><tr><th scope="col">Документ</th><th scope="col">Дата</th><th scope="col">Відправник</th><th scope="col">Сума</th><th scope="col">Отримувач</th><th scope="col">Статус</th></tr></thead>
                            <tbody>
                                <tr v-for="operation in data.operations.data" :key="operation.id">
                                    <td class="payment-document">
                                        <strong>{{ operation.document_number || '—' }}</strong>
                                        <details v-if="operation.purpose" class="payment-purpose"><summary>Призначення</summary><p>{{ operation.purpose }}</p></details>
                                    </td>
                                    <td class="payment-date">{{ calendar(operation.booked_on) }}</td>
                                    <td class="payment-party payment-sender">
                                        <span class="party-label">Відправник</span>
                                        <strong :title="operation.sender?.name || ''">{{ operation.sender?.name || 'Не зазначено' }}</strong>
                                        <span v-if="operation.sender?.iban" class="party-account">{{ operation.sender.iban }}</span>
                                        <span v-if="operation.sender?.tax_code" class="party-tax" title="РНОКПП / ЄДРПОУ">{{ operation.sender.tax_code }}</span>
                                    </td>
                                    <td class="payment-amount" :class="operation.direction === 'in' ? 'payment-amount--in' : 'payment-amount--out'" :aria-label="`${operation.direction === 'in' ? 'Надходження' : 'Списання'}: ${operationAmount(operation.amount_minor)}`">
                                        <span class="payment-sum" :title="operation.direction === 'in' ? 'Надходження' : 'Списання'"><i class="bi" :class="operation.direction === 'in' ? 'bi-arrow-down-left' : 'bi-arrow-up-right'" aria-hidden="true"></i>{{ operationAmount(operation.amount_minor) }}</span>
                                    </td>
                                    <td class="payment-party payment-recipient">
                                        <span class="party-label">Отримувач</span>
                                        <strong :title="operation.recipient?.name || ''">{{ operation.recipient?.name || 'Не зазначено' }}</strong>
                                        <span v-if="operation.recipient?.iban" class="party-account">{{ operation.recipient.iban }}</span>
                                        <span v-if="operation.recipient?.tax_code" class="party-tax" title="РНОКПП / ЄДРПОУ">{{ operation.recipient.tax_code }}</span>
                                    </td>
                                    <td class="payment-status">
                                        <span class="status-chip" :class="`status-chip--${operation.status}`"><i v-if="operation.status === 'posted'" class="bi bi-check-circle" aria-hidden="true"></i>{{ statusLabels[operation.status] || 'Потрібна перевірка' }}</span>
                                        <time v-if="operation.status_changed_at" :datetime="operation.status_changed_at" title="Час останньої зміни статусу в NovaPay">{{ statusTime(operation.status_changed_at) }}</time>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="empty-state"><span class="empty-state-icon"><i class="bi" :class="!account ? 'bi-bank' : filters.search ? 'bi-search' : 'bi-inbox'" aria-hidden="true"></i></span><h3>{{ !account ? 'Підключіть свій рахунок' : filters.search ? 'За цим пошуком нічого не знайдено' : !data.coverage_complete ? 'Очікуємо операції' : 'Операцій поки немає' }}</h3><p>{{ !account ? 'Баланс і рух коштів будуть доступні після підключення NovaPay.' : !data.coverage_complete ? 'Записи ще не отримані повністю. Відсутність рядків не підтверджує відсутність оплат.' : 'Спробуйте інший період або змініть фільтри.' }}</p><button v-if="!account" class="btn action-btn action-btn--primary" @click="openSettings()">Підключити NovaPay<i class="bi bi-arrow-right" aria-hidden="true"></i></button><button v-else-if="filters.search" class="text-action" @click="filters.search = ''">Очистити пошук<i class="bi bi-arrow-right" aria-hidden="true"></i></button></div>
                    <div class="ledger-footer"><span>{{ recordCount(data.operations?.total || 0) }}<span v-if="data.operations?.total"> · по 25 на сторінці</span></span><div v-if="data.operations?.last_page > 1" class="pagination-controls"><button class="icon-button" aria-label="Попередня сторінка" :disabled="filters.page <= 1 || loading" @click="filters.page--"><i class="bi bi-chevron-left" aria-hidden="true"></i></button><span>{{ data.operations.current_page }} <span class="text-secondary">/ {{ data.operations.last_page }}</span></span><button class="icon-button" aria-label="Наступна сторінка" :disabled="filters.page >= data.operations.last_page || loading" @click="filters.page++"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></div><span v-else class="footer-note"><i class="bi bi-shield-check" aria-hidden="true"></i>Лише перегляд операцій</span></div>
                </div>
                <div v-else-if="tab === 'payments'" id="np-panel-payments" class="ledger-content" role="tabpanel" aria-labelledby="np-tab-payments">
                    <div class="data-context"><i class="bi bi-info-circle" aria-hidden="true"></i><span>Зіставлення за точною ТТН. Виплату підтверджуємо після звірки реєстру з банківським надходженням. Реєстр може надійти пізніше за гроші.</span></div>
                    <div v-if="data.payouts?.pending_incoming_count" class="data-context" data-testid="payout-pending"><i class="bi bi-clock-history" aria-hidden="true"></i><span>Надходження NovaPay на <strong>{{ money(data.payouts.pending_incoming_minor) }}</strong> ще не зіставлені з реєстром. Розподіл за замовленнями з’явиться після отримання деталізації та звірки. У підсумку враховані лише підтверджені замовлення.</span></div>
                    <div class="payout-summary"><span>За замовленнями зараховано: <strong>{{ money(data.payouts?.matched_net_minor) }}</strong></span><span>Без замовлення: {{ data.payouts?.unmatched || 0 }} · Неоднозначних: {{ data.payouts?.ambiguous || 0 }} · Очікують звірки: {{ data.payouts?.unconfirmed || 0 }}</span></div>
                    <div v-if="data.payouts?.rows?.data.length" class="table-responsive"><table class="table operations-table align-middle"><thead><tr><th>Дата виплати</th><th>Замовлення / ТТН</th><th>Сплатив покупець</th><th>Комісія</th><th>Зараховано</th><th>Стан</th></tr></thead><tbody>
                        <tr v-for="payout in data.payouts.rows.data" :key="payout.id"><td>{{ calendar(payout.paid_on) }}<small class="d-block text-secondary">{{ payout.register_number }}</small></td><td><a v-if="payout.order_url" :href="payout.order_url">№ {{ payout.order_number || payout.order_id }}</a><span v-else>{{ payout.match_status === 'ambiguous' ? 'Кілька замовлень із цією ТТН' : 'Замовлення не знайдено' }}</span><small class="d-block text-secondary">{{ payout.ttn }}</small></td><td>{{ money(payout.gross_minor) }}</td><td>{{ money(payout.fee_minor) }}</td><td>{{ money(payout.net_minor) }}</td><td><span class="status-chip" :class="payout.confirmed ? 'status-chip--posted' : 'status-chip--pending'">{{ payout.confirmed ? 'Зараховано' : 'Очікує звірки' }}</span></td></tr>
                    </tbody></table></div>
                    <div v-else class="empty-state"><span class="empty-state-icon"><i class="bi bi-receipt" aria-hidden="true"></i></span><h3>{{ data.payouts?.pending_incoming_count ? 'Очікуємо деталізацію надходжень' : data.payouts?.coverage_complete ? 'Виплат за цей період немає' : 'Очікуємо реєстр післяплати' }}</h3><p>Реєстр оновлюється автоматично. Невідомі дані не враховуються як нуль.</p></div>
                    <div class="ledger-footer"><span>Реєстр: {{ timestamp(data.payouts?.updated_at) }}<span v-if="!data.payouts?.coverage_complete"> · Період ще не завантажено повністю</span></span><div v-if="data.payouts?.rows?.last_page > 1" class="pagination-controls"><button class="btn page-btn" aria-label="Попередня сторінка виплат" :disabled="data.payouts.rows.current_page <= 1 || loading" @click="filters.payout_page--"><i class="bi bi-chevron-left" aria-hidden="true"></i></button><span>{{ data.payouts.rows.current_page }} / {{ data.payouts.rows.last_page }}</span><button class="btn page-btn" aria-label="Наступна сторінка виплат" :disabled="data.payouts.rows.current_page >= data.payouts.rows.last_page || loading" @click="filters.payout_page++"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></div></div>
                </div>
                <div v-else id="np-panel-sync" class="sync-content" role="tabpanel" aria-labelledby="np-tab-sync"><div class="table-responsive"><table class="table sync-table align-middle"><thead><tr><th>Джерело</th><th>Період</th><th>Стан</th><th>Останній успіх</th><th>Діагностика</th></tr></thead><tbody><tr v-for="run in data.sync" :key="run.id"><td class="fw-semibold"><i class="bi bi-arrow-repeat me-2 text-secondary" aria-hidden="true"></i>{{ sourceLabels[run.source] }}</td><td>{{ run.date_from ? calendar(run.date_from) : '—' }}</td><td><span class="status-chip" :class="run.status === 'success' ? 'status-chip--posted' : run.status === 'failed' ? 'status-chip--failed' : 'status-chip--pending'"><span class="state-dot"></span>{{ runLabels[run.status] }}</span></td><td>{{ timestamp(run.succeeded_at) }}</td><td class="sync-diagnostic">{{ run.error_message || '—' }}</td></tr><tr v-if="!data.sync.length"><td colspan="5"><div class="empty-state"><span class="empty-state-icon"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></span><h3>Історія з’явиться після першого оновлення</h3><p>Тут можна перевірити стан отримання балансу та операцій.</p></div></td></tr></tbody></table></div></div>
            </section>
            <div class="page-footnote"><i class="bi bi-shield-lock" aria-hidden="true"></i>Захищене підключення<span>·</span>Доступ до фінансів лише для власника</div>
        </template>
        <Toast v-bind="toast" :teleport-to="settingsOpen && settingsDialog ? settingsDialog : 'body'" @close="closeToast" @action="runAction" @secondary="runSecondary" />
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
.connection-dialog { width: min(720px, calc(100vw - 32px)); max-width: none; max-height: calc(100dvh - 32px); margin: auto; padding: 0; color: var(--np-ink); overflow: auto; box-shadow: 0 24px 80px #17132938; }
.connection-dialog::backdrop { background: #15112580; backdrop-filter: blur(3px); }
.connection-dialog .panel-heading { position: sticky; top: 0; z-index: 1; background: #fff; }
.panel-heading { padding: 20px 24px; justify-content: space-between; border-bottom: 1px solid var(--np-border); }
.panel-heading-copy { gap: 12px; }
.section-icon { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 10px; color: var(--np-purple); background: #f1edfc; font-size: 20px; }
.panel-heading h2, .ledger-heading h2 { margin: 0; font-size: 16px; font-weight: 700; letter-spacing: -.35px; }
.panel-heading p, .ledger-heading p { margin: 5px 0 0; color: var(--np-muted); font-size: 12px; }
.connection-content { padding: 20px 24px 24px; }
.setup-help { font-size: 12px; color: #737386; line-height: 1.8; margin-bottom: 20px; }
.setup-help strong { color: #53536b; font-weight: 600; }
.certificate-field { font-family: ui-monospace, monospace; }
.saved-credentials { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 250px), 1fr)); gap: 12px; margin: 4px 0 12px; }
.saved-credential { min-width: 0; padding: 16px; border: 1px solid #e5e0f1; border-radius: 12px; background: #faf9fd; }
.saved-credential dt { display: flex; flex-wrap: wrap; align-items: center; gap: 7px; font-size: 12px; font-weight: 600; color: #545467; }
.saved-credential dt i { color: var(--np-purple); }
.saved-credential dt span { margin-left: auto; padding: 3px 6px; border-radius: 5px; background: #eeebf5; color: #675d7e; font-size: 10px; }
.saved-credential dd { display: flex; flex-direction: column; gap: 7px; margin: 16px 0 0; color: #353549; font-size: 13px; font-weight: 500; }
.saved-credential dd small { color: var(--np-muted); font-size: 11px; font-weight: 400; }
.credential-mask { letter-spacing: 2px; }
.credential-check { display: flex; align-items: baseline; gap: 7px; margin: 0; color: var(--np-muted); font-size: 11px; line-height: 1.6; }
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
/* Розташування платежів повторює порядок колонок бізнес-кабінету NovaPay. */
.payment-list { min-width: 1080px; table-layout: fixed; margin-bottom: 0; }
.payment-list th:nth-child(1) { width: 12%; }
.payment-list th:nth-child(2) { width: 10%; }
.payment-list th:nth-child(3), .payment-list th:nth-child(5) { width: 23%; }
.payment-list th:nth-child(4) { width: 16%; }
.payment-list th:nth-child(6) { width: 16%; }
.payment-list td { height: 106px; padding-top: 19px; padding-bottom: 19px; border-color: #e7edf3; vertical-align: middle; }
.payment-document strong, .payment-list .payment-date { color: #434b50; font-size: 12px; font-weight: 600; font-variant-numeric: tabular-nums; }
.payment-document strong { display: block; overflow-wrap: anywhere; }
.payment-date { white-space: nowrap; }
.payment-purpose { margin-top: 6px; color: #9099a4; font-size: 10px; }
.payment-purpose summary { cursor: pointer; width: fit-content; }
.payment-purpose summary:hover { color: var(--np-purple); }
.payment-purpose p { margin: 7px 0 0; font-size: 11px; line-height: 1.6; overflow-wrap: anywhere; }
.payment-party strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #444d51; font-size: 12px; font-weight: 600; line-height: 1.6; }
.party-account, .party-tax { display: block; color: #858f9d; font-size: 11px; line-height: 1.7; font-variant-numeric: tabular-nums; }
.party-account { white-space: nowrap; }
.party-label { display: none; }
.payment-sum { display: inline-flex; align-items: center; gap: 7px; white-space: nowrap; font-size: 13px; font-weight: 600; font-variant-numeric: tabular-nums; }
.payment-sum i { display: inline-grid; place-items: center; flex-shrink: 0; width: 21px; height: 21px; border-radius: 50%; font-size: 12px; }
.payment-amount--in .payment-sum { color: #259d88; }
.payment-amount--in .payment-sum i { background: #e0f8f1; }
.payment-amount--out .payment-sum { color: #4389db; }
.payment-amount--out .payment-sum i { background: #eaf3ff; }
.payment-status { white-space: nowrap; }
.payment-status .status-chip { gap: 6px; padding: 7px 11px; border: 0; border-radius: 30px; font-size: 11px; font-weight: 600; }
.payment-status .status-chip--posted { color: #237d70; background: #dcfbf3; }
.payment-status time { display: block; margin-top: 8px; color: #858f9d; font-size: 10px; font-weight: 500; font-variant-numeric: tabular-nums; }
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
.payout-summary { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; padding: 16px 24px; color: var(--np-muted); font-size: 12px; }
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
    .payment-list thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
    .payment-list, .payment-list tbody { display: block; min-width: 0; }
    .payment-list tbody tr { display: grid; grid-template-columns: minmax(0, 1fr) auto; grid-template-areas: 'document date' 'sender sender' 'amount amount' 'recipient recipient' 'status status'; gap: 12px 8px; padding: 18px 16px; border-bottom: 1px solid var(--np-border); }
    .payment-list td, .payment-list td:first-child, .payment-list td:last-child { display: block; height: auto; border: 0; padding: 0; min-width: 0; }
    .payment-document { grid-area: document; }
    .payment-date { grid-area: date; font-size: 11px; }
    .payment-sender { grid-area: sender; }
    .payment-recipient { grid-area: recipient; }
    .payment-amount { grid-area: amount; padding-left: 2px !important; }
    .payment-status { grid-area: status; display: flex !important; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
    .payment-status time { margin-top: 0; }
    .party-label { display: block; margin-bottom: 3px; color: #a0a7b2; font-size: 9px; }
    .payment-party strong { white-space: normal; overflow-wrap: anywhere; }
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
