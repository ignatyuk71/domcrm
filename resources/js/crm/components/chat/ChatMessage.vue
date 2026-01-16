<template>
  <div class="chat-message" :class="{ mine: isMine }">
    <div class="chat-message-bubble">
      <div class="message-text">
        {{ message.text || '...' }}
      </div>
    </div>

    <div class="chat-message-time">
      {{ formattedTime }}
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  message: { type: Object, required: true },
  isMine: { type: Boolean, default: false },
});

// Форматування часу для відображення
const formattedTime = computed(() => {
  if (!props.message.created_at) return '';
  // Відображаємо лише години та хвилини для компактності
  const date = new Date(props.message.created_at);
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
});
</script>

<style scoped>
.chat-message {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  max-width: 80%;
  margin-bottom: 8px;
  animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

.chat-message.mine {
  align-items: flex-end;
  align-self: flex-end;
}

.chat-message-bubble {
  background: #f1f5f9;
  border-radius: 16px;
  padding: 10px 14px;
  color: #1e293b;
  border-bottom-left-radius: 4px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}

.chat-message.mine .chat-message-bubble {
  background: #3b82f6;
  color: #fff;
  border-bottom-left-radius: 16px;
  border-bottom-right-radius: 4px;
}

.message-text {
  white-space: pre-wrap;
  word-wrap: break-word;
  line-height: 1.4;
  font-size: 0.95rem;
}

.chat-message-time {
  font-size: 0.7rem;
  color: #94a3b8;
  padding: 0 2px;
}

</style>
