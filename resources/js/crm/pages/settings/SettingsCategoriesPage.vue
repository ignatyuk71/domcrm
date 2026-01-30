<template>
  <div class="container-fluid max-width-1200 py-3 py-md-5">
    <div class="d-flex align-items-center justify-content-between mb-4 px-2">
      <div>
        <h1 class="h3 fw-bold mb-1 text-dark">Категорії</h1>
        <p class="text-muted small mb-0 d-none d-sm-block">Керування структурою каталогу товарів</p>
      </div>
      <button class="btn btn-primary rounded-pill px-4 d-flex align-items-center gap-2 shadow-sm" @click="openModal()">
        <i class="bi bi-plus-circle-fill"></i>
        <span class="d-none d-sm-inline">Створити нову</span>
        <span class="d-inline d-sm-none">Додати</span>
      </button>
    </div>

    <div class="list-container shadow-sm bg-white rounded-4 overflow-hidden">
      <div v-if="loading" class="p-5 text-center">
        <div class="spinner-border text-primary-subtle" role="status"></div>
      </div>

      <div v-else-if="categories.length" class="list-header d-none d-md-grid px-4 py-3 bg-light border-bottom">
        <div class="small fw-bold text-muted uppercase-tracking">Назва</div>
        <div class="small fw-bold text-muted uppercase-tracking text-center">Сортування</div>
        <div class="small fw-bold text-muted uppercase-tracking text-end">Дії</div>
      </div>

      <div v-if="!loading" class="list-group list-group-flush">
        <div v-for="c in categories" :key="c.id" 
             class="list-group-item list-item-hover px-4 py-3 border-start-accent">
          <div class="row align-items-center g-3">
            <div class="col-12 col-md-6 col-lg-7">
              <div class="d-flex align-items-center">
                <div class="icon-box me-3 d-none d-sm-flex">
                  <i class="bi bi-folder2 text-primary"></i>
                </div>
                <div>
                  <div class="fw-bold text-dark fs-5">{{ c.name }}</div>
                  <div class="text-muted small">ID: {{ c.id }}</div>
                </div>
              </div>
            </div>

            <div class="col-6 col-md-2 text-md-center">
              <span class="d-md-none text-muted small d-block mb-1">Порядок:</span>
              <span class="sort-pill">
                <i class="bi bi-sort-numeric-down me-1"></i> {{ c.sort_order ?? 0 }}
              </span>
            </div>

            <div class="col-6 col-md-4 col-lg-3 text-end">
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

      <div v-if="!categories.length && !loading" class="p-5 text-center">
        <i class="bi bi-inbox display-1 text-light"></i>
        <p class="h5 mt-3 text-muted">Список категорій порожній</p>
      </div>
    </div>

    <div v-if="showModal" class="modal-overlay d-flex align-items-center justify-content-center">
      <div class="modal-card bg-white shadow-lg animate-slide-up">
        <div class="modal-card-header">
          <h4 class="fw-bold m-0">{{ editingId ? 'Редагувати' : 'Додати' }}</h4>
          <button class="btn-close-custom" @click="closeModal">&times;</button>
        </div>
        <div class="modal-card-body">
          <div class="mb-4">
            <label class="form-label-custom">Назва категорії</label>
            <input v-model.trim="activeForm.name" type="text" class="form-input-custom" placeholder="Напр: Електроніка" autofocus>
          </div>
          <div class="mb-2">
            <label class="form-label-custom">Порядок відображення</label>
            <input v-model.number="activeForm.sort_order" type="number" class="form-input-custom" min="0">
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
.list-container { border: 1px solid #eee; }
.list-header { grid-template-columns: 1fr 100px 150px; }
.uppercase-tracking { text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.7rem; }

.list-item-hover {
  transition: all 0.2s ease;
  border-left: 4px solid transparent;
}
.list-item-hover:hover {
  background-color: #fcfdfe;
  border-left-color: #0d6efd;
}

.icon-box {
  width: 42px; height: 42px;
  background: #f0f7ff;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
}

.sort-pill {
  background: #f8f9fa;
  padding: 4px 12px;
  border-radius: 20px;
  font-weight: 600;
  color: #495057;
  border: 1px solid #e9ecef;
}

/* Action Buttons */
.btn-action-edit, .btn-action-delete {
  width: 38px; height: 38px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  border: none; transition: 0.2s;
}
.btn-action-edit { background: #fff; color: #0d6efd; border: 1px solid #e0e0e0; }
.btn-action-edit:hover { background: #0d6efd; color: #fff; }
.btn-action-delete { background: #fff; color: #dc3545; border: 1px solid #e0e0e0; }
.btn-action-delete:hover { background: #dc3545; color: #fff; }

/* Modal Custom Design */
.modal-overlay {
  position: fixed; top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px);
  z-index: 1000; padding: 20px;
}
.modal-card {
  width: 100%; max-width: 440px;
  border-radius: 24px; overflow: hidden;
}
.modal-card-header {
  padding: 24px 24px 10px; display: flex;
  justify-content: space-between; align-items: center;
}
.modal-card-body { padding: 24px; }
.modal-card-footer { padding: 0 24px 24px; display: flex; gap: 12px; }

.btn-close-custom {
  background: #f1f5f9; border: none; width: 32px; height: 32px;
  border-radius: 50%; font-size: 20px; line-height: 1;
}

/* Forms */
.form-label-custom { font-weight: 600; font-size: 0.85rem; color: #64748b; margin-bottom: 8px; }
.form-input-custom {
  width: 100%; padding: 12px 16px; border-radius: 12px;
  border: 1px solid #e2e8f0; background: #f8fafc; transition: 0.2s;
}
.form-input-custom:focus { outline: none; border-color: #0d6efd; background: #fff; box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1); }

/* Buttons */
.btn-primary-custom { background: #0d6efd; color: white; border: none; padding: 12px 24px; border-radius: 12px; font-weight: 600; flex: 1; }
.btn-light-custom { background: #f1f5f9; color: #475569; border: none; padding: 12px 24px; border-radius: 12px; font-weight: 600; flex: 1; }

.animate-slide-up { animation: slideUp 0.3s ease-out; }
@keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

@media (max-width: 768px) {
  .list-item-hover { padding: 1.5rem 1rem !important; }
  .modal-card { align-self: flex-end; border-radius: 24px 24px 0 0; margin-bottom: -20px; } /* Drawer style on mobile */
}
</style>

<script setup>
import { onMounted, ref, reactive } from 'vue';
import http from '@/crm/api/http';

const categories = ref([]);
const loading = ref(false);
const saving = ref(false);
const showModal = ref(false);
const editingId = ref(null);

const activeForm = reactive({ name: '', sort_order: 0 });

async function load() {
  loading.value = true;
  try {
    const { data } = await http.get('/settings/categories');
    categories.value = data?.data || [];
  } finally {
    loading.value = false;
  }
}

function openModal(category = null) {
  if (category) {
    editingId.value = category.id;
    activeForm.name = category.name;
    activeForm.sort_order = category.sort_order ?? 0;
  } else {
    editingId.value = null;
    activeForm.name = '';
    activeForm.sort_order = 0;
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
    if (editingId.value) {
      await http.put(`/settings/categories/${editingId.value}`, { ...activeForm });
    } else {
      await http.post('/settings/categories', { ...activeForm });
    }
    closeModal();
    await load();
  } finally {
    saving.value = false;
  }
}

async function confirmDelete(c) {
  if (!confirm(`Видалити категорію "${c.name}"?`)) return;
  await http.delete(`/settings/categories/${c.id}`);
  await load();
}

onMounted(load);
</script>