<template>
  <div class="right-sidebar">
    
    <div v-if="customer" class="profile-content">
      
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
          <div class="id-text">
            {{ customer.fb_user_id || customer.instagram_user_id || customer.id }}
          </div>
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
            
            <div v-if="showPhone || form.phone" class="input-group">
              <input 
                v-model="form.phone" 
                class="simple-input" 
                placeholder="+380..." 
                ref="phoneInputRef"
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
            
            <div v-if="showEmail || form.email" class="input-group">
              <input 
                v-model="form.email" 
                class="simple-input" 
                placeholder="email@example.com"
                ref="emailInputRef"
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
  customer: Object // Сюди приходить об'єкт клієнта з бази
});

const isLoading = ref(false);
const showPhone = ref(false);
const showEmail = ref(false);
const phoneInputRef = ref(null);
const emailInputRef = ref(null);

const form = reactive({
  phone: '',
  email: ''
});

// Визначаємо іконку платформи
const isInstagram = computed(() => {
  if (!props.customer) return false;
  return props.customer.source === 'instagram' || !!props.customer.instagram_user_id;
});

// Слідкуємо за зміною клієнта.
// Якщо в базі вже є телефон/email - показуємо їх в інпутах.
// Якщо немає - показуємо кнопки "Додати".
watch(() => props.customer, (newVal) => {
  if (newVal) {
    form.phone = newVal.phone || '';
    form.email = newVal.email || '';
    
    // Якщо дані є - показуємо інпути, якщо ні - ховаємо (будуть кнопки)
    showPhone.value = !!form.phone;
    showEmail.value = !!form.email;
  }
}, { immediate: true });

// --- ЛОГІКА ТЕЛЕФОНУ ---
const enablePhone = async () => {
  showPhone.value = true;
  await nextTick();
  phoneInputRef.value?.focus();
};
const clearPhone = () => {
  form.phone = '';
  showPhone.value = false;
};

// --- ЛОГІКА EMAIL ---
const enableEmail = async () => {
  showEmail.value = true;
  await nextTick();
  emailInputRef.value?.focus();
};
const clearEmail = () => {
  form.email = '';
  showEmail.value = false;
};

// --- ЗБЕРЕЖЕННЯ ---
const saveData = async () => {
  if (!props.customer?.id) return;
  
  isLoading.value = true;
  try {
    // Відправляємо PUT запит на оновлення клієнта
    await axios.put(`/api/customers/${props.customer.id}`, {
      phone: form.phone,
      email: form.email
    });

    // Оновлюємо дані локально, щоб інтерфейс не блимав
    props.customer.phone = form.phone;
    props.customer.email = form.email;

    // alert('Збережено'); // Можна розкоментувати, якщо треба
  } catch (e) {
    console.error(e);
    alert('Помилка збереження даних');
  } finally {
    isLoading.value = false;
  }
};
</script>

<style scoped>
/* Стилі точнісінько під твій скріншот */
.right-sidebar {
  width: 100%;
  height: 100%;
  background: #f8fafc; /* Світло-сірий фон */
  border-left: 1px solid #e2e8f0;
  display: flex;
  flex-direction: column;
}

.profile-content {
  padding: 20px;
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
  font-size: 20px;
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

.info {
  flex: 1;
  overflow: hidden;
}

.name {
  font-size: 16px;
  color: #3b82f6; /* Синій, як на фото */
  font-weight: 500;
  margin-bottom: 2px;
  line-height: 1.2;
}

.id-text {
  font-size: 13px;
  color: #1e293b;
}

.btn-unlink {
  background: none;
  border: none;
  color: #f59e0b; /* Жовтий колір іконки */
  font-size: 20px;
  cursor: pointer;
  padding: 0;
  margin-left: auto;
}

/* FIELDS */
.fields-section {
  flex: 1;
}

.field-row {
  display: flex;
  margin-bottom: 25px;
  align-items: flex-start;
}

.icon-col {
  width: 30px;
  color: #94a3b8; /* Сірий колір іконки */
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
  color: #3b82f6; /* Голубий колір */
  font-size: 14px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 5px;
  font-weight: 500;
}
.add-btn:hover { text-decoration: underline; }

/* Інпут з хрестиком */
.input-group {
  display: flex;
  align-items: center;
  border-bottom: 1px solid #3b82f6; /* Синя лінія знизу */
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
  padding: 0 0 0 8px;
  font-size: 18px;
}
.btn-clear:hover { color: #ef4444; }

/* FOOTER */
.footer-section {
  margin-top: auto;
}

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
  transition: background 0.2s;
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