<template>
  <div class="right-sidebar">
    <div v-if="customerId" class="profile-content">
      
      <div class="header-section">
        <div class="avatar-wrap">
          <img v-if="avatarUrl" :src="avatarUrl" class="avatar-img">
          <div v-else class="avatar-placeholder">{{ displayInitial }}</div>
          <div class="platform-icon" :class="isInstagram ? 'ig-bg' : 'fb-bg'">
            <i class="bi" :class="isInstagram ? 'bi-instagram' : 'bi-messenger'"></i>
          </div>
        </div>
        
        <div class="info">
          <div v-if="!showNameInput" class="name-display" @click="enableNameEdit" title="Натисніть, щоб змінити ПІП">
            {{ displayName }} <i class="bi bi-pencil edit-icon-mini"></i>
          </div>
          <div v-else class="name-edit-group">
            <input v-model="form.first_name" class="name-input" placeholder="Ім'я">
            <input v-model="form.last_name" class="name-input" placeholder="Прізвище">
            <button class="btn-done" @click="showNameInput = false" title="Готово">
              <i class="bi bi-check-lg"></i>
            </button>
          </div>
          <div class="id-text">ID: {{ customer.fb_user_id || customer.instagram_user_id || customerId }}</div>
        </div>

        <button class="btn-unlink" title="Від'єднати">
          <i class="bi bi-person-x"></i>
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
              <button class="btn-clear" @click="clearPhone"><i class="bi bi-x-circle-fill"></i></button>
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
              <input 
                v-model="form.email" 
                class="simple-input" 
                placeholder="email@example.com"
                ref="emailRef"
                @focus="emailFocused = true"
                @blur="emailFocused = false"
              >
              <button class="btn-clear" @click="clearEmail"><i class="bi bi-x-circle-fill"></i></button>
            </div>
            <div v-else class="add-btn" @click="enableEmail"><i class="bi bi-plus-circle"></i> Додати email</div>
          </div>
        </div>

        <div class="action-row">
          <button class="btn-save-small" @click="saveData" :disabled="isLoading || !isFormValid">
            <span v-if="isLoading" class="spinner"></span>
            {{ isLoading ? 'Збереження...' : 'Зберегти покупця' }}
          </button>
        </div>

      </div>

    </div>

    <div v-else class="empty-state">
      <i class="bi bi-chat-dots"></i>
      <p>Виберіть чат</p>
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
const showPhoneInput = ref(false);
const showEmailInput = ref(false);
const phoneRef = ref(null);
const emailRef = ref(null);

const form = reactive({ 
  first_name: '',
  last_name: '',
  phone: '', 
  email: '' 
});

// Форматування телефону (суто цифри, примусовий старт 380)
watch(() => form.phone, (newVal) => {
  if (!newVal) return;
  let cleaned = newVal.replace(/\D/g, ''); // Видаляємо все крім цифр
  if (cleaned.length > 0 && !cleaned.startsWith('38')) {
    cleaned = '380' + cleaned;
  }
  form.phone = cleaned.substring(0, 12);
});

const isPhoneValid = computed(() => !form.phone || /^380\d{9}$/.test(form.phone));
const isFormValid = computed(() => isPhoneValid.value);

const customerId = computed(() => props.customer?.id ?? props.customer?.customer_id ?? null);

const displayName = computed(() => {
  const combined = `${form.first_name} ${form.last_name}`.trim();
  return combined || props.customer?.customer_name || 'Невідомий клієнт';
});

const displayInitial = computed(() => (displayName.value ? displayName.value[0] : ''));
const avatarUrl = computed(() => props.customer?.fb_profile_pic || props.customer?.customer_avatar || '');
const platform = computed(() => props.customer?.source || props.customer?.platform || '');
const isInstagram = computed(() => platform.value === 'instagram' || !!props.customer?.instagram_user_id);

// Завантаження даних при зміні клієнта
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

const enableNameEdit = () => { showNameInput.value = true; };

const enablePhone = async () => { 
  showPhoneInput.value = true; 
  if (!form.phone) form.phone = '380'; 
  await nextTick(); 
  phoneRef.value?.focus(); 
};
const clearPhone = () => { form.phone = ''; showPhoneInput.value = false; };

const enableEmail = async () => { 
  showEmailInput.value = true; 
  await nextTick(); 
  emailRef.value?.focus(); 
};
const clearEmail = () => { form.email = ''; showEmailInput.value = false; };

const saveData = async () => {
  if (!customerId.value || !isFormValid.value) return;
  isLoading.value = true;
  try {
    // Відправляємо ПІП, телефон та email (телефон суто цифри)
    await axios.put(`/api/customers/${customerId.value}`, { 
      first_name: form.first_name,
      last_name: form.last_name,
      phone: form.phone, 
      email: form.email 
    });
    
    // Оновлюємо оригінальний об'єкт
    if (props.customer) { 
      props.customer.first_name = form.first_name;
      props.customer.last_name = form.last_name;
      props.customer.phone = form.phone; 
      props.customer.email = form.email; 
    }
    showNameInput.value = false;
  } catch (e) { 
    console.error(e); 
    alert('Помилка збереження'); 
  } finally { 
    isLoading.value = false; 
  }
};
</script>

<style scoped>
.right-sidebar { width: 100%; height: 100%; background: #fff; border-left: 1px solid #e5e7eb; display: flex; flex-direction: column; font-family: sans-serif; }
.profile-content { padding: 16px; display: flex; flex-direction: column; }
.divider { border: 0; border-top: 1px solid #f1f5f9; margin: 12px 0; }

/* HEADER & NAME EDIT */
.header-section { display: flex; align-items: center; gap: 12px; }
.avatar-wrap { position: relative; width: 48px; height: 48px; flex-shrink: 0; }
.avatar-img, .avatar-placeholder { width: 100%; height: 100%; border-radius: 10px; object-fit: cover; }
.avatar-placeholder { background: #6366f1; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; }

.platform-icon { position: absolute; bottom: -4px; right: -4px; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #fff; }
.ig-bg { background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fd5949 45%, #d6249f 60%, #285AEB 90%); }
.fb-bg { background: #0084FF; }
.platform-icon i { color: white; font-size: 10px; }

.info { flex: 1; min-width: 0; }
.name-display { font-size: 15px; color: #111827; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 6px; }
.name-display:hover { color: #6366f1; }
.edit-icon-mini { font-size: 12px; color: #94a3b8; }

.name-edit-group { display: flex; flex-direction: column; gap: 4px; }
.name-input { border: 1px solid #e5e7eb; border-radius: 4px; padding: 2px 6px; font-size: 13px; outline: none; }
.name-input:focus { border-color: #6366f1; }
.btn-done { background: #6366f1; color: white; border: none; border-radius: 4px; cursor: pointer; width: 24px; height: 24px; padding: 0; display: flex; align-items: center; justify-content: center; margin-top: 2px; }

.id-text { font-size: 11px; color: #6b7280; margin-top: 2px; }
.btn-unlink { background: #fff1f2; border: none; color: #f43f5e; width: 28px; height: 28px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; }

/* FIELDS */
.fields-section { display: flex; flex-direction: column; gap: 10px; }
.field-row { display: flex; align-items: flex-start; }
.icon-col { width: 32px; color: #94a3b8; font-size: 18px; padding-top: 18px; }
.input-col { flex: 1; display: flex; flex-direction: column; }
.input-col label { font-size: 11px; font-weight: 600; color: #94a3b8; text-transform: uppercase; margin-bottom: 2px; }

.input-group { display: flex; align-items: center; border-bottom: 1px solid #e5e7eb; padding: 2px 0; transition: all 0.2s; }
.input-group.is-focused { border-color: #6366f1; }
.input-group.is-invalid { border-color: #ef4444; }
.simple-input { flex: 1; border: none; background: transparent; font-size: 14px; color: #1f2937; outline: none; padding: 2px 0; font-weight: 500; }
.error-text { color: #ef4444; font-size: 10px; margin-top: 2px; }

.add-btn { color: #6366f1; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px; padding: 4px 0; }
.btn-clear { background: none; border: none; color: #d1d5db; cursor: pointer; padding: 0 2px; }

/* ACTION BUTTON */
.action-row { padding-left: 32px; margin-top: 6px; }
.btn-save-small {
  background: #111827; color: #fff; border: none; border-radius: 6px;
  padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer;
  display: inline-flex; align-items: center; gap: 6px; transition: opacity 0.2s;
}
.btn-save-small:disabled { background: #cbd5e1; cursor: not-allowed; }
.btn-save-small:hover:not(:disabled) { background: #000; }

.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #94a3b8; gap: 8px; }
.empty-state i { font-size: 32px; opacity: 0.5; }

.spinner { width: 12px; height: 12px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>