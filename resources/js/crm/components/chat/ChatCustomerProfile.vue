<template>
  <div class="right-sidebar">
    <transition name="toast">
      <div v-if="toast.show" class="toast-notification" :class="toast.type">
        <div class="toast-content">
          <i class="bi" :class="toast.type === 'success' ? 'bi-check2-all' : 'bi-exclamation-octagon'"></i>
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
            <button class="btn-edit-soft-purple"><i class="bi bi-pencil-fill"></i></button>
          </div>

          <div v-else class="name-edit-flow">
            <div class="inputs-stack">
              <input v-model="form.first_name" @blur="capitalizeField('first_name')" class="modern-underline-input" placeholder="Ім'я">
              <input v-model="form.last_name" @blur="capitalizeField('last_name')" class="modern-underline-input" placeholder="Прізвище">
            </div>
            <button class="btn-confirm-tick" @click="showNameInput = false"><i class="bi bi-check-lg"></i></button>
          </div>
          <div class="id-badge-minimal">ID: {{ customer.fb_user_id || customer.instagram_user_id || customerId }}</div>
        </div>

        <div class="status-indicator-box" :class="isProfileComplete ? 'ready' : 'pending'">
           <i class="bi" :class="isProfileComplete ? 'bi-person-check-fill' : 'bi-person-x-fill'"></i>
        </div>
      </div>

      <hr class="elegant-divider" />

      <div class="fields-grid">
        <div class="field-item">
          <div class="field-icon"><i class="bi bi-telephone-fill"></i></div>
          <div class="field-body">
            <label>Контактний телефон</label>
            <div v-if="form.phone || showPhoneInput" class="input-wrapper" 
                 :class="{ 'focused': phoneFocused, 'invalid-pulse': form.phone && !isPhoneValid }">
              <input v-model="form.phone" class="clean-input" placeholder="380..." ref="phoneRef" type="tel" @focus="phoneFocused = true" @blur="phoneFocused = false">
              <button v-if="form.phone" class="btn-input-clear" @click="clearPhone"><i class="bi bi-x"></i></button>
            </div>
            <div v-else class="action-link" @click="enablePhone"><i class="bi bi-plus-circle"></i> Додати телефон</div>
            <transition name="fade">
              <span v-if="form.phone && !isPhoneValid" class="helper-error">Має бути 12 цифр (380...)</span>
            </transition>
          </div>
        </div>

        <div class="field-item">
          <div class="field-icon"><i class="bi bi-envelope-paper-fill"></i></div>
          <div class="field-body">
            <label>E-mail адреса</label>
            <div v-if="form.email || showEmailInput" class="input-wrapper" :class="{ 'focused': emailFocused }">
              <input v-model="form.email" class="clean-input" placeholder="example@mail.com" @focus="emailFocused = true" @blur="emailFocused = false">
              <button v-if="form.email" class="btn-input-clear" @click="clearEmail"><i class="bi bi-x"></i></button>
            </div>
            <div v-else class="action-link" @click="enableEmail"><i class="bi bi-plus-circle"></i> Додати email</div>
          </div>
        </div>

        <div class="action-footer">
          <button class="btn-save-hero" 
                  @click="saveData" 
                  :disabled="isLoading || !isProfileComplete || saveSuccess"
                  :class="{ 'btn-success': saveSuccess }">
            <template v-if="saveSuccess">
              <i class="bi bi-check-circle-fill"></i> Дані оновлено
            </template>
            <template v-else-if="isLoading">
              <div class="loading-ring"></div> Обробка...
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
const saveSuccess = ref(false);
const showPhoneInput = ref(false);
const showEmailInput = ref(false);
const phoneRef = ref(null);

const toast = reactive({ show: false, message: '', type: 'success' });
const form = reactive({ first_name: '', last_name: '', phone: '', email: '' });

const cyrillicRegex = /^[А-Яа-яЁёЇїІіЄєҐґ' \-]+$/;

// Автоматичне виправлення регістру (Перша велика)
const capitalizeField = (field) => {
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
  return name || props.customer?.customer_name || 'Дані відсутні';
});
const displayInitial = computed(() => (displayName.value ? displayName.value[0].toUpperCase() : '?'));
const avatarUrl = computed(() => props.customer?.fb_profile_pic || props.customer?.customer_avatar || '');
const isInstagram = computed(() => (props.customer?.source || props.customer?.platform) === 'instagram');

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

const triggerToast = (msg, type = 'success') => {
  toast.message = msg; toast.type = type; toast.show = true;
  setTimeout(() => { toast.show = false; }, 3500);
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
    // ВАЖЛИВО: Програміст має реалізувати цей ендпоінт на бекенді
    await axios.put(`/api/customers/${customerId.value}`, form);
    if (props.customer) Object.assign(props.customer, form);
    
    showNameInput.value = false;
    saveSuccess.value = true;
    triggerToast('Профіль успішно оновлено');
    setTimeout(() => { saveSuccess.value = false; }, 2500);
  } catch (e) { 
    console.error(e); 
    triggerToast('Помилка сервера (404/500)', 'error');
  } finally { 
    isLoading.value = false; 
  }
};
</script>

<style scoped>
/* GENERAL LAYOUT */
.right-sidebar { width: 100%; height: 100%; background: #ffffff; border-left: 1px solid #eef2f6; display: flex; flex-direction: column; position: relative; font-family: 'Inter', sans-serif; }
.profile-content { padding: 24px; }

/* MODERN TOAST */
.toast-notification {
  position: absolute; top: 20px; left: 20px; right: 20px; z-index: 1000;
  backdrop-filter: blur(12px); padding: 14px 24px; border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  color: white; box-shadow: 0 12px 30px rgba(0,0,0,0.12);
}
.toast-notification.success { background: rgba(16, 185, 129, 0.95); border: 1px solid rgba(255,255,255,0.25); }
.toast-notification.error { background: rgba(239, 68, 68, 0.95); }
.toast-content { display: flex; align-items: center; gap: 12px; font-weight: 600; font-size: 14px; }

/* HEADER */
.header-section { display: flex; align-items: flex-start; gap: 16px; position: relative; }
.avatar-wrap { position: relative; width: 64px; height: 64px; flex-shrink: 0; }
.avatar-img, .avatar-placeholder { width: 100%; height: 100%; border-radius: 18px; object-fit: cover; box-shadow: 0 4px 15px rgba(0,0,0,0.06); }
.avatar-placeholder { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 24px; }

.platform-icon-indicator {
  position: absolute; bottom: -6px; right: -6px; width: 24px; height: 24px;
  border-radius: 50%; display: flex; align-items: center; justify-content: center;
  border: 3px solid #fff; color: white; font-size: 12px;
}
.ig-bg { background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fd5949 45%, #d6249f 60%, #285AEB 90%); }
.fb-bg { background: #0084FF; }

/* STATUS INDICATOR RIGHT */
.status-indicator-box { margin-left: auto; font-size: 28px; transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); }
.pending { color: #fecaca; filter: drop-shadow(0 0 5px rgba(239, 68, 68, 0.2)); }
.ready { color: #10b981; transform: scale(1.1); filter: drop-shadow(0 0 8px rgba(16, 185, 129, 0.3)); }

/* NAME EDITING */
.name-display-wrapper { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; transition: 0.2s; }
.name-display-wrapper:hover .name-text { color: #4f46e5; }
.name-text { font-size: 18px; font-weight: 800; color: #1e293b; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.btn-edit-soft-purple { background: #f5f3ff; color: #8b5cf6; border: none; width: 32px; height: 32px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 14px; cursor: pointer; transition: 0.2s; }
.btn-edit-soft-purple:hover { background: #ede9fe; transform: translateY(-2px); }

.inputs-stack { display: flex; flex-direction: column; gap: 6px; flex: 1; }
.modern-underline-input { border: none; border-bottom: 2px solid #f1f5f9; font-size: 14px; font-weight: 700; padding: 4px 0; outline: none; transition: 0.3s; background: transparent; }
.modern-underline-input:focus { border-color: #6366f1; }

/* GRID & FIELDS */
.elegant-divider { border: 0; border-top: 1px solid #f8fafc; margin: 24px 0; }
.fields-grid { display: flex; flex-direction: column; gap: 20px; }
.field-item { display: flex; align-items: flex-start; gap: 14px; }
.field-icon { width: 36px; height: 36px; background: #f8fafc; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 16px; margin-top: 4px; }
.field-body { flex: 1; display: flex; flex-direction: column; }
.field-body label { font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; }

.input-wrapper { display: flex; align-items: center; border-bottom: 2px solid #f1f5f9; padding: 4px 0; transition: 0.3s; }
.input-wrapper.focused { border-color: #6366f1; }
.invalid-pulse { animation: softPulseRed 2s infinite; border-color: #fca5a5; }

@keyframes softPulseRed { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }

.clean-input { flex: 1; border: none; background: transparent; font-size: 15px; color: #334155; outline: none; font-weight: 600; }
.action-link { color: #6366f1; font-size: 14px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; padding: 6px 0; transition: 0.2s; }
.action-link:hover { color: #4f46e5; }
.helper-error { font-size: 11px; color: #ef4444; font-weight: 600; margin-top: 6px; }

/* FOOTER BUTTON */
.action-footer { margin-top: 12px; }
.btn-save-hero {
  width: 100%; background: #1e293b; color: white; border: none; border-radius: 14px;
  padding: 14px; font-size: 15px; font-weight: 700; cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  display: flex; align-items: center; justify-content: center; gap: 10px;
}
.btn-save-hero:hover:not(:disabled) { background: #0f172a; transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
.btn-save-hero:disabled { background: #f1f5f9; color: #cbd5e1; cursor: not-allowed; }
.btn-success { background: #10b981 !important; box-shadow: 0 8px 25px rgba(16, 185, 129, 0.25) !important; }

/* ANIMATIONS */
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.loading-ring { width: 16px; height: 16px; border: 3px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>