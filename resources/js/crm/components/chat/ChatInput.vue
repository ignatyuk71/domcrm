<template>
  <div class="chat-input-wrapper">
    <form class="chat-input-form" @submit.prevent="handleSend">
      
      <div class="text-input-card">
        <textarea
          v-model="text"
          class="chat-textarea"
          rows="1"
          placeholder="Напишіть щось..."
          :disabled="disabled"
          @keydown.ctrl.enter.prevent="handleSend"
          @input="autoResize"
          ref="textareaRef"
        ></textarea>
        
        <span class="kb-hint" v-if="text.length > 0">Ctrl + Enter для відправки</span>
      </div>

      <div class="chat-actions">
        <div class="chat-tools">
          <button type="button" class="tool-btn" title="Шаблони"><i class="bi bi-file-text"></i></button>
          <button type="button" class="tool-btn" title="Швидкі відповіді"><i class="bi bi-lightning"></i></button>
          <button type="button" class="tool-btn" title="Товари"><i class="bi bi-box-seam"></i></button>
          <button type="button" class="tool-btn" title="Емодзі"><i class="bi bi-emoji-smile"></i></button>
          <button 
            type="button" 
            class="tool-btn" 
            :class="{ active: showAttachment }"
            @click="toggleAttachment"
            title="Додати посилання"
          >
            <i class="bi bi-paperclip"></i>
          </button>
          <button type="button" class="tool-btn" title="Голосове"><i class="bi bi-mic"></i></button>
        </div>

        <button 
          class="chat-send-btn" 
          type="submit" 
          :disabled="disabled || !canSend"
        >
          <span>Надіслати</span>
          <i class="bi bi-send-fill"></i>
        </button>
      </div>

      <transition name="slide-fade">
        <div v-if="showAttachment" class="attachment-area">
          <div class="link-input-group">
            <i class="bi bi-link-45deg"></i>
            <input
              v-model="attachmentUrl"
              type="url"
              class="link-input"
              placeholder="Вставте посилання на зображення або файл..."
            />
          </div>
        </div>
      </transition>
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
  if (el) {
    el.style.height = 'auto';
    el.style.height = (el.scrollHeight) + 'px';
  }
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
.chat-input-wrapper {
  background: #f4f9ff; /* Твій ніжно-блакитний фон */
  padding: 16px 24px;
  border-top: 1px solid #e1e8f0;
}

/* БЛОК-КАРТКА ДЛЯ ТЕКСТУ */
.text-input-card {
  background: #ffffff;
  border: 1px solid #d1d9e2;
  border-radius: 12px;
  padding: 12px 16px;
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.03);
  margin-bottom: 12px;
  transition: border-color 0.2s, box-shadow 0.2s;
  position: relative;
}

.text-input-card:focus-within {
  border-color: #3b82f6;
  box-shadow: 0 4px 10px rgba(59, 130, 246, 0.08);
}

.chat-textarea {
  width: 100%;
  border: none;
  background: transparent;
  resize: none;
  font-size: 1rem;
  color: #1e293b;
  outline: none;
  min-height: 24px;
  max-height: 180px;
  line-height: 1.5;
  display: block;
}

.kb-hint {
  position: absolute;
  bottom: 4px;
  right: 12px;
  font-size: 0.7rem;
  color: #94a3b8;
  pointer-events: none;
}

/* ДІЇ ТА КНОПКИ */
.chat-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.chat-tools {
  display: flex;
  gap: 16px;
}

.tool-btn {
  background: transparent;
  border: none;
  color: #64748b;
  font-size: 1.3rem;
  cursor: pointer;
  padding: 4px;
  border-radius: 6px;
  transition: background 0.2s, color 0.2s;
}

.tool-btn:hover {
  background: #eef2f7;
  color: #3b82f6;
}

.tool-btn.active {
  color: #3b82f6;
  background: #e0e7ff;
}

.chat-send-btn {
  background: #3b82f6; /* Зробив більш активною */
  color: #ffffff;
  border: none;
  border-radius: 8px;
  padding: 10px 20px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
}

.chat-send-btn:hover:not(:disabled) {
  background: #2563eb;
}

.chat-send-btn:disabled {
  background: #cbd5e1;
  box-shadow: none;
  cursor: not-allowed;
}

/* ПОЛЕ ПОСИЛАННЯ */
.attachment-area {
  margin-top: 12px;
}

.link-input-group {
  display: flex;
  align-items: center;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 6px 12px;
  gap: 8px;
}

.link-input-group i {
  color: #94a3b8;
  font-size: 1.2rem;
}

.link-input {
  flex: 1;
  border: none;
  outline: none;
  font-size: 0.9rem;
  color: #334155;
}

/* АНІМАЦІЯ */
.slide-fade-enter-active {
  transition: all 0.3s ease-out;
}
.slide-fade-leave-active {
  transition: all 0.2s cubic-bezier(1, 0.5, 0.8, 1);
}
.slide-fade-enter-from,
.slide-fade-leave-to {
  transform: translateY(-10px);
  opacity: 0;
}
</style>