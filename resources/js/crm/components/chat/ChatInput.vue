<template>
  <div class="chat-input-wrapper">
    <form class="chat-input-form" @submit.prevent="handleSend">
      <div class="textarea-container">
        <textarea
          v-model="text"
          class="chat-textarea"
          rows="1"
          placeholder="Введіть повідомлення..."
          :disabled="disabled"
          @keydown.ctrl.enter.prevent="handleSend"
          @input="autoResize"
          ref="textareaRef"
        ></textarea>
      </div>

      <div class="chat-actions">
        <div class="chat-tools">
          <button type="button" class="tool-btn" :disabled="disabled">
            <i class="bi bi-file-text"></i>
          </button>
          <button type="button" class="tool-btn" :disabled="disabled">
            <i class="bi bi-lightning"></i>
          </button>
          <button type="button" class="tool-btn" :disabled="disabled">
            <i class="bi bi-box-seam"></i>
          </button>
          <button type="button" class="tool-btn" :disabled="disabled">
            <i class="bi bi-emoji-smile"></i>
          </button>
          <button 
            type="button" 
            class="tool-btn" 
            :class="{ active: showAttachment }"
            :disabled="disabled" 
            @click="toggleAttachment"
          >
            <i class="bi bi-paperclip"></i>
          </button>
          <button type="button" class="tool-btn" :disabled="disabled">
            <i class="bi bi-mic"></i>
          </button>
        </div>

        <button 
          class="chat-send-btn" 
          type="submit" 
          :disabled="disabled || !canSend"
        >
          <i class="bi bi-send-fill"></i>
          <span>Надіслати</span>
        </button>
      </div>

      <div v-if="showAttachment" class="attachment-input-area">
        <input
          v-model="attachmentUrl"
          type="url"
          class="link-input"
          placeholder="Вставте посилання на файл..."
        />
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['send']);

const text = ref('');
const attachmentUrl = ref('');
const showAttachment = ref(false);
const textareaRef = ref(null);

const canSend = computed(() => text.value.trim().length > 0 || attachmentUrl.value.trim().length > 0);

function autoResize() {
  const el = textareaRef.value;
  el.style.height = 'auto';
  el.style.height = el.scrollHeight + 'px';
}

function toggleAttachment() {
  showAttachment.value = !showAttachment.value;
}

function handleSend() {
  if (!canSend.value) return;

  const url = attachmentUrl.value.trim();
  const attachments = url ? [{ type: 'file', url }] : [];

  emit('send', {
    text: text.value.trim(),
    attachments,
  });

  text.value = '';
  attachmentUrl.value = '';
  showAttachment.value = false;
  if (textareaRef.value) textareaRef.value.style.height = 'auto';
}
</script>

<style scoped>
/* Головний контейнер як на скріні (світлий фон) */
.chat-input-wrapper {
  background: #f0f7ff; 
  padding: 15px 20px;
  border-top: 1px solid #e2e8f0;
}

.chat-input-form {
  display: flex;
  flex-direction: column;
}

.textarea-container {
  margin-bottom: 10px;
}

.chat-textarea {
  width: 100%;
  border: none;
  background: transparent;
  resize: none;
  font-size: 1rem;
  color: #475569;
  padding: 0;
  outline: none;
  min-height: 24px;
  line-height: 1.5;
}

.chat-textarea::placeholder {
  color: #94a3b8;
}

.chat-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.chat-tools {
  display: flex;
  gap: 15px;
}

.tool-btn {
  background: transparent;
  border: none;
  color: #64748b;
  font-size: 1.2rem;
  padding: 0;
  cursor: pointer;
  display: flex;
  align-items: center;
  transition: color 0.2s;
}

.tool-btn:hover {
  color: #3b82f6;
}

.tool-btn.active {
  color: #3b82f6;
}

/* Кнопка Надіслати (світло-синій фон, синій текст) */
.chat-send-btn {
  background: #dbeafe;
  color: #2563eb;
  border: none;
  border-radius: 8px;
  padding: 8px 16px;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  transition: all 0.2s;
}

.chat-send-btn:hover:not(:disabled) {
  background: #bfdbfe;
}

.chat-send-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.attachment-input-area {
  margin-top: 12px;
}

.link-input {
  width: 100%;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 6px 12px;
  font-size: 0.85rem;
  outline: none;
}
</style>