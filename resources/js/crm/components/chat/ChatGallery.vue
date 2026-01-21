<template>
  <teleport to="body">
    <transition name="gallery-fade">
      <div class="gallery-overlay" @click.self="emit('close')">
        <div class="gallery-modal">
          <div class="header">
            <h4>Галерея</h4>
            <div class="header-actions">
              <label class="upload-btn">
                <i class="bi bi-plus-lg"></i>
                <input type="file" hidden @change="handleUpload" accept="image/*,video/*" />
              </label>
              <button type="button" class="close-btn" @click="emit('close')">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>
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
      </div>
    </transition>
  </teleport>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { getSavedFiles, uploadSavedFile } from '@/crm/services/chatApi';

const emit = defineEmits(['confirm', 'close']);
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
  emit('close');
};

onMounted(load);
</script>

<style scoped>
  /* --- ЗАГАЛЬНІ ТА ДЕСКТОП СТИЛІ --- */
  .gallery-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(6px);
    z-index: 1100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
  
  .gallery-modal {
    width: 720px;
    max-width: 96vw;
    max-height: 85vh;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.2);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: popUp 0.2s ease-out;
  }
  
  @keyframes popUp {
    from { opacity: 0; transform: translateY(10px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }
  
  .header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
    flex-shrink: 0;
  }
  
  .header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  
  .header h4 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 700;
  }
  
  .upload-btn, .close-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: #f1f5f9;
    color: #64748b;
    cursor: pointer;
    border: none;
  }
  
  .grid-container {
    display: grid;
    /* Десктоп: авто-заповнення */
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 16px;
    padding: 16px;
    overflow-y: auto;
    flex: 1; 
  }
  
  .grid-item {
    position: relative;
    border-radius: 12px;
    overflow: hidden;
    cursor: pointer;
    border: 2px solid transparent;
    aspect-ratio: 2 / 3;
    background: transparent; 
    transition: transform 0.1s ease-in-out, border-color 0.1s;
    /* Важливо для запобігання вилізання контенту */
    display: flex;
    align-items: center;
    justify-content: center;
  }
  
  .grid-item:hover {
    transform: translateY(-2px);
  }
  
  .grid-item img,
  .video-placeholder {
    width: 100%;
    height: 100%;
    object-fit: contain; 
    background: transparent;
    display: block;
  }
  
  .video-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #64748b;
    background: #f1f5f9;
    font-size: 2rem;
  }
  
  .grid-item.selected {
    border-color: #3b82f6;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
  }
  
  .check-overlay {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #3b82f6;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    opacity: 0;
    transition: all 0.2s;
    transform: scale(0.8);
    z-index: 2;
  }
  
  .grid-item.selected .check-overlay {
    opacity: 1;
    transform: scale(1);
  }
  
  .footer {
    padding: 12px 16px;
    border-top: 1px solid #f1f5f9;
    flex-shrink: 0;
    background: #fff;
  }
  
  .confirm-btn {
    width: 100%;
    background: #3b82f6;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 10px;
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
    width: 24px;
    height: 24px;
    border: 3px solid #e2e8f0;
    border-top-color: #3b82f6;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin: 0 auto;
  }
  
  @keyframes spin { to { transform: rotate(360deg); } }
  
  .gallery-fade-enter-active, .gallery-fade-leave-active { transition: opacity 0.2s ease; }
  .gallery-fade-enter-from, .gallery-fade-leave-to { opacity: 0; }
  
  /* --- МОБІЛЬНА ВЕРСІЯ (ВИПРАВЛЕНА) --- */
  @media (max-width: 768px) {
    .gallery-overlay {
      padding: 0;
      background: rgba(0,0,0,0.6); 
      backdrop-filter: none;
    }
  
    .gallery-modal {
      width: 100%;
      height: 100%; /* Розтягуємо на весь екран */
      max-width: 100%;
      max-height: 100dvh; /* Використовуємо dvh для правильного скролу в Safari/Chrome */
      border-radius: 0;
      border: none;
    }
  
    .grid-container {
      /* 1. СТРОГО 2 стовпці */
      grid-template-columns: repeat(2, 1fr);
      
      /* 2. Збільшений відступ, щоб картинки не злипались */
      gap: 12px;
      padding: 12px;
      
      /* 3. Забороняємо накладання рядків */
      align-content: start;
      grid-auto-rows: auto; 
    }

    .grid-item {
      /* Вимикаємо hover ефекти */
      transform: none !important;
      
      /* Додаємо фон, щоб видно було межі картки, якщо картинка вузька */
      background: #f8fafc;
      
      /* Тінь, щоб відокремити картки одна від одної */
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
      
      /* Трішки міняємо пропорції для мобільного, щоб було видно довгі картинки */
      aspect-ratio: 2 / 3; 
    }

    .grid-item img {
      /* ГАРАНТІЯ, що картинка влізе повністю і не обріжеться */
      object-fit: contain;
      width: 100%;
      height: 100%;
    }
  }
  
  .custom-scrollbar::-webkit-scrollbar { width: 5px; }
  .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
</style>