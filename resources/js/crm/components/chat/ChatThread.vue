<template>
  <div class="chat-thread-wrapper">
    <header class="chat-thread-header">
      <h2 class="chat-thread-title">{{ activeChat?.customer_name || 'Чат' }}</h2>
      <span v-if="activeChat?.last_message_time" class="chat-thread-subtitle">
        {{ activeChat.last_message_time }}
      </span>
    </header>

    <div class="chat-thread-body">
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
  </div>
</template>

<script setup>
import ChatMessage from './ChatMessage.vue';

defineProps({
  activeChat: { type: Object, default: null },
  messages: { type: Array, default: () => [] },
});
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
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
}

.chat-thread-title {
  font-size: 1rem;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
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
