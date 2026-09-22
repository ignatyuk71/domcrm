<template>
  <div class="customer-section flex-column d-flex gap-3">
    
    <div v-if="hasData && !editing" class="customer-card rounded-4 bg-white p-3 animate-fade-in">
      <div class="customer-summary d-flex align-items-center gap-3">
        <div class="avatar-circle" aria-hidden="true">
          <i class="bi bi-person"></i>
        </div>
        
        <div class="customer-info flex-grow-1">
          <h6 class="customer-name mb-0">{{ displayName }}</h6>
        </div>
      </div>

      <div v-if="local.phone || local.email" class="customer-meta">
        <div v-if="local.phone" class="customer-contact customer-phone">
          <i class="bi bi-telephone" aria-hidden="true"></i>
          <span>{{ formattedPhone }}</span>
        </div>
        <div v-if="local.email" class="customer-contact">
          <i class="bi bi-envelope" aria-hidden="true"></i>
          <span>{{ local.email }}</span>
        </div>
      </div>

      <div class="customer-actions d-flex gap-2">
        <button type="button" class="btn customer-edit" @click="editing = true">
          <i class="bi bi-pencil-square" aria-hidden="true"></i>
          <span>Редагувати</span>
        </button>
        <button type="button" class="btn-action delete" @click="reset" title="Очистити дані покупця у формі" aria-label="Очистити дані покупця у формі">
          <i class="bi bi-trash3" aria-hidden="true"></i>
        </button>
      </div>
    </div>

    <button v-else-if="!hasData && !editing"
         type="button"
         class="empty-state-card rounded-4 p-4 text-center"
         @click="editing = true">
      <span class="empty-state-icon mb-3" aria-hidden="true"><i class="bi bi-person-plus"></i></span>
      <span class="d-block fw-semibold text-dark mb-1">Додати покупця</span>
      <span class="d-block text-muted small">Знайдіть за номером або створіть новий профіль</span>
    </button>

    <div v-else class="customer-card rounded-4 bg-white animate-fade-in overflow-visible">
      <div class="card-header-custom px-3 py-2 d-flex justify-content-between align-items-center gap-2">
        <span class="fw-semibold small">Дані покупця</span>
        <button type="button" class="btn-close-custom" @click="closeForm" aria-label="Згорнути дані покупця">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
      
      <div class="p-3">
        <div class="row g-3">
          <div class="col-12 position-relative">
            <label :for="`${formId}-phone`" class="form-label-custom">Мобільний телефон</label>
            <div class="input-group-custom">
              <i class="bi bi-telephone input-icon-left"></i>
              <input
                :id="`${formId}-phone`"
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
                <div class="d-flex flex-wrap gap-1 w-100 justify-content-between align-items-center">
                  <span class="fw-bold text-dark">
                    {{ customer.first_name }} {{ customer.last_name }}
                  </span>
                  <span class="badge bg-light text-dark border">{{ customer.phone }}</span>
                </div>
                <small class="text-muted" v-if="customer.email">{{ customer.email }}</small>
              </button>
            </div>

            <div v-if="local.id" class="duplicate-hint mt-2 d-flex align-items-start gap-2">
              <i class="bi bi-info-circle" aria-hidden="true"></i>
              <span>Редагування оновить дані наявного клієнта.</span>
            </div>
            
            <div class="text-danger small mt-2" v-if="searchError">{{ searchError }}</div>
            <div class="invalid-feedback d-block" v-if="errors.phone">{{ errors.phone }}</div>
            <div class="invalid-feedback d-block" v-else-if="phoneError">{{ phoneError }}</div>
          </div>

          <div class="col-12">
            <label :for="`${formId}-name`" class="form-label-custom">Імʼя та прізвище</label>
            <input
              :id="`${formId}-name`"
              type="text"
              autocomplete="name"
              class="form-control custom-input"
              :class="{ 'is-invalid': errors.first_name || errors.last_name || nameError }"
              v-model="fullName"
              placeholder="Іван Іванов"
              @input="handleNameInput"
            />
            <div class="invalid-feedback d-block" v-if="errors.first_name || errors.last_name">
              {{ errors.first_name || errors.last_name }}
            </div>
            <div class="invalid-feedback d-block" v-else-if="nameError">
              {{ nameError }}
            </div>
          </div>

          <div class="col-12">
            <label :for="`${formId}-email`" class="form-label-custom">Електронна пошта <span class="text-muted fw-normal">(необовʼязково)</span></label>
            <input :id="`${formId}-email`" type="email" autocomplete="email" class="form-control custom-input" :class="{ 'is-invalid': errors.email }" v-model="local.email" placeholder="example@mail.com" />
            <div class="invalid-feedback d-block" v-if="errors.email">{{ errors.email }}</div>
          </div>
        </div>
      </div>

      <div class="px-3 pb-3">
        <button type="button" class="btn btn-primary customer-done w-100" @click="editing = false">
          <i class="bi bi-check2 me-1" aria-hidden="true"></i>
          Готово
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch, useId } from 'vue';
import { searchCustomers } from '@/crm/api/customers';

const props = defineProps({
  errors: { type: Object, default: () => ({}) },
});

const formId = useId();

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

// Якщо прийшли помилки валідації — розгортаємо форму, щоб червоні поля було видно.
watch(() => props.errors, (errs) => {
  if (errs && Object.keys(errs).length) editing.value = true;
}, { deep: true });

const searchLoading = ref(false);
const searchError = ref('');
const suggestions = ref([]);
const showSuggestions = ref(false);
const fullName = ref(`${local.first_name || ''} ${local.last_name || ''}`.trim());
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

const nameError = computed(() => {
  const value = fullName.value.trim();
  if (!value) return '';
  const words = value.split(/\s+/).filter(Boolean);
  if (words.length < 2) {
    return 'Вкажіть імʼя та прізвище';
  }
  return '';
});
function reset() {
  Object.assign(local, {
    id: null, first_name: '', last_name: '', phone: '', email: ''
  });
  fullName.value = '';
  suggestions.value = [];
  searchError.value = '';
}

function handleNameInput() {
  const words = fullName.value.trim().split(/\s+/).filter(Boolean);
  local.first_name = words[0] || '';
  // Зберігаємо всі частини ПІБ, зокрема подвійне прізвище чи по батькові.
  local.last_name = words.slice(1).join(' ');
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
  fullName.value = `${local.first_name} ${local.last_name}`.trim();
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
    fullName.value = `${local.first_name || ''} ${local.last_name || ''}`.trim();
  }
}, { deep: true, immediate: true });

</script>

<style scoped>
/* Картка покупця */
.customer-section,
.customer-card {
  min-width: 0;
  max-width: 100%;
}
.customer-card {
  border: 1px solid #e5e9f0;
  box-shadow: 0 2px 6px rgb(15 23 42 / 3%);
}

/* Аватар */
.avatar-circle {
  width: 40px;
  height: 40px;
  background: #eef2ff;
  color: #6366f1;
  font-size: 1.35rem;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

/* Мета-дані */
.customer-info { min-width: 0; }
.customer-name {
  color: #1e293b;
  font-size: 0.9375rem;
  font-weight: 650;
  line-height: 1.5;
  overflow-wrap: anywhere;
}
.customer-meta {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px;
  margin-top: 16px;
  border-radius: 10px;
  background: #f8fafc;
}
.customer-contact {
  display: grid;
  grid-template-columns: 16px minmax(0, 1fr);
  gap: 8px;
  align-items: start;
  color: #64748b;
  font-size: 0.8125rem;
  line-height: 1.5;
}
.customer-contact span { overflow-wrap: anywhere; }
.customer-contact i { color: #94a3b8; }
.customer-phone { color: #334155; font-weight: 500; }

/* Кнопки дій */
.customer-actions { margin-top: 14px; }
.customer-edit {
  --bs-btn-color: #4f46e5;
  --bs-btn-bg: #eef2ff;
  --bs-btn-border-color: transparent;
  --bs-btn-hover-color: #4338ca;
  --bs-btn-hover-bg: #e0e7ff;
  --bs-btn-hover-border-color: transparent;
  --bs-btn-active-color: #3730a3;
  --bs-btn-active-bg: #c7d2fe;
  --bs-btn-active-border-color: transparent;
  --bs-btn-focus-shadow-rgb: 99, 102, 241;
  flex: 1;
  min-width: 0;
  min-height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  border-radius: 10px;
  font-size: 0.8125rem;
  font-weight: 600;
}
.btn-action {
  width: 40px; height: 40px; flex-shrink: 0;
  border-radius: 10px; border: none;
  display: flex; align-items: center; justify-content: center;
  transition: background-color 0.2s, color 0.2s;
  background: transparent; color: #94a3b8;
}
.btn-action.delete:hover { background: #fee2e2; color: #ef4444; }
.btn-action:focus-visible,
.btn-close-custom:focus-visible,
.empty-state-card:focus-visible {
  outline: 2px solid #6366f1;
  outline-offset: 2px;
}

/* Порожній стан */
.empty-state-card {
  width: 100%;
  border: 1px dashed #cbd5e1;
  background: #fafbfe;
  color: #64748b;
  transition: border-color 0.2s, background-color 0.2s;
}
.empty-state-card:hover {
  border-color: #a5b4fc; background: #f5f7ff;
}
.empty-state-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 14px;
  background: #eef2ff;
  color: #6366f1;
  font-size: 1.4rem;
}

/* Форми та інпути */
.card-header-custom {
  color: #334155;
  background: #f8fafc;
  border-bottom: 1px solid #eef1f5;
  border-radius: 15px 15px 0 0;
}
.form-label-custom {
  font-size: 0.8125rem; font-weight: 500;
  color: #475569; margin-bottom: 0.4rem;
}

.custom-input {
  border-radius: 10px; border: 1px solid #e2e8f0;
  padding: 0.6rem 0.75rem;
  min-height: 42px;
  font-size: 0.875rem; transition: border-color 0.2s, box-shadow 0.2s;
}
.custom-input:focus { border-color: #a5b4fc; box-shadow: 0 0 0 3px rgb(99 102 241 / 10%); }
.customer-done {
  --bs-btn-bg: #6366f1;
  --bs-btn-border-color: #6366f1;
  --bs-btn-hover-bg: #4f46e5;
  --bs-btn-hover-border-color: #4f46e5;
  --bs-btn-active-bg: #4338ca;
  --bs-btn-active-border-color: #4338ca;
  --bs-btn-focus-shadow-rgb: 99, 102, 241;
  min-height: 40px;
  border-radius: 10px;
  font-size: 0.875rem;
  font-weight: 600;
}

/* Інпут з іконкою */
.input-group-custom { position: relative; }
.input-group-custom .custom-input { padding-left: 2.6rem; }
.input-icon-left {
  position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
  color: #94a3b8; z-index: 5;
}
.input-loader { position: absolute; right: 12px; top: 30%; }

/* Закрити форму */
.btn-close-custom {
  display: inline-flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; flex-shrink: 0; border-radius: 8px;
  background: none; border: none; color: #94a3b8; transition: 0.2s;
}
.btn-close-custom:hover { color: #475569; background: #e9eef5; }

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
  white-space: normal;
  overflow-wrap: anywhere;
}
.customer-suggest-dropdown .dropdown-item:hover { background: #f8fafc; }
.customer-suggest-dropdown .dropdown-item:last-child { border-bottom: none; }

/* Hint */
.duplicate-hint { color: #64748b; font-size: 0.75rem; line-height: 1.5; }

@media (prefers-reduced-motion: reduce) {
  .animate-fade-in { animation: none; }
}
</style>
