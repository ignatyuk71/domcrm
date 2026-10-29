<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import Toast from '@/crm/components/ui/Toast.vue';
import { useToast } from '@/crm/composables/useToast';
import api from './api';
import CheckoutCard from './CheckoutCard.vue';

const { toast, showToast, closeToast, runAction, runSecondary } = useToast();
const settings = reactive({ has_key: false, key_hint: null, model: '', connection_status: 'missing', verified_at: null, connection_error: null });
const form = reactive({ api_key: '', model: '', meta_connection_id: null });
const stores = ref([]);
const models = ref([]);
const chat = ref(null);
const catalog = ref(null);
const draft = ref('');
const photos = ref([]);
const fileInput = ref(null);
const transcript = ref(null);
const busy = ref('');
const loading = ref(true);
const ready = ref(false);
const errors = ref({});
const reveal = ref(false);
const draftKeyVerified = ref(false);
const requestId = ref(crypto.randomUUID());
const errorContext = ref('');

const working = computed(() => loading.value || Boolean(busy.value));
const dirtySettings = computed(() => Boolean(form.api_key) || form.model !== settings.model || form.meta_connection_id !== (settings.meta_connection_id ?? null));
const stopped = computed(() => chat.value?.checkout?.status === 'ready_for_manager'
    || (chat.value?.checkout?.status === 'needs_human' && new Date(chat.value.checkout.paused_until).getTime() > Date.now()));
const unfinished = computed(() => chat.value?.turns.find(turn => turn.status !== 'completed'));
const canSend = computed(() => ready.value && settings.has_key && !dirtySettings.value && !working.value
    && !unfinished.value && !stopped.value && (draft.value.trim() !== '' || photos.value.length > 0));
const hasDraft = computed(() => dirtySettings.value || draft.value.trim() !== '' || photos.value.length > 0 || busy.value === 'send' || busy.value === 'retry');
const connectionState = computed(() => {
    if (busy.value === 'connection') return { label: 'Перевіряємо підключення…', tone: 'secondary', icon: 'bi-arrow-repeat' };
    if (form.api_key) {
        if (errorContext.value === 'settings' && errors.value.api_key) return { label: 'Помилка перевірки ключа', tone: 'danger', icon: 'bi-exclamation-circle' };
        return draftKeyVerified.value
            ? { label: 'Ключ перевірено · збережіть', tone: 'primary', icon: 'bi-check-circle' }
            : { label: 'Новий ключ · не збережено', tone: 'secondary', icon: 'bi-key' };
    }
    if (settings.connection_status === 'error') return { label: 'Помилка підключення', tone: 'danger', icon: 'bi-exclamation-circle' };
    if (settings.connection_status === 'connected') return { label: 'Підключено до OpenAI', tone: 'success', icon: 'bi-check-circle-fill' };
    return settings.has_key
        ? { label: 'Ключ збережено · перевірте', tone: 'secondary', icon: 'bi-key' }
        : { label: 'Ключ не додано', tone: 'secondary', icon: 'bi-plug' };
});

watch([draft, photos], () => { requestId.value = crypto.randomUUID(); });
watch(() => form.api_key, () => { draftKeyVerified.value = false; });

function clearError(context) {
    if (errorContext.value === context) {
        closeToast();
        errorContext.value = '';
        errors.value = {};
    }
}

function failure(context, error, retry) {
    errorContext.value = context;
    errors.value = error?.response?.data?.errors || {};
    const messages = Object.values(errors.value).flat();
    if (!messages.length) messages.push(error?.response?.data?.message || error?.message || 'Не вдалося виконати дію. Повторіть спробу.');
    showToast({ type: 'error', title: context === 'settings' ? 'Перевірте підключення' : 'Не вдалося отримати відповідь',
        messages, duration: 0, actionLabel: 'Повторити', onAction: retry });
}

function success(context, message) {
    clearError(context);
    // Успіх іншої дії не перекриває помилку, яку ще потрібно виправити.
    if (!errorContext.value) showToast({ type: 'success', title: 'Готово', messages: [message] });
}

function acceptSettings(data) {
    Object.assign(settings, data);
    form.model = data.model;
    form.meta_connection_id = data.meta_connection_id ?? null;
}

async function scrollToLatest() {
    await nextTick();
    if (transcript.value) transcript.value.scrollTop = transcript.value.scrollHeight;
}

function acceptTurn(turn) {
    if (!chat.value) return;
    const index = chat.value.turns.findIndex(item => item.id === turn.id);
    if (index === -1) chat.value.turns.push(turn);
    else chat.value.turns[index] = turn;
    scrollToLatest();
}

async function load() {
    loading.value = true;
    try {
        const { data } = await api.state();
        acceptSettings(data.settings);
        chat.value = data.chat;
        catalog.value = data.catalog ?? null;
        stores.value = data.stores ?? [];
        ready.value = true;
        clearError('load');
        if (unfinished.value) failure('chat', { message: unfinished.value.error || 'Попередню відповідь не завершено. Натисніть «Повторити».' }, retryReply);
        await scrollToLatest();
    } catch (error) {
        failure('load', error, load);
    } finally {
        loading.value = false;
    }
}

async function checkConnection() {
    if (working.value) return;
    busy.value = 'connection';
    try {
        const { data } = await api.connection({ api_key: form.api_key || null });
        models.value = data.models;
        if (data.settings) Object.assign(settings, data.settings);
        draftKeyVerified.value = true;
        success('settings', 'Ключ OpenAI дійсний. Список доступних моделей оновлено.');
    } catch (error) {
        if (error.response?.data?.settings) Object.assign(settings, error.response.data.settings);
        failure('settings', error, checkConnection);
    } finally {
        busy.value = '';
    }
}

async function saveSettings() {
    if (working.value) return;
    busy.value = 'save';
    try {
        const { data } = await api.save({ api_key: form.api_key || null, model: form.model, meta_connection_id: form.meta_connection_id });
        acceptSettings(data.settings);
        form.api_key = '';
        reveal.value = false;
        success('settings', 'Ключ і модель збережено. Можна починати переписку.');
    } catch (error) {
        failure('settings', error, saveSettings);
    } finally {
        busy.value = '';
    }
}

function releasePhotos() {
    photos.value.forEach(photo => URL.revokeObjectURL(photo.url));
    photos.value = [];
    if (fileInput.value) fileInput.value.value = '';
}

function choosePhotos(event) {
    const files = Array.from(event.target.files || []);
    event.target.value = '';
    if (!files.length) return;
    const invalid = files.some(file => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 4 * 1024 * 1024);
    if (invalid || photos.value.length + files.length > 3) {
        failure('upload', { message: 'Додайте до 3 фото у форматі JPG, PNG або WEBP, кожне до 4 МБ.' }, () => fileInput.value?.click());
        return;
    }
    clearError('upload');
    photos.value = [...photos.value, ...files.map(file => ({ file, url: URL.createObjectURL(file) }))];
}

function removePhoto(index) {
    URL.revokeObjectURL(photos.value[index].url);
    photos.value = photos.value.filter((_, i) => i !== index);
}

async function newChat() {
    if (working.value) return;
    busy.value = 'new';
    try {
        const { data } = await api.newChat();
        chat.value = data.chat;
        requestId.value = crypto.randomUUID();
        clearError('chat');
        // Ненадіслана чернетка залишається доступною в новій розмові.
    } catch (error) {
        failure('chat', error, newChat);
    } finally {
        busy.value = '';
    }
}

async function send() {
    if (!canSend.value) return;
    busy.value = 'send';
    const payload = new FormData();
    payload.append('request_id', requestId.value);
    payload.append('text', draft.value.trim());
    photos.value.forEach(photo => payload.append('images[]', photo.file));
    try {
        if (!chat.value) chat.value = (await api.newChat()).data.chat;
        const { data } = await api.send(chat.value.id, payload);
        if (data.settings) Object.assign(settings, data.settings);
        if ('checkout' in data) chat.value.checkout = data.checkout;
        acceptTurn(data.turn);
        draft.value = '';
        releasePhotos();
        clearError('chat');
    } catch (error) {
        if (error.response?.data?.settings) Object.assign(settings, error.response.data.settings);
        if (error.response?.data?.turn) acceptTurn(error.response.data.turn);
        failure('chat', error, () => unfinished.value ? retryReply() : send());
    } finally {
        busy.value = '';
    }
}

async function retryReply() {
    if (working.value || !unfinished.value || dirtySettings.value) return;
    busy.value = 'retry';
    try {
        const { data } = await api.retry(chat.value.id, unfinished.value.id);
        if (data.settings) Object.assign(settings, data.settings);
        if ('checkout' in data) chat.value.checkout = data.checkout;
        acceptTurn(data.turn);
        draft.value = '';
        releasePhotos();
        clearError('chat');
    } catch (error) {
        if (error.response?.data?.settings) Object.assign(settings, error.response.data.settings);
        if (error.response?.data?.turn) acceptTurn(error.response.data.turn);
        failure('chat', error, retryReply);
    } finally {
        busy.value = '';
    }
}

function beforeLeave(event) {
    if (!hasDraft.value) return;
    event.preventDefault();
    event.returnValue = '';
}

onMounted(() => { window.addEventListener('beforeunload', beforeLeave); load(); });
onBeforeUnmount(() => { window.removeEventListener('beforeunload', beforeLeave); releasePhotos(); });
</script>

<template>
    <div class="gpt-sandbox" :aria-busy="working">
        <header class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <div class="small text-secondary mb-1">Налаштування / AI-інструменти</div>
                <h1 class="h3 fw-bold mb-2">GPT · тестовий чат</h1>
                <p class="text-secondary mb-0">Підключіть OpenAI та перевірте відповіді на повідомлення й фото.</p>
            </div>
            <span class="badge rounded-pill text-bg-light border px-3 py-2"><i class="bi bi-lock me-1" aria-hidden="true"></i> Приватне тестування</span>
        </header>

        <div v-if="loading" class="py-5 text-center text-secondary" role="status"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Завантаження…</div>
        <button v-else-if="!ready" class="btn btn-outline-primary" type="button" @click="load">Повторити завантаження</button>

        <div v-else class="row g-4 align-items-stretch">
            <section class="col-12 col-lg-6" aria-labelledby="gpt-connection-title">
                <div class="card border-0 shadow-sm h-100 rounded-4">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-3 mb-4">
                            <span class="gpt-section-icon"><i class="bi bi-plug" aria-hidden="true"></i></span>
                            <div><h2 id="gpt-connection-title" class="h5 fw-semibold mb-1">Підключення OpenAI</h2><p class="small text-secondary mb-0">Окремий ключ і модель для цього чату</p></div>
                        </div>
                        <div class="mb-4" aria-live="polite" data-test="connection-state">
                            <span class="badge rounded-pill border px-3 py-2" :class="`text-${connectionState.tone} bg-${connectionState.tone}-subtle`"><i class="bi me-1" :class="connectionState.icon" aria-hidden="true"></i>{{ connectionState.label }}</span>
                            <div v-if="settings.verified_at && !form.api_key && settings.connection_status === 'connected'" class="small text-secondary mt-2">API-ключ перевірено. Можна тестувати переписку.</div>
                        </div>
                        <form @submit.prevent="saveSettings">
                            <label for="gpt-api-key" class="form-label fw-semibold">API-ключ OpenAI</label>
                            <div class="input-group mb-2">
                                <input id="gpt-api-key" v-model.trim="form.api_key" :type="reveal ? 'text' : 'password'" class="form-control"
                                    :class="{ 'is-invalid': errorContext === 'settings' && errors.api_key }" :aria-invalid="Boolean(errorContext === 'settings' && errors.api_key)"
                                    autocomplete="new-password" spellcheck="false" :disabled="working" :placeholder="settings.has_key ? 'Ключ збережено · ' + settings.key_hint : 'sk-…'" maxlength="512" />
                                <button type="button" class="btn btn-outline-secondary" :disabled="working" :aria-label="reveal ? 'Приховати ключ' : 'Показати ключ'" :aria-pressed="reveal" @click="reveal = !reveal"><i class="bi" :class="reveal ? 'bi-eye-slash' : 'bi-eye'" aria-hidden="true"></i></button>
                            </div>
                            <p class="small text-secondary mb-3">Збережений ключ зашифрований і не повертається у браузер. Порожнє поле залишає його без змін.</p>
                            <button type="button" class="btn btn-outline-primary mb-4" :disabled="working || (!settings.has_key && !form.api_key)" data-test="connection" @click="checkConnection">
                                <span v-if="busy === 'connection'" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Перевірити ключ і завантажити моделі
                            </button>

                            <label for="gpt-model" class="form-label fw-semibold d-block">Модель</label>
                            <input id="gpt-model" v-model.trim="form.model" list="gpt-models" class="form-control" :class="{ 'is-invalid': errorContext === 'settings' && errors.model }"
                                :aria-invalid="Boolean(errorContext === 'settings' && errors.model)" placeholder="Оберіть модель або введіть її ID" :disabled="working" maxlength="120" required autocomplete="off" />
                            <datalist id="gpt-models"><option v-for="model in models" :key="model" :value="model" /></datalist>
                            <p class="small text-secondary mt-2 mb-4">Список з’явиться після перевірки ключа. Для фото виберіть модель із підтримкою зображень.</p>

                            <div v-if="stores.length" class="mb-4">
                                <label for="gpt-store" class="form-label fw-semibold">Магазин</label>
                                <select id="gpt-store" v-model="form.meta_connection_id" class="form-select" :class="{ 'is-invalid': errorContext === 'settings' && errors.meta_connection_id }" :disabled="working"
                                    :aria-invalid="Boolean(errorContext === 'settings' && errors.meta_connection_id)">
                                    <option :value="null">{{ stores.length === 1 ? 'Єдиний магазин' : 'Оберіть магазин' }}</option>
                                    <option v-for="store in stores" :key="store.id" :value="store.id">{{ store.name }}</option>
                                </select>
                            </div>

                            <button class="btn btn-primary px-4" type="submit" :disabled="working || !dirtySettings || !form.model || (!settings.has_key && !form.api_key)" data-test="save">
                                <span v-if="busy === 'save'" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Зберегти підключення
                            </button>
                        </form>
                        <hr class="my-4" />
                        <div v-if="catalog" class="small text-secondary mb-3" data-test="catalog-state">
                            <div class="fw-semibold text-dark mb-1"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Каталог і галерея</div>
                            <div>Активних товарів: {{ catalog.products }} · Категорій: {{ catalog.categories.length }} · Прив’язаних фото: {{ catalog.photos }}</div>
                            <div v-if="catalog.source === 'server_snapshot' && catalog.captured_at" class="mt-1">Копія із сервера: {{ new Date(catalog.captured_at).toLocaleString('uk-UA') }}. Залишки відповідають часу копії.</div>
                            <div v-else class="mt-1">Ціни й залишки читаються з каталогу CRM під час запиту.</div>
                        </div>
                        <p class="small text-secondary mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i> GPT консультує та збирає тестову заявку лише тут. Замовлення в CRM і резерв товару не створюються.</p>
                        <CheckoutCard v-if="chat?.checkout" :order="chat.checkout" />
                    </div>
                </div>
            </section>

            <section class="col-12 col-lg-6" aria-labelledby="gpt-chat-title">
                <div class="card border-0 shadow-sm rounded-4 h-100 gpt-chat">
                    <div class="card-header bg-transparent p-4 d-flex align-items-center justify-content-between gap-2 flex-wrap">
                        <div><h2 id="gpt-chat-title" class="h5 fw-semibold mb-1">Тестова переписка</h2><span class="small text-secondary">{{ settings.model || 'Спочатку оберіть модель' }}</span></div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="working" data-test="new-chat" @click="newChat"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Нова розмова</button>
                    </div>
                    <div ref="transcript" class="gpt-transcript p-3 p-xl-4" role="log" aria-label="Історія тестової переписки" aria-live="polite" aria-relevant="additions text">
                        <div v-if="!chat?.turns.length" class="gpt-empty text-secondary text-center py-5">
                            <i class="bi bi-chat-square-text fs-2 d-block mb-3" aria-hidden="true"></i>
                            <p class="mb-1 fw-semibold">Почніть розмову з GPT</p>
                            <p class="small mb-0">Напишіть повідомлення або додайте фото.</p>
                        </div>
                        <template v-for="turn in chat?.turns || []" :key="turn.id">
                            <div class="gpt-message gpt-message-user mb-3">
                                <div class="small fw-semibold mb-1">Ви</div>
                                <p v-if="turn.text" class="mb-0 gpt-message-text">{{ turn.text }}</p>
                                <div v-if="turn.images.length" class="d-flex flex-wrap gap-2 mt-2">
                                    <a v-for="image in turn.images" :key="image.url" :href="image.url" target="_blank" rel="noopener"><img class="gpt-photo" :src="image.url" :alt="image.name" loading="lazy" /></a>
                                </div>
                            </div>
                            <div v-for="(message, index) in turn.checkout_messages || []" :key="turn.id + '-checkout-' + index" class="gpt-message gpt-message-assistant mb-3" data-test="checkout-message">
                                <p class="mb-0 gpt-message-text">{{ message }}</p>
                            </div>
                            <div v-if="turn.reply || turn.gallery_photos?.length" class="gpt-message gpt-message-assistant mb-4">
                                <div class="small fw-semibold text-secondary mb-1">GPT <span class="fw-normal">· {{ turn.model }}</span></div>
                                <div v-if="turn.gallery_photos?.length" class="gpt-gallery mt-3" data-test="gallery-photos">
                                    <a v-for="photo in turn.gallery_photos" :key="photo.id" :href="photo.url" target="_blank" rel="noopener" class="gpt-gallery-link text-decoration-none text-body">
                                        <img :src="photo.url" :alt="photo.label" loading="lazy" class="gpt-gallery-photo" />
                                        <span class="small d-block mt-1">{{ photo.label }}</span>
                                    </a>
                                </div>
                                <p v-if="turn.reply" class="mb-0 gpt-message-text">{{ turn.reply }}</p>
                            </div>
                            <button v-else-if="turn.status !== 'completed' && busy !== 'send' && busy !== 'retry'" type="button" class="btn btn-sm btn-outline-primary mb-3" :disabled="working || dirtySettings" data-test="retry" @click="retryReply">Повторити відповідь</button>
                        </template>
                        <p v-if="busy === 'send' || busy === 'retry'" class="small text-secondary mb-0" role="status"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>GPT готує відповідь…</p>
                    </div>

                    <form class="card-footer bg-transparent p-3 p-xl-4" @submit.prevent="send">
                        <label for="gpt-message" class="form-label small text-secondary">{{ stopped ? 'Розмову передано на опрацювання. Для нового тесту почніть нову розмову.' : !settings.has_key ? 'Збережіть підключення, щоб написати GPT' : dirtySettings ? 'Збережіть зміни підключення перед надсиланням' : unfinished ? 'Повторіть відповідь або почніть нову розмову' : 'Ваше повідомлення' }}</label>
                        <textarea id="gpt-message" v-model="draft" rows="3" maxlength="6000" class="form-control gpt-composer" :class="{ 'is-invalid': errorContext === 'chat' && errors.text }"
                            :aria-invalid="Boolean(errorContext === 'chat' && errors.text)" :disabled="working || Boolean(unfinished) || stopped" placeholder="Напишіть повідомлення…" @keydown.ctrl.enter.prevent="send" @keydown.meta.enter.prevent="send" />
                        <div v-if="photos.length" class="d-flex flex-wrap gap-2 mt-3">
                            <div v-for="(photo, index) in photos" :key="photo.url" class="position-relative">
                                <img class="gpt-photo" :src="photo.url" :alt="photo.file.name" />
                                <button type="button" class="btn btn-sm btn-light gpt-remove-photo" :disabled="working || Boolean(unfinished) || stopped" :aria-label="'Прибрати фото ' + photo.file.name" @click="removePhoto(index)"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                            </div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
                            <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" multiple class="d-none" aria-label="Вибрати фото" :disabled="working || Boolean(unfinished) || stopped" @change="choosePhotos" />
                            <button type="button" class="btn btn-outline-secondary" :class="{ 'is-invalid': errorContext === 'chat' && Object.keys(errors).some(key => key.startsWith('images')) }" :disabled="working || Boolean(unfinished) || stopped || photos.length >= 3" @click="fileInput.click()"><i class="bi bi-image me-1" aria-hidden="true"></i>Додати фото</button>
                            <button type="submit" class="btn btn-primary px-4" :disabled="!canSend" data-test="send"><i class="bi bi-send me-1" aria-hidden="true"></i>Надіслати</button>
                        </div>
                        <p class="small text-secondary mt-2 mb-0">До 3 фото · JPG, PNG, WEBP · до 4 МБ кожне</p>
                    </form>
                </div>
            </section>
        </div>
        <Toast v-bind="toast" @close="closeToast" @action="runAction" @secondary="runSecondary" />
    </div>
</template>

<style scoped>
.gpt-sandbox { max-width: 1600px; margin-inline: auto; }
.gpt-section-icon { display: grid; place-items: center; width: 44px; height: 44px; flex: 0 0 44px; border-radius: 12px; background: #eef2ff; color: #4f46e5; font-size: 1.35rem; }
.gpt-chat { min-width: 0; }
.gpt-transcript { height: 430px; min-height: 280px; overflow-y: auto; overflow-wrap: anywhere; }
.gpt-empty { display: grid; align-content: center; min-height: 100%; }
.gpt-message { max-width: 94%; border-radius: 14px; padding: 12px 14px; }
.gpt-message-user { margin-left: auto; background: #eef2ff; color: #252d54; }
.gpt-message-assistant { background: #f5f6f8; }
.gpt-message-text { white-space: pre-wrap; overflow-wrap: anywhere; }
.gpt-photo { display: block; width: 96px; height: 96px; object-fit: cover; border-radius: 10px; }
.gpt-gallery { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(140px, 100%), 1fr)); gap: 12px; }
.gpt-gallery-link { min-width: 0; overflow-wrap: anywhere; }
.gpt-gallery-photo { display: block; width: 100%; height: 160px; object-fit: contain; border-radius: 10px; background: #fff; }
.gpt-remove-photo { position: absolute; top: 3px; right: 3px; }
.gpt-composer { resize: vertical; min-height: 84px; max-height: 240px; }
@media (max-width: 575.98px) { .gpt-transcript { height: 360px; } .gpt-message { max-width: 100%; } }
</style>
