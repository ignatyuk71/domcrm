<template>
  <div class="chat-input-wrapper">
    
    <div v-if="selectedFiles.length" class="file-preview-area">
      <div v-for="(item, index) in selectedFiles" :key="item.id" class="file-badge">
        <div class="file-info" :title="item.file.name">
          <img v-if="item.isImage" :src="item.previewUrl" class="file-thumb" alt="preview" />
          <i v-else class="bi bi-file-earmark-text file-icon"></i>
        </div>
        <button type="button" class="remove-btn" @click="removeFile(index)">
          <i class="bi bi-x"></i>
        </button>
      </div>
    </div>

    <form class="chat-input-bar-container" @submit.prevent="handleSend" :class="{ 'has-error': fileError }">
      <div class="chat-input-main-row">
        <div class="input-area">
          <textarea
            v-model="text"
            class="chat-textarea custom-scrollbar"
            rows="1"
            :placeholder="composerPlaceholder"
            :disabled="disabled"
            @keydown.enter.exact.prevent="handleSend"
            @input="autoResize"
            ref="textareaRef"
          ></textarea>
        </div>
      </div>

      <div class="chat-actions-row">
        <div class="chat-tools">
          <div class="relative-container">
            <ChatGallery
              v-if="showGallery"
              @confirm="handleGallerySelect"
              @close="showGallery = false"
            />
            <button
              type="button"
              class="tool-btn"
              :class="{ active: showGallery }"
              title="Галерея"
              @click="showGallery = !showGallery"
            >
              <i class="bi bi-handbag"></i>
            </button>
          </div>

          <button
            type="button"
            class="tool-btn"
            :class="{ active: selectedFiles.length }"
            @click="triggerFileInput"
            title="Прикріпити файл"
          >
            <i class="bi bi-paperclip"></i>
          </button>
          <input
            type="file"
            ref="fileInputRef"
            style="display: none"
            @change="onFileChange"
            accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt"
            multiple
          />

          <div class="relative-container">
            <ChatTemplates 
              v-if="showTemplates" 
              @select="handleTemplateSelect"
              @close="showTemplates = false"
              v-click-outside="() => showTemplates = false"
            />
            <button
              type="button"
              class="tool-btn"
              :class="{ active: showTemplates }"
              title="Шаблони відповідей"
              @click="showTemplates = !showTemplates"
            >
              <i class="bi bi-chat-square-dots"></i>
            </button>
          </div>
        </div>

        <button
          type="button"
          class="action-btn"
          :disabled="disabled"
          :title="hasContent ? 'Надіслати' : 'Надіслати лайк'"
          @click="handleSendClick"
        >
          <i v-if="hasContent" class="bi bi-send-fill send-icon"></i>
          <i v-else class="bi bi-hand-thumbs-up-fill like-icon"></i>
        </button>
      </div>
    </form>

    <div class="input-footer">
      <span v-if="fileError" class="error-text">{{ fileError }}</span>
      <span v-else class="hint-text mobile-hide">Enter — надіслати, Shift+Enter — новий рядок</span>
    </div>

  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import ChatTemplates from './ChatTemplates.vue';
import ChatGallery from './ChatGallery.vue';

const props = defineProps({
  disabled: { type: Boolean, default: false },
  platform: { type: String, default: 'messenger' },
});

const emit = defineEmits(['send']);

const text = ref('');
const selectedFiles = ref([]);
const fileError = ref('');
const fileInputRef = ref(null);
const textareaRef = ref(null);
const showTemplates = ref(false);
const showGallery = ref(false);

const maxFileSize = 5 * 1024 * 1024; // 5 MB

const hasContent = computed(() => text.value.trim().length > 0 || selectedFiles.value.length > 0);
const composerPlaceholder = computed(() => (
  props.platform === 'instagram'
    ? 'Відповідь в Instagram Direct...'
    : 'Відповідь у Messenger...'
));

function triggerFileInput() {
  fileInputRef.value.click();
}

function onFileChange(e) {
  const files = Array.from(e.target.files || []);
  if (!files.length) return;
  fileError.value = '';

  files.forEach((file) => {
    if (file.size > maxFileSize) {
      fileError.value = `Файл ${file.name} завеликий (макс 5 МБ)`;
      return;
    }
    const previewUrl = file.type.startsWith('image/') ? URL.createObjectURL(file) : '';
    
    selectedFiles.value.push({
      id: `${Date.now()}_${Math.random().toString(16).slice(2)}`,
      file,
      previewUrl,
      isImage: file.type.startsWith('image/'),
      isRemote: false,
    });
  });
  fileInputRef.value.value = '';
}

function removeFile(index) {
  const item = selectedFiles.value[index];
  if (item?.previewUrl && item.previewUrl.startsWith('blob:')) {
    URL.revokeObjectURL(item.previewUrl);
  }
  selectedFiles.value.splice(index, 1);
}

function handleTemplateSelect(content) {
  text.value = (text.value ? text.value + ' ' : '') + content;
  showTemplates.value = false;
  if (textareaRef.value) {
    textareaRef.value.focus();
    setTimeout(autoResize, 0);
  }
}

function handleGallerySelect(files) {
  files.forEach((file) => {
    selectedFiles.value.push({
      id: `remote-${file.id}`,
      file: { name: file.filename },
      previewUrl: file.url,
      isImage: file.type === 'image',
      isRemote: true,
      remoteUrl: file.url,
    });
  });
  showGallery.value = false;
  if (textareaRef.value) {
    textareaRef.value.focus();
    setTimeout(autoResize, 0);
  }
}

function autoResize() {
  const el = textareaRef.value;
  if (el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 150) + 'px';
  }
}

function sendLike() {
  emit('send', { text: '👍', files: [], remote_urls: [] });
}

function handleSendClick() {
  if (hasContent.value) {
    handleSend();
    return;
  }
  sendLike();
}

function handleSend() {
  if (props.disabled) return;
  if (!hasContent.value) return;

  const filesToUpload = selectedFiles.value
    .filter((item) => !item.isRemote)
    .map((item) => item.file);

  const remoteUrls = selectedFiles.value
    .filter((item) => item.isRemote)
    .map((item) => item.remoteUrl);

  emit('send', {
    text: text.value.trim(),
    files: filesToUpload,
    remote_urls: remoteUrls,
  });

  text.value = '';
  selectedFiles.value.forEach((item) => {
    if (item.previewUrl && item.previewUrl.startsWith('blob:')) {
      URL.revokeObjectURL(item.previewUrl);
    }
  });
  selectedFiles.value = [];
  fileError.value = '';
  
  if (textareaRef.value) {
    textareaRef.value.style.height = 'auto';
    textareaRef.value.focus();
  }
}
</script>

<style scoped>
.chat-input-wrapper {
  padding: 8px 20px;
  background: #ffffff;
  border-top: 1px solid #e5e7eb;
}

.chat-input-bar-container {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-height: 56px;
  background: #ffffff;
  border: 1px solid #d0d7de;
  border-radius: 10px;
  padding: 8px 14px 6px;
  transition: border-color 0.2s;
}

.chat-input-bar-container:focus-within {
  border-color: #3b82f6;
}

.chat-input-bar-container.has-error {
  border-color: #ef4444;
}

/* Рядок: Текст + Відправити */
.input-area {
  min-height: 22px;
}

.chat-textarea {
  width: 100%;
  border: none;
  outline: none;
  resize: none;
  background: transparent;
  font-size: 15px;
  color: #32465a;
  line-height: 1.25;
  max-height: 72px;
  min-height: 22px;
  padding: 0;
  margin: 0;
}

.chat-actions-row {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}

.chat-tools {
  display: flex;
  align-items: center;
  gap: 12px;
}

.relative-container {
  position: relative;
  display: flex;
}

.tool-btn {
  background: none;
  border: none;
  padding: 0;
  color: #4b5563;
  font-size: 1.2rem;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

.tool-btn:hover, .tool-btn.active {
  color: #1877f2;
}

/* Кнопка відправки */
.action-btn {
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

.send-icon, .like-icon {
  font-size: 1.3rem;
  color: #1877f2;
}

.action-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

@media (max-width: 768px) {
  .chat-input-wrapper {
    padding: 8px 10px;
  }
  
  .chat-textarea {
    font-size: 16px; /* Щоб iPhone не збільшував сторінку при фокусі */
  }

  .mobile-hide {
    display: none;
  }

  .chat-input-bar-container {
    min-height: 54px;
  }

  .chat-actions-row {
    justify-content: space-between;
  }

  .chat-tools {
    gap: 18px;
  }
}

/* СТИЛІ ПРЕВ'Ю ТА ФУТЕРА */
.file-preview-area { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
.file-badge { display: flex; align-items: center; background: #f1f5f9; border-radius: 8px; padding: 4px; border: 1px solid #e2e8f0; }
.file-thumb { width: 60px; height: 60px; border-radius: 4px; object-fit: cover; }
.file-icon { font-size: 1.2rem; color: #64748b; margin: 0 4px; }
.remove-btn { background: none; border: none; color: #94a3b8; cursor: pointer; margin-left: 4px; font-size: 1.1rem; }
.input-footer { display: flex; justify-content: flex-end; margin-top: 3px; padding: 0 8px; }
.hint-text { font-size: 0.66rem; color: #cbd5e1; }
.error-text { font-size: 0.75rem; color: #ef4444; }

.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
</style>
