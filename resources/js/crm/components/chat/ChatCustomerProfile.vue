<template>
  <div class="right-sidebar">
    
    <div v-if="customer && customer.id" class="profile-content">
      
      <div class="header-section">
        <div class="avatar-wrap">
          <img v-if="customer.fb_profile_pic" :src="customer.fb_profile_pic" class="avatar-img">
          <div v-else class="avatar-placeholder">
            {{ (customer.first_name?.[0] || '') }}
          </div>
          <div class="platform-icon">
            <i class="bi" :class="isInstagram ? 'bi-instagram' : 'bi-messenger'"></i>
          </div>
        </div>
        
        <div class="info">
          <div class="name">
            {{ customer.first_name }} {{ customer.last_name }}
          </div>
          <div class="id-text">{{ customer.fb_user_id || customer.instagram_user_id || customer.id }}</div>
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

// Визначаємо іконку
const isInstagram = computed(() => {
  return props.customer?.source === 'instagram' || !!props.customer?.instagram_user_id;
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
  if (!props.customer?.id) return;

  isLoading.value = true;
  try {
    // Відправляємо на сервер те, що ввів менеджер
    await axios.put(`/api/customers/${props.customer.id}`, {
      phone: form.phone,
      email: form.email
    });

    // Оновлюємо об'єкт клієнта (щоб оновилось в списку без перезавантаження)
    props.customer.phone = form.phone;
    props.customer.email = form.email;

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
  background: #f8fafc;
  border-left: 1px solid #e2e8f0;
  display: flex;
  flex-direction: column;
}

.profile-content {
  padding: 24px;
  display: flex;
  flex-direction: column;
  height: 100%;
}

/* HEADER */
.header-section {
  display: flex;
  align-items: center;
  gap: 15px;
  margin-bottom: 30px;
}

.avatar-wrap {
  position: relative;
  width: 50px;
  height: 50px;
}

.avatar-img, .avatar-placeholder {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
}

.avatar-placeholder {
  background: #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  color: #64748b;
  font-size: 18px;
}

.platform-icon {
  position: absolute;
  bottom: -2px;
  right: -5px;
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
  font-size: 16px;
  color: #3b82f6; /* Синій колір */
  font-weight: 500;
  margin-bottom: 2px;
}

.id-text {
  font-size: 12px;
  color: #1e293b;
}

.btn-unlink {
  background: none;
  border: none;
  color: #f59e0b; /* Жовтий */
  font-size: 20px;
  cursor: pointer;
  padding: 0;
  margin-left: auto;
}

/* FIELDS */
.fields-section { flex: 1; }

.field-row {
  display: flex;
  margin-bottom: 25px;
  align-items: flex-start;
}

.icon-col {
  width: 30px;
  color: #94a3b8;
  font-size: 20px;
  padding-top: 0px;
}

.input-col {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.input-col label {
  font-size: 14px;
  color: #0f172a;
  margin-bottom: 4px;
}

/* Кнопка "+ Додати" */
.add-btn {
  color: #3b82f6;
  font-size: 14px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 5px;
  font-weight: 500;
}
.add-btn:hover { text-decoration: underline; }

/* Інпут */
.input-group {
  display: flex;
  align-items: center;
  border-bottom: 1px solid #3b82f6;
  padding-bottom: 2px;
}

.simple-input {
  flex: 1;
  border: none;
  background: transparent;
  font-size: 15px;
  color: #1e293b;
  outline: none;
  padding: 0;
}
.simple-input::placeholder { color: #94a3b8; }

.btn-clear {
  background: none;
  border: none;
  color: #94a3b8;
  cursor: pointer;
  font-size: 18px;
}
.btn-clear:hover { color: #ef4444; }

/* FOOTER */
.footer-section { margin-top: auto; }

.btn-save {
  width: 100%;
  background: #3b82f6;
  color: #fff;
  border: none;
  border-radius: 6px;
  padding: 12px;
  font-size: 16px;
  font-weight: 500;
  cursor: pointer;
}
.btn-save:hover { background: #2563eb; }
.btn-save:disabled { background: #93c5fd; cursor: not-allowed; }

.empty-state {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #94a3b8;
}
</style>