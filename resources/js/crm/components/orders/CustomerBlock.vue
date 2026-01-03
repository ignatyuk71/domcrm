<template>
  <div class="customer-section flex-column d-flex gap-3">
    
    <div v-if="hasData && !editing" class="customer-card border rounded-4 bg-white shadow-sm p-3 animate-fade-in">
      <div class="d-flex align-items-center gap-3">
        <div class="avatar-circle">
          <i class="bi bi-person-check-fill fs-4 text-primary"></i>
        </div>
        
        <div class="flex-grow-1 min-width-0">
          <div class="d-flex align-items-center gap-2 mb-1">
            <h6 class="mb-0 fw-bold text-dark text-truncate">{{ displayName }}</h6>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2">Клієнт</span>
          </div>
          <div class="customer-meta small d-flex flex-wrap gap-x-3">
            <span v-if="local.phone" class="text-secondary">
              <i class="bi bi-telephone-fill me-1 text-muted"></i>{{ local.phone }}
            </span>
            <span v-if="local.email" class="text-secondary">
              <i class="bi bi-envelope-at-fill me-1 text-muted"></i>{{ local.email }}
            </span>
          </div>
        </div>

        <div class="d-flex gap-2">
          <button class="btn-action edit" @click="editing = true" title="Редагувати">
            <i class="bi bi-pencil-square"></i>
          </button>
          <button class="btn-action delete" @click="reset" title="Очистити">
            <i class="bi bi-trash3"></i>
          </button>
        </div>
      </div>
    </div>

    <div v-else-if="!hasData && !editing" 
         class="empty-state-card rounded-4 p-4 text-center cursor-pointer transition-all" 
         @click="editing = true">
      <div class="icon-pulse mb-2">
        <i class="bi bi-person-plus text-primary fs-2"></i>
      </div>
      <h6 class="fw-bold text-dark mb-1">Додати покупця</h6>
      <p class="text-muted small mb-0">Знайдіть за номером або створіть новий профіль</p>
    </div>

    <div v-else class="customer-card border rounded-4 bg-white shadow-sm animate-fade-in overflow-hidden">
      <div class="card-header-custom px-3 py-2 border-bottom d-flex justify-content-between align-items-center bg-light-subtle">
        <span class="fw-bold small text-uppercase letter-spacing-1 text-muted">Дані покупця</span>
        <button type="button" class="btn-close-custom" @click="closeForm">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
      
      <div class="p-3">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label-custom">Мобільний телефон</label>
            <div class="input-group-custom">
              <i class="bi bi-telephone input-icon-left"></i>
              <input
                type="tel"
                class="form-control custom-input"
                :class="{ 'is-invalid': errors.phone }"
                v-model="local.phone"
                placeholder="+380..."
                @input="handlePhoneInput"
              />
              <div v-if="searchLoading" class="spinner-border spinner-border-sm input-loader text-primary"></div>
            </div>
            <div class="invalid-feedback d-block" v-if="errors.phone">{{ errors.phone }}</div>
          </div>

          <div class="col-sm-6">
            <label class="form-label-custom">Імʼя</label>
            <input type="text" class="form-control custom-input" :class="{ 'is-invalid': errors.first_name }" v-model="local.first_name" placeholder="Введіть ім'я" />
          </div>

          <div class="col-sm-6">
            <label class="form-label-custom">Прізвище</label>
            <input type="text" class="form-control custom-input" :class="{ 'is-invalid': errors.last_name }" v-model="local.last_name" placeholder="Введіть прізвище" />
          </div>

          <div class="col-12">
            <label class="form-label-custom">Електронна пошта</label>
            <input type="email" class="form-control custom-input" :class="{ 'is-invalid': errors.email }" v-model="local.email" placeholder="example@mail.com" />
          </div>
        </div>
      </div>

      <div class="p-3 bg-light-subtle border-top text-end">
        <button class="btn btn-primary rounded-3 px-4 fw-bold shadow-sm btn-sm" @click="editing = false">
          Зберегти та продовжити
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
  errors: { type: Object, default: () => ({}) },
});
const model = defineModel({ type: Object, default: () => ({}) });
const local = reactive({ 
  phone: '',
  first_name: '',
  last_name: '',
  email: '',
  ...model.value 
});

const editing = ref(false);
const searchLoading = ref(false);

const demoBuyers = [
  { phone: '+380981234567', first_name: 'Світлана', last_name: 'Петрова', email: 'petrova@mail.com' },
  { phone: '+380931112233', first_name: 'Іван', last_name: 'Іванов', email: 'ivanov@mail.com' },
];

const displayName = computed(() => {
  const name = `${local.first_name || ''} ${local.last_name || ''}`.trim();
  return name || 'Покупець без імені';
});

const hasData = computed(() => !!(local.phone || local.first_name || local.last_name || local.email));

function reset() {
  local.first_name = '';
  local.last_name = '';
  local.phone = '';
  local.email = '';
}

function handlePhoneInput() {
  if (local.phone.length >= 10) {
    searchLoading.value = true;
    // Імітація затримки мережі
    setTimeout(() => {
      findByPhone();
      searchLoading.value = false;
    }, 500);
  }
}

function findByPhone() {
  const phone = (local.phone || '').trim();
  const found = demoBuyers.find((b) => b.phone === phone);
  if (found) {
    local.first_name = found.first_name;
    local.last_name = found.last_name;
    local.email = found.email;
  }
}

function closeForm() {
  editing.value = false;
}

watch(() => ({ ...local }), (val) => { model.value = val; }, { deep: true });
watch(() => model.value, (val) => { Object.assign(local, val); }, { deep: true });
</script>

<style scoped>
/* Картка покупця */
.customer-card {
  transition: all 0.3s ease;
  border: 1px solid #edf2f7 !important;
}

/* Аватар */
.avatar-circle {
  width: 52px;
  height: 52px;
  background: #f0f7ff;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

/* Мета-дані */
.customer-meta i {
  font-size: 0.85rem;
}
.gap-x-3 { column-gap: 1rem; }

/* Кнопки дій */
.btn-action {
  width: 34px;
  height: 34px;
  border-radius: 10px;
  border: none;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: 0.2s;
  background: #f8f9fa;
  color: #64748b;
}
.btn-action.edit:hover { background: #e0f2fe; color: #0ea5e9; }
.btn-action.delete:hover { background: #fee2e2; color: #ef4444; }

/* Порожній стан */
.empty-state-card {
  border: 2px dashed #e2e8f0;
  background: #f8fafc;
  color: #64748b;
}
.empty-state-card:hover {
  border-color: #3b82f6;
  background: #fff;
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
}

/* Анімація іконки */
.icon-pulse {
  animation: pulse-soft 2s infinite;
}
@keyframes pulse-soft {
  0% { transform: scale(1); }
  50% { transform: scale(1.05); }
  100% { transform: scale(1); }
}

/* Форми та інпути */
.form-label-custom {
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  color: #94a3b8;
  margin-bottom: 0.4rem;
  letter-spacing: 0.025em;
}

.custom-input {
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  padding: 0.6rem 0.75rem;
  font-size: 0.95rem;
  transition: all 0.2s;
}
.custom-input:focus {
  border-color: #3b82f6;
  box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
}

/* Інпут з іконкою */
.input-group-custom {
  position: relative;
}
.input-icon-left {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: #94a3b8;
  z-index: 5;
}
.input-group-custom .custom-input {
  padding-left: 2.5rem;
}

.input-loader {
  position: absolute;
  right: 12px;
  top: 35%;
}

/* Закрити форму */
.btn-close-custom {
  background: none;
  border: none;
  color: #94a3b8;
  padding: 5px;
  transition: 0.2s;
}
.btn-close-custom:hover { color: #ef4444; }

.animate-fade-in {
  animation: fadeIn 0.3s ease-out;
}
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>