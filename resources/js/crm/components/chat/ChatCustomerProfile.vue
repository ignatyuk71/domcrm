<template>
  <div class="right-sidebar">
    
    <div v-if="customerId" class="profile-content">
      
      <div class="header-section">
        <div class="avatar-wrap">
          <img v-if="avatarUrl" :src="avatarUrl" class="avatar-img">
          <div v-else class="avatar-placeholder">
            {{ displayInitial }}
          </div>
          <div class="platform-icon" :class="isInstagram ? 'ig-bg' : 'fb-bg'">
            <i class="bi" :class="isInstagram ? 'bi-instagram' : 'bi-messenger'"></i>
          </div>
        </div>
        
        <div class="info">
          <div class="name">
            {{ displayName }}
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
            
            <div v-if="form.phone || showPhoneInput" class="input-group" :class="{ 'is-focused': phoneFocused }">
              <input 
                v-model="form.phone" 
                class="simple-input" 
                placeholder="+380..." 
                ref="phoneRef"
                @focus="phoneFocused = true"
                @blur="phoneFocused = false"
              >
              <button class="btn-clear" @click="clearPhone">
                <i class="bi bi-x-circle-fill"></i>
              </button>
            </div>
            
            <div v-else class="add-btn" @click="enablePhone">
              <i class="bi bi-plus-circle"></i> Додати телефон
            </div>
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
              <button class="btn-clear" @click="clearEmail">
                <i class="bi bi-x-circle-fill"></i>
              </button>
            </div>
            
            <div v-else class="add-btn" @click="enableEmail">
              <i class="bi bi-plus-circle"></i> Додати email
            </div>
          </div>
        </div>

      </div>

      <div class="footer-section">
        <button class="btn-save" @click="saveData" :disabled="isLoading">
          <span v-if="isLoading" class="spinner"></span>
          {{ isLoading ? 'Збереження...' : 'Зберегти зміни' }}
        </button>
      </div>

    </div>

    <div v-else class="empty-state">
      <i class="bi bi-chat-dots"></i>
      <p>Виберіть чат для перегляду профілю</p>
    </div>

  </div>
</template>

<script setup>
import { ref, reactive, watch, nextTick, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
  customer: Object
});

// Стани для візуальних ефектів (тепер вони точно є!)
const phoneFocused = ref(false);
const emailFocused = ref(false);

const isLoading = ref(false);
const showPhoneInput = ref(false);
const showEmailInput = ref(false);
const phoneRef = ref(null);
const emailRef = ref(null);

const form = reactive({
  phone: '',
  email: ''
});

const customerId = computed(() => props.customer?.id ?? props.customer?.customer_id ?? null);
const displayName = computed(() => {
  const first = props.customer?.first_name || '';
  const last = props.customer?.last_name || '';
  const combined = `${first} ${last}`.trim();
  return combined || props.customer?.customer_name || 'Невідомий клієнт';
});
const displayInitial = computed(() => (displayName.value ? displayName.value[0] : ''));
const avatarUrl = computed(() => props.customer?.fb_profile_pic || props.customer?.customer_avatar || '');
const platform = computed(() => props.customer?.source || props.customer?.platform || '');

const isInstagram = computed(() => {
  return platform.value === 'instagram' || !!props.customer?.instagram_user_id;
});

watch(() => props.customer, (newVal) => {
  if (newVal) {
    form.phone = newVal.phone || '';
    form.email = newVal.email || '';
    showPhoneInput.value = !!form.phone;
    showEmailInput.value = !!form.email;
  }
}, { immediate: true });

const enablePhone = async () => {
  showPhoneInput.value = true;
  await nextTick();
  phoneRef.value?.focus();
};
const clearPhone = () => {
  form.phone = '';
  showPhoneInput.value = false;
};

const enableEmail = async () => {
  showEmailInput.value = true;
  await nextTick();
  emailRef.value?.focus();
};
const clearEmail = () => {
  form.email = '';
  showEmailInput.value = false;
};

const saveData = async () => {
  if (!customerId.value) return;
  isLoading.value = true;
  try {
    await axios.put(`/api/customers/${customerId.value}`, {
      phone: form.phone,
      email: form.email
    });
    if (props.customer) {
      props.customer.phone = form.phone;
      props.customer.email = form.email;
    }
  } catch (e) {
    console.error(e);
    alert('Помилка збереження');
  } finally {
    isLoading.value = false;
  }
};
</script>

<style scoped>
.right-sidebar {
  width: 100%;
  height: 100%;
  background: #ffffff;
  border-left: 1px solid #e5e7eb;
  display: flex;
  flex-direction: column;
}

.profile-content {
  padding: 24px;
  display: flex;
  flex-direction: column;
  height: 100%;
}

.divider {
  border: 0;
  border-top: 1px solid #f1f5f9;
  margin: 20px 0;
}

.header-section {
  display: flex;
  align-items: center;
  gap: 16px;
}

.avatar-wrap {
  position: relative;
  width: 64px;
  height: 64px;
  flex-shrink: 0;
}

.avatar-img, .avatar-placeholder {
  width: 100%;
  height: 100%;
  border-radius: 16px;
  object-fit: cover;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.avatar-placeholder {
  background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 24px;
}

.platform-icon {
  position: absolute;
  bottom: -4px;
  right: -4px;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px solid #fff;
}
.ig-bg { background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285AEB 90%); }
.fb-bg { background: #0084FF; }
.platform-icon i { color: white; font-size: 12px; }

.info { flex: 1; min-width: 0; }
.name {
  font-size: 18px;
  color: #111827;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.id-text {
  font-size: 12px;
  color: #6b7280;
  margin-top: 2px;
}

.btn-unlink {
  background: #fff1f2;
  border: none;
  color: #f43f5e;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
}
.btn-unlink:hover { background: #ffe4e6; transform: scale(1.05); }

.fields-section { flex: 1; margin-top: 8px; }

.field-row {
  display: flex;
  margin-bottom: 24px;
}

.icon-col {
  width: 40px;
  color: #94a3b8;
  font-size: 20px;
  padding-top: 22px;
}

.input-col {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.input-col label {
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.025em;
  margin-bottom: 4px;
}

.add-btn {
  color: #6366f1;
  font-size: 14px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 500;
  padding: 8px 0;
}

.input-group {
  display: flex;
  align-items: center;
  border-bottom: 2px solid #e5e7eb;
  padding: 4px 0;
  transition: border-color 0.3s;
}
.input-group.is-focused {
  border-color: #6366f1;
}

.simple-input {
  flex: 1;
  border: none;
  background: transparent;
  font-size: 15px;
  color: #1f2937;
  outline: none;
  padding: 4px 0;
  font-weight: 500;
}

.btn-clear {
  background: none;
  border: none;
  color: #d1d5db;
  cursor: pointer;
  padding: 0 4px;
}

.footer-section { margin-top: auto; }

.btn-save {
  width: 100%;
  background: #111827;
  color: #fff;
  border: none;
  border-radius: 10px;
  padding: 14px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 8px;
}
.btn-save:hover:not(:disabled) { background: #000; }
.btn-save:disabled { background: #9ca3af; cursor: not-allowed; }

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #94a3b8;
  gap: 12px;
}
.empty-state i { font-size: 48px; opacity: 0.5; }

.spinner {
  width: 18px;
  height: 18px;
  border: 2px solid rgba(255,255,255,0.3);
  border-radius: 50%;
  border-top-color: #fff;
  animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>