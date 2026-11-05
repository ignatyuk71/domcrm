<script setup>
import { computed, reactive, ref } from 'vue';
import http from '@/crm/api/http';
import Toast from '@/crm/components/ui/Toast.vue';
import { useToast } from '@/crm/composables/useToast';

const props = defineProps({ initial: { type: Object, required: true } });
const connections = props.initial.connections;
const form = reactive({ search: 'pajama', country: 'PL', connection_id: connections[0]?.id ?? '' });
const busy = ref(false);
const errors = ref({});
const result = ref(null);
const { toast, showToast, closeToast, runAction, runSecondary } = useToast();
const selectedConnection = computed(() => connections.find(item => item.id === Number(form.connection_id)));
const libraryUrl = computed(() => `https://www.facebook.com/ads/library/?${new URLSearchParams({
    active_status: 'active', ad_type: 'all', country: form.country, q: form.search.trim(), search_type: 'keyword_unordered', media_type: 'all',
})}`);
const stateLabel = state => ({ connected: 'Доступ підтверджено', missing: 'Немає підключення',
    error: 'Доступ не підтверджено', invalid_token: 'Токен недійсний', unavailable: 'Тимчасово недоступно',
    web_only: 'Пошук на сайті Meta' }[state] || 'Ще не перевіряли');
const timestamp = value => value ? new Intl.DateTimeFormat('uk-UA', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '';
const adDate = value => value && !Number.isNaN(Date.parse(value)) ? new Intl.DateTimeFormat('uk-UA', { dateStyle: 'medium' }).format(new Date(value)) : 'Не вказано';
const failed = item => ['error', 'invalid_token', 'unavailable', 'missing'].includes(item?.state);
const diagnostics = computed(() => {
    const checkedConnection = result.value
        ? connections.find(item => item.id === result.value.query.connection_id) : selectedConnection.value;
    const tokenSource = result.value?.library.token_source
        ?? (props.initial.library_token_configured ? 'user' : selectedConnection.value ? 'page' : 'none');
    return [
        { key: 'page', title: 'Сторінка Facebook', icon: 'bi-facebook', value: result.value?.page,
            caption: result.value?.page.name || checkedConnection?.page_name || 'Сторінку не підключено' },
        { key: 'library', title: 'Meta Ad Library API', icon: 'bi-plug', value: result.value?.library,
            caption: { user: 'Окремий доступ до бібліотеки', page: 'Проба через токен сторінки Facebook', none: 'Токен ще не налаштований' }[tokenSource] },
    ];
});

async function check() {
    if (busy.value) return;
    busy.value = true;
    errors.value = {};
    try {
        const { data } = await http.post('/api/market-research/check', { ...form, connection_id: form.connection_id || null }, { timeout: 25000 });
        result.value = data;
        const problems = diagnostics.value.filter(item => failed(item.value));
        if (problems.length) {
            showToast({ title: 'Результат перевірки Meta', type: 'warning', duration: 0,
                messages: problems.map(item => `${item.title}: ${item.value.message}${item.value.detail ? ` ${item.value.detail}` : ''}`),
                actionLabel: 'Повторити', onAction: check });
        } else if (data.library.state === 'web_only' && (!toast.show || !['error', 'warning'].includes(toast.type))) {
            showToast({ title: 'Пошук для України', type: 'info', duration: 0, messages: [data.library.message] });
        } else if (!toast.show || !['error', 'warning'].includes(toast.type)) {
            // Успішна відповідь не перекриває помилку, яку користувач ще не закрив.
            showToast({ title: 'Перевірку завершено', type: 'success', messages: [data.library.message] });
        }
    } catch (error) {
        errors.value = error.response?.data?.errors || {};
        const messages = Object.values(errors.value).flat();
        showToast({ title: 'Не вдалося виконати перевірку', type: 'error', duration: 0,
            messages: messages.length ? messages : [error.response?.status === 429
                ? 'Забагато перевірок. Зачекайте хвилину та повторіть запит.'
                : error.response?.data?.message || 'Не вдалося отримати відповідь. Перевірте з’єднання та повторіть запит.'],
            actionLabel: 'Повторити', onAction: check });
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="market-page container-fluid p-3 p-lg-4" :aria-busy="busy">
        <header class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
            <div>
                <div class="market-eyebrow mb-2">МАРКЕТИНГ <span class="mx-2">/</span> META AD LIBRARY</div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h1 class="h3 fw-bold mb-0">Аналіз ринку</h1>
                    <span class="market-badge">Пробна версія</span>
                </div>
                <p class="text-secondary mt-2 mb-0">Досліджуйте рекламу товарів і конкурентів. Почніть із перевірки доступу до Meta.</p>
            </div>
            <a href="/settings/meta" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear me-1" aria-hidden="true"></i>Підключення Facebook</a>
        </header>

        <section class="card border-0 shadow-sm rounded-4 mb-3" aria-labelledby="search-heading">
            <div class="card-body p-3 p-lg-4">
                <h2 id="search-heading" class="h6 fw-bold mb-3">Що шукаємо?</h2>
                <form @submit.prevent="check">
                    <fieldset :disabled="busy">
                        <div class="row g-3">
                            <div class="col-lg-5">
                                <label for="market-search" class="form-label small fw-semibold">Товар, бренд або ключове слово</label>
                                <input id="market-search" v-model="form.search" name="search" type="search" maxlength="100" required
                                    class="form-control" :class="{ 'is-invalid': errors.search }" placeholder="Наприклад, pajama або взуття"
                                    :aria-invalid="Boolean(errors.search)" :aria-describedby="errors.search ? 'market-search-error' : undefined">
                                <div v-if="errors.search" id="market-search-error" class="invalid-feedback">{{ errors.search[0] }}</div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <label for="market-country" class="form-label small fw-semibold">Країна показу реклами</label>
                                <select id="market-country" v-model="form.country" name="country" class="form-select" :class="{ 'is-invalid': errors.country }"
                                    :aria-invalid="Boolean(errors.country)" :aria-describedby="errors.country ? 'market-country-error' : undefined">
                                    <option v-for="(label, code) in initial.countries" :key="code" :value="code">{{ label }}</option>
                                </select>
                                <div v-if="errors.country" id="market-country-error" class="invalid-feedback">{{ errors.country[0] }}</div>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="market-connection" class="form-label small fw-semibold">Підключення для перевірки</label>
                                <select id="market-connection" v-model="form.connection_id" name="connection_id" class="form-select" :class="{ 'is-invalid': errors.connection_id }"
                                    :aria-invalid="Boolean(errors.connection_id)" :aria-describedby="errors.connection_id ? 'market-connection-error' : undefined">
                                    <option value="">Без сторінки Facebook</option>
                                    <option v-for="connection in connections" :key="connection.id" :value="connection.id">{{ connection.page_name }}{{ connection.status !== 'active' ? ' · потребує перевірки' : '' }}</option>
                                </select>
                                <div v-if="errors.connection_id" id="market-connection-error" class="invalid-feedback">{{ errors.connection_id[0] }}</div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                            <button type="submit" class="btn btn-primary px-4">
                                <span v-if="busy" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                                <i v-else class="bi bi-plug me-2" aria-hidden="true"></i>{{ busy ? 'Перевіряємо Meta…' : 'Перевірити доступ і знайти рекламу' }}
                            </button>
                            <a :href="libraryUrl" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary">Відкрити в Meta <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i></a>
                        </div>
                    </fieldset>
                </form>
                <p class="small text-secondary mt-3 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Для першої перевірки API обрано Польщу. Рекламу для України можна переглянути на сайті Meta.</p>
            </div>
        </section>

        <div class="row g-3 mb-3">
            <div v-for="item in diagnostics" :key="item.key" class="col-md-6">
                <section class="card border-0 shadow-sm rounded-4 h-100" :aria-labelledby="`diagnostic-${item.key}`">
                    <div class="card-body p-3 p-lg-4">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="market-icon"><i class="bi" :class="item.icon" aria-hidden="true"></i></span>
                            <h2 :id="`diagnostic-${item.key}`" class="h6 mb-0 fw-semibold">{{ item.title }}</h2>
                            <i v-if="item.value" class="bi ms-auto text-secondary" :class="item.value.state === 'connected' ? 'bi-check-circle' : 'bi-info-circle'" aria-hidden="true"></i>
                        </div>
                        <div class="fw-semibold mb-1">{{ stateLabel(item.value?.state) }}</div>
                        <div class="small text-secondary">{{ item.caption }}</div>
                        <p v-if="item.value" class="small text-secondary mt-3 mb-0">{{ item.value.message }}</p>
                        <p v-else class="small text-secondary mt-3 mb-0">Статус буде підтверджено лише після відповіді Meta на ваш запит.</p>
                        <details v-if="item.value?.detail || item.value?.http_status" class="market-details mt-3">
                            <summary class="small">Відповідь Meta</summary>
                            <p v-if="item.value.detail" class="small mt-2 mb-1 text-break">{{ item.value.detail }}</p>
                            <span class="small text-secondary">HTTP {{ item.value.http_status }}<template v-if="item.value.error_code"> · Код {{ item.value.error_code }}</template><template v-if="item.value.error_subcode"> / {{ item.value.error_subcode }}</template></span>
                        </details>
                    </div>
                </section>
            </div>
        </div>

        <section class="card border-0 shadow-sm rounded-4 mb-3" aria-labelledby="results-heading">
            <div class="card-body p-3 p-lg-4">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                    <div>
                        <h2 id="results-heading" class="h6 fw-bold mb-1">Реклама за запитом <span v-if="result?.library.ads.length" class="market-badge ms-1">{{ result.library.ads.length }}</span></h2>
                        <p v-if="result" class="small text-secondary mb-0">«{{ result.query.search }}» · {{ initial.countries[result.query.country] }} · Перевірено {{ timestamp(result.checked_at) }}</p>
                    </div>
                    <span class="small text-secondary">До 6 оголошень у пробній вибірці</span>
                </div>
                <div v-if="result?.library.ads.length" class="row g-3">
                    <div v-for="ad in result.library.ads" :key="ad.id" class="col-md-6 col-xl-4">
                        <article class="market-ad h-100 p-3 d-flex flex-column">
                            <div class="d-flex gap-2 align-items-center mb-3">
                                <span class="market-icon"><i class="bi bi-shop" aria-hidden="true"></i></span>
                                <div class="min-width-0"><h3 class="h6 mb-1 text-break">{{ ad.page_name }}</h3><span class="small text-secondary">{{ ad.platforms.join(' · ') || 'Meta' }}</span></div>
                            </div>
                            <h4 v-if="ad.title" class="small fw-bold text-break">{{ ad.title }}</h4>
                            <p class="market-ad-body small text-secondary">{{ ad.body || 'Текст оголошення не надано. Перегляньте креатив у Meta.' }}</p>
                            <div class="small text-secondary mt-auto mb-3">Початок показів: {{ adDate(ad.started_at) }}</div>
                            <a :href="ad.url" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary btn-sm align-self-start">Переглянути оголошення <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i></a>
                        </article>
                    </div>
                </div>
                <div v-else class="market-empty text-center px-2 py-4">
                    <i class="bi bi-megaphone fs-2" aria-hidden="true"></i>
                    <h3 class="h6 mt-3">{{ !result ? 'Почніть із перевірки підключення' : result.library.state === 'connected' ? 'За цим запитом оголошень не знайдено' : 'Оголошення ще не завантажено' }}</h3>
                    <p class="small text-secondary mb-0">{{ !result ? 'Натисніть кнопку перевірки — тут з’являться реальні дані або причина відмови Meta.' : result.library.state === 'connected' ? 'Спробуйте інший товар, бренд або країну. Порожня вибірка не означає відсутність попиту.' : 'Перегляньте результат перевірки вище або відкрийте бібліотеку безпосередньо в Meta.' }}</p>
                </div>
            </div>
        </section>

        <details class="market-help card border-0 shadow-sm rounded-4 p-3 p-lg-4">
            <summary class="fw-semibold small">Як працює доступ і що показують ці дані</summary>
            <div class="small text-secondary mt-3">
                <p>Підключення чату Facebook і доступ до Ad Library перевіряються окремо. {{ initial.library_token_configured ? 'Окремий токен бібліотеки налаштований; його чинність підтвердить перевірка.' : 'Окремий токен бібліотеки ще не налаштований. Для проби перевіряємо токен сторінки; Meta може вимагати токен користувача.' }}</p>
                <p>Для доступу до API виконайте кроки на <a href="https://www.facebook.com/ads/library/api/" target="_blank" rel="noopener noreferrer">сторінці Meta Ad Library API</a>. Пробний пошук комерційної реклами через API доступний тут для Польщі, Німеччини та Чехії.</p>
                <p class="mb-0">Рекламні оголошення допомагають досліджувати пропозиції та креативи. Це не рейтинг трендових товарів: даних про реальні продажі, прибуток або ROAS конкурентів ця сторінка не показує.</p>
            </div>
        </details>
        <Toast v-bind="toast" @close="closeToast" @action="runAction" @secondary="runSecondary" />
    </div>
</template>

<style scoped>
.market-page { max-width: 1500px; margin: 0 auto; color: #263047; }
.market-eyebrow { font-size: .68rem; letter-spacing: .08em; font-weight: 700; color: #78738b; }
.market-eyebrow span { color: #b7b1c9; }
.market-badge { display: inline-block; background: #eeebfa; color: #615186; border-radius: 6px; padding: 4px 8px; font-size: .68rem; font-weight: 600; }
.market-icon { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; width: 35px; height: 35px; background: #f1eefb; color: #7160aa; border-radius: 10px; }
.market-page fieldset { min-width: 0; }
.market-page .form-label { color: #546078; }
.market-page .form-control, .market-page .form-select { min-height: 42px; }
.market-page .btn-primary { background: #7160aa; border-color: #7160aa; }
.market-page .btn-primary:hover { background: #5f4e96; border-color: #5f4e96; }
.market-page .btn-outline-secondary { --bs-btn-color: #5d6579; --bs-btn-border-color: #dce0e9; --bs-btn-hover-bg: #f2f0f7; --bs-btn-hover-color: #433958; --bs-btn-hover-border-color: #cbc5dc; }
.market-details summary, .market-help summary { cursor: pointer; }
.market-ad { border: 1px solid #e9e7ef; border-radius: 12px; }
.market-ad-body { white-space: pre-line; overflow-wrap: anywhere; max-height: 200px; overflow: auto; }
.market-empty > .bi { color: #9a8fc0; }
.market-page .min-width-0 { min-width: 0; }
@media (max-width: 575.98px) { .market-page form .btn { width: 100%; } }
</style>
