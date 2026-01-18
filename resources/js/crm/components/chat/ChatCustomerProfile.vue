<template>
  <div class="right-sidebar">
    
    <div v-if="customerId" class="profile-content">
      
      <div class="header-section">
        <div class="avatar-wrap">
          <img v-if="avatarUrl" :src="avatarUrl" class="avatar-img">
          <div v-else class="avatar-placeholder">
            {{ displayInitial }}
          </div>
          <div class="platform-icon">
            <i class="bi" :class="isInstagram ? 'bi-instagram' : 'bi-messenger'"></i>
          </div>
        </div>
        
        <div class="info">
          <div class="name">
            {{ displayName }}
          </div>
          <div class="id-text">{{ customer.fb_user_id || customer.instagram_user_id || customerId }}</div>
        </div>

        <button class="btn-unlink">
          <i class="bi bi-person-x-fill"></i>
        </button>
      </div>

      <div class="fields-section">
        
        <div class="field-row">
          <div class="icon-col"><i class="bi bi-telephone"></i></div>
          <div class="input-col">
            <label>Телефон</label>
            
            <div v-if="form.phone || showPhoneInput" class="input-group">
              <input 
                v-model="form.phone" 
                class="simple-input" 
                placeholder="+380..." 
                ref="phoneRef"
              >
              <button class="btn-clear" @click="clearPhone">
                <i class="bi bi-x"></i>
              </button>
            </div>
            
            <div v-else class="add-btn" @click="enablePhone">
              <i class="bi bi-plus-lg"></i> Додати телефон
            </div>
          </div>
        </div>

        <div class="field-row">
          <div class="icon-col"><i class="bi bi-envelope"></i></div>
          <div class="input-col">
            <label>E-mail</label>
            
            <div v-if="form.email || showEmailInput" class="input-group">
              <input 
                v-model="form.email" 
                class="simple-input" 
                placeholder="email@example.com"
                ref="emailRef"
              >
              <button class="btn-clear" @click="clearEmail">
                <i class="bi bi-x"></i>
              </button>
            </div>
            
            <div v-else class="add-btn" @click="enableEmail">
              <i class="bi bi-plus-lg"></i> Додати email
            </div>
          </div>
        </div>

      </div>

      <div class="footer-section">
        <button class="btn-save" @click="saveData" :disabled="isLoading">
          {{ isLoading ? 'Збереження...' : 'Зберегти покупця' }}
        </button>
      </div>

    </div>

    <div v-else class="empty-state">
      Виберіть чат
    </div>

  </div>
</template>

<script setup>
import { ref, reactive, watch, nextTick, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
  customer: Object
});

const isLoading = ref(false);
const showPhoneInput = ref(false);
const showEmailInput = ref(false);
const phoneRef = ref(null);
const emailRef = ref(null);

// Форма для ручного вводу
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

// Визначаємо іконку
const isInstagram = computed(() => {
  return platform.value === 'instagram' || !!props.customer?.instagram_user_id;
});

// Коли відкриваємо нового клієнта - підтягуємо те, що є (або пустоту)
watch(() => props.customer, (newVal) => {
  if (newVal) {
    form.phone = newVal.phone || '';
    form.email = newVal.email || '';
    
    // Якщо дані вже є - показуємо інпути, якщо ні - показуємо кнопки "Додати"
    showPhoneInput.value = !!form.phone;
    showEmailInput.value = !!form.email;
  }
}, { immediate: true });

// --- ЛОГІКА ІНПУТІВ ---
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

// --- ЗБЕРЕЖЕННЯ ---
const saveData = async () => {
  if (!customerId.value) return;

  isLoading.value = true;
  try {
    // Відправляємо на сервер те, що ввів менеджер
    await axios.put(`/api/customers/${customerId.value}`, {
      phone: form.phone,
      email: form.email
    });

    // Оновлюємо об'єкт клієнта (щоб оновилось в списку без перезавантаження)
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
/* Стилі під твій дизайн */
.right-sidebar {
  width: 100%;
  height: 100%;
  background: #eef2f5;
  border-left: 1px solid #e2e8f0;
  display: flex;
  flex-direction: column;
}

.profile-content {
  padding: 18px 20px 22px;
  display: flex;
  flex-direction: column;
  height: 100%;
}

/* HEADER */
.header-section {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 20px;
}

.avatar-wrap {
  position: relative;
  width: 56px;
  height: 56px;
}

.avatar-img, .avatar-placeholder {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
}

.avatar-placeholder {
  background: #e1e7ec;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  color: #64748b;
  font-size: 20px;
}

.platform-icon {
  position: absolute;
  bottom: -2px;
  right: -4px;
  background: #fff;
  border-radius: 50%;
  padding: 2px;
  display: flex;
  box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}
.bi-instagram { color: #E1306C; font-size: 14px; }
.bi-messenger { color: #0084FF; font-size: 14px; }

.info { flex: 1; overflow: hidden; }

.name {
  font-size: 17px;
  color: #1d8cff;
  font-weight: 600;
  margin-bottom: 2px;
}

.id-text {
  font-size: 13px;
  color: #1f2937;
}

.btn-unlink {
  background: none;
  border: none;
  color: #f4b23a;
  font-size: 20px;
  cursor: pointer;
  padding: 0;
  margin-left: auto;
}

/* FIELDS */
.fields-section { flex: 1; }

.field-row {
  display: flex;
  margin-bottom: 20px;
  align-items: flex-start;
}

.icon-col {
  width: 34px;
  color: #b0b7bf;
  font-size: 22px;
  padding-top: 2px;
}

.input-col {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.input-col label {
  font-size: 14px;
  color: #111827;
  margin-bottom: 6px;
}

/* Кнопка "+ Додати" */
.add-btn {
  color: #1d8cff;
  font-size: 14px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
}
.add-btn:hover { text-decoration: underline; }

/* Інпут */
.input-group {
  display: flex;
  align-items: center;
  border-bottom: 1px solid #1d8cff;
  padding-bottom: 2px;
}

.simple-input {
  flex: 1;
  border: none;
  background: transparent;
  font-size: 15px;
  color: #1f2937;
  outline: none;
  padding: 0;
}
.simple-input::placeholder { color: #94a3b8; }

.btn-clear {
  background: none;
  border: none;
  color: #9ca3af;
  cursor: pointer;
  font-size: 18px;
}
.btn-clear:hover { color: #ef4444; }

/* FOOTER */
.footer-section { margin-top: auto; }

.btn-save {
  width: 100%;
  background: #0ea5e9;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 12px;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
}
.btn-save:hover { background: #0284c7; }
.btn-save:disabled { background: #93c5fd; cursor: not-allowed; }

.empty-state {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #94a3b8;
}
</style>
