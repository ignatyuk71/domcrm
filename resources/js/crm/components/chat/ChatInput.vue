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

    <form class="chat-input-bar" @submit.prevent="handleSend" :class="{ 'has-error': fileError }">
      
      <div class="input-area">
        <textarea
          v-model="text"
          class="chat-textarea custom-scrollbar"
          rows="1"
          placeholder="Напишіть повідомлення..."
          :disabled="disabled"
          @keydown.ctrl.enter.prevent="handleSend"
          @input="autoResize"
          ref="textareaRef"
        ></textarea>
      </div>

      <div class="chat-tools">
        <div class="relative-container">
          <ChatGallery
            v-if="showGallery"
            @confirm="handleGallerySelect"
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
          accept="image/*"
          multiple
        />

        <div class="relative-container">
          <ChatTemplates 
            v-if="showTemplates" 
            @select="handleTemplateSelect"
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

        <button type="button" class="tool-btn" title="Емодзі">
          <i class="bi bi-emoji-smile"></i>
        </button>

        <button
          class="action-btn"
          type="submit"
          :disabled="disabled"
          :title="hasContent ? 'Надіслати' : 'Надіслати лайк'"
        >
          <i v-if="hasContent" class="bi bi-send-fill send-icon"></i>
          <i v-else class="bi bi-hand-thumbs-up-fill like-icon" @click.prevent="sendLike"></i>
        </button>
      </div>

    </form>

    <div class="input-footer">
      <span v-if="fileError" class="error-text">{{ fileError }}</span>
      <span v-else class="hint-text">Enter — новий рядок, Ctrl+Enter — надіслати</span>
    </div>

  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import ChatTemplates from './ChatTemplates.vue';
import ChatGallery from './ChatGallery.vue';

const props = defineProps({
  disabled: { type: Boolean, default: false },
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

// Перевірка, чи є контент для відправки
const hasContent = computed(() => text.value.trim().length > 0 || selectedFiles.value.length > 0);

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
    el.style.height = 'auto'; // Скидаємо висоту
    el.style.height = Math.min(el.scrollHeight, 150) + 'px'; // Обмежуємо макс висоту
  }
}

// Функція швидкого відправлення лайка
function sendLike() {
  emit('send', {
    text: '👍', // Відправляємо смайлик
    files: [],
    remote_urls: [],
  });
}

function handleSend() {
  // Якщо пусто - нічого не робимо (кнопка лайка обробляється окремо в @click)
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

  // Очистка
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
  padding: 12px 20px;
  background: #ffffff; /* Білий фон, як на скріні */
  border-top: 1px solid #f1f5f9;
}

/* --- Прев'ю файлів --- */
.file-preview-area {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}

.file-badge {
  display: flex;
  align-items: center;
  background: #f1f5f9;
  border-radius: 8px;
  padding: 4px 8px 4px 4px;
  font-size: 0.85rem;
  border: 1px solid #e2e8f0;
}

.file-info {
  display: flex;
  align-items: center;
  gap: 6px;
  max-width: 150px;
}

.file-thumb {
  width: 70px;
  height: 70px;
  border-radius: 4px;
  object-fit: cover;
}

.file-icon {
  font-size: 1.2rem;
  color: #64748b;
  margin-left: 4px;
}

.file-name {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: #334155;
}

.remove-btn {
  background: none;
  border: none;
  color: #94a3b8;
  cursor: pointer;
  margin-left: 6px;
  padding: 0 4px;
  font-size: 1.1rem;
}

.remove-btn:hover { color: #ef4444; }


/* --- Основний рядок вводу (Стиль Messenger) --- */
.chat-input-bar {
  display: flex;
  align-items: flex-end; /* Вирівнювання по низу, щоб іконки були на рівні тексту при багаторядковості */
  gap: 12px;
  background: #ffffff;
  border: 1px solid #cbd5e1; /* Сіра рамка */
  border-radius: 24px; /* Сильне заокруглення */
  padding: 10px 16px;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.chat-input-bar:focus-within {
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.chat-input-bar.has-error {
  border-color: #ef4444;
}

/* Область тексту */
.input-area {
  flex: 1; /* Займає весь вільний простір */
  display: flex;
  align-items: center;
  min-height: 24px;
}

.chat-textarea {
  width: 100%;
  border: none;
  outline: none;
  resize: none;
  background: transparent;
  font-size: 0.95rem;
  color: #1e293b;
  line-height: 1.5;
  max-height: 150px; /* Скрол, якщо тексту дуже багато */
  padding: 0;
  margin: 0;
}

.chat-textarea::placeholder {
  color: #94a3b8;
}

/* --- Інструменти (Іконки) --- */
.chat-tools {
  display: flex;
  align-items: center;
  gap: 14px; /* Відступи між іконками */
  padding-bottom: 2px; /* Мікро-корекція для вирівнювання з текстом */
}

.relative-container {
  position: relative;
  display: flex;
}

.tool-btn {
  background: none;
  border: none;
  padding: 0;
  color: #64748b; /* Темно-сірий колір іконок, як на скріні */
  font-size: 1.25rem;
  cursor: pointer;
  transition: color 0.2s, transform 0.1s;
  display: flex;
  align-items: center;
  justify-content: center;
}

.tool-btn:hover, .tool-btn.active {
  color: #3b82f6;
}

.tool-btn:active {
  transform: scale(0.95);
}

/* Кнопка дії (Лайк / Send) */
.action-btn {
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-left: 4px; /* Трохи відсунути від решти */
}

.like-icon {
  font-size: 1.35rem;
  color: #3b82f6; /* Лайк синій */
  transition: transform 0.2s;
}

.like-icon:hover {
  transform: scale(1.1);
}

.send-icon {
  font-size: 1.25rem;
  color: #3b82f6; /* Літачок синій */
}

.action-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* --- Футер з підказками --- */
.input-footer {
  display: flex;
  justify-content: flex-end; /* Текст помилки/підказки справа або зліва */
  margin-top: 6px;
  padding: 0 12px;
}

.hint-text {
  font-size: 0.75rem;
  color: #cbd5e1;
}

.error-text {
  font-size: 0.75rem;
  color: #ef4444;
}

/* Скролбар для textarea */
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
</style>
