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
            <i class="bi" :class="customer.source === 'instagram' ? 'bi-instagram' : 'bi-messenger'"></i>
          </div>
        </div>
        
        <div class="info">
          <div class="name">
            {{ customer.first_name }} {{ customer.last_name }}
          </div>
          <div class="id-text">{{ customer.fb_user_id || customer.instagram_user_id }}</div>
        </div>

        <button class="btn-unlink" title="Відв'язати">
          <i class="bi bi-person-x-fill"></i>
        </button>
      </div>

      <div class="fields-section">
        
        <div class="field-row">
          <div class="icon-col">
            <i class="bi bi-telephone"></i>
          </div>
          <div class="input-col">
            <label>Телефон</label>
            
            <div v-if="hasPhone || showPhoneInput" class="input-group">
              <input 
                v-model="form.phone" 
                class="simple-input" 
                placeholder="+380..." 
                ref="phoneInputRef"
              >
              <button class="btn-clear" @click="removePhone">
                <i class="bi bi-x"></i>
              </button>
            </div>
            
            <div v-else class="add-btn" @click="enablePhone">
              <i class="bi bi-plus-lg"></i> Додати телефон
            </div>
          </div>
        </div>

        <div class="field-row">
          <div class="icon-col">
            <i class="bi bi-envelope"></i>
          </div>
          <div class="input-col">
            <label>E-mail</label>
            
            <div v-if="hasEmail || showEmailInput" class="input-group">
              <input 
                v-model="form.email" 
                class="simple-input" 
                placeholder="email@example.com"
                ref="emailInputRef"
              >
              <button class="btn-clear" @click="removeEmail">
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
const phoneInputRef = ref(null);
const emailInputRef = ref(null);

const form = reactive({
  phone: '',
  email: ''
});

// Перевірка: чи є значення в формі
const hasPhone = computed(() => !!form.phone && form.phone.trim() !== '');
const hasEmail = computed(() => !!form.email && form.email.trim() !== '');

// При зміні клієнта заповнюємо форму і скидаємо стан кнопок
watch(() => props.customer, (newVal) => {
  if (newVal) {
    form.phone = newVal.phone || '';
    form.email = newVal.email || '';
    showPhoneInput.value = false;
    showEmailInput.value = false;
  }
}, { immediate: true });

// Логіка Телефон
const enablePhone = async () => {
  showPhoneInput.value = true;
  await nextTick();
  if (phoneInputRef.value) phoneInputRef.value.focus();
};
const removePhone = () => {
  form.phone = '';
  showPhoneInput.value = false;
};

// Логіка Email
const enableEmail = async () => {
  showEmailInput.value = true;
  await nextTick();
  if (emailInputRef.value) emailInputRef.value.focus();
};
const removeEmail = () => {
  form.email = '';
  showEmailInput.value = false;
};

// Збереження
const saveData = async () => {
  if (!props.customer?.id) return;

  // Валідація (якщо поля відкриті і заповнені)
  if (hasEmail.value && !form.email.includes('@')) {
    alert('Перевірте формат E-mail');
    return;
  }
  
  isLoading.value = true;
  try {
    await axios.put(`/api/customers/${props.customer.id}`, {
      phone: form.phone,
      email: form.email
    });
    
    // Оновлюємо об'єкт клієнта в батьківському компоненті
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
.right-sidebar {
  width: 100%;
  height: 100%;
  background: #f8fafc; /* Світло-сірий фон як на скріні */
  border-left: 1px solid #e2e8f0;
  display: flex;
  flex-direction: column;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
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
  width: 48px;
  height: 48px;
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
  line-height: 1;
}
.bi-instagram { color: #E1306C; }
.bi-messenger { color: #0084FF; }

.info {
  flex: 1;
  overflow: hidden;
}

.name {
  font-size: 16px;
  color: #3b82f6; /* Синій колір імені */
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
  color: #f59e0b; /* Жовтий */
  font-size: 20px;
  cursor: pointer;
  padding: 0;
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
  color: #94a3b8; /* Сіра іконка */
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
  color: #1e293b;
  margin-bottom: 4px;
}

/* Кнопка "+ Додати" */
.add-btn {
  color: #3b82f6; /* Голубий */
  font-size: 14px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 4px;
  font-weight: 500;
}
.add-btn:hover { text-decoration: underline; }

/* Група вводу */
.input-group {
  display: flex;
  align-items: center;
  border-bottom: 1px solid #3b82f6;
}

.simple-input {
  flex: 1;
  border: none;
  background: transparent;
  font-size: 15px;
  color: #1e293b;
  outline: none;
  padding: 2px 0;
}

.btn-clear {
  background: none;
  border: none;
  color: #ef4444; /* Червоний хрестик */
  cursor: pointer;
  padding: 0 4px;
  font-size: 18px;
}

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