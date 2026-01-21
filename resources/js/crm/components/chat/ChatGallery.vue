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
/* --- ОСНОВНИЙ КОНТЕЙНЕР --- */
.gallery-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.6); /* Трохи темніший фон */
  backdrop-filter: blur(4px);
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
  border-radius: 16px;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  /* Анімація появи */
  animation: modalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes modalPop {
  from { opacity: 0; transform: scale(0.96); }
  to { opacity: 1; transform: scale(1); }
}

/* --- ШАПКА --- */
.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px;
  border-bottom: 1px solid #e2e8f0;
  background: #fff;
}

.header h4 {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 600;
  color: #0f172a;
}

.header-actions {
  display: flex;
  gap: 10px;
}

.upload-btn, .close-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: #f1f5f9;
  color: #64748b;
  cursor: pointer;
  border: none;
  transition: all 0.2s;
}

.upload-btn:hover, .close-btn:hover {
  background: #e2e8f0;
  color: #334155;
}

/* --- СІТКА (GRID) --- */
.grid-container {
  display: grid;
  /* Адаптивні колонки: мінімум 150px, інакше розтягуються */
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 12px;
  padding: 16px;
  overflow-y: auto;
  /* Висота для десктопу, щоб залишалось місце для хедера/футера */
  flex: 1; 
  min-height: 0; 
}

.grid-item {
  position: relative;
  background: #f8fafc; /* Світлий фон під картинкою */
  border-radius: 12px;
  overflow: hidden;
  cursor: pointer;
  border: 2px solid transparent;
  
  /* ВАЖЛИВО: Пропорція 2:3 (вертикальна), ідеально для 4:5 та 9:16 */
  aspect-ratio: 2 / 3;
  
  transition: transform 0.2s, box-shadow 0.2s;
}

.grid-item:hover {
  transform: translateY(-2px);
}

.grid-item.selected {
  border-color: #3b82f6;
  box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
}

.grid-item img,
.video-placeholder {
  width: 100%;
  height: 100%;
  display: block;
  /* contain покаже картинку цілком, не обрізаючи краї */
  object-fit: contain; 
}

.video-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  color: #94a3b8;
  background: #f1f5f9;
}

/* --- ГАЛОЧКА --- */
.check-overlay {
  position: absolute;
  top: 8px;
  right: 8px;
  width: 24px;
  height: 24px;
  background: #3b82f6;
  color: white;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  
  opacity: 0;
  transform: scale(0.5);
  transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.grid-item.selected .check-overlay {
  opacity: 1;
  transform: scale(1);
}

/* --- ФУТЕР --- */
.footer {
  padding: 16px;
  border-top: 1px solid #e2e8f0;
  background: #fff;
}

.confirm-btn {
  width: 100%;
  padding: 12px;
  background: #3b82f6;
  color: white;
  border: none;
  border-radius: 10px;
  font-weight: 600;
  font-size: 1rem;
  cursor: pointer;
  transition: background 0.2s;
}

.confirm-btn:hover {
  background: #2563eb;
}

/* --- СПІНЕР ТА ПОВІДОМЛЕННЯ --- */
.state-msg {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 200px;
  color: #94a3b8;
}

.spinner {
  width: 24px;
  height: 24px;
  border: 3px solid #e2e8f0;
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* --- МОБІЛЬНА ВЕРСІЯ (ВИПРАВЛЕНО) --- */
@media (max-width: 768px) {
  .gallery-overlay {
    padding: 0;
    align-items: flex-end; /* Модалка виїжджає знизу або на весь екран */
  }

  .gallery-modal {
    width: 100%;
    height: 100%; /* На весь екран */
    max-width: 100%;
    max-height: 100%;
    border-radius: 0;
    border: none;
  }

  .header {
    padding: 12px 16px; /* Трохи компактніше */
  }

  .grid-container {
    /* Примусово 2 колонки на мобільному */
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    padding: 10px;
  }
  
  /* ТУТ БУВ FIX:
     Ми не задаємо height для img.
     Висота сама підтягнеться через aspect-ratio: 2/3 у .grid-item
  */
}

/* Скролбар */
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 20px; }
</style>
