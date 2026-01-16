<template>
  <div class="chat-thread-wrapper">
    <header class="chat-thread-header">
      <button
        type="button"
        class="chat-thread-list-btn"
        title="Список чатів"
        @click="$emit('open-list')"
      >
        <i class="bi bi-list"></i>
      </button>
      <div class="chat-thread-user">
        <div class="chat-thread-avatar">
          <img v-if="activeChat?.customer_avatar" :src="activeChat.customer_avatar" alt="avatar" />
          <span v-else class="chat-thread-avatar-fallback">
            {{ (activeChat?.customer_name || '?').charAt(0).toUpperCase() }}
          </span>
        </div>
        <div class="chat-thread-meta">
          <div class="chat-thread-title-row">
            <h2 class="chat-thread-title">{{ activeChat?.customer_name || 'Чат' }}</h2>
            <button
              type="button"
              class="chat-thread-sync"
              :class="{ 'is-syncing': isSyncing }"
              :disabled="isSyncing || loading"
              title="Оновити історію"
              @click="$emit('force-sync')"
            >
              <i class="bi bi-arrow-clockwise"></i>
            </button>
          </div>
          <div class="chat-thread-platform" v-if="activeChat?.platform">
            <i :class="activeChat.platform === 'instagram' ? 'bi bi-instagram' : 'bi bi-messenger'"></i>
            <span>{{ activeChat.platform === 'instagram' ? 'Instagram' : 'Facebook' }}</span>
          </div>
        </div>
      </div>
      <span v-if="activeChat?.last_message_time" class="chat-thread-subtitle">
        {{ activeChat.last_message_time }}
      </span>
    </header>

    <div ref="threadBody" class="chat-thread-body">
      
      <div v-if="loading" class="chat-loading">
        <div class="spinner"></div>
        <span>Завантаження історії...</span>
      </div>

      <div v-else-if="!messages.length" class="chat-thread-empty">
        Немає повідомлень
      </div>

      <template v-else>
        <ChatMessage
          v-for="message in messages"
          :key="message.id"
          :message="message"
          :is-mine="message.direction === 'outbound'"
        />
      </template>
    </div>

    <ChatInput
      :disabled="isSending || loading"
      @send="$emit('send', { ...$event, customer_id: activeChat?.customer_id })"
    />
  </div>
</template>

<script setup>
import { nextTick, onMounted, ref, watch } from 'vue';
import ChatMessage from './ChatMessage.vue';
import ChatInput from './ChatInput.vue';

const props = defineProps({
  activeChat: { type: Object, default: null },
  messages: { type: Array, default: () => [] },
  isSending: { type: Boolean, default: false },
  loading: { type: Boolean, default: false }, // <--- ДОДАНО НОВИЙ PROPS
  isSyncing: { type: Boolean, default: false },
});

defineEmits(['send', 'force-sync', 'open-list']);

const threadBody = ref(null);

function scrollToBottom() {
  if (!threadBody.value) return;
  threadBody.value.scrollTop = threadBody.value.scrollHeight;
}

// Скролимо вниз при монтуванні
onMounted(() => {
  scrollToBottom();
});

// Скролимо вниз, коли приходять нові повідомлення
watch(
  () => props.messages.length,
  async () => {
    await nextTick();
    scrollToBottom();
  }
);

// Також бажано скролити вниз, коли закінчується завантаження
watch(
  () => props.loading,
  async (newVal) => {
    if (!newVal) { // Якщо завантаження завершилось (false)
      await nextTick();
      scrollToBottom();
    }
  }
);
</script>

<style scoped>
.chat-thread-wrapper {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.chat-thread-header {
  padding: 16px 20px;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.chat-thread-user {
  display: flex;
  align-items: center;
  gap: 12px;
}

.chat-thread-avatar {
  width: 44px;
  height: 44px;
  border-radius: 14px;
  overflow: hidden;
  background: #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.chat-thread-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.chat-thread-avatar-fallback {
  font-weight: 700;
  color: #475569;
  font-size: 1rem;
}

.chat-thread-meta {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.chat-thread-title-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.chat-thread-title {
  font-size: 1rem;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
}

.chat-thread-platform {
  font-size: 0.75rem;
  color: #64748b;
  display: flex;
  align-items: center;
  gap: 6px;
}

.chat-thread-subtitle {
  font-size: 0.75rem;
  color: #94a3b8;
}

.chat-thread-list-btn {
  display: none;
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  color: #475569;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  padding: 0;
  flex-shrink: 0;
}

.chat-thread-list-btn:hover {
  background: #e2e8f0;
}

@media (max-width: 768px) {
  .chat-thread-header {
    justify-content: flex-start;
  }

  .chat-thread-list-btn {
    display: inline-flex;
  }
}

.chat-thread-sync {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  border: 1px solid transparent;
  background: rgba(148, 163, 184, 0.12);
  color: #64748b;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s ease;
  padding: 0;
}

.chat-thread-sync:hover:not(:disabled) {
  background: rgba(59, 130, 246, 0.12);
  color: #2563eb;
}

.chat-thread-sync:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.chat-thread-sync.is-syncing i {
  animation: spin 1s linear infinite;
}

.chat-thread-body {
  flex: 1;
  padding: 16px 20px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}

.chat-thread-empty {
  color: #94a3b8;
  text-align: center;
  margin-top: 32px;
  font-size: 0.9rem;
}

/* --- СТИЛІ ДЛЯ ЗАВАНТАЖЕННЯ --- */
.chat-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #94a3b8;
  gap: 12px;
  font-size: 0.9rem;
}

.spinner {
  width: 32px;
  height: 32px;
  border: 3px solid #e2e8f0;
  border-top-color: #3b82f6; /* Синій колір */
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
