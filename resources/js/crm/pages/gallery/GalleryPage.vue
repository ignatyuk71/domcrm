<template>
  <div class="gallery-page">
    <div class="gallery-header">
      <div>
        <h2>Галерея</h2>
        <p>Збережені зображення для швидкої відправки клієнтам</p>
      </div>
      <label class="upload-btn">
        <i class="bi bi-plus-lg"></i>
        Додати фото
        <input type="file" hidden accept="image/*,video/*" @change="handleUpload" />
      </label>
    </div>

    <div class="gallery-grid custom-scrollbar">
      <div v-if="isLoading" class="gallery-state"><div class="spinner"></div></div>
      <div v-else-if="!files.length" class="gallery-state">Файлів ще немає</div>

      <div v-else class="grid">
        <div v-for="file in files" :key="file.id" class="grid-item">
          <img v-if="file.type === 'image'" :src="file.url" loading="lazy" />
          <div v-else class="video-placeholder">
            <i class="bi bi-play-circle-fill"></i>
          </div>
          <div class="grid-footer">
            <span class="file-name">{{ file.filename }}</span>
            <button type="button" class="delete-btn" @click="removeFile(file.id)">
              <i class="bi bi-trash"></i>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { deleteSavedFile, getSavedFiles, uploadSavedFile } from '@/crm/services/chatApi';

const files = ref([]);
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

const handleUpload = async (event) => {
  const input = event.target;
  if (!input.files?.length) return;
  const formData = new FormData();
  formData.append('file', input.files[0]);
  await uploadSavedFile(formData);
  input.value = '';
  await load();
};

const removeFile = async (id) => {
  await deleteSavedFile(id);
  files.value = files.value.filter((file) => file.id !== id);
};

onMounted(load);
</script>

<style scoped>
.gallery-page {
  padding: 24px;
}

.gallery-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 20px;
}

.gallery-header h2 {
  margin: 0;
  font-size: 1.4rem;
  font-weight: 700;
  color: #0f172a;
}

.gallery-header p {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 0.9rem;
}

.upload-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: #3b82f6;
  color: #fff;
  border-radius: 10px;
  padding: 10px 16px;
  cursor: pointer;
  font-weight: 600;
}

.gallery-grid {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 16px;
  min-height: 360px;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 16px;
}

.grid-item {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  overflow: hidden;
  background: #f8fafc;
  display: flex;
  flex-direction: column;
}

.grid-item img,
.video-placeholder {
  width: 100%;
  height: 140px;
  object-fit: cover;
  background: #e2e8f0;
}

.video-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #64748b;
  font-size: 1.5rem;
}

.grid-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 10px;
}

.file-name {
  font-size: 0.8rem;
  color: #475569;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 120px;
}

.delete-btn {
  background: transparent;
  border: none;
  color: #ef4444;
  cursor: pointer;
}

.gallery-state {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px;
  color: #94a3b8;
}

.spinner {
  width: 20px;
  height: 20px;
  border: 2px solid #e2e8f0;
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
