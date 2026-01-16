<template>
  <div class="chat-input-wrapper">
    <form class="chat-input-form" @submit.prevent="handleSend">
      
      <div class="text-input-card">
        <div v-if="selectedFile" class="file-preview-badge">
          <div class="preview-content">
            <i class="bi bi-image" v-if="isImage"></i>
            <i class="bi bi-file-earmark" v-else></i>
            <span class="file-name">{{ selectedFile.name }}</span>
          </div>
          <button type="button" class="remove-file-btn" @click="removeFile">
            <i class="bi bi-x"></i>
          </button>
        </div>

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
        
        <span class="kb-hint" v-if="text.length > 0 || selectedFile">Ctrl + Enter для відправки</span>
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
            :class="{ active: selectedFile }"
            @click="triggerFileInput"
            title="Завантажити фото/файл"
          >
            <i class="bi bi-paperclip"></i>
          </button>
          <input 
            type="file" 
            ref="fileInputRef" 
            style="display: none" 
            @change="onFileChange"
            accept="image/*,.pdf,.doc,.docx"
          />

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
const selectedFile = ref(null);
const fileInputRef = ref(null);
const textareaRef = ref(null);

const isImage = computed(() => selectedFile.value?.type.startsWith('image/'));
const canSend = computed(() => text.value.trim().length > 0 || selectedFile.value);

function triggerFileInput() {
  fileInputRef.value.click();
}

function onFileChange(e) {
  const file = e.target.files[0];
  if (file) {
    selectedFile.value = file;
  }
}

function removeFile() {
  selectedFile.value = null;
  fileInputRef.value.value = '';
}

function autoResize() {
  const el = textareaRef.value;
  if (el) {
    el.style.height = 'auto';
    el.style.height = (el.scrollHeight) + 'px';
  }
}

function handleSend() {
  if (!canSend.value) return;

  // Відправляємо об'єкт повідомлення та сам файл (якщо є)
  emit('send', {
    text: text.value.trim(),
    file: selectedFile.value
  });

  // Очищення
  text.value = '';
  selectedFile.value = null;
  fileInputRef.value.value = '';
  if (textareaRef.value) textareaRef.value.style.height = 'auto';
}
</script>

<style scoped>
.chat-input-wrapper {
  background: #f4f9ff;
  padding: 16px 24px;
  border-top: 1px solid #e1e8f0;
}

.text-input-card {
  background: #ffffff;
  border: 1px solid #d1d9e2;
  border-radius: 12px;
  padding: 12px 16px;
  margin-bottom: 12px;
  position: relative;
}

/* СТИЛЬ ПРЕВ'Ю ФАЙЛУ */
.file-preview-badge {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #f1f5f9;
  border-radius: 8px;
  padding: 6px 10px;
  margin-bottom: 8px;
  border: 1px solid #e2e8f0;
}

.preview-content {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #334155;
  font-size: 0.85rem;
}

.file-name {
  max-width: 200px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.remove-file-btn {
  background: transparent;
  border: none;
  color: #94a3b8;
  cursor: pointer;
  display: flex;
  font-size: 1.1rem;
}

.remove-file-btn:hover {
  color: #ef4444;
}

.chat-textarea {
  width: 100%;
  border: none;
  background: transparent;
  resize: none;
  font-size: 1rem;
  outline: none;
  min-height: 24px;
}

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
}

.tool-btn.active {
  color: #3b82f6;
}

.chat-send-btn {
  background: #3b82f6;
  color: #ffffff;
  border: none;
  border-radius: 8px;
  padding: 10px 20px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
}

.kb-hint {
  position: absolute;
  bottom: 4px;
  right: 12px;
  font-size: 0.7rem;
  color: #94a3b8;
}
</style>