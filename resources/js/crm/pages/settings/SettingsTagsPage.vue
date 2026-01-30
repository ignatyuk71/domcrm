<template>
  <div class="container-fluid max-width-1200 py-3 py-md-5">
    <div class="d-flex align-items-center justify-content-between mb-4 px-2">
      <div>
        <h1 class="h3 fw-bold mb-1 text-dark">Теги</h1>
        <p class="text-muted small mb-0 d-none d-sm-block">Маркування замовлень та товарів</p>
      </div>
      <button class="btn btn-primary rounded-pill px-4 shadow-sm" @click="openModal()">
        <i class="bi bi-plus-circle-fill me-2"></i> Новий тег
      </button>
    </div>

    <div class="list-container shadow-sm bg-white rounded-4 overflow-hidden">
      <div v-if="loading" class="p-5 text-center">
        <div class="spinner-border text-primary-subtle" role="status"></div>
      </div>

      <div v-else class="list-group list-group-flush">
        <div v-for="t in tags" :key="t.id" class="list-group-item list-item-hover px-4 py-3">
          <div class="row align-items-center">
            <div class="col">
              <span class="custom-tag-badge shadow-sm" 
                    :style="{ backgroundColor: t.color + '20', color: t.color, borderColor: t.color + '40' }">
                <i :class="['bi', t.icon || 'bi-tag-fill', 'me-2']"></i>
                {{ t.name }}
              </span>
            </div>
            <div class="col-auto d-flex gap-2">
              <button class="btn btn-action-edit shadow-sm" @click="openModal(t)"><i class="bi bi-pencil-square"></i></button>
              <button class="btn btn-action-delete shadow-sm" @click="confirmDelete(t)"><i class="bi bi-trash3"></i></button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showModal" class="modal-overlay d-flex align-items-center justify-content-center p-3">
      <div class="modal-card bg-white shadow-lg animate-slide-up">
        <div class="modal-card-header px-4 pt-4">
          <h4 class="fw-bold m-0">{{ editingId ? 'Редагувати' : 'Створити тег' }}</h4>
          <button class="btn-close-custom" @click="closeModal">&times;</button>
        </div>
        
        <div class="modal-card-body p-4">
          <div class="preview-box mb-4 p-3 rounded-4 border text-center bg-light">
            <span class="custom-tag-badge shadow-sm" 
                  :style="{ backgroundColor: activeForm.color + '20', color: activeForm.color, borderColor: activeForm.color + '40' }">
              <i :class="['bi', activeForm.icon, 'me-2']"></i>
              {{ activeForm.name || 'Назва тега' }}
            </span>
          </div>

          <div class="mb-4">
            <label class="form-label-custom">Назва тега</label>
            <input v-model.trim="activeForm.name" type="text" 
                   class="form-input-custom" :class="{'is-invalid': nameExists}"
                   placeholder="Наприклад: Повернення" @input="checkNameExists">
            <div v-if="nameExists" class="text-danger small mt-1">Такий тег уже існує!</div>
          </div>

          <div class="row g-3">
            <div class="col-6">
              <label class="form-label-custom">Колір</label>
              <div class="d-flex align-items-center gap-2 p-2 border rounded-3 bg-light">
                <div class="color-picker-mini shadow-sm">
                  <input type="color" v-model="activeForm.color" class="real-color-picker">
                  <div class="color-visual-trigger" :style="{ backgroundColor: activeForm.color }"></div>
                </div>
                <input v-model="activeForm.color" type="text" class="form-control border-0 bg-transparent small p-0 fw-bold" style="width: 70px">
              </div>
            </div>

            <div class="col-6">
              <label class="form-label-custom">Іконка</label>
              <button class="btn btn-outline-secondary w-100 rounded-3 py-2 d-flex align-items-center justify-content-center gap-2" 
                      @click="showIconGallery = true">
                <i :class="['bi', activeForm.icon]"></i>
                <span>Змінити</span>
              </button>
            </div>
          </div>
        </div>

        <div class="modal-card-footer px-4 pb-4 d-flex gap-2">
          <button class="btn btn-light-custom" @click="closeModal">Скасувати</button>
          <button class="btn btn-primary-custom" :disabled="saving || nameExists || !activeForm.name" @click="handleSubmit">
            <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
            Зберегти
          </button>
        </div>
      </div>
    </div>

    <div v-if="showIconGallery" class="modal-overlay d-flex align-items-center justify-content-center p-3 secondary-modal">
      <div class="modal-card bg-white shadow-xl animate-zoom-in" style="max-width: 400px;">
        <div class="modal-card-header px-4 pt-4 d-flex justify-content-between">
          <h5 class="fw-bold m-0">Оберіть іконку</h5>
          <button class="btn-close-custom" @click="showIconGallery = false">&times;</button>
        </div>
        <div class="modal-card-body p-4">
          <div class="icon-gallery-grid">
            <div v-for="icon in iconLibrary" :key="icon" 
                 class="gallery-icon-item" 
                 :class="{ 'active': activeForm.icon === icon }"
                 @click="selectIcon(icon)">
              <i :class="['bi', icon]"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref, reactive } from 'vue';
import http from '@/crm/api/http';

const tags = ref([]);
const loading = ref(false);
const saving = ref(false);
const showModal = ref(false);
const showIconGallery = ref(false);
const editingId = ref(null);
const nameExists = ref(false);

const activeForm = reactive({ name: '', color: '#3b82f6', icon: 'bi-tag-fill' });

const iconLibrary = [
  'bi-tag-fill', 'bi-star-fill', 'bi-bookmark-fill', 'bi-check-circle-fill', 'bi-exclamation-circle-fill',
  'bi-clock-fill', 'bi-truck', 'bi-wallet2', 'bi-chat-dots-fill', 'bi-telephone-fill',
  'bi-envelope-fill', 'bi-arrow-return-left', 'bi-gift-fill', 'bi-percent', 'bi-cart-fill',
  'bi-person-fill', 'bi-lightning-fill', 'bi-shield-fill-check', 'bi-heart-fill', 'bi-flag-fill',
  'bi-bell-fill', 'bi-camera-fill', 'bi-credit-card-fill', 'bi-geo-alt-fill', 'bi-hand-thumbs-up-fill',
  'bi-hourglass-split', 'bi-info-circle-fill', 'bi-key-fill', 'bi-pencil-fill', 'bi-plus-circle-fill',
  'bi-question-circle-fill', 'bi-search', 'bi-tools', 'bi-trash-fill', 'bi-unlock-fill',
  'bi-wifi', 'bi-x-circle-fill', 'bi-bag-fill', 'bi-gem', 'bi-trophy-fill'
];

function transliterate(text) {
  const map = {'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','є':'ye','ж':'zh','з':'z','и':'y','і':'i','ї':'yi','й':'y','к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u','ф':'f','х':'kh','ц':'ts','ч':'ch','ш':'sh','щ':'shch','ь':'','ю':'yu','я':'ya'};
  return text.toLowerCase().split('').map(c => map[c] || (/[a-z0-9]/.test(c) ? c : '_')).join('').replace(/__+/g, '_');
}

async function load() {
  loading.value = true;
  try {
    const { data } = await http.get('/tags');
    tags.value = data?.data || [];
  } finally { loading.value = false; }
}

function checkNameExists() {
  nameExists.value = tags.value.some(t => t.name.toLowerCase() === activeForm.name.toLowerCase() && t.id !== editingId.value);
}

function openModal(tag = null) {
  if (tag) {
    editingId.value = tag.id;
    Object.assign(activeForm, { name: tag.name, color: tag.color || '#3b82f6', icon: tag.icon || 'bi-tag-fill' });
  } else {
    editingId.value = null;
    Object.assign(activeForm, { name: '', color: '#3b82f6', icon: 'bi-tag-fill' });
  }
  showModal.value = true;
}

function selectIcon(icon) {
  activeForm.icon = icon;
  showIconGallery = false; // Закриваємо галерею після вибору
}

function closeModal() { showModal.value = false; }

async function handleSubmit() {
  saving.value = true;
  try {
    const payload = { ...activeForm, code: transliterate(activeForm.name) };
    editingId.value ? await http.put(`/tags/${editingId.value}`, payload) : await http.post('/tags', payload);
    closeModal();
    await load();
  } finally { saving.value = false; }
}

async function confirmDelete(t) {
  if (!confirm(`Видалити тег "${t.name}"?`)) return;
  await http.delete(`/tags/${t.id}`);
  await load();
}

onMounted(load);
</script>

<style scoped>
.max-width-1200 { max-width: 1200px; margin: 0 auto; }
.secondary-modal { z-index: 1100; background: rgba(0,0,0,0.2); }

/* Gallery Grid */
.icon-gallery-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 12px;
  max-height: 300px;
  overflow-y: auto;
  padding: 4px;
}

.gallery-icon-item {
  aspect-ratio: 1;
  display: flex; align-items: center; justify-content: center;
  border-radius: 12px;
  border: 1px solid #f1f5f9;
  cursor: pointer;
  font-size: 1.4rem;
  transition: 0.2s;
}
.gallery-icon-item:hover { background: #f8fafc; transform: scale(1.1); border-color: #cbd5e1; }
.gallery-icon-item.active { background: #0d6efd; color: white; }

/* Styles from previous versions */
.list-container { border: 1px solid #eee; background: white; }
.list-item-hover { transition: all 0.2s ease; border-left: 4px solid transparent; }
.list-item-hover:hover { background-color: #fcfdfe; border-left-color: #0d6efd; }
.custom-tag-badge { display: inline-flex; align-items: center; padding: 8px 16px; border-radius: 10px; font-weight: 700; border: 1px solid transparent; }
.modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 1050; }
.modal-card { width: 100%; max-width: 460px; border-radius: 28px; overflow: hidden; }
.form-input-custom { width: 100%; padding: 12px; border-radius: 12px; border: 2px solid #f1f5f9; background: #f8fafc; }
.color-picker-mini { position: relative; width: 35px; height: 35px; border-radius: 8px; overflow: hidden; }
.real-color-picker { position: absolute; top: -5px; left: -5px; width: 50px; height: 50px; cursor: pointer; }
.color-visual-trigger { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
.btn-primary-custom { background: #0d6efd; color: white; border: none; padding: 14px; border-radius: 14px; font-weight: 600; flex: 1; }
.btn-light-custom { background: #f1f5f9; color: #475569; border: none; padding: 14px; border-radius: 14px; font-weight: 600; flex: 1; }
.btn-action-edit, .btn-action-delete { width: 40px; height: 40px; border-radius: 10px; border: 1px solid #eee; background: white; }
.animate-slide-up { animation: slideUp 0.3s ease-out; }
.animate-zoom-in { animation: zoomIn 0.2s ease-out; }
@keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
@keyframes zoomIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
</style>