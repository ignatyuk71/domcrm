<script setup>
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    runs: { type: Array, default: () => [] }, attachments: { type: Array, default: () => [] },
    uploading: Boolean, uploadProgress: { type: Number, default: 0 },
    busy: Boolean, saving: Boolean, enabled: Boolean, errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['send', 'new', 'refresh', 'copy', 'error', 'upload', 'remove-attachment']);
const message = defineModel({ type: String, default: '' });
const thread = ref(null);
const composer = ref(null);
const fileInput = ref(null);
const following = ref(true);
const canSend = computed(() => props.enabled && !props.busy && !props.saving && !props.uploading && (!!message.value.trim() || props.attachments.length > 0));
const pending = run => ['pending', 'running'].includes(run.status);
function parts(value) {
    return String(value || '').split(/(https?:\/\/[^\s<>]+)/g).filter(Boolean).map(text => ({ text, url: /^https?:\/\//i.test(text) }));
}
function chooseFiles(event) {
    const files = Array.from(event.target.files || []);
    if (files.length) emit('upload', files);
    event.target.value = '';
}
function send() {
    if (!canSend.value) return;
    following.value = true;
    emit('send');
    nextTick(() => composer.value?.focus());
}
function onKeydown(event) {
    if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.keyCode === 229) return;
    event.preventDefault();
    send();
}
function onScroll() {
    const el = thread.value;
    if (el) following.value = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
}
watch(() => props.runs, async () => {
    await nextTick();
    if (thread.value && following.value) thread.value.scrollTop = thread.value.scrollHeight;
}, { deep: true, flush: 'post' });
watch(message, async () => {
    await nextTick();
    if (!composer.value) return;
    composer.value.style.height = 'auto';
    composer.value.style.height = `${Math.min(144, Math.max(46, composer.value.scrollHeight))}px`;
});
</script>

<template>
    <section class="card border-0 shadow-sm gpt-chat" aria-label="Тестовий чат із GPT">
        <header class="gpt-chat-header">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <div><h2 class="h5 mb-1">Чат із GPT</h2><span class="small text-secondary">Тестова розмова · без надсилання клієнтам</span></div>
                <button class="btn btn-sm btn-outline-secondary text-nowrap" :disabled="busy || uploading" @click="following = true; emit('new')">Новий чат</button>
            </div>
        </header>
        <div ref="thread" class="gpt-chat-thread" role="log" aria-label="Повідомлення діалогу" aria-live="polite" @scroll="onScroll">
            <div v-if="!runs.length" class="gpt-chat-empty"><i class="bi bi-chat-dots text-primary" aria-hidden="true"></i><h3 class="h5">Почніть розмову</h3><p class="text-secondary mb-0">Напишіть повідомлення або завантажте фото кнопкою вкладення.</p></div>
            <template v-for="run in runs" :key="run.id">
                <div class="gpt-chat-row gpt-chat-row--user"><div class="gpt-chat-bubble gpt-chat-bubble--user"><div class="gpt-chat-author">Ви</div><p class="gpt-chat-text mb-0"><template v-for="(part, index) in parts(run.input_message)" :key="index"><a v-if="part.url" :href="part.text" target="_blank" rel="noopener noreferrer">{{ part.text }}</a><template v-else>{{ part.text }}</template></template></p></div></div>
                <div class="gpt-chat-row gpt-chat-row--assistant"><div class="gpt-chat-bubble gpt-chat-bubble--assistant">
                    <div class="gpt-chat-author">GPT</div>
                    <p v-if="pending(run)" class="text-secondary mb-0" role="status"><span class="spinner-grow spinner-grow-sm me-2" aria-hidden="true"></span>Готує відповідь…</p>
                    <template v-else-if="run.result">
                        <p class="gpt-chat-text mb-0"><template v-for="(part, index) in parts(run.result.message)" :key="index"><a v-if="part.url" :href="part.text" target="_blank" rel="noopener noreferrer">{{ part.text }}</a><template v-else>{{ part.text }}</template></template></p>
                        <div v-if="run.result.photos?.length" class="gpt-chat-photos mt-2"><a v-for="photo in run.result.photos" :key="photo.id" :href="photo.url" target="_blank" rel="noopener noreferrer">{{ photo.kind === 'collage' ? 'Відкрити колаж' : 'Відкрити фото' }} ↗</a></div>
                        <button class="btn btn-sm btn-link p-0 mt-2" @click="emit('copy', run.result.message)">Копіювати відповідь</button>
                        <p v-if="run.result.action === 'handoff'" class="small text-secondary mt-2 mb-0">Рекомендація GPT: допомога менеджера. Робочий чат не перемикається.</p>
                    </template>
                    <template v-else-if="run.status === 'failed'"><p class="gpt-chat-text mb-2">{{ run.error_message || 'Не вдалося отримати відповідь.' }}</p><button class="btn btn-sm btn-outline-secondary" @click="emit('error', run)">Переглянути помилку / повторити</button></template>
                    <details v-if="run.evidence?.length || run.usage" class="gpt-chat-diagnostics mt-2"><summary class="small text-secondary">Деталі перевірки</summary><p v-if="run.usage" class="small text-secondary mt-2 mb-0">{{ run.model }} · {{ run.usage.input_tokens }} вхідних / {{ run.usage.output_tokens }} вихідних токенів · {{ run.usage.elapsed_ms }} мс</p><pre v-if="run.evidence?.length" class="small bg-light p-2 rounded mt-2 mb-0">{{ JSON.stringify(run.evidence, null, 2) }}</pre></details>
                </div></div>
            </template>
        </div>
        <form class="gpt-chat-composer" aria-label="Надіслати тестове повідомлення" @submit.prevent="send">
            <input id="gpt-files" ref="fileInput" type="file" class="visually-hidden" accept="image/jpeg,image/png,image/webp" multiple tabindex="-1" aria-label="Файли для GPT" @change="chooseFiles">
            <div v-if="attachments.length" class="d-flex flex-wrap gap-2 mb-2"><span v-for="file in attachments" :key="file.id" class="d-inline-flex align-items-center gap-2 border rounded px-2 py-1 small"><a :href="file.url" target="_blank" rel="noopener noreferrer" class="text-break">{{ file.name }} ↗</a><button type="button" class="btn-close" :disabled="busy || uploading" :aria-label="`Прибрати ${file.name}`" @click="emit('remove-attachment', file.id)"></button></span></div>
            <div v-if="uploading" class="mb-2" role="status"><span class="small text-secondary">Завантаження файлу… {{ uploadProgress }}%</span><div class="progress mt-1" style="height:4px"><div class="progress-bar" :style="{ width: `${uploadProgress}%` }"></div></div></div>
            <div class="d-flex align-items-end gap-2">
                <button type="button" class="btn btn-light gpt-chat-icon" aria-label="Завантажити файл" :disabled="busy || uploading || attachments.length >= 3" @click="fileInput?.click()"><i class="bi bi-paperclip" aria-hidden="true"></i></button>
                <div class="flex-grow-1 gpt-chat-input-wrap"><label for="gpt-message" class="visually-hidden">Ваше повідомлення</label><textarea id="gpt-message" ref="composer" v-model="message" class="form-control gpt-chat-input" :class="{ 'is-invalid': errors.message }" rows="1" maxlength="3000" placeholder="Напишіть повідомлення…" @keydown="onKeydown" /></div>
                <button class="btn btn-primary gpt-chat-icon" :disabled="!canSend" aria-label="Надіслати повідомлення"><i class="bi bi-send-fill" aria-hidden="true"></i><span class="d-none d-sm-inline ms-2">Надіслати</span></button>
            </div>
            <div v-if="errors.message" class="small text-danger mt-1">{{ errors.message[0] }}</div>
            <div class="d-flex justify-content-between gap-2 mt-2 small text-secondary"><span>Enter — надіслати · Shift+Enter — новий рядок</span><button v-if="runs.some(pending)" type="button" class="btn btn-sm btn-link p-0" @click="emit('refresh')">Оновити статус</button></div>
        </form>
    </section>
</template>

<style scoped>
.gpt-chat{display:flex;flex-direction:column;height:min(78dvh,820px);min-height:520px;overflow:hidden}
.gpt-chat-header,.gpt-chat-composer{padding:1rem 1.25rem;background:var(--bs-body-bg)}
.gpt-chat-header{border-bottom:1px solid var(--bs-border-color)}
.gpt-chat-thread{flex:1;min-height:0;overflow-y:auto;overscroll-behavior:contain;padding:1.25rem;background:var(--bs-tertiary-bg,var(--bs-light))}
.gpt-chat-empty{min-height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:.65rem;padding:2rem 1rem}
.gpt-chat-empty>i{font-size:2rem}
.gpt-chat-row{display:flex;margin-bottom:1rem}.gpt-chat-row--user{justify-content:flex-end}
.gpt-chat-bubble{max-width:88%;min-width:0;padding:.8rem 1rem;border-radius:1rem;overflow-wrap:anywhere}
.gpt-chat-bubble--user{background:var(--bs-primary);color:var(--bs-white);border-bottom-right-radius:.25rem}
.gpt-chat-bubble--user a{color:inherit;text-decoration:underline}
.gpt-chat-bubble--assistant{background:var(--bs-body-bg);color:var(--bs-body-color);border:1px solid var(--bs-border-color);border-bottom-left-radius:.25rem}
.gpt-chat-author{font-size:.75rem;font-weight:600;opacity:.8;margin-bottom:.35rem}.gpt-chat-text{white-space:pre-wrap;overflow-wrap:anywhere}
.gpt-chat-photos{display:flex;flex-wrap:wrap;gap:.65rem}.gpt-chat-photos a{display:flex;flex-direction:column;gap:.25rem;max-width:100%}
.gpt-chat-composer{flex-shrink:0;border-top:1px solid var(--bs-border-color)}
.gpt-chat-input-wrap{min-width:0}.gpt-chat-input{min-height:46px;max-height:144px;resize:none;padding:.65rem .8rem}.gpt-chat-icon{min-height:46px;min-width:46px}
.gpt-chat-diagnostics summary,.gpt-chat-header summary{cursor:pointer}.gpt-chat-diagnostics pre{max-height:18rem;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere}
@media(max-width:575.98px){.gpt-chat{min-height:460px;height:78dvh}.gpt-chat-header,.gpt-chat-composer,.gpt-chat-thread{padding:.85rem}.gpt-chat-bubble{max-width:94%;padding:.75rem}}
@media(prefers-reduced-motion:reduce){.spinner-grow{animation:none}}
</style>
