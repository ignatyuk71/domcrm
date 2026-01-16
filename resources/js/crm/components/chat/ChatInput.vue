<template>
  <form class="chat-input" @submit.prevent="handleSend">
    <div v-if="isWindowClosed" class="window-closed-alert">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span>24-годинне вікно закрите. Ви зможете відповісти, коли клієнт напише вам знову.</span>
    </div>

    <div class="chat-input-row">
      <textarea
        v-model="text"
        class="chat-textarea"
        rows="2"
        :placeholder="isWindowClosed ? 'Відповідь заблокована' : 'Напишіть повідомлення...'"
        :disabled="disabled || isWindowClosed"
        @keydown.ctrl.enter.prevent="handleSend"
      ></textarea>
      
      <button 
        class="chat-send-btn" 
        type="submit" 
        :disabled="disabled || !canSend || isWindowClosed"
      >
        <i class="bi bi-send"></i>
      </button>
    </div>

    <div v-if="!isWindowClosed" class="chat-attachment-row">
      <button
        type="button"
        class="chat-attachment-btn"
        :disabled="disabled"
        @click="toggleAttachment"
      >
        <i class="bi bi-paperclip"></i>
        Додати посилання на файл
      </button>
      <input
        v-if="showAttachment"
        v-model="attachmentUrl"
        type="url"
        class="chat-attachment-input"
        placeholder="https://..."
        :disabled="disabled"
      />
    </div>
  </form>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  disabled: { type: Boolean, default: false },
  // Додаємо дату останнього повідомлення від клієнта
  lastCustomerMessageAt: { type: String, default: null }, 
});

const emit = defineEmits(['send']);

const text = ref('');
const attachmentUrl = ref('');
const showAttachment = ref(false);

// Логіка перевірки 24 годин
const isWindowClosed = computed(() => {
  if (!props.lastCustomerMessageAt) return false;
  
  const lastDate = new Date(props.lastCustomerMessageAt);
  const now = new Date();
  const diffInHours = (now - lastDate) / (1000 * 60 * 60);
  
  return diffInHours >= 24; // Якщо минуло 24 години або більше
});

const canSend = computed(() => {
  if (isWindowClosed.value) return false;
  return text.value.trim().length > 0 || attachmentUrl.value.trim().length > 0;
});

function toggleAttachment() {
  showAttachment.value = !showAttachment.value;
  if (!showAttachment.value) {
    attachmentUrl.value = '';
  }
}

function handleSend() {
  if (!canSend.value) return;

  const url = attachmentUrl.value.trim();
  const attachments = url
    ? [
        {
          type: /\.(jpeg|jpg|gif|png|webp)$/i.test(url) ? 'image' : 'file',
          url,
        },
      ]
    : [];

  emit('send', {
    text: text.value.trim(),
    attachments,
  });

  text.value = '';
  attachmentUrl.value = '';
  showAttachment.value = false;
}
</script>

<style scoped>
/* Додаємо стилі для попередження */
.window-closed-alert {
  background: #fef2f2;
  border: 1px solid #fee2e2;
  color: #b91c1c;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 0.85rem;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 4px;
}

.chat-input {
  border-top: 1px solid #e2e8f0;
  padding: 12px 16px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* Стараємося зробити поле сірим, коли воно заблоковане */
.chat-textarea:disabled {
  background: #f8fafc;
  color: #94a3b8;
  cursor: not-allowed;
}

.chat-input-row {
  display: flex;
  gap: 10px;
  align-items: flex-end;
}

.chat-textarea {
  flex: 1;
  resize: none;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 10px 12px;
  font-size: 0.9rem;
}

.chat-textarea:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.chat-send-btn {
  background: #3b82f6;
  border: none;
  color: #fff;
  border-radius: 12px;
  padding: 10px 14px;
  cursor: pointer;
  transition: background 0.2s ease;
}

.chat-send-btn:disabled {
  background: #cbd5e1; /* Більш нейтральний сірий */
  cursor: not-allowed;
}

.chat-attachment-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.chat-attachment-btn {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  color: #64748b;
  border-radius: 10px;
  padding: 6px 10px;
  font-size: 0.8rem;
  cursor: pointer;
}

.chat-attachment-input {
  flex: 1;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 6px 10px;
  font-size: 0.85rem;
}
</style>