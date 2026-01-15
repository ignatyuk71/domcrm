<template>
  <div class="chat-message" :class="{ mine: isMine }">
    <div class="chat-message-bubble">
      
      <div v-if="hasAttachments" class="message-attachments">
        <template v-for="(att, index) in normalizedAttachments" :key="index">
          
          <div v-if="att.type === 'image'" class="attachment-img-wrapper">
            <img 
              :src="att.url" 
              alt="attachment" 
              loading="lazy"
              @click="openImage(att.url)"
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
      {{ message.created_at || '' }}
      <span v-if="isMine && message.id" class="ms-1">
        <i class="bi bi-check2-all"></i>
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

// Перевіряємо, чи є вкладення
const hasAttachments = computed(() => {
  return props.message.attachments && Array.isArray(props.message.attachments) && props.message.attachments.length > 0;
});

// Нормалізація даних вкладень (Facebook може слати різну структуру)
const normalizedAttachments = computed(() => {
  if (!hasAttachments.value) return [];
  
  return props.message.attachments.map(att => {
    // Якщо прийшов просто рядок URL
    if (typeof att === 'string') {
      return { type: 'image', url: att };
    }
    
    // Якщо прийшов об'єкт Facebook (payload.url)
    const url = att.payload?.url || att.url;
    const type = att.type || (url?.match(/\.(jpeg|jpg|gif|png)$/) != null ? 'image' : 'file');
    
    return { type, url };
  });
});

// Функція для відкриття зображення (можна підключити лайтбокс пізніше)
function openImage(url) {
  window.open(url, '_blank');
}
</script>

<style scoped>
/* Загальні стилі контейнера повідомлення */
.chat-message {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  max-width: 75%;
  margin-bottom: 12px;
}

.chat-message.mine {
  align-items: flex-end;
  align-self: flex-end; /* Щоб притискало вправо у флекс-контейнері */
}

/* Бульбашка (фон) */
.chat-message-bubble {
  background: #f1f5f9; /* Сірий для вхідних */
  border-radius: 18px;
  padding: 12px 16px;
  color: #1e293b;
  position: relative;
  border-bottom-left-radius: 4px; /* "Хвостик" зліва */
}

.chat-message.mine .chat-message-bubble {
  background: #3b82f6; /* Синій для своїх */
  color: #fff;
  border-bottom-left-radius: 18px;
  border-bottom-right-radius: 4px; /* "Хвостик" справа */
}

/* Стилі тексту */
.message-text {
  white-space: pre-wrap; /* Зберігає переноси рядків */
  word-wrap: break-word;
  line-height: 1.5;
}

/* Стилі вкладень */
.message-attachments {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 8px;
}

/* Якщо картинок декілька, робимо сітку */
.attachment-img-wrapper {
  max-width: 100%;
  border-radius: 12px;
  overflow: hidden;
  cursor: pointer;
}

.message-attachments img {
  display: block;
  max-width: 250px;
  max-height: 300px;
  object-fit: cover;
  border-radius: 12px;
  transition: opacity 0.2s;
}

.message-attachments img:hover {
  opacity: 0.9;
}

/* Стиль для файлів */
.attachment-file {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  background: rgba(0,0,0,0.05);
  border-radius: 8px;
  text-decoration: none;
  color: inherit;
  font-size: 0.9rem;
  font-weight: 500;
}

.chat-message.mine .attachment-file {
  background: rgba(255,255,255,0.2);
  color: #fff;
}

/* Час */
.chat-message-time {
  font-size: 0.7rem;
  color: #94a3b8;
  padding: 0 4px;
  display: flex;
  align-items: center;
}
</style>