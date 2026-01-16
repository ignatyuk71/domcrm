<template>
  <div class="chat-message" :class="{ mine: isMine }">
    <div class="chat-message-bubble">
      <div v-if="hasAttachments" class="message-attachments">
        <template v-for="(att, index) in normalizedAttachments" :key="index">
          <div v-if="att.type === 'image'" class="attachment-img-wrapper">
            <img :src="att.url" alt="attachment" loading="lazy" />
          </div>
          <a v-else :href="att.url" target="_blank" class="attachment-file">
            <i class="bi bi-paperclip"></i>
            <span>Завантажити файл</span>
          </a>
        </template>
      </div>

      <div v-if="message.text" class="message-text">
        {{ message.text }}
      </div>
    </div>

    <div class="chat-message-time">
      {{ formattedTime }}
      <span v-if="isMine" class="ms-1 status-icon">
        <i class="bi" :class="statusIcon"></i>
      </span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  message: { type: Object, required: true },
  isMine: { type: Boolean, default: false },
});

const hasAttachments = computed(() => {
  return Array.isArray(props.message.attachments) && props.message.attachments.length > 0;
});

const normalizedAttachments = computed(() => {
  if (!hasAttachments.value) return [];

  return props.message.attachments.map((att) => {
    const url = att?.payload?.url || att?.url || (typeof att === 'string' ? att : '');
    const isImage = typeof url === 'string' && url.match(/\.(jpeg|jpg|gif|png|webp)$/i);
    const type = att?.type || (isImage ? 'image' : 'file');

    return { type, url };
  });
});

// Форматування часу для відображення
const formattedTime = computed(() => {
  if (!props.message.created_at) return '';
  // Відображаємо лише години та хвилини для компактності
  const date = new Date(props.message.created_at);
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
});

const statusIcon = computed(() => {
  if (props.message.status === 'sending') return 'bi-clock';
  if (props.message.is_read || props.message.status === 'read') return 'bi-check2-all';
  return 'bi-check2';
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

.message-attachments {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 6px;
}

.attachment-img-wrapper img {
  display: block;
  max-width: 100%;
  max-height: 300px;
  object-fit: contain;
  border-radius: 8px;
}

.attachment-file {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  background: rgba(0,0,0,0.05);
  border-radius: 8px;
  text-decoration: none;
  color: #475569;
  font-size: 0.85rem;
}

.chat-message.mine .attachment-file {
  background: rgba(255,255,255,0.15);
  color: #fff;
}

.chat-message-time {
  font-size: 0.7rem;
  color: #94a3b8;
  padding: 0 2px;
}

.status-icon {
  font-size: 0.8rem;
}
</style>
