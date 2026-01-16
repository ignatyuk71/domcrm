<template>
  <form class="chat-composer" @submit.prevent="handleSend">
    <div class="input-wrapper" :class="{ 'is-focused': isFocused }">
      <textarea
        ref="textarea"
        v-model="draft"
        rows="1"
        placeholder="Напишіть повідомлення..."
        class="custom-scrollbar"
        @input="adjustHeight"
        @keydown.enter.exact.prevent="handleSend"
        @keydown.enter.shift.exact="handleNewLine"
        @focus="isFocused = true"
        @blur="isFocused = false"
      ></textarea>
    </div>
    
    <button 
      type="submit" 
      class="send-btn"
      :disabled="!canSend"
      aria-label="Надіслати повідомлення"
    >
      <div v-if="sending" class="spinner-border spinner-border-sm" role="status"></div>
      <template v-else>
        <i class="bi bi-send-fill"></i>
        <span class="ms-2 d-none d-md-inline">Надіслати</span>
      </template>
    </button>
  </form>
</template>

<script setup>
import { ref, nextTick, computed } from 'vue';

const props = defineProps({
  sending: { type: Boolean, default: false },
});

const emit = defineEmits(['send']);
const draft = ref('');
const textarea = ref(null);
const isFocused = ref(false);

const canSend = computed(() => draft.value.trim().length > 0 && !props.sending);

/**
 * Автоматичне підлаштування висоти textarea
 */
function adjustHeight() {
  const el = textarea.value;
  if (!el) return;
  
  el.style.height = 'auto';
  const newHeight = el.scrollHeight;
  // Лімітуємо висоту до 150px, далі вмикається внутрішній скрол
  el.style.height = (newHeight > 150 ? 150 : newHeight) + 'px';
}

function handleNewLine() {
  // Дозволяємо стандартну поведінку Shift+Enter (новий рядок)
  // adjustHeight викликається автоматично через @input
}

function handleSend() {
  const text = draft.value.trim();
  if (!text || props.sending) return;
  
  emit('send', text);
  draft.value = '';
  
  // Скидаємо висоту до початкового стану
  nextTick(() => {
    if (textarea.value) {
      textarea.value.style.height = 'auto';
    }
  });
}

// Метод для фокусування ззовні (наприклад, при зміні активного чату)
function focus() {
  textarea.value?.focus();
}

defineExpose({ focus });
</script>

<style scoped>
.chat-composer {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  padding: 12px 20px;
  background: #fff;
  border-top: 1px solid #e2e8f0;
  flex-shrink: 0;
}

.input-wrapper {
  flex: 1;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 8px 14px;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  display: flex;
  align-items: center;
}

.input-wrapper.is-focused {
  border-color: #3b82f6;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
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
  overflow-y: auto;
}

/* Стилізація скролбару всередині textarea */
textarea::-webkit-scrollbar {
  width: 4px;
}
textarea::-webkit-scrollbar-track {
  background: transparent;
}
textarea::-webkit-scrollbar-thumb {
  background: #e2e8f0;
  border-radius: 10px;
}

.send-btn {
  height: 42px;
  min-width: 42px;
  padding: 0 18px;
  background: #3b82f6;
  color: #fff;
  border: none;
  border-radius: 10px;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  white-space: nowrap;
  flex-shrink: 0;
}

.send-btn:hover:not(:disabled) {
  background: #2563eb;
  transform: translateY(-1px);
  box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);
}

.send-btn:active:not(:disabled) {
  transform: translateY(0);
}

.send-btn:disabled {
  background: #cbd5e1;
  color: #94a3b8;
  cursor: not-allowed;
}

.spinner-border {
  width: 1.1rem;
  height: 1.1rem;
}

@media (max-width: 576px) {
  .send-btn {
    padding: 0;
    width: 42px;
  }
}
</style>