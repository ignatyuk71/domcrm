<template>
  <div class="chat-thread-wrapper">
    <header class="chat-thread-header">
      <div class="chat-thread-user">
        <div class="chat-thread-avatar">
          <img v-if="activeChat?.customer_avatar" :src="activeChat.customer_avatar" alt="avatar" />
          <span v-else class="chat-thread-avatar-fallback">
            {{ (activeChat?.customer_name || '?').charAt(0).toUpperCase() }}
          </span>
        </div>
        <div class="chat-thread-meta">
          <h2 class="chat-thread-title">{{ activeChat?.customer_name || 'Чат' }}</h2>
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
      <div v-if="!messages.length" class="chat-thread-empty">
        Немає повідомлень
      </div>

      <ChatMessage
        v-for="message in messages"
        :key="message.id"
        :message="message"
        :is-mine="message.direction === 'outbound'"
      />
    </div>

    <ChatInput
      :disabled="isSending"
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
});

defineEmits(['send']);

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
</style>
