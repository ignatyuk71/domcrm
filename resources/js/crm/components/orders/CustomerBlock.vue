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
              <i class="bi bi-telephone-fill me-1 text-muted"></i>{{ formattedPhone }}
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

    <div v-else class="customer-card border rounded-4 bg-white shadow-sm animate-fade-in overflow-visible">
      <div class="card-header-custom px-3 py-2 border-bottom d-flex justify-content-between align-items-center bg-light-subtle">
        <span class="fw-bold small text-uppercase letter-spacing-1 text-muted">Дані покупця</span>
        <button type="button" class="btn-close-custom" @click="closeForm">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
      
      <div class="p-3">
        <div class="row g-3">
          <div class="col-12 position-relative">
            <label class="form-label-custom">Мобільний телефон</label>
            <div class="input-group-custom">
              <i class="bi bi-telephone input-icon-left"></i>
              <input
                type="tel"
                autocomplete="off"
                name="crm_customer_phone"
                class="form-control custom-input"
                :class="{ 'is-invalid': errors.phone || phoneError }"
                v-model="local.phone"
                placeholder="380..."
                @input="handlePhoneInput"
                @focus="openSuggestions"
                @blur="scheduleCloseSuggestions"
              />
              <div v-if="searchLoading" class="spinner-border spinner-border-sm input-loader text-primary"></div>
            </div>

            <div 
              v-if="showSuggestions && suggestions.length" 
              class="customer-suggest-dropdown shadow-lg"
            >
              <button 
                v-for="customer in suggestions" 
                :key="customer.id" 
                type="button" 
                class="dropdown-item d-flex flex-column align-items-start py-2"
                @mousedown.prevent="selectSuggestion(customer)"
              >
                <div class="d-flex w-100 justify-content-between align-items-center">
                  <span class="fw-bold text-dark">
                    {{ customer.first_name }} {{ customer.last_name }}
                  </span>
                  <span class="badge bg-light text-dark border">{{ customer.phone }}</span>
                </div>
                <small class="text-muted" v-if="customer.email">{{ customer.email }}</small>
              </button>
            </div>

            <div v-if="local.id" class="duplicate-hint mt-2 text-success d-flex align-items-center gap-2 small animate-fade-in">
              <i class="bi bi-check2-circle fs-5"></i>
              <div>
                <span class="fw-bold">Клієнт знайдений у базі.</span>
                <span class="d-block text-muted smaller" style="line-height: 1.2">Редагування оновить його дані.</span>
              </div>
            </div>
            
            <div class="text-danger small mt-2" v-if="searchError">{{ searchError }}</div>
            <div class="invalid-feedback d-block" v-if="errors.phone">{{ errors.phone }}</div>
            <div class="invalid-feedback d-block" v-else-if="phoneError">{{ phoneError }}</div>
          </div>

          <div class="col-sm-6">
            <label class="form-label-custom">Імʼя</label>
            <input type="text" autocomplete="given-name" class="form-control custom-input" :class="{ 'is-invalid': errors.first_name }" v-model="local.first_name" placeholder="Іван" />
          </div>

          <div class="col-sm-6">
            <label class="form-label-custom">Прізвище</label>
            <input type="text" autocomplete="family-name" class="form-control custom-input" :class="{ 'is-invalid': errors.last_name }" v-model="local.last_name" placeholder="Іванов" />
          </div>

          <div class="col-12">
            <label class="form-label-custom">Електронна пошта</label>
            <input type="email" autocomplete="email" class="form-control custom-input" :class="{ 'is-invalid': errors.email }" v-model="local.email" placeholder="example@mail.com" />
          </div>
        </div>
      </div>

      <div class="p-3 bg-light-subtle border-top text-end">
        <button class="btn btn-primary rounded-3 px-4 fw-bold shadow-sm btn-sm" @click="editing = false">
          Готово
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch, onMounted } from 'vue';
import { searchCustomers } from '@/crm/api/customers';

const props = defineProps({
  errors: { type: Object, default: () => ({}) },
});

// Використовуємо defineModel для двостороннього зв'язку (Vue 3.4+)
const model = defineModel({ type: Object, default: () => ({}) });

const local = reactive({ 
  id: null,
  phone: '',
  first_name: '',
  last_name: '',
  email: '',
  ...model.value 
});

const editing = ref(false);
const searchLoading = ref(false);
const searchError = ref('');
const suggestions = ref([]);
const showSuggestions = ref(false);
let searchTimer = null;

// --- Computed ---

const displayName = computed(() => {
  const name = `${local.first_name || ''} ${local.last_name || ''}`.trim();
  return name || 'Покупець без імені';
});

const hasData = computed(() => !!(local.id || local.phone || local.first_name || local.last_name));

const formattedPhone = computed(() => {
  // Просте форматування для відображення
  if (!local.phone) return '';
  // Якщо номер схожий на 380XX...
  const cleaned = normalizePhone(local.phone);
  if (cleaned.length === 12) {
    return `+${cleaned.substring(0, 2)} (${cleaned.substring(2, 5)}) ${cleaned.substring(5, 8)}-${cleaned.substring(8, 10)}-${cleaned.substring(10, 12)}`;
  }
  return local.phone;
});

// --- Methods ---

const normalizePhone = (value) => (value || '').replace(/\D+/g, '');
const normalizePhoneInput = (value) => {
  let digits = normalizePhone(value);
  if (!digits) return '';
  if (digits.startsWith('0')) {
    digits = '38' + digits;
  } else if (digits.startsWith('38') && !digits.startsWith('380')) {
    digits = '380' + digits.slice(2);
  }
  if (digits.startsWith('3800')) {
    digits = '380' + digits.slice(4);
  }
  return digits.slice(0, 12);
};

const phoneError = computed(() => {
  if (!local.phone) return '';
  const digits = normalizePhone(local.phone);
  if (!/^380\d{9}$/.test(digits)) {
    return 'Невірний номер (формат 380XXXXXXXXX)';
  }
  return '';
});

function reset() {
  Object.assign(local, {
    id: null, first_name: '', last_name: '', phone: '', email: ''
  });
  suggestions.value = [];
  searchError.value = '';
}

function handlePhoneInput() {
  if (searchTimer) clearTimeout(searchTimer);
  searchError.value = '';
  local.id = null; // Скидаємо ID, бо номер змінився

  const normalized = normalizePhoneInput(local.phone || '');
  if (normalized !== local.phone) {
    local.phone = normalized;
  }
  const phone = normalized;
  
  // Якщо стерли номер - ховаємо все
  if (!phone) {
    suggestions.value = [];
    showSuggestions.value = false;
    return;
  }

  // Починаємо шукати від 4 цифр (раніше було 7, краще раніше показувати)
  if (phone.length < 4) {
    searchLoading.value = false;
    return;
  }

  searchLoading.value = true;
  searchTimer = setTimeout(() => lookupCustomers(phone), 400);
}

async function lookupCustomers(phone) {
  try {
    const { data } = await searchCustomers(phone);
    const results = data?.data || data || [];
    suggestions.value = results;

    const normalizedInput = normalizePhone(phone);
    
    // Шукаємо точний збіг
    const exact = results.find((c) => normalizePhone(c.phone) === normalizedInput);
    
    if (exact) {
      // Якщо знайшли повний збіг номера - автозаповнюємо
      applyCustomer(exact);
      showSuggestions.value = false;
    } else {
      showSuggestions.value = !!results.length;
    }
  } catch (e) {
    console.error(e);
    // searchError.value = 'Помилка пошуку'; // Можна не показувати юзеру
  } finally {
    searchLoading.value = false;
  }
}

function applyCustomer(customer) {
  local.id = customer.id;
  local.first_name = customer.first_name || '';
  local.last_name = customer.last_name || '';
  local.email = customer.email || '';
  local.phone = normalizePhoneInput(customer.phone || local.phone);
}

function selectSuggestion(customer) {
  applyCustomer(customer);
  showSuggestions.value = false;
}

function openSuggestions() {
  if (suggestions.value.length) {
    showSuggestions.value = true;
  }
}

function closeForm() {
  // Якщо закрили форму, але даних немає - очищаємо
  if (!hasData.value) {
    reset();
  }
  editing.value = false;
}

const scheduleCloseSuggestions = () => setTimeout(() => (showSuggestions.value = false), 200);

// --- Watchers (Optimized) ---

// 1. Оновлюємо батьківську модель, коли змінюється локальна
watch(() => ({ ...local }), (newVal) => { 
  // JSON.stringify для простого deep compare, щоб уникнути циклів
  if (JSON.stringify(newVal) !== JSON.stringify(model.value)) {
    model.value = newVal; 
  }
}, { deep: true });

// 2. Оновлюємо локальну модель, якщо батько змінив дані (наприклад, завантаження існуючого замовлення)
watch(() => model.value, (newVal) => { 
  if (newVal && JSON.stringify(newVal) !== JSON.stringify(local)) {
    Object.assign(local, newVal);
  }
}, { deep: true, immediate: true });

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
.customer-meta i { font-size: 0.85rem; }
.gap-x-3 { column-gap: 1rem; }

/* Кнопки дій */
.btn-action {
  width: 34px; height: 34px;
  border-radius: 10px; border: none;
  display: flex; align-items: center; justify-content: center;
  transition: 0.2s; background: #f8f9fa; color: #64748b;
}
.btn-action.edit:hover { background: #e0f2fe; color: #0ea5e9; }
.btn-action.delete:hover { background: #fee2e2; color: #ef4444; }

/* Порожній стан */
.empty-state-card {
  border: 2px dashed #e2e8f0; background: #f8fafc; color: #64748b;
}
.empty-state-card:hover {
  border-color: #3b82f6; background: #fff;
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
}

/* Анімація іконки */
.icon-pulse { animation: pulse-soft 2s infinite; }
@keyframes pulse-soft {
  0% { transform: scale(1); }
  50% { transform: scale(1.05); }
  100% { transform: scale(1); }
}

/* Форми та інпути */
.form-label-custom {
  font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
  color: #94a3b8; margin-bottom: 0.4rem; letter-spacing: 0.025em;
}

.custom-input {
  border-radius: 10px; border: 1px solid #e2e8f0;
  padding: 0.6rem 0.75rem 0.6rem 2.6rem; /* padding-left for icon */
  font-size: 0.95rem; transition: all 0.2s;
}
.custom-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }

/* Інпут з іконкою */
.input-group-custom { position: relative; }
.input-icon-left {
  position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
  color: #94a3b8; z-index: 5;
}
.input-loader { position: absolute; right: 12px; top: 30%; }

/* Закрити форму */
.btn-close-custom {
  background: none; border: none; color: #94a3b8; padding: 5px; transition: 0.2s;
}
.btn-close-custom:hover { color: #ef4444; }

.animate-fade-in { animation: fadeIn 0.3s ease-out; }
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Dropdown */
.customer-suggest-dropdown {
  position: absolute; top: 105%; left: 0; right: 0;
  background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
  overflow-y: auto; max-height: 250px; z-index: 1050; /* Вищий Z-Index */
}
.customer-suggest-dropdown .dropdown-item {
  border-bottom: 1px solid #f8fafc; padding: 10px 14px;
  width: 100%; text-align: left; background: none; border: 0; border-bottom: 1px solid #f1f5f9;
  transition: background 0.1s;
}
.customer-suggest-dropdown .dropdown-item:hover { background: #f8fafc; }
.customer-suggest-dropdown .dropdown-item:last-child { border-bottom: none; }

/* Hint */
.duplicate-hint { font-weight: 500; }
</style>
