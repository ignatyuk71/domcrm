<template>
  <div class="chat-message" :class="{ mine: isMine }">
    <div class="chat-message-bubble">
      <div v-if="message.reply_to" class="reply-wrapper">
        <div
          v-if="message.reply_to.attachments && message.reply_to.attachments.length"
          class="reply-image"
        >
          <img
            :src="fixUrl(message.reply_to.attachments[0].url)"
            alt="Reply attachment"
            loading="lazy"
          />
        </div>
        <div class="reply-content">
          <span class="reply-author">
            {{ message.reply_to.direction === 'outbound' ? 'Ви' : 'Клієнт' }}
          </span>
          <span class="reply-text">
            {{ message.reply_to.text || 'Вкладення' }}
          </span>
        </div>
      </div>
      <div v-if="hasAttachments" class="message-attachments">
        <template v-for="(att, index) in normalizedAttachments" :key="index">
          
          <div v-if="att.type === 'image'" class="attachment-img-wrapper">
            <img 
              :src="att.url" 
              alt="attachment" 
              loading="lazy" 
              @click="$emit('image-click', att.url)"
            />
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

defineEmits(['image-click']);

// --- Виправлення шляхів для хостингу ---
const fixUrl = (url) => {
  if (!url || typeof url !== 'string') return '';
  
  // Якщо це повне посилання (Facebook CDN) - не чіпаємо
  if (url.startsWith('http')) return url;

  if (url.startsWith('chat/')) return `/${url}`;
  if (url.startsWith('/chat/')) return url;
  
  return url;
};

const hasAttachments = computed(() => {
  return Array.isArray(props.message.attachments) && props.message.attachments.length > 0;
});

const normalizedAttachments = computed(() => {
  if (!hasAttachments.value) return [];

  return props.message.attachments.map((att) => {
    // Дістаємо "сире" посилання
    let rawUrl = att?.payload?.url || att?.url || (typeof att === 'string' ? att : '');
    
    // Виправляємо його через нашу функцію
    const url = fixUrl(rawUrl);

    // Визначаємо тип (картинка чи файл)
    const isImage = typeof url === 'string' && url.match(/\.(jpeg|jpg|gif|png|webp|bmp)($|\?)/i);
    // Якщо тип явно вказаний бекендом, беремо його, інакше вгадуємо по розширенню
    const type = att?.type === 'image' || isImage ? 'image' : 'file';

    return { type, url };
  });
});

// Форматування часу
const formattedTime = computed(() => {
  if (!props.message.created_at) return '';
  const date = new Date(props.message.created_at);
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
});

// Іконки статусу (відправлено, прочитано)
const statusIcon = computed(() => {
  if (props.message.status === 'sending') return 'bi-clock'; // Годинник (відправляється)
  if (props.message.is_read || props.message.status === 'read') return 'bi-check2-all'; // Дві галочки (прочитано)
  return 'bi-check2'; // Одна галочка (доставлено)
});
</script>

<style scoped>
.chat-message {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  max-width: 80%;
  margin-bottom: 12px;
  animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Мої повідомлення (справа) */
.chat-message.mine {
  align-items: flex-end;
  align-self: flex-end;
}

.chat-message-bubble {
  background: #f1f5f9; /* Світло-сірий для вхідних */
  border-radius: 16px;
  padding: 10px 14px;
  color: #1e293b;
  border-bottom-left-radius: 4px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.05);
  position: relative;
}

.chat-message.mine .chat-message-bubble {
  background: #3b82f6; /* Синій для моїх */
  color: #fff;
  border-bottom-left-radius: 16px;
  border-bottom-right-radius: 4px;
}

.reply-wrapper {
  display: flex;
  flex-direction: row;
  gap: 10px;
  padding: 6px 8px;
  border-left: 3px solid rgba(0, 0, 0, 0.2);
  background: rgba(0, 0, 0, 0.05);
  border-radius: 6px;
  margin-bottom: 8px;
  overflow: hidden;
  max-width: 100%;
}

.chat-message.mine .reply-wrapper {
  background: rgba(255, 255, 255, 0.2);
  border-left-color: rgba(255, 255, 255, 0.6);
}

.reply-image {
  flex-shrink: 0;
  width: 80px;
  height: 80px;
  border-radius: 4px;
  overflow: hidden;
  background: rgba(0, 0, 0, 0.1);
}

.reply-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.reply-content {
  display: flex;
  flex-direction: column;
  justify-content: center;
  overflow: hidden;
  flex: 1;
}

.reply-author {
  font-size: 0.75rem;
  font-weight: 700;
  color: inherit;
  opacity: 0.9;
  margin-bottom: 2px;
}

.reply-text {
  font-size: 0.8rem;
  opacity: 0.85;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.2;
}

/* Текст */
.message-text {
  white-space: pre-wrap;
  word-wrap: break-word;
  line-height: 1.5;
  font-size: 0.95rem;
}

/* Вкладення */
.message-attachments {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 8px;
}

/* Картинки */
.attachment-img-wrapper img {
  display: block;
  max-width: 100%;
  max-height: 350px; /* Обмеження висоти, щоб не рвало чат */
  object-fit: cover;
  border-radius: 8px;
  cursor: pointer; /* Курсор при наведенні */
  transition: opacity 0.2s;
}

.attachment-img-wrapper img:hover {
  opacity: 0.9;
}

/* Файли */
.attachment-file {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  background: rgba(0,0,0,0.06);
  border-radius: 8px;
  text-decoration: none;
  color: #334155;
  font-size: 0.9rem;
  font-weight: 500;
  transition: background 0.2s;
}

.attachment-file:hover {
  background: rgba(0,0,0,0.1);
}

.chat-message.mine .attachment-file {
  background: rgba(255,255,255,0.2);
  color: #fff;
}

.chat-message.mine .attachment-file:hover {
  background: rgba(255,255,255,0.3);
}

/* Час */
.chat-message-time {
  font-size: 0.7rem;
  color: #94a3b8;
  padding: 0 4px;
  display: flex;
  align-items: center;
}

.status-icon {
  font-size: 0.85rem;
  margin-left: 4px;
}
</style>
