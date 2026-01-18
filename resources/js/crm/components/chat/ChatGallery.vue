<template>
  <div class="gallery-popover">
    <div class="header">
      <h4>Галерея</h4>
      <label class="upload-btn">
        <i class="bi bi-plus-lg"></i>
        <input type="file" hidden @change="handleUpload" accept="image/*,video/*" />
      </label>
    </div>

    <div class="grid-container custom-scrollbar">
      <div v-if="isLoading" class="state-msg"><div class="spinner"></div></div>
      <div v-else-if="files.length === 0" class="state-msg text-gray-400">Нічого не знайдено</div>

      <div
        v-else
        v-for="file in files"
        :key="file.id"
        class="grid-item"
        :class="{ selected: selectedIds.includes(file.id) }"
        @click="toggleSelect(file)"
      >
        <img v-if="file.type === 'image'" :src="file.url" loading="lazy" />
        <div v-else class="video-placeholder">
          <i class="bi bi-play-circle-fill"></i>
        </div>
        <div class="check-overlay"><i class="bi bi-check-lg"></i></div>
      </div>
    </div>

    <div class="footer" v-if="selectedIds.length > 0">
      <button class="confirm-btn" @click="confirmSelection">
        Додати ({{ selectedIds.length }})
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { getSavedFiles, uploadSavedFile } from '@/crm/services/chatApi';

const emit = defineEmits(['confirm']);
const files = ref([]);
const selectedIds = ref([]);
const isLoading = ref(false);

const load = async () => {
  isLoading.value = true;
  try {
    const { data } = await getSavedFiles();
    files.value = data?.data || data || [];
  } finally {
    isLoading.value = false;
  }
};

const handleUpload = async (e) => {
  if (!e.target.files.length) return;
  const fd = new FormData();
  fd.append('file', e.target.files[0]);
  await uploadSavedFile(fd);
  await load();
  e.target.value = '';
};

const toggleSelect = (file) => {
  if (selectedIds.value.includes(file.id)) {
    selectedIds.value = selectedIds.value.filter((id) => id !== file.id);
  } else {
    selectedIds.value.push(file.id);
  }
};

const confirmSelection = () => {
  const selectedFiles = files.value.filter((f) => selectedIds.value.includes(f.id));
  emit('confirm', selectedFiles);
  selectedIds.value = [];
};

onMounted(load);
</script>

<style scoped>
.gallery-popover {
  position: absolute;
  bottom: 100%;
  left: 0;
  margin-bottom: 12px;
  width: 360px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  box-shadow: 0 10px 30px rgba(0,0,0,0.15);
  z-index: 100;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid #f1f5f9;
}

.header h4 {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 700;
}

.upload-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 8px;
  background: #f1f5f9;
  color: #64748b;
  cursor: pointer;
}

.grid-container {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  padding: 12px;
  max-height: 280px;
  overflow-y: auto;
}

.grid-item {
  position: relative;
  border-radius: 8px;
  overflow: hidden;
  cursor: pointer;
  border: 2px solid transparent;
}

.grid-item img,
.video-placeholder {
  width: 100%;
  height: 90px;
  object-fit: cover;
  background: #e2e8f0;
}

.video-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #64748b;
  font-size: 1.2rem;
}

.grid-item.selected {
  border-color: #3b82f6;
}

.check-overlay {
  position: absolute;
  top: 6px;
  right: 6px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: rgba(59, 130, 246, 0.9);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7rem;
  opacity: 0;
  transition: opacity 0.2s;
}

.grid-item.selected .check-overlay {
  opacity: 1;
}

.footer {
  padding: 10px 16px;
  border-top: 1px solid #f1f5f9;
}

.confirm-btn {
  width: 100%;
  background: #3b82f6;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 8px 12px;
  font-weight: 600;
  cursor: pointer;
}

.state-msg {
  grid-column: 1 / -1;
  padding: 20px;
  text-align: center;
  color: #94a3b8;
}

.spinner {
  width: 20px;
  height: 20px;
  border: 2px solid #e2e8f0;
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin: 0 auto;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
