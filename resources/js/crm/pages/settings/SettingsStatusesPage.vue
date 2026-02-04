<template>
  <div class="container-fluid max-width-1200 py-3 py-md-5">
    <div class="d-flex align-items-center justify-content-between mb-4 px-2">
      <div>
        <h1 class="h3 fw-bold mb-1 text-dark">Статуси</h1>
        <p class="text-muted small mb-0 d-none d-sm-block">Керування статусами замовлень та оплат</p>
      </div>
      <button class="btn btn-primary rounded-pill px-4 shadow-sm" @click="openModal()">
        <i class="bi bi-plus-circle-fill me-2"></i> Новий статус
      </button>
    </div>

    <div class="list-container shadow-sm bg-white rounded-4 overflow-hidden">
      <div v-if="loading" class="p-5 text-center">
        <div class="spinner-border text-primary-subtle" role="status"></div>
      </div>

      <div v-else-if="statuses.length" class="list-header d-none d-lg-grid px-4 py-3 bg-light border-bottom">
        <div class="small fw-bold text-muted uppercase-tracking">Статус</div>
        <div class="small fw-bold text-muted uppercase-tracking">Код</div>
        <div class="small fw-bold text-muted uppercase-tracking text-center">Тип</div>
        <div class="small fw-bold text-muted uppercase-tracking text-center">Порядок</div>
        <div class="small fw-bold text-muted uppercase-tracking text-center">Default</div>
        <div class="small fw-bold text-muted uppercase-tracking text-end">Дії</div>
      </div>

      <div v-if="!loading" class="list-group list-group-flush">
        <div v-for="s in statuses" :key="s.id" class="list-group-item list-item-hover px-4 py-3">
          <div class="row align-items-center g-3">
            <div class="col-12 col-lg-4">
              <div class="d-flex align-items-center gap-3">
                <span class="status-badge shadow-sm" :style="statusStyle(s)">
                  <i :class="['bi', s.icon || 'bi-flag-fill', 'me-2']"></i>
                  {{ s.name }}
                </span>
                <span v-if="s.is_default" class="badge rounded-pill bg-success-subtle text-success fw-semibold d-lg-none">Default</span>
              </div>
              <div class="text-muted small mt-1 d-lg-none">Код: <code>{{ s.code }}</code></div>
            </div>

            <div class="col-6 col-lg-2 d-none d-lg-block">
              <code>{{ s.code }}</code>
            </div>

            <div class="col-6 col-lg-2 text-lg-center">
              <span class="type-pill">{{ s.type }}</span>
            </div>

            <div class="col-6 col-lg-1 text-lg-center">
              <span class="sort-pill">
                <i class="bi bi-sort-numeric-down me-1"></i> {{ s.sort_order ?? 0 }}
              </span>
            </div>

            <div class="col-6 col-lg-1 text-lg-center">
              <span class="default-indicator" :class="{ active: s.is_default }">
                <i class="bi" :class="s.is_default ? 'bi-check-circle-fill' : 'bi-circle'"></i>
              </span>
            </div>

            <div class="col-12 col-lg-2 text-end">
              <div class="d-flex justify-content-end gap-2">
                <button class="btn btn-action-edit shadow-sm" @click="openModal(s)">
                  <i class="bi bi-pencil-square"></i>
                </button>
                <button class="btn btn-action-delete shadow-sm" @click="confirmDelete(s)">
                  <i class="bi bi-trash3"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="!statuses.length && !loading" class="p-5 text-center">
        <i class="bi bi-inbox display-1 text-light"></i>
        <p class="h5 mt-3 text-muted">Список статусів порожній</p>
      </div>
    </div>

    <div v-if="showModal" class="modal-overlay d-flex align-items-center justify-content-center p-3">
      <div class="modal-card bg-white shadow-lg animate-slide-up">
        <div class="modal-card-header px-4 pt-4">
          <h4 class="fw-bold m-0">{{ editingId ? 'Редагувати статус' : 'Створити статус' }}</h4>
          <button class="btn-close-custom" @click="closeModal">&times;</button>
        </div>

        <div class="modal-card-body p-4">
          <div class="preview-box mb-4 p-3 rounded-4 border text-center bg-light">
            <span class="status-badge shadow-sm" :style="statusStyle(activeForm)">
              <i :class="['bi', activeForm.icon || 'bi-flag-fill', 'me-2']"></i>
              {{ activeForm.name || 'Назва статусу' }}
            </span>
          </div>

          <div class="mb-4">
            <label class="form-label-custom">Назва статусу</label>
            <input v-model.trim="activeForm.name" type="text"
                   class="form-input-custom" :class="{ 'is-invalid': nameExists }"
                   placeholder="Наприклад: В обробці" @input="onNameInput">
            <div v-if="nameExists" class="text-danger small mt-1">Такий статус уже існує!</div>
          </div>

          <div class="mb-4">
            <label class="form-label-custom d-flex justify-content-between align-items-center">
              Код
              <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" @click="generateCode">
                Згенерувати
              </button>
            </label>
            <input v-model.trim="activeForm.code" type="text"
                   class="form-input-custom" :class="{ 'is-invalid': codeExists }"
                   placeholder="Наприклад: in_process" @input="onCodeInput">
            <div v-if="codeExists" class="text-danger small mt-1">Код уже використовується!</div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-6">
              <label class="form-label-custom">Тип</label>
              <input v-model.trim="activeForm.type" list="status-types" type="text"
                     class="form-input-custom" placeholder="order">
              <datalist id="status-types">
                <option value="order"></option>
                <option value="payment"></option>
                <option value="delivery"></option>
              </datalist>
            </div>
            <div class="col-6">
              <label class="form-label-custom">Порядок</label>
              <input v-model.number="activeForm.sort_order" type="number" min="0" class="form-input-custom">
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-6">
              <label class="form-label-custom">Колір</label>
              <div class="d-flex align-items-center gap-2 p-2 border rounded-3 bg-light">
                <div class="color-picker-mini shadow-sm">
                  <input type="color" v-model="activeForm.color" class="real-color-picker">
                  <div class="color-visual-trigger" :style="{ backgroundColor: safeColor(activeForm.color) }"></div>
                </div>
                <input v-model.trim="activeForm.color" type="text"
                       class="form-control border-0 bg-transparent small p-0 fw-bold" style="width: 90px">
              </div>
            </div>
            <div class="col-6">
              <label class="form-label-custom">Іконка</label>
              <button class="btn btn-outline-secondary w-100 rounded-3 py-2 d-flex align-items-center justify-content-center gap-2"
                      @click="showIconGallery = true">
                <i :class="['bi', activeForm.icon || 'bi-flag-fill']"></i>
                <span>Змінити</span>
              </button>
            </div>
          </div>

          <div class="form-check form-switch">
            <input v-model="activeForm.is_default" class="form-check-input" type="checkbox" role="switch" id="statusDefault">
            <label class="form-check-label" for="statusDefault">Статус за замовчуванням</label>
          </div>
          <div class="text-muted small mt-1">У межах одного типу може бути лише один default.</div>
        </div>

        <div class="modal-card-footer px-4 pb-4 d-flex gap-2">
          <button class="btn btn-light-custom" @click="closeModal">Скасувати</button>
          <button class="btn btn-primary-custom" :disabled="saving || codeExists || nameExists || !activeForm.name || !activeForm.code"
                  @click="handleSubmit">
            <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
            Зберегти
          </button>
        </div>
      </div>
    </div>

    <div v-if="showIconGallery" class="modal-overlay d-flex align-items-center justify-content-center p-3 secondary-modal">
      <div class="modal-card bg-white shadow-xl animate-zoom-in" style="max-width: 420px;">
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
import { onMounted, reactive, ref } from 'vue';
import http from '@/crm/api/http';

const statuses = ref([]);
const loading = ref(false);
const saving = ref(false);
const showModal = ref(false);
const showIconGallery = ref(false);
const editingId = ref(null);
const nameExists = ref(false);
const codeExists = ref(false);
const codeTouched = ref(false);

const activeForm = reactive({
  name: '',
  code: '',
  type: 'order',
  icon: 'bi-flag-fill',
  color: '#0d6efd',
  sort_order: 0,
  is_default: false,
});

const iconLibrary = [
  'bi-flag-fill', 'bi-check-circle-fill', 'bi-hourglass-split', 'bi-gear-wide-connected',
  'bi-box-seam', 'bi-truck', 'bi-bag-check', 'bi-x-circle', 'bi-arrow-counterclockwise',
  'bi-credit-card-fill', 'bi-cash-stack', 'bi-wallet2', 'bi-exclamation-circle-fill',
  'bi-lightning-fill', 'bi-shield-check', 'bi-stars', 'bi-person-check-fill', 'bi-clock-fill',
];

function transliterate(text) {
  const map = {'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','є':'ye','ж':'zh','з':'z','и':'y','і':'i','ї':'yi','й':'y','к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u','ф':'f','х':'kh','ц':'ts','ч':'ch','ш':'sh','щ':'shch','ь':'','ю':'yu','я':'ya'};
  return text.toLowerCase().split('').map(c => map[c] || (/[a-z0-9]/.test(c) ? c : '_')).join('').replace(/__+/g, '_');
}

function isHexColor(value) {
  return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(String(value || '').trim());
}

function safeColor(value) {
  return isHexColor(value) ? value : '#0d6efd';
}

function statusStyle(item) {
  const color = safeColor(item?.color);
  return { backgroundColor: `${color}20`, color, borderColor: `${color}40` };
}

async function load() {
  loading.value = true;
  try {
    const { data } = await http.get('/statuses');
    statuses.value = data?.data || [];
  } finally { loading.value = false; }
}

function checkDuplicates() {
  const name = (activeForm.name || '').toLowerCase();
  const code = (activeForm.code || '').toLowerCase();
  nameExists.value = statuses.value.some(s => s.name?.toLowerCase() === name && s.id !== editingId.value);
  codeExists.value = statuses.value.some(s => s.code?.toLowerCase() === code && s.id !== editingId.value);
}

function onNameInput() {
  if (!codeTouched.value) {
    activeForm.code = activeForm.name ? transliterate(activeForm.name) : '';
  }
  checkDuplicates();
}

function onCodeInput() {
  codeTouched.value = true;
  checkDuplicates();
}

function generateCode() {
  activeForm.code = activeForm.name ? transliterate(activeForm.name) : '';
  codeTouched.value = true;
  checkDuplicates();
}

function openModal(status = null) {
  if (status) {
    editingId.value = status.id;
    Object.assign(activeForm, {
      name: status.name,
      code: status.code,
      type: status.type || 'order',
      icon: status.icon || 'bi-flag-fill',
      color: status.color || '#0d6efd',
      sort_order: status.sort_order ?? 0,
      is_default: !!status.is_default,
    });
    codeTouched.value = true;
  } else {
    editingId.value = null;
    Object.assign(activeForm, {
      name: '',
      code: '',
      type: 'order',
      icon: 'bi-flag-fill',
      color: '#0d6efd',
      sort_order: 0,
      is_default: false,
    });
    codeTouched.value = false;
  }
  checkDuplicates();
  showModal.value = true;
}

function selectIcon(icon) {
  activeForm.icon = icon;
  showIconGallery.value = false;
}

function closeModal() { showModal.value = false; }

async function handleSubmit() {
  saving.value = true;
  try {
    const payload = { ...activeForm, is_default: !!activeForm.is_default };
    if (editingId.value) {
      await http.put(`/statuses/${editingId.value}`, payload);
    } else {
      await http.post('/statuses', payload);
    }
    closeModal();
    await load();
  } catch (error) {
    const message = error?.response?.data?.message || 'Не вдалося зберегти статус.';
    alert(message);
  } finally { saving.value = false; }
}

async function confirmDelete(status) {
  if (!confirm(`Видалити статус "${status.name}"?`)) return;
  try {
    await http.delete(`/statuses/${status.id}`);
    await load();
  } catch (error) {
    const message = error?.response?.data?.message || 'Не вдалося видалити статус.';
    alert(message);
  }
}

onMounted(load);
</script>

<style scoped>
.max-width-1200 { max-width: 1200px; margin: 0 auto; }
.secondary-modal { z-index: 1100; background: rgba(0,0,0,0.2); }

/* List */
.list-container { border: 1px solid #eee; background: white; }
.list-header { grid-template-columns: 1.6fr 1fr 0.7fr 0.5fr 0.5fr 0.7fr; }
.uppercase-tracking { text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.7rem; }
.list-item-hover { transition: all 0.2s ease; border-left: 4px solid transparent; }
.list-item-hover:hover { background-color: #fcfdfe; border-left-color: #0d6efd; }

.status-badge { display: inline-flex; align-items: center; padding: 8px 16px; border-radius: 10px; font-weight: 700; border: 1px solid transparent; }
.type-pill { display: inline-flex; align-items: center; justify-content: center; padding: 4px 10px; border-radius: 999px; background: #f1f5f9; font-weight: 600; font-size: 0.75rem; }
.sort-pill { display: inline-flex; align-items: center; justify-content: center; padding: 4px 8px; border-radius: 999px; background: #eef2ff; color: #4338ca; font-weight: 700; font-size: 0.75rem; }
.default-indicator { font-size: 1.1rem; color: #cbd5e1; }
.default-indicator.active { color: #22c55e; }

/* Modal */
.modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 1050; }
.modal-card { width: 100%; max-width: 520px; border-radius: 28px; overflow: hidden; }
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

/* Icon Gallery */
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
</style>
