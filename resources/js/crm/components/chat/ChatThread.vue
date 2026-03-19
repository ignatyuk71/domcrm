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
            <span class="platform-pill" :class="platformClass">
              <i :class="platformIcon"></i>
              {{ platformLabel }}
            </span>
          </div>

          <div class="subtitle-row">
            <span v-if="activeChat?.external_username">@{{ sanitizedUsername }}</span>
            <span v-if="activeChat?.last_message_time">
              Оновлено {{ formattedLastActivity }}
            </span>
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
          class="thread-action-btn"
          :class="{ 'is-syncing': isSyncing }"
          :disabled="isSyncing || loading"
          title="Синхронізувати"
          @click="$emit('force-sync')"
        >
          <i class="bi bi-arrow-clockwise"></i>
        </button>

        <button
          type="button"
          class="thread-action-btn"
          title="Профіль клієнта"
          @click="$emit('open-profile')"
        >
          <i class="bi bi-layout-text-sidebar-reverse"></i>
        </button>
      </div>
    </header>

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

const platformClass = computed(() => (
  props.activeChat?.platform === 'instagram' ? 'is-instagram' : 'is-messenger'
));

const platformIcon = computed(() => (
  props.activeChat?.platform === 'instagram' ? 'bi bi-instagram' : 'bi bi-messenger'
));

const platformLabel = computed(() => (
  props.activeChat?.platform === 'instagram' ? 'Instagram' : 'Messenger'
));

const sanitizedUsername = computed(() => String(props.activeChat?.external_username || '').replace(/^@/, ''));

const formattedLastActivity = computed(() => {
  if (!props.activeChat?.last_message_time) {
    return '';
  }

  const date = new Date(props.activeChat.last_message_time);
  return date.toLocaleString('uk-UA', {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
});

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
  background:
    radial-gradient(circle at top right, rgba(14, 165, 233, 0.08), transparent 28%),
    linear-gradient(180deg, #ffffff, #f8fafc 100%);
}

.chat-thread-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 18px 24px;
  border-bottom: 1px solid rgba(148, 163, 184, 0.16);
  background: rgba(255, 255, 255, 0.84);
  backdrop-filter: blur(10px);
}

.thread-user-block {
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 14px;
}

.thread-mobile-btn {
  display: none;
  width: 42px;
  height: 42px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  border-radius: 14px;
  background: #fff;
  color: #0f172a;
}

.thread-avatar {
  width: 52px;
  height: 52px;
  border-radius: 18px;
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
  font-size: 20px;
  line-height: 1.1;
  font-weight: 800;
  color: #0f172a;
}

.subtitle-row {
  color: #64748b;
  font-size: 13px;
  font-weight: 600;
}

.platform-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 999px;
  color: #fff;
  font-size: 12px;
  font-weight: 800;
}

.platform-pill.is-messenger {
  background: linear-gradient(135deg, #0ea5e9, #2563eb);
}

.platform-pill.is-instagram {
  background: linear-gradient(135deg, #e11d48, #f97316 55%, #9333ea);
}

.thread-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.stage-picker {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  border-radius: 16px;
  background: rgba(248, 250, 252, 0.86);
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
  width: 44px;
  height: 44px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.88);
  color: #0f172a;
  transition: transform 0.18s ease, border-color 0.18s ease, color 0.18s ease;
}

.thread-action-btn:hover {
  transform: translateY(-1px);
  border-color: rgba(14, 165, 233, 0.32);
  color: #0284c7;
}

.thread-action-btn.is-syncing i {
  animation: spin 0.8s linear infinite;
}

.chat-thread-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 22px 24px;
  background:
    linear-gradient(180deg, rgba(248, 250, 252, 0.78), rgba(255, 255, 255, 0.92)),
    radial-gradient(circle at center, rgba(226, 232, 240, 0.4), transparent 55%);
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
  background: rgba(255, 255, 255, 0.86);
  border: 1px solid rgba(148, 163, 184, 0.18);
  color: #64748b;
  font-size: 12px;
  font-weight: 800;
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
