<template>
  <div class="chat-thread-wrapper">
    <header class="chat-thread-header">
      <!-- ЛІВА ЧАСТИНА: Меню (моб) + Аватар + Інфо -->
      <div class="header-left-group">
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
            <h2 class="chat-thread-title">{{ activeChat?.customer_name || 'Чат' }}</h2>
            
            <div class="meta-row">
              <div class="chat-thread-platform" v-if="activeChat?.platform">
                <i :class="activeChat.platform === 'instagram' ? 'bi bi-instagram' : 'bi bi-messenger'"></i>
                <span>{{ activeChat.platform === 'instagram' ? 'Instagram' : 'Facebook' }}</span>
              </div>
              <span v-if="activeChat?.last_message_time" class="chat-time-separator">• {{ activeChat.last_message_time }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- ПРАВА ЧАСТИНА: Кнопки дій (Оновити + Профіль) -->
      <div class="chat-thread-actions">
        <button
          type="button"
          class="action-btn-icon"
          :class="{ 'is-syncing': isSyncing }"
          :disabled="isSyncing || loading"
          title="Оновити історію"
          @click="$emit('force-sync')"
        >
          <i class="bi bi-arrow-clockwise"></i>
        </button>

        <button
          type="button"
          class="action-btn-icon"
          title="Профіль клієнта"
          @click="$emit('open-profile')"
        >
          <i class="bi bi-person-circle"></i>
        </button>
      </div>
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
  loading: { type: Boolean, default: false },
  isSyncing: { type: Boolean, default: false },
});

defineEmits(['send', 'force-sync', 'open-list', 'open-profile']);

const threadBody = ref(null);

function scrollToBottom() {
  if (!threadBody.value) return;
  threadBody.value.scrollTop = threadBody.value.scrollHeight;
}

onMounted(() => {
  scrollToBottom();
});

watch(
  () => props.messages.length,
  async () => {
    await nextTick();
    scrollToBottom();
  }
);

watch(
  () => props.loading,
  async (newVal) => {
    if (!newVal) {
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
  background: #fff;
  position: relative;
}

/* --- HEADER --- */
.chat-thread-header {
  padding: 12px 20px;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: space-between; /* Розносить ліву і праву групи */
  gap: 12px;
  background: #ffffff;
  height: 64px;
  flex-shrink: 0;
}

/* Ліва група (Меню + Аватар + Текст) */
.header-left-group {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1; /* Займає весь вільний простір */
  min-width: 0; /* Для обрізки тексту */
}

.chat-thread-user {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1;
  min-width: 0;
}

.chat-thread-avatar {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  overflow: hidden;
  background: #f1f5f9;
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
  color: #64748b;
  font-size: 1rem;
}

.chat-thread-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0; /* Важливо для обрізки */
}

.chat-thread-title {
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.meta-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #64748b;
}

.chat-thread-platform {
  display: flex;
  align-items: center;
  gap: 4px;
}

.chat-time-separator {
  white-space: nowrap;
  opacity: 0.7;
}

/* Кнопка списку (мобільна) */
.chat-thread-list-btn {
  display: none; /* Прихована на десктопі */
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #475569;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-shrink: 0;
}
.chat-thread-list-btn:hover { background: #f8fafc; }

/* ПРАВА ЧАСТИНА: Кнопки дій */
.chat-thread-actions {
  display: flex;
  align-items: center;
  gap: 8px; /* Відступ між кнопками */
  flex-shrink: 0;
}

.action-btn-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid #e2e8f0; /* Легка рамка */
  background: #fff;
  color: #64748b;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s ease;
}

.action-btn-icon:hover {
  background: #f1f5f9;
  color: #3b82f6;
  border-color: #cbd5e1;
}

.action-btn-icon:active {
  transform: scale(0.96);
}

.action-btn-icon.is-syncing i {
  animation: spin 1s linear infinite;
}

/* --- BODY --- */
.chat-thread-body {
  flex: 1;
  padding: 16px 20px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  background: #f8fafc; /* Трохи світліший фон для чату */
}

.chat-thread-empty {
  color: #94a3b8;
  text-align: center;
  margin-top: 40px;
  font-size: 0.9rem;
}

/* LOADING */
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
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* --- MOBILE ADAPTATION --- */
@media (max-width: 768px) {
  .chat-thread-header {
    padding: 10px 14px; /* Менші відступи */
  }

  .chat-thread-list-btn {
    display: inline-flex; /* Показуємо кнопку меню */
  }
  
  .chat-thread-user {
    margin-left: 4px; /* Відступ від кнопки меню */
  }
  
  /* Приховуємо час на дуже вузьких екранах, якщо не влазить */
  .chat-time-separator {
    display: none; 
  }
  @media (min-width: 380px) {
    .chat-time-separator { display: inline; }
  }
}
</style>