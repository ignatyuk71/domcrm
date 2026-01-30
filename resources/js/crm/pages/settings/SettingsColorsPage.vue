<template>
  <div class="container-fluid max-width-1200 py-3 py-md-5">
    <div class="d-flex align-items-center justify-content-between mb-4 px-2">
      <div>
        <h1 class="h3 fw-bold mb-1 text-dark">Кольори</h1>
        <p class="text-muted small mb-0 d-none d-sm-block">Керування палітрою товарів</p>
      </div>
      <button class="btn btn-primary rounded-pill px-4 d-flex align-items-center gap-2 shadow-sm" @click="openModal()">
        <i class="bi bi-plus-circle-fill"></i>
        <span>Додати колір</span>
      </button>
    </div>

    <div class="list-container shadow-sm bg-white rounded-4 overflow-hidden">
      <div v-if="loading" class="p-5 text-center">
        <div class="spinner-border text-primary-subtle" role="status"></div>
      </div>

      <div v-else-if="colors.length" class="list-header d-none d-md-grid px-4 py-3 bg-light border-bottom">
        <div class="small fw-bold text-muted uppercase-tracking">Назва та візуал</div>
        <div class="small fw-bold text-muted uppercase-tracking text-center">HEX Код</div>
        <div class="small fw-bold text-muted uppercase-tracking text-end">Дії</div>
      </div>

      <div v-if="!loading" class="list-group list-group-flush">
        <div v-for="c in colors" :key="c.id" class="list-group-item list-item-hover px-4 py-3">
          <div class="row align-items-center g-3">
            <div class="col-12 col-md-6">
              <div class="d-flex align-items-center">
                <div class="color-preview-circle me-3 shadow-sm" :style="{ backgroundColor: c.hex_code || '#eee' }"></div>
                <div>
                  <div class="fw-bold text-dark fs-5">{{ c.name }}</div>
                  <div class="text-muted small d-md-none">{{ c.hex_code || '—' }}</div>
                </div>
              </div>
            </div>

            <div class="col-md-2 text-center d-none d-md-block">
              <code class="hex-badge">{{ c.hex_code || '—' }}</code>
            </div>

            <div class="col-12 col-md-4 text-end">
              <div class="d-flex justify-content-end gap-2">
                <button class="btn btn-action-edit shadow-sm" @click="openModal(c)">
                  <i class="bi bi-pencil-square"></i>
                </button>
                <button class="btn btn-action-delete shadow-sm" @click="confirmDelete(c)">
                  <i class="bi bi-trash3"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="!colors.length && !loading" class="p-5 text-center">
        <i class="bi bi-palette display-1 text-light"></i>
        <p class="h5 mt-3 text-muted">Палітра порожня</p>
      </div>
    </div>

    <div v-if="showModal" class="modal-overlay d-flex align-items-center justify-content-center">
      <div class="modal-card bg-white shadow-lg animate-slide-up">
        <div class="modal-card-header">
          <h4 class="fw-bold m-0">{{ editingId ? 'Редагувати колір' : 'Новий колір' }}</h4>
          <button class="btn-close-custom" @click="closeModal">&times;</button>
        </div>
        
        <div class="modal-card-body">
          <div class="mb-4">
            <label class="form-label-custom">Назва кольору</label>
            <input v-model.trim="activeForm.name" type="text" class="form-input-custom" placeholder="Напр: Океанський синій">
          </div>
          
          <div class="mb-2">
            <label class="form-label-custom">Виберіть колір</label>
            <div class="d-flex align-items-center gap-3 p-3 border rounded-4 bg-light">
              <div class="color-input-wrapper shadow-sm">
                <input type="color" v-model="activeForm.hex_code" class="real-color-picker">
                <div class="color-visual-trigger" :style="{ backgroundColor: activeForm.hex_code }"></div>
              </div>
              <div class="flex-grow-1">
                <input v-model.trim="activeForm.hex_code" type="text" class="form-control border-0 bg-transparent fw-mono" placeholder="#000000">
                <div class="small text-muted">Натисніть на круг, щоб відкрити палітру</div>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-card-footer">
          <button class="btn btn-light-custom" @click="closeModal">Відмінити</button>
          <button class="btn btn-primary-custom" :disabled="saving" @click="handleSubmit">
            <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
            Зберегти
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.max-width-1200 { max-width: 1200px; margin: 0 auto; }

/* List Styles */
.list-container { border: 1px solid #eee; background: white; }
.list-header { grid-template-columns: 1fr 120px 150px; }
.uppercase-tracking { text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.7rem; }

.list-item-hover { transition: all 0.2s ease; border-left: 4px solid transparent; }
.list-item-hover:hover { background-color: #fcfdfe; border-left-color: #0d6efd; }

/* Color Visuals */
.color-preview-circle {
  width: 42px; height: 42px;
  border-radius: 50%;
  border: 3px solid white;
  outline: 1px solid #eee;
}

.hex-badge {
  background: #f1f5f9;
  padding: 4px 10px;
  border-radius: 8px;
  font-family: monospace;
  color: #475569;
}

/* Color Picker Trigger Customization */
.color-input-wrapper {
  position: relative;
  width: 50px; height: 50px;
  border-radius: 50%;
  overflow: hidden;
  cursor: pointer;
  border: 2px solid white;
}

.real-color-picker {
  position: absolute;
  top: -10px; left: -10px; width: 100px; height: 100px;
  cursor: pointer;
}

.color-visual-trigger {
  position: absolute;
  top: 0; left: 0; width: 100%; height: 100%;
  pointer-events: none; /* Пропускає клік до реального інпуту */
}

/* Modal UI */
.modal-overlay {
  position: fixed; top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px);
  z-index: 1000; padding: 20px;
}
.modal-card { width: 100%; max-width: 440px; border-radius: 24px; overflow: hidden; }
.modal-card-header { padding: 24px 24px 10px; display: flex; justify-content: space-between; align-items: center; }
.modal-card-body { padding: 24px; }
.modal-card-footer { padding: 0 24px 24px; display: flex; gap: 12px; }

.btn-close-custom { background: #f1f5f9; border: none; width: 32px; height: 32px; border-radius: 50%; }

.form-label-custom { font-weight: 600; font-size: 0.85rem; color: #64748b; margin-bottom: 8px; }
.form-input-custom {
  width: 100%; padding: 12px 16px; border-radius: 12px;
  border: 1px solid #e2e8f0; background: #f8fafc;
}

.btn-primary-custom { background: #0d6efd; color: white; border: none; padding: 12px 24px; border-radius: 12px; font-weight: 600; flex: 1; }
.btn-light-custom { background: #f1f5f9; color: #475569; border: none; padding: 12px 24px; border-radius: 12px; font-weight: 600; flex: 1; }

.btn-action-edit, .btn-action-delete {
  width: 38px; height: 38px; border-radius: 10px; border: 1px solid #e0e0e0; background: white;
}

.fw-mono { font-family: monospace; font-size: 1.1rem; }

@media (max-width: 768px) {
  .modal-card { align-self: flex-end; border-radius: 24px 24px 0 0; margin-bottom: -20px; }
}
</style>

<script setup>
import { onMounted, ref, reactive } from 'vue';
import http from '@/crm/api/http';

const colors = ref([]);
const loading = ref(false);
const saving = ref(false);
const showModal = ref(false);
const editingId = ref(null);

const activeForm = reactive({
  name: '',
  hex_code: '#0d6efd' // Значення за замовчуванням для пікера
});

async function load() {
  loading.value = true;
  try {
    const { data } = await http.get('/settings/colors');
    colors.value = data?.data || [];
  } finally {
    loading.value = false;
  }
}

function openModal(color = null) {
  if (color) {
    editingId.value = color.id;
    activeForm.name = color.name;
    activeForm.hex_code = color.hex_code || '#000000';
  } else {
    editingId.value = null;
    activeForm.name = '';
    activeForm.hex_code = '#0d6efd';
  }
  showModal.value = true;
}

function closeModal() {
  showModal.value = false;
}

async function handleSubmit() {
  if (!activeForm.name) return;
  saving.value = true;
  try {
    const payload = {
      name: activeForm.name,
      hex_code: activeForm.hex_code
    };
    
    if (editingId.value) {
      await http.put(`/settings/colors/${editingId.value}`, payload);
    } else {
      await http.post('/settings/colors', payload);
    }
    closeModal();
    await load();
  } finally {
    saving.value = false;
  }
}

async function confirmDelete(c) {
  if (!confirm(`Видалити колір "${c.name}"?`)) return;
  await http.delete(`/settings/colors/${c.id}`);
  await load();
}

onMounted(load);
</script>