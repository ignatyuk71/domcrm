<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import Toast from '../../components/ui/Toast.vue';
import { useToast } from '../../composables/useToast';
import { gptDraftsApi as api } from '../../services/gptDraftsApi';

const { toast, showToast, closeToast, runAction, runSecondary } = useToast();
const settings = reactive({ enabled: false, model: '', store_policies: '', has_api_key: false, version: 0 });
const apiKey = ref('');
const groups = ref([]);
const dialogues = ref([]);
const runs = ref([]);
const dialogueId = ref(null);
const groupId = ref('');
const message = ref('');
const ready = ref(false);
const saving = ref(false);
const submitting = ref(false);
const activeRun = ref(null);
const settingsErrors = ref({});
const draftErrors = ref({});
const busy = computed(() => submitting.value || !!activeRun.value);
let timer, pollStarted, submitted, alive = true;

function failure(error, retry, target) {
    const data = error?.response?.data || {};
    if (target) target.value = data.errors || {};
    showToast({ title: 'Потрібна ваша дія', messages: [data.message || 'Не вдалося виконати запит. Введені дані збережено.'],
        type: 'error', actionLabel: 'Повторити', onAction: retry });
}
function success(text) {
    // Непов’язаний успіх не перекриває помилку, яку власник ще не виправив.
    if (toast.show && ['error', 'warning'].includes(toast.type)) return;
    showToast({ title: 'GPT', messages: [text], type: 'success' });
}
async function load() {
    try {
        const data = await api.data();
        if (!alive) return;
        Object.assign(settings, data.settings);
        groups.value = data.groups;
        dialogues.value = data.dialogues;
        ready.value = true;
    } catch (error) { failure(error, load); }
}
async function saveSettings() {
    saving.value = true;
    settingsErrors.value = {};
    try {
        const data = await api.saveSettings({
            enabled: settings.enabled, mode: 'drafts', model: settings.model,
            api_key: apiKey.value || null, store_policies: settings.store_policies, version: settings.version,
        });
        Object.assign(settings, data.settings);
        apiKey.value = '';
        success('Налаштування ручних чернеток збережено.');
    } catch (error) { failure(error, saveSettings, settingsErrors); }
    finally { saving.value = false; }
}
function putRun(run) {
    const index = runs.value.findIndex(r => r.id === run.id);
    if (index < 0) runs.value.push(run);
    else runs.value[index] = run;
}
function runError(run) {
    showToast({ title: 'Чернетку не створено', messages: [run.error_message || 'Повторіть спробу.'], type: 'error',
        actionLabel: 'Повернути повідомлення', onAction: () => { message.value = run.input_message; } });
}
function acceptRun(run) {
    putRun(run);
    dialogueId.value = run.gpt_dialogue_id;
    if (['pending', 'running'].includes(run.status)) {
        activeRun.value = run.id;
        if (alive) timer = setTimeout(refreshRun, 1500);
        return;
    }
    activeRun.value = null;
    if (run.status === 'failed') runError(run);
    if (run.status === 'completed') {
        if (submitted && message.value === submitted.message) message.value = '';
        submitted = null;
        success('Чернетку підготовлено для перевірки.');
    }
}
async function refreshRun() {
    clearTimeout(timer);
    if (!activeRun.value) return;
    try {
        const { run } = await api.run(activeRun.value);
        if (!alive) return;
        if (Date.now() - pollStarted > 120000 && ['pending', 'running'].includes(run.status)) {
            putRun(run);
            showToast({ title: 'Обробка ще триває', messages: ['Повідомлення збережено. Перевірте статус чернетки пізніше.'],
                type: 'warning', actionLabel: 'Оновити статус', onAction: () => { pollStarted = Date.now(); return refreshRun(); } });
            return;
        }
        acceptRun(run);
    } catch (error) { failure(error, refreshRun); }
}
async function createDraft(retry = false) {
    submitting.value = true;
    draftErrors.value = {};
    // Після мережевої помилки повторюємо той самий запит без подвійної генерації.
    if (!retry || !submitted) submitted = {
        request_id: crypto.randomUUID(), message: message.value.trim(),
        dialogue_id: dialogueId.value, gallery_group_id: groupId.value ? Number(groupId.value) : null,
    };
    try {
        const { run } = await api.create(submitted);
        if (!alive) return;
        pollStarted = Date.now();
        acceptRun(run);
        const { dialogues: list } = await api.data();
        if (alive) dialogues.value = list;
    } catch (error) { failure(error, () => createDraft(true), draftErrors); }
    finally { submitting.value = false; }
}
async function openDialogue(id) {
    try {
        const data = await api.dialogue(id);
        if (!alive) return;
        clearTimeout(timer);
        dialogueId.value = data.dialogue.id;
        groupId.value = data.dialogue.memory?.group_id || '';
        runs.value = data.runs;
        submitted = null;
        const pending = data.runs.find(r => ['pending', 'running'].includes(r.status));
        activeRun.value = pending?.id || null;
        if (pending) { pollStarted = Date.now(); await refreshRun(); }
    } catch (error) { failure(error, () => openDialogue(id)); }
}
function newDialogue() {
    clearTimeout(timer);
    dialogueId.value = null;
    runs.value = [];
    submitted = null;
}
async function copyDraft(text) {
    try { await navigator.clipboard.writeText(text); success('Чернетку скопійовано.'); }
    catch (error) { failure(error, () => copyDraft(text)); }
}
const statusName = status => ({ pending: 'У черзі', running: 'Готується', completed: 'Чернетка', failed: 'Не створено' }[status] || status);
onMounted(load);
onBeforeUnmount(() => { alive = false; clearTimeout(timer); });
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1">GPT · тестові чернетки</h1>
                <p class="text-secondary mb-0">Ручні тести на вашій галереї та каталозі. Відповіді залишаються на цій сторінці.</p>
            </div>
            <span class="badge text-bg-secondary">Без підключення до робочих чатів</span>
        </div>

        <p v-if="!ready" class="text-secondary" role="status">Завантаження модуля…</p>
        <div v-else class="row g-4">
            <div class="col-12 col-xl-4">
                <form class="card border-0 shadow-sm" @submit.prevent="saveSettings">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Налаштування GPT</h2>
                        <label class="form-check form-switch mb-3">
                            <input v-model="settings.enabled" class="form-check-input" type="checkbox" :disabled="busy || saving">
                            <span class="form-check-label">Дозволити ручні чернетки</span>
                        </label>
                        <div class="mb-3">
                            <label for="gpt-model" class="form-label">Модель OpenAI</label>
                            <input id="gpt-model" v-model="settings.model" class="form-control" :class="{ 'is-invalid': settingsErrors.model }" required maxlength="100" placeholder="ID GPT-моделі" :disabled="busy || saving">
                            <div v-if="settingsErrors.model" class="invalid-feedback">{{ settingsErrors.model[0] }}</div>
                        </div>
                        <div class="mb-3">
                            <label for="gpt-key" class="form-label">Окремий API-ключ</label>
                            <input id="gpt-key" v-model="apiKey" type="password" class="form-control" :class="{ 'is-invalid': settingsErrors.api_key }" autocomplete="new-password" maxlength="512" :placeholder="settings.has_api_key ? 'Ключ уже налаштовано' : 'sk-…'" :disabled="busy || saving">
                            <div v-if="settingsErrors.api_key" class="invalid-feedback">{{ settingsErrors.api_key[0] }}</div>
                            <div class="form-text">Порожнє поле залишає збережений ключ без змін.</div>
                        </div>
                        <div class="mb-3">
                            <label for="gpt-policies" class="form-label">Правила магазину</label>
                            <textarea id="gpt-policies" v-model="settings.store_policies" class="form-control" :class="{ 'is-invalid': settingsErrors.store_policies }" rows="5" maxlength="8000" placeholder="Затверджені умови доставки, оплати та обміну" :disabled="busy || saving" />
                            <div v-if="settingsErrors.store_policies" class="invalid-feedback">{{ settingsErrors.store_policies[0] }}</div>
                        </div>
                        <button class="btn btn-primary" :disabled="saving || busy">{{ saving ? 'Збереження…' : 'Зберегти налаштування' }}</button>
                    </div>
                </form>
                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-body">
                        <h2 class="h6">Тестові діалоги</h2>
                        <ul class="list-unstyled mb-0">
                            <li v-for="item in dialogues" :key="item.id" class="mt-2">
                                <button class="btn btn-sm btn-link text-start p-0 text-break" :disabled="busy" @click="openDialogue(item.id)">{{ item.title }}</button>
                            </li>
                        </ul>
                        <p v-if="!dialogues.length" class="text-secondary small mb-0">Збережені тести з’являться тут.</p>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                            <h2 class="h5 mb-0">Перевірка відповіді</h2>
                            <button class="btn btn-sm btn-outline-secondary" :disabled="busy" @click="newDialogue">Новий тест</button>
                        </div>
                        <form @submit.prevent="createDraft()">
                            <label for="gpt-group" class="form-label">Модель із галереї</label>
                            <select id="gpt-group" v-model="groupId" class="form-select" :class="{ 'is-invalid': draftErrors.gallery_group_id }" :disabled="busy">
                                <option value="">GPT визначає за запитом</option>
                                <option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</option>
                            </select>
                            <div v-if="draftErrors.gallery_group_id" class="invalid-feedback">{{ draftErrors.gallery_group_id[0] }}</div>
                            <label for="gpt-message" class="form-label mt-3">Тестове повідомлення клієнта</label>
                            <textarea id="gpt-message" v-model="message" class="form-control" :class="{ 'is-invalid': draftErrors.message }" rows="4" maxlength="3000" required placeholder="Наприклад: покажіть цю модель в чорному кольорі, які є розміри?" />
                            <div v-if="draftErrors.message" class="invalid-feedback">{{ draftErrors.message[0] }}</div>
                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <button class="btn btn-primary" :disabled="busy || saving || !settings.enabled || !message.trim()">{{ busy ? 'Підготовка чернетки…' : 'Створити чернетку' }}</button>
                                <button v-if="activeRun" type="button" class="btn btn-outline-secondary" @click="refreshRun">Оновити статус</button>
                            </div>
                        </form>

                        <div class="mt-4" aria-live="polite">
                            <article v-for="run in runs" :key="run.id" class="border-top pt-3 mt-3">
                                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                    <strong>Тестове повідомлення</strong>
                                    <span class="badge" :class="run.status === 'failed' ? 'text-bg-danger' : 'text-bg-secondary'">{{ statusName(run.status) }}</span>
                                </div>
                                <p class="gpt-draft-text">{{ run.input_message }}</p>
                                <template v-if="run.result">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                        <strong>Чернетка GPT</strong>
                                        <button class="btn btn-sm btn-outline-secondary" @click="copyDraft(run.result.message)">Копіювати чернетку</button>
                                    </div>
                                    <p class="gpt-draft-text">{{ run.result.message }}</p>
                                    <p v-if="run.result.action === 'handoff'" class="small text-secondary">Рекомендація: відповідь менеджера. Робочий чат не перемикався.</p>
                                    <div v-if="run.result.photos?.length" class="d-flex flex-wrap gap-2 mb-3">
                                        <figure v-for="photo in run.result.photos" :key="photo.id" class="mb-0">
                                            <img :src="photo.url" :alt="photo.kind === 'collage' ? 'Колаж моделі' : 'Фото вибраного кольору'" class="gpt-draft-photo rounded" loading="lazy">
                                            <figcaption class="small text-secondary">{{ photo.kind === 'collage' ? 'Колаж' : 'Фото кольору' }} · №{{ photo.id }}</figcaption>
                                        </figure>
                                    </div>
                                </template>
                                <button v-if="run.status === 'failed'" class="btn btn-sm btn-outline-danger" @click="runError(run)">Переглянути помилку / повторити</button>
                                <details v-if="run.evidence?.length" class="mt-2">
                                    <summary class="small">Джерела та перевірені дані</summary>
                                    <pre class="small text-break bg-light p-2 rounded mt-2 gpt-evidence">{{ JSON.stringify(run.evidence, null, 2) }}</pre>
                                </details>
                                <p v-if="run.usage" class="small text-secondary mt-2 mb-0">{{ run.model }} · {{ run.usage.input_tokens }} вхідних / {{ run.usage.output_tokens }} вихідних токенів · {{ run.usage.elapsed_ms }} мс</p>
                            </article>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <Toast v-bind="toast" @close="closeToast" @action="runAction" @secondary="runSecondary" />
    </div>
</template>

<style scoped>
.gpt-draft-text { white-space: pre-wrap; overflow-wrap: anywhere; }
.gpt-draft-photo { width: 180px; max-width: 100%; height: 180px; object-fit: contain; }
.gpt-evidence { white-space: pre-wrap; overflow-wrap: anywhere; max-height: 24rem; overflow: auto; }
</style>
