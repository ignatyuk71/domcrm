<template>
  <div class="chat-thread-shell">
    <header class="chat-thread-header">
      <div class="thread-user-block">
        <button
          type="button"
          class="thread-mobile-btn"
          title="Список чатів"
          @click="$emit('open-list')"
        >
          <i class="bi bi-list"></i>
        </button>

        <div class="thread-avatar">
          <img
            v-if="safeAvatarUrl"
            :src="safeAvatarUrl"
            alt="Клієнт"
            @error="avatarFailed = true"
          >
          <span v-else>{{ displayInitial }}</span>
        </div>

        <div class="thread-meta">
          <div class="title-row">
            <h2>{{ activeChat?.customer_name || 'Чат' }}</h2>
          </div>

          <div v-if="metaSubtitle" class="subtitle-row">
            <span class="platform-pill">{{ platformLabel }}</span>
            <span v-if="originBadgeLabel" class="source-pill" :class="originBadgeClass">
              {{ originBadgeLabel }}
            </span>
            <span class="meta-subtitle-text">{{ metaSubtitle }}</span>
            <a
              v-if="originContext?.url"
              :href="originContext.url"
              target="_blank"
              rel="noopener noreferrer"
              class="source-link-inline"
            >
              Джерело
            </a>
          </div>
        </div>
      </div>

      <div class="thread-actions">
        <label class="stage-picker">
          <span>Етап</span>
          <select
            v-model="localStage"
            :disabled="!activeChat?.conversation_id"
            @change="commitStage"
          >
            <option v-for="option in stageOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </label>

        <button
          type="button"
          class="thread-action-btn profile-open-btn"
          title="Профіль клієнта"
          @click="$emit('open-profile')"
        >
          <i class="bi bi-person-lines-fill"></i>
        </button>

        <button
          type="button"
          class="thread-action-btn"
          :disabled="isArchiving"
          title="Прибрати з інбоксу"
          @click="$emit('delete-conversation')"
        >
          <i class="bi bi-trash"></i>
        </button>

        <button
          type="button"
          class="thread-action-btn"
          :class="{ 'is-syncing': isSyncing }"
          :disabled="isSyncing || loading"
          title="Оновити історію переписки"
          @click="$emit('force-sync')"
        >
          <i class="bi bi-arrow-repeat"></i>
        </button>
      </div>
    </header>

    <div v-if="originContext || syncNotice" class="thread-context-stack">
      <div v-if="originContext" class="thread-origin-card" :class="{ 'has-embed': originEmbedUrl }">
        <div class="origin-head-row">
          <div class="origin-copy">
            <span class="origin-label">{{ originContext.summary }}</span>
            <strong>{{ originTitle }}</strong>
            <span v-if="originPreviewTitle" class="origin-preview-title">{{ originPreviewTitle }}</span>
            <span v-if="originPreviewDescription" class="origin-preview-description">{{ originPreviewDescription }}</span>
            <span v-else-if="originSourceDisplay" class="origin-source-meta">
              {{ originSourceTitle }}: {{ originSourceDisplay }}
            </span>
          </div>
          <a
            v-if="originContext.url"
            :href="originContext.url"
            target="_blank"
            rel="noopener noreferrer"
            class="origin-link-btn"
          >
            Відкрити
          </a>
        </div>

        <div v-if="originEmbedUrl" class="origin-embed-frame">
          <iframe
            :src="originEmbedUrl"
            title="Джерело коментаря"
            loading="lazy"
            scrolling="no"
            allowfullscreen
          ></iframe>
        </div>

        <div v-else-if="originPreviewImage" class="origin-preview-media">
          <img :src="originPreviewImage" alt="Джерело коментаря" loading="lazy">
        </div>
      </div>

      <div v-if="syncNotice" class="thread-sync-notice" :class="`is-${syncNotice.type}`">
        <i class="bi" :class="syncNotice.type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'"></i>
        <span>{{ syncNotice.text }}</span>
      </div>
    </div>

    <div ref="threadBody" class="chat-thread-body">
      <div v-if="loading" class="chat-state-block">
        <div class="spinner"></div>
        <span>Завантаження історії…</span>
      </div>

      <div v-else-if="!messages.length" class="chat-state-block is-empty">
        <i class="bi bi-chat-square"></i>
        <strong>Поки що без повідомлень</strong>
        <p>Почни діалог або підтягни історію через синхронізацію.</p>
      </div>

      <template v-else>
        <div
          v-for="(group, index) in groupedMessages"
          :key="`${group.label}-${index}`"
          class="message-group"
        >
          <div class="date-separator">
            <span>{{ group.label }}</span>
          </div>

          <ChatMessage
            v-for="message in group.items"
            :key="message.id"
            :message="message"
            :is-mine="message.direction === 'outbound'"
          />
        </div>
      </template>
    </div>

    <ChatInput
      :disabled="isSending"
      :platform="activeChat?.platform"
      @send="$emit('send', $event)"
    />
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import ChatInput from './ChatInput.vue';
import ChatMessage from './ChatMessage.vue';

const props = defineProps({
  activeChat: { type: Object, default: null },
  messages: { type: Array, default: () => [] },
  isSending: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  isSyncing: { type: Boolean, default: false },
  isArchiving: { type: Boolean, default: false },
  syncNotice: { type: Object, default: null },
});

const emit = defineEmits(['send', 'force-sync', 'delete-conversation', 'open-list', 'open-profile', 'update-stage']);

const threadBody = ref(null);
const localStage = ref('');
const avatarFailed = ref(false);

const stageOptions = [
  { value: '', label: 'Без етапу' },
  { value: 'new', label: 'Новий' },
  { value: 'waiting_reply', label: 'Чекаємо відповідь' },
  { value: 'order_confirmed', label: 'Замовлення підтверджене' },
  { value: 'done', label: 'Виконано' },
  { value: 'closed', label: 'Закрито' },
];

const safeAvatarUrl = computed(() => {
  if (avatarFailed.value) {
    return '';
  }

  return props.activeChat?.customer_avatar || props.activeChat?.fb_profile_pic || '';
});

const displayInitial = computed(() => (props.activeChat?.customer_name || '?').charAt(0).toUpperCase());
const platformLabel = computed(() => (
  props.activeChat?.platform === 'instagram' ? 'Instagram' : 'Messenger'
));
const metaSubtitle = computed(() => {
  const username = String(props.activeChat?.external_username || '').trim();
  if (username) {
    return `@${username.replace(/^@/, '')}`;
  }

  return props.activeChat?.platform === 'instagram'
    ? 'Instagram Direct'
    : 'Messenger чат';
});

const originContext = computed(() => props.activeChat?.origin_context || null);
const originTitle = computed(() => {
  if (!originContext.value) {
    return '';
  }

  return originContext.value.object_type === 'ad'
    ? 'Коментар до реклами'
    : originContext.value.object_type === 'story'
      ? 'Відповідь на сторіс'
      : originContext.value.object_type === 'reel'
        ? 'Коментар до reels'
        : 'Коментар до поста';
});
const originSourceTitle = computed(() => originContext.value?.source_title || 'Джерело');
const originSourceDisplay = computed(() => originContext.value?.source_display || '');
const originEmbedUrl = computed(() => originContext.value?.embed_url || '');
const originPreviewImage = computed(() => originContext.value?.preview_image_url || '');
const originPreviewTitle = computed(() => originContext.value?.preview_title || '');
const originPreviewDescription = computed(() => originContext.value?.preview_description || '');
const originBadgeLabel = computed(() => {
  if (!originContext.value) {
    return '';
  }

  return originContext.value.object_type === 'ad'
    ? 'Реклама'
    : originContext.value.object_type === 'story'
      ? 'Сторіс'
      : originContext.value.object_type === 'reel'
        ? 'Reels'
        : 'Пост';
});
const originBadgeClass = computed(() => (
  originContext.value ? `source-${originContext.value.object_type || 'comment'}` : ''
));

const groupedMessages = computed(() => {
  const groups = [];

  props.messages.forEach((message) => {
    const date = new Date(message.created_at || Date.now());
    const label = date.toLocaleDateString('uk-UA', {
      day: '2-digit',
      month: 'long',
    });

    const lastGroup = groups[groups.length - 1];
    if (lastGroup?.label === label) {
      lastGroup.items.push(message);
      return;
    }

    groups.push({ label, items: [message] });
  });

  return groups;
});

function commitStage() {
  emit('update-stage', {
    conversationId: props.activeChat?.conversation_id,
    stage: localStage.value || null,
  });
}

function scrollToBottom() {
  if (!threadBody.value) {
    return;
  }

  threadBody.value.scrollTop = threadBody.value.scrollHeight;
}

onMounted(scrollToBottom);

watch(
  () => props.messages.length,
  async () => {
    await nextTick();
    scrollToBottom();
  }
);

watch(
  () => props.loading,
  async (value) => {
    if (!value) {
      await nextTick();
      scrollToBottom();
    }
  }
);

watch(
  () => props.activeChat?.stage,
  (value) => {
    localStage.value = value ?? '';
  },
  { immediate: true }
);

watch(
  () => [props.activeChat?.customer_avatar, props.activeChat?.fb_profile_pic],
  () => {
    avatarFailed.value = false;
  }
);
</script>

<style scoped>
.chat-thread-shell {
  display: flex;
  flex-direction: column;
  min-height: 0;
  height: 100%;
  background: #fff;
}

.chat-thread-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 18px 20px;
  border-bottom: 1px solid #e5e7eb;
  background: #fff;
}

.thread-context-stack {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 10px 18px 0;
  background: #ffffff;
}

.thread-origin-card,
.thread-sync-notice {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
}

.thread-origin-card {
  flex-direction: column;
  align-items: stretch;
}

.origin-head-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.origin-preview-media {
  width: 100%;
  max-width: 500px;
  border-radius: 16px;
  overflow: hidden;
  background: #e2e8f0;
  border: 1px solid #dbe4ee;
}

.origin-preview-media img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.origin-copy {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.origin-label {
  font-size: 11px;
  line-height: 1.2;
  font-weight: 700;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.origin-copy strong {
  font-size: 13px;
  line-height: 1.3;
  color: #0f172a;
}

.origin-preview-title {
  font-size: 13px;
  line-height: 1.35;
  color: #0f172a;
  font-weight: 600;
}

.origin-preview-description {
  font-size: 12px;
  line-height: 1.4;
  color: #64748b;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.origin-source-meta {
  font-size: 12px;
  line-height: 1.35;
  color: #475569;
  word-break: break-word;
}

.origin-embed-frame {
  width: 100%;
  max-width: 500px;
  border-radius: 16px;
  overflow: hidden;
  border: 1px solid #dbe4ee;
  background: #ffffff;
}

.origin-embed-frame iframe {
  width: 100%;
  min-height: 560px;
  border: 0;
  display: block;
  background: #ffffff;
}

.origin-link-btn {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 34px;
  padding: 0 12px;
  border-radius: 10px;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  color: #0f172a;
  text-decoration: none;
  font-size: 12px;
  font-weight: 700;
}

.thread-sync-notice {
  justify-content: flex-start;
  font-size: 12px;
  color: #0f172a;
}

.thread-sync-notice i {
  font-size: 14px;
}

.thread-sync-notice.is-success {
  border-color: #bbf7d0;
  background: #f0fdf4;
  color: #166534;
}

.thread-sync-notice.is-error {
  border-color: #fecaca;
  background: #fef2f2;
  color: #b91c1c;
}

.thread-user-block {
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 14px;
}

.thread-mobile-btn {
  display: none;
  width: 40px;
  height: 40px;
  border: 1px solid #d1d5db;
  border-radius: 10px;
  background: #fff;
  color: #0f172a;
}

.thread-avatar {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  overflow: hidden;
  flex-shrink: 0;
  background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #0f172a;
  font-size: 18px;
  font-weight: 800;
}

.thread-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.thread-meta {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.title-row,
.subtitle-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.title-row h2 {
  margin: 0;
  font-size: 18px;
  line-height: 1.1;
  font-weight: 600;
  color: #0f172a;
}

.subtitle-row {
  color: #64748b;
  font-size: 13px;
  font-weight: 500;
}

.platform-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 24px;
  padding: 0 8px;
  border-radius: 999px;
  background: #eef2ff;
  color: #3b82f6;
  font-size: 12px;
  font-weight: 700;
}

.source-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 24px;
  padding: 0 8px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
}

.source-pill.source-post {
  background: #eff6ff;
  color: #1d4ed8;
}

.source-pill.source-story {
  background: #fef3c7;
  color: #b45309;
}

.source-pill.source-ad {
  background: #dcfce7;
  color: #15803d;
}

.source-pill.source-reel {
  background: #f5f3ff;
  color: #7c3aed;
}

.meta-subtitle-text {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.source-link-inline {
  color: #2563eb;
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
}

.thread-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.stage-picker {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 10px;
  height: 34px;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  background: #fff;
}

.stage-picker span {
  font-size: 11px;
  font-weight: 700;
  color: #64748b;
}

.stage-picker select {
  min-width: 156px;
  border: none;
  background: transparent;
  color: #0f172a;
  font-size: 13px;
  font-weight: 600;
  outline: none;
}

.thread-action-btn {
  width: 44px;
  height: 44px;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  background: #fff;
  color: #4b5563;
  transition: background 0.18s ease, border-color 0.18s ease;
}

.profile-open-btn {
  width: 44px;
  height: 44px;
}

.thread-action-btn:hover {
  background: #f9fafb;
  border-color: #9ca3af;
}

.thread-action-btn.is-syncing i {
  animation: spin 0.8s linear infinite;
}

.chat-thread-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 14px 20px 24px;
  background: #fff;
}

.message-group {
  display: flex;
  flex-direction: column;
}

.date-separator {
  display: flex;
  justify-content: center;
  margin: 10px 0 18px;
}

.date-separator span {
  padding: 6px 12px;
  border-radius: 999px;
  background: #f3f4f6;
  border: 1px solid #e5e7eb;
  color: #64748b;
  font-size: 12px;
  font-weight: 600;
}

.chat-state-block {
  height: 100%;
  min-height: 260px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  color: #64748b;
  text-align: center;
}

.chat-state-block.is-empty i {
  font-size: 38px;
  color: #94a3b8;
}

.chat-state-block strong {
  font-size: 16px;
  font-weight: 800;
  color: #0f172a;
}

.chat-state-block p {
  margin: 0;
  max-width: 280px;
  line-height: 1.5;
}

.spinner {
  width: 22px;
  height: 22px;
  border: 2px solid rgba(148, 163, 184, 0.22);
  border-top-color: #0ea5e9;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

@media (max-width: 768px) {
  .chat-thread-header {
    padding: 16px;
    align-items: flex-start;
    flex-direction: column;
  }

  .thread-mobile-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .thread-user-block,
  .thread-actions {
    width: 100%;
  }

  .thread-actions {
    justify-content: space-between;
  }

  .origin-head-row {
    flex-direction: column;
  }

  .origin-link-btn {
    width: 100%;
    justify-content: center;
  }

  .origin-embed-frame iframe {
    min-height: 460px;
  }

  .stage-picker {
    width: 100%;
    justify-content: space-between;
  }

  .stage-picker select {
    min-width: 0;
    width: 100%;
  }

  .chat-thread-body {
    padding: 18px 14px;
  }
}
</style>
