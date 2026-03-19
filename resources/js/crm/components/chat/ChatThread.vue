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

          <div class="subtitle-row">
            <span class="assignment-label">Призначити цю переписку</span>
            <i class="bi bi-caret-down-fill"></i>
          </div>
        </div>
      </div>

      <div class="thread-actions">
        <button
          type="button"
          class="thread-action-btn"
          title="Позначка"
        >
          <i class="bi bi-bookmark-fill"></i>
        </button>

        <button
          type="button"
          class="thread-action-btn"
          title="Видалити"
        >
          <i class="bi bi-trash"></i>
        </button>

        <button
          type="button"
          class="thread-action-btn"
          title="Обране"
        >
          <i class="bi bi-star-fill"></i>
        </button>

        <button
          type="button"
          class="thread-action-btn"
          title="Позначити непрочитаним"
        >
          <i class="bi bi-envelope-fill"></i>
        </button>

        <button
          type="button"
          class="thread-action-btn"
          :class="{ 'is-syncing': isSyncing }"
          :disabled="isSyncing || loading"
          title="Оновити"
          @click="$emit('force-sync')"
        >
          <i class="bi bi-check-lg"></i>
        </button>
      </div>
    </header>

    <div class="thread-stage-row">
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
        class="profile-open-btn"
        title="Профіль клієнта"
        @click="$emit('open-profile')"
      >
        <i class="bi bi-person-lines-fill"></i>
      </button>
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
});

const emit = defineEmits(['send', 'force-sync', 'open-list', 'open-profile', 'update-stage']);

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
  padding: 14px 18px;
  border-bottom: 1px solid #e5e7eb;
  background: #fff;
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
  width: 48px;
  height: 48px;
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
  gap: 6px;
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
  font-size: 16px;
  line-height: 1.1;
  font-weight: 700;
  color: #0f172a;
}

.subtitle-row {
  color: #4b5563;
  font-size: 13px;
  font-weight: 600;
}

.assignment-label {
  color: #4b5563;
}

.subtitle-row i {
  font-size: 11px;
}

.thread-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.thread-stage-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 18px;
  border-bottom: 1px solid #e5e7eb;
}

.stage-picker {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border: 1px solid #d1d5db;
  border-radius: 10px;
  background: #fff;
}

.stage-picker span {
  font-size: 12px;
  font-weight: 800;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.stage-picker select {
  min-width: 170px;
  border: none;
  background: transparent;
  color: #0f172a;
  font-size: 14px;
  font-weight: 700;
  outline: none;
}

.thread-action-btn {
  width: 40px;
  height: 40px;
  border: 1px solid #d1d5db;
  border-radius: 10px;
  background: #fff;
  color: #0f172a;
  transition: background 0.18s ease, border-color 0.18s ease;
}

.profile-open-btn {
  width: 40px;
  height: 40px;
  border: 1px solid #d1d5db;
  border-radius: 10px;
  background: #fff;
  color: #111827;
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
  padding: 12px 18px 24px;
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

  .thread-stage-row {
    padding: 10px 16px;
  }

  .stage-picker {
    flex: 1;
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
