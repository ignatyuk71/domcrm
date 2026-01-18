<template>
  <div class="right-sidebar">
    <transition name="toast">
      <div v-if="toast.show" class="toast-notification" :class="toast.type">
        <div class="toast-content">
          <i class="bi" :class="toast.type === 'success' ? 'bi-check2-circle' : 'bi-shield-exclamation'"></i>
          <span>{{ toast.message }}</span>
        </div>
      </div>
    </transition>

    <div v-if="customerId" class="profile-content">
      <div class="header-section">
        <div class="avatar-wrap">
          <img v-if="avatarUrl" :src="avatarUrl" class="avatar-img">
          <div v-else class="avatar-placeholder">{{ displayInitial }}</div>
          
          <div class="platform-icon-indicator" :class="[isInstagram ? 'ig-bg' : 'fb-bg']">
            <i class="bi" :class="isInstagram ? 'bi-instagram' : 'bi-messenger'"></i>
          </div>
        </div>
        
        <div class="info">
          <div v-if="!showNameInput" class="name-display-wrapper" @click="enableNameEdit">
            <span class="name-text" :class="{ 'text-error': !isNameValid }">{{ displayName }}</span>
            <button class="btn-edit-purple"><i class="bi bi-pencil-fill"></i></button>
          </div>

          <div v-else class="name-edit-flow">
            <div class="inputs-stack">
              <input v-model="form.first_name" @blur="formatName('first_name')" class="modern-input" placeholder="Ім'я">
              <input v-model="form.last_name" @blur="formatName('last_name')" class="modern-input" placeholder="Прізвище">
            </div>
            <button class="btn-confirm-tick" @click="showNameInput = false"><i class="bi bi-check2"></i></button>
          </div>
          <div class="id-badge">ID: {{ customer.fb_user_id || customer.instagram_user_id || customerId }}</div>
        </div>

        <div class="status-icon-wrap" :class="isProfileComplete ? 'is-complete' : 'is-pending'">
           <i class="bi" :class="isProfileComplete ? 'bi-person-check-fill' : 'bi-person-x-fill'"></i>
        </div>
      </div>

      <hr class="divider" />

      <div class="fields-section">
        <div class="field-row">
          <div class="icon-col"><i class="bi bi-telephone"></i></div>
          <div class="input-col">
            <label>Контактний телефон</label>
            <div v-if="form.phone || showPhoneInput" class="input-group" 
                 :class="{ 'is-focused': phoneFocused, 'is-invalid': form.phone && !isPhoneValid, 'pulse-error': form.phone && !isPhoneValid }">
              <input v-model="form.phone" class="simple-input" placeholder="380..." ref="phoneRef" type="tel" @focus="phoneFocused = true" @blur="phoneFocused = false">
              <button class="btn-clear" @click="clearPhone"><i class="bi bi-x"></i></button>
            </div>
            <div v-else class="add-btn" @click="enablePhone"><i class="bi bi-plus-lg"></i> Додати телефон</div>
          </div>
        </div>

        <div class="field-row">
          <div class="icon-col"><i class="bi bi-envelope-at"></i></div>
          <div class="input-col">
            <label>E-mail адреса</label>
            <div v-if="form.email || showEmailInput" class="input-group" :class="{ 'is-focused': emailFocused }">
              <input v-model="form.email" class="simple-input" placeholder="email@example.com" @focus="emailFocused = true" @blur="emailFocused = false">
              <button class="btn-clear" @click="clearEmail"><i class="bi bi-x"></i></button>
            </div>
            <div v-else class="add-btn" @click="enableEmail"><i class="bi bi-plus-lg"></i> Додати email</div>
          </div>
        </div>

        <div class="action-row">
          <button class="btn-save-premium" 
                  @click="saveData" 
                  :disabled="isLoading || !isProfileComplete || isSavedSuccess"
                  :class="{ 'btn-success-active': isSavedSuccess }">
            <template v-if="isSavedSuccess">
              <i class="bi bi-check-lg"></i> Збережено!
            </template>
            <template v-else-if="isLoading">
              <span class="spinner"></span> Оновлюємо...
            </template>
            <template v-else>
              Зберегти покупця
            </template>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, watch, nextTick, computed } from 'vue';
import axios from 'axios';

const props = defineProps({ customer: Object });

const showNameInput = ref(false);
const phoneFocused = ref(false);
const emailFocused = ref(false);
const isLoading = ref(false);
const isSavedSuccess = ref(false);
const showPhoneInput = ref(false);
const showEmailInput = ref(false);
const phoneRef = ref(null);

const toast = reactive({ show: false, message: '', type: 'success' });
const form = reactive({ first_name: '', last_name: '', phone: '', email: '' });

const cyrillicRegex = /^[А-Яа-яЁёЇїІіЄєҐґ' \-]+$/;

// Авто-форматування: робить першу літеру великою
const formatName = (field) => {
  if (form[field]) {
    form[field] = form[field].trim().toLowerCase().replace(/^\w|^\u0430-\u044f/, l => l.toUpperCase());
  }
};

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
  return name || props.customer?.customer_name || 'Дані не вказано';
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
  }
}, { immediate: true });

const showToast = (msg, type = 'success') => {
  toast.message = msg; toast.type = type; toast.show = true;
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
    // ВАЖЛИВО: запит на твій сервер
    await axios.put(`/api/customers/${customerId.value}`, form);
    if (props.customer) Object.assign(props.customer, form);
    
    showNameInput.value = false;
    isSavedSuccess.value = true; // Активуємо стан успіху на кнопці
    showToast('Профіль оновлено успішно');
    
    setTimeout(() => { isSavedSuccess.value = false; }, 2000);
  } catch (e) { 
    console.error(e); 
    showToast('Помилка запиту (404/500)', 'error');
  } finally { 
    isLoading.value = false; 
  }
};
</script>

<style scoped>
.right-sidebar { width: 100%; height: 100%; background: #ffffff; border-left: 1px solid #edf2f7; display: flex; flex-direction: column; position: relative; }
.profile-content { padding: 20px; }

/* TOAST (GLASSMORPHISM) */
.toast-notification {
  position: absolute; top: 15px; left: 15px; right: 15px; z-index: 1000;
  backdrop-filter: blur(8px); padding: 12px 20px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  color: white; box-shadow: 0 10px 25px rgba(0,0,0,0.1);
}
.toast-notification.success { background: rgba(16, 185, 129, 0.9); border: 1px solid rgba(255,255,255,0.2); }
.toast-notification.error { background: rgba(239, 68, 68, 0.9); }
.toast-content { display: flex; align-items: center; gap: 10px; font-weight: 600; }

.header-section { display: flex; align-items: flex-start; gap: 14px; }
.avatar-wrap { position: relative; width: 56px; height: 56px; flex-shrink: 0; }
.avatar-img, .avatar-placeholder { width: 100%; height: 100%; border-radius: 16px; object-fit: cover; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.avatar-placeholder { background: linear-gradient(135deg, #6366f1, #a855f7); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 22px; }

.platform-icon-indicator {
  position: absolute; bottom: -4px; right: -4px; width: 22px; height: 22px;
  border-radius: 50%; display: flex; align-items: center; justify-content: center;
  border: 2.5px solid #fff; color: white; font-size: 11px;
}
.ig-bg { background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fd5949 45%, #d6249f 60%, #285AEB 90%); }
.fb-bg { background: #0084FF; }

/* АНІМОВАНИЙ СТАТУС */
.status-icon-wrap { margin-left: auto; font-size: 24px; transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
.is-pending { color: #f87171; transform: rotate(-10deg); }
.is-complete { color: #10b981; transform: scale(1.1); }

/* КНОПКА РЕДАГУВАННЯ */
.btn-edit-purple {
  background: #f5f3ff; color: #7c3aed; border: none;
  width: 28px; height: 28px; border-radius: 8px; margin-left: 8px;
  cursor: pointer; transition: 0.2s;
}
.btn-edit-purple:hover { background: #ede9fe; transform: translateY(-1px); }

/* ПОЛЯ */
.modern-input { border: none; border-bottom: 2px solid #f1f5f9; font-size: 14px; font-weight: 600; padding: 4px 0; outline: none; transition: 0.3s; }
.modern-input:focus { border-color: #6366f1; }

.divider { border: 0; border-top: 1px solid #f1f5f9; margin: 20px 0; }
.input-col label { font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
.input-group { display: flex; align-items: center; border-bottom: 2px solid #f1f5f9; padding: 4px 0; }
.input-group.is-focused { border-color: #6366f1; }
.pulse-error { animation: pulseRed 1.5s infinite; border-color: #f87171; }

@keyframes pulseRed { 0% { opacity: 1; } 50% { opacity: 0.6; } 100% { opacity: 1; } }

/* PREMIUM SAVE BUTTON */
.btn-save-premium {
  width: 100%; background: #1e293b; color: white; border: none; border-radius: 12px;
  padding: 12px; font-size: 14px; font-weight: 700; cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-save-premium:hover:not(:disabled) { background: #0f172a; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.15); }
.btn-save-premium:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }
.btn-success-active { background: #10b981 !important; color: white !important; }

.spinner { width: 14px; height: 14px; border: 2.5px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>