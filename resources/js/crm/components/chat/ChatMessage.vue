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

// Визначаємо подію, яку будемо посилати нагору
const emit = defineEmits(['image-click']);

// Перевіряємо, чи є вкладення
const hasAttachments = computed(() => {
  return props.message.attachments && Array.isArray(props.message.attachments) && props.message.attachments.length > 0;
});

// Нормалізація даних вкладень
const normalizedAttachments = computed(() => {
  if (!hasAttachments.value) return [];
  
  return props.message.attachments.map(att => {
    // Якщо прийшов просто рядок URL
    if (typeof att === 'string') {
      return { type: 'image', url: att };
    }
    
    // Якщо прийшов об'єкт Facebook
    const url = att.payload?.url || att.url;
    // Проста перевірка на розширення картинки
    const type = att.type || (url?.match(/\.(jpeg|jpg|gif|png|webp)$/i) != null ? 'image' : 'file');
    
    return { type, url };
  });
});

// Функція кліку по картинці
function openImage(url) {
  // Замість відкриття вкладки, емітимо подію для Лайтбоксу
  emit('image-click', url);
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
  /* Анімація появи (якщо раптом TransitionGroup не спрацює) */
  transition: transform 0.2s; 
}

.chat-message.mine {
  align-items: flex-end;
  align-self: flex-end;
}

/* Бульбашка (фон) */
.chat-message-bubble {
  background: #f1f5f9;
  border-radius: 18px;
  padding: 12px 16px;
  color: #1e293b;
  position: relative;
  border-bottom-left-radius: 4px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.05); /* Легка тінь */
}

.chat-message.mine .chat-message-bubble {
  background: #3b82f6;
  color: #fff;
  border-bottom-left-radius: 18px;
  border-bottom-right-radius: 4px;
}

/* Стилі тексту */
.message-text {
  white-space: pre-wrap;
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

.attachment-img-wrapper {
  max-width: 100%;
  border-radius: 12px;
  overflow: hidden;
  cursor: zoom-in; /* Показуємо, що можна збільшити */
}

.message-attachments img {
  display: block;
  max-width: 250px;
  max-height: 300px;
  object-fit: cover;
  border-radius: 12px;
  transition: transform 0.2s, opacity 0.2s;
}

.message-attachments img:hover {
  opacity: 0.95;
  transform: scale(1.02); /* Легкий ефект при наведенні */
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
</style>