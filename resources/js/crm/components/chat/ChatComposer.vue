<template>
  <form class="chat-composer" @submit.prevent="handleSend">
    <div class="input-wrapper">
      <textarea
        ref="textarea"
        v-model="draft"
        rows="1"
        placeholder="Напишіть повідомлення..."
        @input="adjustHeight"
        @keydown.enter.exact.prevent="handleSend"
      ></textarea>
    </div>
    
    <button 
      type="submit" 
      class="send-btn"
      :disabled="!draft.trim() || sending"
    >
      <span v-if="sending" class="spinner-border spinner-border-sm"></span>
      <i v-else class="bi bi-send-fill"></i>
      <span class="ms-2">Надіслати</span>
    </button>
  </form>
</template>

<script setup>
import { ref, nextTick } from 'vue';

const props = defineProps({
  sending: { type: Boolean, default: false },
});

const emit = defineEmits(['send']);
const draft = ref('');
const textarea = ref(null);

/**
 * Автоматичне підлаштування висоти textarea
 */
function adjustHeight() {
  const el = textarea.value;
  if (!el) return;
  
  el.style.height = 'auto';
  // Встановлюємо висоту відповідно до контенту (макс. 150px)
  el.style.height = (el.scrollHeight > 150 ? 150 : el.scrollHeight) + 'px';
}

function handleSend() {
  const text = draft.value.trim();
  if (!text || props.sending) return;
  
  emit('send', text);
  draft.value = '';
  
  // Скидаємо висоту після відправки
  nextTick(() => {
    if (textarea.value) {
      textarea.value.style.height = 'auto';
    }
  });
}
</script>

<style scoped>
.chat-composer {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  padding: 16px 20px;
  background: #fff;
  border-top: 1px solid #e2e8f0;
}

.input-wrapper {
  flex: 1;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 8px 12px;
  transition: border-color 0.2s;
}

.input-wrapper:focus-within {
  border-color: #3b82f6;
  background: #fff;
}

textarea {
  width: 100%;
  border: none;
  background: transparent;
  resize: none;
  outline: none;
  font-size: 0.95rem;
  line-height: 1.5;
  max-height: 150px;
  display: block;
  padding: 0;
  margin: 0;
  color: #1e293b;
}

.send-btn {
  height: 42px;
  padding: 0 20px;
  background: #3b82f6;
  color: #fff;
  border: none;
  border-radius: 10px;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
  white-space: nowrap;
}

.send-btn:hover:not(:disabled) {
  background: #2563eb;
  transform: translateY(-1px);
}

.send-btn:disabled {
  background: #94a3b8;
  cursor: not-allowed;
  transform: none;
}

.spinner-border {
  width: 1rem;
  height: 1rem;
}
</style>