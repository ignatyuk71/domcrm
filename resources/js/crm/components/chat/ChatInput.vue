<template>
  <div class="chat-input-wrapper">
    <form class="chat-input-form" @submit.prevent="handleSend">
      <div class="text-input-card">
        <div v-if="selectedFiles.length" class="file-preview-list">
          <div v-for="(item, index) in selectedFiles" :key="item.id" class="file-preview-badge">
            <div class="preview-content" :title="item.sizeLabel">
              <img v-if="item.isImage" :src="item.previewUrl" class="file-thumb" alt="preview" />
              <i class="bi bi-file-earmark-arrow-up" v-else></i>
              <span class="file-name">{{ item.file.name }}</span>
            </div>
            <button type="button" class="remove-file-btn" @click="removeFile(index)">
              <i class="bi bi-x"></i>
            </button>
          </div>
        </div>

        <textarea
          v-model="text"
          class="chat-textarea"
          rows="1"
          placeholder="Напишіть повідомлення..."
          :disabled="disabled"
          @keydown.ctrl.enter.prevent="handleSend"
          @input="autoResize"
          ref="textareaRef"
        ></textarea>

        <span class="kb-hint" v-if="text.length > 0 || selectedFiles.length">Ctrl + Enter для відправки</span>
        <span class="size-hint">Макс. 5 МБ на файл</span>
        <span v-if="fileError" class="file-error">{{ fileError }}</span>
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
            title="Прикріпити фото"
          >
            <i class="bi bi-paperclip"></i>
          </button>
          <input
            type="file"
            ref="fileInputRef"
            style="display: none"
            @change="onFileChange"
            accept="image/*"
            multiple
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
const selectedFiles = ref([]);
const fileError = ref('');
const fileInputRef = ref(null);
const textareaRef = ref(null);

const maxFileSize = 5 * 1024 * 1024;
const canSend = computed(() => text.value.trim().length > 0 || selectedFiles.value.length > 0);

function triggerFileInput() {
  fileInputRef.value.click();
}

function onFileChange(e) {
  const files = Array.from(e.target.files || []);
  if (!files.length) return;

  fileError.value = '';

  files.forEach((file) => {
    if (file.size > maxFileSize) {
      fileError.value = 'Файл більший за 5 МБ';
      return;
    }
    const previewUrl = file.type.startsWith('image/') ? URL.createObjectURL(file) : '';
    selectedFiles.value.push({
      id: `${Date.now()}_${Math.random().toString(16).slice(2)}`,
      file,
      previewUrl,
      isImage: file.type.startsWith('image/'),
      sizeLabel: `${Math.round(file.size / 1024)} KB`,
    });
  });

  fileInputRef.value.value = '';
}

function removeFile(index) {
  const item = selectedFiles.value[index];
  if (item?.previewUrl) {
    URL.revokeObjectURL(item.previewUrl);
  }
  selectedFiles.value.splice(index, 1);
}

function autoResize() {
  const el = textareaRef.value;
  if (el) {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
  }
}

function handleSend() {
  if (!canSend.value) return;

  emit('send', {
    text: text.value.trim(),
    files: selectedFiles.value.map((item) => item.file),
  });

  text.value = '';
  selectedFiles.value.forEach((item) => {
    if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);
  });
  selectedFiles.value = [];
  fileError.value = '';
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
  box-shadow: 0 2px 4px rgba(0,0,0,0.02);
  position: relative;
}

.text-input-card:focus-within {
  border-color: #3b82f6;
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
}

.file-preview-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 10px;
}

.file-preview-badge {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 6px 12px;
}

.preview-content {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.85rem;
  color: #475569;
}

.file-thumb {
  width: 28px;
  height: 28px;
  object-fit: cover;
  border-radius: 6px;
  border: 1px solid #e2e8f0;
}

.remove-file-btn {
  background: transparent;
  border: none;
  color: #94a3b8;
  cursor: pointer;
  font-size: 1.2rem;
  line-height: 1;
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
  padding: 0;
  transition: color 0.2s;
}

.tool-btn:hover,
.tool-btn.active {
  color: #3b82f6;
}

.chat-send-btn {
  background: #3b82f6;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 8px 18px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
}

.chat-send-btn:disabled {
  background: #cbd5e1;
  cursor: not-allowed;
}

.kb-hint {
  position: absolute;
  bottom: 4px;
  right: 12px;
  font-size: 0.7rem;
  color: #cbd5e1;
}

.size-hint {
  position: absolute;
  bottom: 4px;
  left: 12px;
  font-size: 0.7rem;
  color: #94a3b8;
}

.file-error {
  position: absolute;
  bottom: 22px;
  right: 12px;
  font-size: 0.7rem;
  color: #ef4444;
}
</style>
