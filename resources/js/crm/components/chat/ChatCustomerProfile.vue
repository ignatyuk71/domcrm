<template>
  <div class="right-sidebar">
    <transition name="toast">
      <div v-if="toast.show" class="toast-notification" :class="toast.type">
        <i class="bi" :class="toast.type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'"></i>
        <span>{{ toast.message }}</span>
      </div>
    </transition>

    <div v-if="customerId" class="profile-content">
      <div class="profile-mobile-header">
        <button class="profile-back-btn" type="button" @click="emit('close')">
          <i class="bi bi-arrow-left"></i>
          Назад
        </button>
      </div>
      
      <div class="header-section">
        <div class="avatar-wrap">
          <img v-if="avatarUrl" :src="avatarUrl" class="avatar-img">
          <div v-else class="avatar-placeholder">{{ displayInitial }}</div>
          
          <div 
            class="platform-icon-indicator" 
            :class="[isInstagram ? 'ig-bg' : 'fb-bg', isProfileComplete ? 'glow-green' : 'glow-red']"
          >
            <i class="bi" :class="isInstagram ? 'bi-instagram' : 'bi-messenger'"></i>
          </div>
        </div>
        
        <div class="info">
          <div v-if="!showNameInput" class="name-display-wrapper" @click="enableNameEdit">
            <span class="name-text" :class="{ 'text-error': !isNameValid }">{{ displayName }}</span>
            <button class="btn-edit-purple" type="button">
              <i class="bi bi-pencil-square"></i>
            </button>
          </div>

          <div v-else class="name-edit-flow">
            <div class="inputs-stack">
              <input v-model="form.first_name" class="modern-input" placeholder="Ім'я (кирилиця)">
              <input v-model="form.last_name" class="modern-input" placeholder="Прізвище (кирилиця)">
            </div>
            <button class="btn-confirm-tick" type="button" @click="showNameInput = false">
              <i class="bi bi-check2"></i>
            </button>
          </div>
          
          <div class="id-badge">ID: {{ customer.fb_user_id || customer.instagram_user_id || customerId }}</div>
        </div>

        <button
          type="button"
          class="btn-status-indicator" 
          :class="isProfileComplete ? 'status-ready' : 'status-attention'"
          title="Статус заповнення"
        >
          <i class="bi" :class="isProfileComplete ? 'bi-person-check-fill' : 'bi-person-x-fill'"></i>
        </button>
      </div>

      <hr class="divider" />

      <div class="fields-section">
        <div class="field-row">
          <div class="icon-col"><i class="bi bi-telephone"></i></div>
          <div class="input-col">
            <label>Телефон</label>
            <div v-if="form.phone || showPhoneInput" class="input-group" :class="{ 'is-focused': phoneFocused, 'is-invalid': form.phone && !isPhoneValid }">
              <input 
                v-model="form.phone" 
                class="simple-input" 
                placeholder="380XXXXXXXXX" 
                ref="phoneRef"
                type="tel"
                @focus="phoneFocused = true"
                @blur="phoneFocused = false"
              >
              <button class="btn-clear" type="button" @click="clearPhone"><i class="bi bi-x-circle-fill"></i></button>
            </div>
            <div v-else class="add-btn" @click="enablePhone"><i class="bi bi-plus-circle"></i> Додати телефон</div>
            <small v-if="form.phone && !isPhoneValid" class="error-text">Має бути 12 цифр (380...)</small>
          </div>
        </div>

        <div class="field-row">
          <div class="icon-col"><i class="bi bi-envelope"></i></div>
          <div class="input-col">
            <label>E-mail</label>
            <div v-if="form.email || showEmailInput" class="input-group" :class="{ 'is-focused': emailFocused }">
              <input v-model="form.email" class="simple-input" placeholder="email@example.com" @focus="emailFocused = true" @blur="emailFocused = false">
              <button class="btn-clear" type="button" @click="clearEmail"><i class="bi bi-x-circle-fill"></i></button>
            </div>
            <div v-else class="add-btn" @click="enableEmail"><i class="bi bi-plus-circle"></i> Додати email</div>
          </div>
        </div>

        <div class="action-row">
          <button class="btn-save-modern" @click="saveData" :disabled="isLoading || !isProfileComplete">
            <span v-if="isLoading" class="spinner"></span>
            {{ isLoading ? 'Зберігаємо...' : 'Зберегти покупця' }}
          </button>
          
          <button class="btn-create-order" type="button" @click="showOrderPanel = true">
            <i class="bi bi-bag-plus"></i>
            Створити замовлення
          </button>
        </div>
      </div>

    </div>

    <div v-else class="empty-state">
      <i class="bi bi-person-bounding-box"></i>
      <p>Виберіть чат</p>
    </div>

    <ChatOrderPanel
      v-show="showOrderPanel"
      :open="showOrderPanel"
      :customer="customer"
      :order-draft="orderDraft"
      @close="handleOrderClose"
      @minimize="handleOrderMinimize"
      @saved="handleOrderSaved"
    />

  </div>
</template>

<script setup>
import { ref, reactive, watch, nextTick, computed } from 'vue';
import axios from 'axios';
import ChatOrderPanel from '@/crm/components/chat/ChatOrderPanel.vue';

const props = defineProps({ customer: Object });
const emit = defineEmits(['close']);

const showNameInput = ref(false);
const phoneFocused = ref(false);
const emailFocused = ref(false);
const isLoading = ref(false);
const showPhoneInput = ref(false);
const showEmailInput = ref(false);
const phoneRef = ref(null);

// Стан для панелі замовлення
const showOrderPanel = ref(false);
const orderDraft = reactive({
  items: [],
  delivery: {
    first_name: '',
    last_name: '',
    middle_name: '',
    phone: '',
    delivery_type: 'warehouse',
    city: '',
    warehouse: '',
    street: '',
    building: '',
    apartment: '',
    payer: 'recipient',
  },
});

function resetOrderDraft() {
  orderDraft.items = [];
  orderDraft.delivery = {
    first_name: '',
    last_name: '',
    middle_name: '',
    phone: '',
    delivery_type: 'warehouse',
    city: '',
    warehouse: '',
    street: '',
    building: '',
    apartment: '',
    payer: 'recipient',
  };
}

// Стан для сповіщень
const toast = reactive({
  show: false,
  message: '',
  type: 'success'
});

const form = reactive({ 
  first_name: '',
  last_name: '',
  phone: '', 
  email: '' 
});

const cyrillicRegex = /^[А-Яа-яЁёЇїІіЄєҐґ' \-]+$/;

const isNameValid = computed(() => {
  return form.first_name.trim().length >= 2 && 
         form.last_name.trim().length >= 2 && 
         cyrillicRegex.test(form.first_name) && 
         cyrillicRegex.test(form.last_name);
});

const isPhoneValid = computed(() => /^380\d{9}$/.test(form.phone));
const isProfileComplete = computed(() => isNameValid.value && isPhoneValid.value);

watch(() => form.phone, (newVal) => {
  if (!newVal) return;
  let cleaned = newVal.replace(/\D/g, '');
  if (cleaned.length > 0 && !cleaned.startsWith('38')) cleaned = '380' + cleaned;
  form.phone = cleaned.substring(0, 12);
});

const customerId = computed(() => props.customer?.id ?? props.customer?.customer_id ?? null);
const displayName = computed(() => {
  const name = `${form.first_name} ${form.last_name}`.trim();
  return name || props.customer?.customer_name || 'Не заповнено';
});
const displayInitial = computed(() => (displayName.value ? displayName.value[0].toUpperCase() : '?'));
const avatarUrl = computed(() => props.customer?.fb_profile_pic || props.customer?.customer_avatar || '');
const isInstagram = computed(() => (props.customer?.source || props.customer?.platform) === 'instagram' || !!props.customer?.instagram_user_id);

watch(() => props.customer, (newVal) => {
  if (newVal) {
    form.first_name = newVal.first_name || '';
    form.last_name = newVal.last_name || '';
    form.phone = newVal.phone ? newVal.phone.replace(/\D/g, '') : '';
    form.email = newVal.email || '';
    showPhoneInput.value = !!form.phone;
    showEmailInput.value = !!form.email;
    showNameInput.value = false;
    showOrderPanel.value = false;
    resetOrderDraft();
  }
}, { immediate: true });

const showToast = (msg, type = 'success') => {
  toast.message = msg;
  toast.type = type;
  toast.show = true;
  setTimeout(() => { toast.show = false; }, 3000);
};

const enableNameEdit = () => { showNameInput.value = true; };
const enablePhone = async () => { showPhoneInput.value = true; if (!form.phone) form.phone = '380'; await nextTick(); phoneRef.value?.focus(); };
const clearPhone = () => { form.phone = ''; showPhoneInput.value = false; };
const enableEmail = async () => { showEmailInput.value = true; await nextTick(); };
const clearEmail = () => { form.email = ''; showEmailInput.value = false; };

const saveData = async () => {
  if (!customerId.value || !isProfileComplete.value) return;
  isLoading.value = true;
  try {
    const response = await axios.put(`/api/customers/${customerId.value}`, form);
    const updatedCustomer = response?.data?.data;
    if (props.customer && updatedCustomer) {
      Object.assign(props.customer, updatedCustomer);
    } else if (props.customer) {
      Object.assign(props.customer, form);
    }

    showNameInput.value = false;
    showToast('Дані покупця успішно збережено!');
  } catch (e) { 
    console.error(e); 
    showToast('Помилка сервера. Дані не збережено.', 'error');
  } finally { 
    isLoading.value = false; 
  }
};

const handleOrderSaved = () => {
  showOrderPanel.value = false;
  showToast('Замовлення створено!');
};

const handleOrderMinimize = () => {
  showOrderPanel.value = false;
};

const handleOrderClose = () => {
  if (orderDraft.items.length > 0) {
    const confirmed = window.confirm('Видалити чернетку замовлення?');
    if (!confirmed) return;
  }
  resetOrderDraft();
  showOrderPanel.value = false;
};
</script>

<style scoped>
.right-sidebar { width: 100%; height: 100%; background: #ffffff; border-left: 1px solid #edf2f7; display: flex; flex-direction: column; position: relative; overflow: hidden; }
.profile-content { padding: 16px; }

/* TOAST STYLES */
.toast-notification {
  position: absolute; top: 10px; left: 10%; right: 10%; z-index: 100;
  padding: 10px 15px; border-radius: 10px; display: flex; align-items: center; gap: 10px;
  font-size: 13px; font-weight: 600; color: white; box-shadow: 0 5px 15px rgba(0,0,0,0.15);
}
.toast-notification.success { background: #10b981; }
.toast-notification.error { background: #ef4444; }
.toast-enter-active, .toast-leave-active { transition: all 0.4s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(-20px); }

/* HEADER */
.header-section { display: flex; align-items: flex-start; gap: 12px; }
.avatar-wrap { position: relative; width: 52px; height: 52px; flex-shrink: 0; }
.avatar-img, .avatar-placeholder { width: 100%; height: 100%; border-radius: 12px; object-fit: cover; }
.avatar-placeholder { background: #edf2f7; color: #a0aec0; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 20px; }

.platform-icon-indicator {
  position: absolute; bottom: -4px; right: -4px; width: 22px; height: 22px;
  border-radius: 50%; display: flex; align-items: center; justify-content: center;
  border: 2px solid #fff; transition: all 0.4s ease; color: white;
}
.ig-bg { background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fd5949 45%, #d6249f 60%, #285AEB 90%); }
.fb-bg { background: #0084FF; }
.glow-red { box-shadow: 0 0 10px #ef4444; border-color: #ef4444; }
.glow-green { box-shadow: 0 0 10px #10b981; border-color: #10b981; }
.platform-icon-indicator i { font-size: 11px; }

.info { flex: 1; min-width: 0; padding-top: 2px; }
.name-display-wrapper { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
.name-text { font-size: 15px; font-weight: 700; color: #1a202c; }
.text-error { color: #ef4444; }

.btn-status-indicator {
  background: none; border: none; cursor: pointer; transition: all 0.4s ease;
  font-size: 24px; padding: 0; margin-left: auto; display: flex; align-items: center;
}
.status-attention { color: #ef4444; filter: drop-shadow(0 0 5px rgba(239, 68, 68, 0.4)); }
.status-ready { color: #10b981; filter: drop-shadow(0 0 5px rgba(16, 185, 129, 0.4)); }

.btn-edit-purple {
  background: #a78bfa; color: white; border: none;
  width: 30px; height: 30px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; cursor: pointer; transition: all 0.3s ease; margin-left: 10px;
}
.btn-edit-purple:hover { background: #8b5cf6; transform: scale(1.1); }

.name-edit-flow { display: flex; align-items: center; gap: 8px; }
.inputs-stack { display: flex; flex-direction: column; gap: 4px; flex: 1; }
.modern-input { border: none; border-bottom: 1.5px solid #e2e8f0; font-size: 13px; font-weight: 600; outline: none; transition: 0.3s; padding: 2px 0; }
.modern-input:focus { border-color: #6366f1; }
.btn-confirm-tick { background: #6366f1; color: white; border: none; width: 26px; height: 26px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; }

.id-badge { font-size: 11px; color: #a0aec0; margin-top: 4px; display: inline-block; background: #f7fafc; padding: 2px 6px; border-radius: 4px; }
.divider { border: 0; border-top: 1px solid #f3f4f6; margin: 16px 0; }

.fields-section { display: flex; flex-direction: column; gap: 12px; }
.field-row { display: flex; align-items: flex-start; }
.icon-col { width: 32px; color: #cbd5e0; font-size: 18px; padding-top: 18px; }
.input-col label { font-size: 10px; font-weight: 700; color: #a0aec0; text-transform: uppercase; margin-bottom: 2px; display: block; }
.input-group { display: flex; align-items: center; border-bottom: 2px solid #edf2f7; padding: 2px 0; }
.simple-input { flex: 1; border: none; background: transparent; font-size: 14px; color: #2d3748; outline: none; font-weight: 600; }
.error-text { color: #ef4444; font-size: 10px; margin-top: 2px; }
.add-btn { color: #6366f1; font-size: 13px; font-weight: 600; cursor: pointer; padding: 4px 0; }

.action-row { display: flex; flex-direction: column; gap: 10px; margin-top: 8px; }

.profile-mobile-header {
  display: none;
  margin-bottom: 12px;
}

.profile-back-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  color: #334155;
  border-radius: 10px;
  height: 40px;
  padding: 0 12px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}

/* КНОПКА ЗБЕРЕЖЕННЯ */
.btn-save-modern {
  background: #A78BFB; 
  color: white;
  border: none;
  border-radius: 8px;
  height: 40px; 
  display: flex; 
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 600;
  width: 100%;
  cursor: pointer;
  transition: all 0.3s ease;
}
.btn-save-modern:hover:not(:disabled) {
  background: #9061f9; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(167, 139, 251, 0.3);
}
.btn-save-modern:disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; box-shadow: none; }

/* НОВА КНОПКА: СТВОРИТИ ЗАМОВЛЕННЯ */
.btn-create-order {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: #1f2937;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  width: 100%;
  transition: 0.2s;
}
.btn-create-order:hover {
  background: #f8fafc;
  border-color: #cbd5e0;
}

@media (max-width: 768px) {
  .profile-content {
    padding: 14px 16px 24px;
  }

  .profile-mobile-header {
    display: flex;
  }

  .btn-save-modern,
  .btn-create-order {
    height: 48px;
  }
}

.spinner { width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #cbd5e0; gap: 8px; }
</style>
