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
              aria-label="Відкрити галерею товарів"
              @click="showGallery = !showGallery"
            >
              <i class="bi bi-handbag"></i>
            </button>
          </div>

          <button
            type="button"
            class="tool-btn"
            :class="{ active: selectedFiles.length }"
            aria-label="Прикріпити файл"
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
              aria-label="Відкрити шаблони відповідей"
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
          :aria-label="hasContent ? 'Надіслати повідомлення' : 'Надіслати лайк'"
          @click="handleSendClick"
        >
          <i v-if="hasContent" class="bi bi-send-fill send-icon"></i>
          <i v-else class="bi bi-hand-thumbs-up-fill like-icon"></i>
        </button>
      </div>
    </form>

    <div v-if="fileError" class="input-footer">
      <span class="error-text">{{ fileError }}</span>
    </div>

  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
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
  focusTextarea();
  setTimeout(autoResize, 0);
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
  focusTextarea();
  setTimeout(autoResize, 0);
}

function autoResize() {
  const el = textareaRef.value;
  if (el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 150) + 'px';
  }
}

function focusTextarea() {
  nextTick(() => {
    const el = textareaRef.value;
    if (!el || props.disabled) {
      return;
    }

    el.focus();
    const position = el.value.length;
    el.setSelectionRange(position, position);
  });
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
  }

  focusTextarea();
}

watch(
  () => props.disabled,
  (isDisabled, wasDisabled) => {
    if (wasDisabled && !isDisabled) {
      focusTextarea();
    }
  }
);

onMounted(() => {
  focusTextarea();
});
</script>

<style scoped>
.chat-input-wrapper {
  padding: 14px 18px 16px;
  background: rgba(255, 255, 255, 0.78);
  border-top: 1px solid rgba(148, 163, 184, 0.16);
}

.chat-input-bar-container {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 108px;
  background: rgba(255, 255, 255, 0.96);
  border: 1px solid rgba(148, 163, 184, 0.2);
  border-radius: 20px;
  padding: 10px 14px 10px;
  box-shadow: 0 16px 30px rgba(15, 23, 42, 0.06);
  transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
}

.chat-input-bar-container:focus-within {
  border-color: rgba(37, 99, 235, 0.28);
  box-shadow: 0 20px 40px rgba(37, 99, 235, 0.1);
}

.chat-input-bar-container.has-error {
  border-color: #ef4444;
}

/* Рядок: Текст + Відправити */
.chat-input-main-row {
  flex: 1 1 auto;
  display: flex;
  align-items: flex-start;
}

.input-area {
  flex: 1 1 auto;
  min-height: 22px;
}

.chat-textarea {
  width: 100%;
  border: none;
  outline: none;
  resize: none;
  background: transparent;
  font-size: 15px;
  color: #0f172a;
  line-height: 1.5;
  max-height: 112px;
  min-height: 24px;
  padding: 0;
  margin: 0;
}

.chat-textarea::placeholder {
  color: #94a3b8;
}

.chat-actions-row {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  margin-top: auto;
}

.chat-tools {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
  padding: 4px;
  border: 1px solid rgba(148, 163, 184, 0.16);
  border-radius: 16px;
  background: rgba(248, 250, 252, 0.8);
}

.relative-container {
  position: relative;
  display: flex;
}

.tool-btn {
  width: 38px;
  height: 38px;
  border: 1px solid transparent;
  border-radius: 12px;
  background: transparent;
  color: #475569;
  font-size: 1rem;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
}

.tool-btn:hover,
.tool-btn.active {
  color: #1d4ed8;
  border-color: rgba(37, 99, 235, 0.12);
  background: rgba(255, 255, 255, 0.96);
  transform: none;
}

.action-btn {
  width: 40px;
  height: 40px;
  border: 1px solid transparent;
  border-radius: 14px;
  background: linear-gradient(135deg, #2563eb, #1d4ed8);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 10px 20px rgba(37, 99, 235, 0.22);
  transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
}

.action-btn:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 20px 34px rgba(37, 99, 235, 0.28);
}

.send-icon,
.like-icon {
  font-size: 1rem;
  color: #ffffff;
}

.tool-btn i,
.action-btn i {
  display: block;
  line-height: 1;
}

.action-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

@media (max-width: 768px) {
  .chat-input-wrapper {
    padding: 10px 12px 14px;
  }
  
  .chat-textarea {
    font-size: 16px;
  }

  .mobile-hide {
    display: none;
  }

  .chat-input-bar-container {
    min-height: 92px;
    border-radius: 18px;
  }

  .chat-actions-row {
    justify-content: flex-end;
  }

  .chat-tools {
    gap: 4px;
  }
}

.file-preview-area { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
.file-badge { display: flex; align-items: center; background: rgba(248, 250, 252, 0.96); border-radius: 14px; padding: 6px; border: 1px solid rgba(148, 163, 184, 0.18); box-shadow: 0 12px 20px rgba(15, 23, 42, 0.05); }
.file-thumb { width: 64px; height: 64px; border-radius: 10px; object-fit: cover; }
.file-icon { font-size: 1.2rem; color: #64748b; margin: 0 8px; }
.remove-btn { background: none; border: none; color: #94a3b8; cursor: pointer; margin-left: 6px; font-size: 1rem; width: 28px; height: 28px; border-radius: 10px; }
.remove-btn:hover { background: rgba(148, 163, 184, 0.12); color: #334155; }
.input-footer { display: flex; justify-content: flex-end; margin-top: 6px; padding: 0 8px; }
.error-text { font-size: 0.75rem; color: #ef4444; }

.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
</style>
