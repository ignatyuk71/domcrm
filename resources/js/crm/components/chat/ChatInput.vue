<template>
  <div class="chat-container">
    <form class="message-box" @submit.prevent="handleSend">
      
      <transition name="slide-up">
        <div v-if="showAttachment" class="attachment-panel">
          <div class="attachment-input-wrapper">
            <i class="bi bi-link-45deg"></i>
            <input
              v-model="attachmentUrl"
              type="url"
              class="attachment-input"
              placeholder="Додати URL зображення або файлу..."
            />
            <button type="button" class="close-attach" @click="showAttachment = false">
              <i class="bi bi-x"></i>
            </button>
          </div>
        </div>
      </transition>

      <div class="input-main-card">
        <textarea
          v-model="text"
          class="message-textarea"
          rows="1"
          placeholder="Напишіть повідомлення..."
          :disabled="disabled"
          @keydown.ctrl.enter.prevent="handleSend"
          @input="autoResize"
          ref="textareaRef"
        ></textarea>

        <div class="input-footer">
          <div class="toolbar-left">
            <button type="button" class="icon-btn" title="Шаблони"><i class="bi bi- lightning-charge"></i></button>
            <button type="button" class="icon-btn" title="Файли"><i class="bi bi-folder2-open"></i></button>
            <button 
              type="button" 
              class="icon-btn" 
              :class="{ active: showAttachment }"
              @click="toggleAttachment"
            >
              <i class="bi bi-paperclip"></i>
            </button>
            <div class="divider"></div>
            <button type="button" class="icon-btn"><i class="bi bi-emoji-smile"></i></button>
          </div>

          <div class="toolbar-right">
            <span class="char-counter" v-if="text.length > 0">{{ text.length }}</span>
            <button 
              class="send-circle-btn" 
              type="submit" 
              :disabled="disabled || !canSend"
            >
              <i class="bi bi-arrow-up-short"></i>
            </button>
          </div>
        </div>
      </div>
      
      <p class="hint-text"><b>Ctrl + Enter</b> щоб надіслати швидко</p>
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
/* Контейнер */
.chat-container {
  padding: 20px;
  background-color: #f8fafc;
  display: flex;
  justify-content: center;
}

.message-box {
  width: 100%;
  max-width: 800px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

/* Картка вводу */
.input-main-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 8px;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
  transition: all 0.2s ease;
}

.input-main-card:focus-within {
  border-color: #3b82f6;
  box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.1);
}

/* Текстове поле */
.message-textarea {
  width: 100%;
  border: none;
  outline: none;
  padding: 12px 12px 4px 12px;
  font-size: 0.95rem;
  line-height: 1.5;
  color: #1e293b;
  resize: none;
  min-height: 40px;
  max-height: 200px;
}

/* Футер з кнопками */
.input-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 4px 8px;
  border-top: 1px solid #f1f5f9;
  margin-top: 4px;
}

.toolbar-left {
  display: flex;
  align-items: center;
  gap: 4px;
}

.divider {
  width: 1px;
  height: 20px;
  background: #e2e8f0;
  margin: 0 8px;
}

.icon-btn {
  background: transparent;
  border: none;
  color: #64748b;
  width: 34px;
  height: 34px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 1.1rem;
  transition: all 0.2s;
}

.icon-btn:hover {
  background: #f1f5f9;
  color: #3b82f6;
}

.icon-btn.active {
  background: #eff6ff;
  color: #3b82f6;
}

/* Кнопка відправки */
.toolbar-right {
  display: flex;
  align-items: center;
  gap: 12px;
}

.char-counter {
  font-size: 0.75rem;
  color: #94a3b8;
}

.send-circle-btn {
  background: #3b82f6;
  color: white;
  border: none;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.8rem;
  cursor: pointer;
  transition: transform 0.2s, background 0.2s;
}

.send-circle-btn:hover:not(:disabled) {
  background: #2563eb;
  transform: scale(1.05);
}

.send-circle-btn:disabled {
  background: #e2e8f0;
  color: #94a3b8;
  cursor: not-allowed;
}

/* Панель вкладень */
.attachment-panel {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 8px 12px;
}

.attachment-input-wrapper {
  display: flex;
  align-items: center;
  gap: 8px;
}

.attachment-input {
  flex: 1;
  border: none;
  outline: none;
  font-size: 0.85rem;
}

.close-attach {
  background: none;
  border: none;
  color: #94a3b8;
  cursor: pointer;
  font-size: 1.2rem;
}

.hint-text {
  font-size: 0.75rem;
  color: #94a3b8;
  text-align: right;
  margin-right: 8px;
}

/* Анімації */
.slide-up-enter-active, .slide-up-leave-active {
  transition: all 0.3s ease;
}
.slide-up-enter-from, .slide-up-leave-to {
  opacity: 0;
  transform: translateY(10px);
}
</style>