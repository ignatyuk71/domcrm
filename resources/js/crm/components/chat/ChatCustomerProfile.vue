<template>
  <div class="right-sidebar">
    
    <div v-if="customer" class="profile-content">
      
      <div class="header-section">
        <div class="avatar">
          <img v-if="customer.fb_profile_pic" :src="customer.fb_profile_pic">
          <span v-else>{{ (customer.first_name?.[0] || '') }}</span>
          <div class="platform-badge">
            <i class="bi" :class="customer.source === 'instagram' ? 'bi-instagram' : 'bi-messenger'"></i>
          </div>
        </div>
        
        <div class="info">
          <h4 class="name">
            {{ customer.first_name }} {{ customer.last_name }}
          </h4>
          <div class="id-text">{{ customer.fb_user_id || customer.instagram_user_id }}</div>
        </div>

        <button class="btn-close-custom">
          <i class="bi bi-person-x-fill"></i>
        </button>
      </div>

      <div class="fields-section">
        
        <div class="field-row">
          <div class="icon-wrap">
            <i class="bi bi-telephone"></i>
          </div>
          <div class="input-wrap">
            <label>Телефон</label>
            <input 
              v-model="form.phone" 
              class="simple-input" 
              placeholder="+ Додати телефон"
            >
          </div>
        </div>

        <div class="field-row">
          <div class="icon-wrap">
            <i class="bi bi-envelope"></i>
          </div>
          <div class="input-wrap">
            <label>E-mail</label>
            <input 
              v-model="form.email" 
              class="simple-input" 
              placeholder="+ Додати email"
            >
          </div>
        </div>
        
        <div class="field-row">
           <div class="icon-wrap">
            <i class="bi bi-sticky"></i>
          </div>
          <div class="input-wrap">
            <label>Нотатка</label>
            <textarea 
              v-model="form.note" 
              class="simple-input" 
              placeholder="Додати коментар"
              rows="1"
            ></textarea>
          </div>
        </div>

      </div>

      <div class="footer-section">
        <button class="btn-save" @click="saveCustomer" :disabled="isLoading">
          {{ isLoading ? 'Збереження...' : 'Зберегти покупця' }}
        </button>
      </div>

    </div>

    <div v-else class="loading-state">
      Виберіть чат
    </div>

  </div>
</template>

<script setup>
import { ref, watch, reactive } from 'vue';
import axios from 'axios';

const props = defineProps({
  customer: Object
});

const isLoading = ref(false);
const form = reactive({
  phone: '',
  email: '',
  note: ''
});

// Коли змінюється клієнт, заповнюємо форму його даними
watch(() => props.customer, (newVal) => {
  if (newVal) {
    form.phone = newVal.phone || '';
    form.email = newVal.email || '';
    form.note = newVal.note || '';
  }
}, { immediate: true });

const saveCustomer = async () => {
  if (!props.customer?.id) return;
  
  isLoading.value = true;
  try {
    // Оновлюємо локально (щоб зразу видно було)
    props.customer.phone = form.phone;
    props.customer.email = form.email;
    props.customer.note = form.note;

    // Відправляємо на сервер
    await axios.put(`/api/customers/${props.customer.id}`, {
      phone: form.phone,
      email: form.email,
      note: form.note
    });
    
    // Можна додати сповіщення "Збережено"
  } catch (e) {
    alert('Помилка збереження');
    console.error(e);
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
  display: flex;
  flex-direction: column;
}

.profile-content {
  padding: 20px;
  display: flex;
  flex-direction: column;
  height: 100%;
}

/* --- HEADER --- */
.header-section {
  display: flex;
  gap: 15px;
  margin-bottom: 30px;
  position: relative;
}

.avatar {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  overflow: visible;
  position: relative;
}
.avatar img, .avatar span {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #fff;
  border: 1px solid #dee2e6;
  font-weight: bold;
  color: #555;
}

.platform-badge {
  position: absolute;
  bottom: -2px;
  right: -5px;
  background: #fff;
  border-radius: 50%;
  padding: 2px;
}
.platform-badge i {
  color: #0d6efd; /* Blue for messenger */
  font-size: 14px;
}
.bi-instagram { color: #E1306C !important; }

.info {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.name {
  font-size: 16px;
  color: #0ea5e9; /* Голубий колір імені як на скріні */
  font-weight: 500;
  margin: 0;
  cursor: pointer;
}

.id-text {
  font-size: 12px;
  color: #000;
  font-weight: 500;
  margin-top: 2px;
}

.btn-close-custom {
  background: none;
  border: none;
  color: #f59e0b; /* Жовтий колір іконки */
  font-size: 20px;
  cursor: pointer;
  padding: 0;
  align-self: flex-start;
}

/* --- FIELDS --- */
.fields-section {
  flex: 1;
}

.field-row {
  display: flex;
  margin-bottom: 25px; /* Відступи між полями */
}

.icon-wrap {
  width: 30px;
  padding-top: 2px;
}
.icon-wrap i {
  font-size: 18px;
  color: #adb5bd; /* Сірий колір іконок */
}

.input-wrap {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.input-wrap label {
  font-size: 13px;
  color: #1e293b;
  margin-bottom: 2px;
  font-weight: 400;
}

.simple-input {
  border: none;
  background: transparent;
  padding: 0;
  font-size: 14px;
  color: #0ea5e9; /* Голубий текст вводу/плейсхолдера */
  outline: none;
  width: 100%;
}
.simple-input::placeholder {
  color: #0ea5e9; /* Стиль "Додати телефон" */
}
.simple-input:focus {
  border-bottom: 1px solid #0ea5e9;
}

/* --- FOOTER --- */
.footer-section {
  margin-top: auto;
}

.btn-save {
  width: 100%;
  background-color: #3b82f6; /* Синя кнопка */
  color: white;
  border: none;
  padding: 12px;
  border-radius: 6px;
  font-size: 16px;
  font-weight: 500;
  cursor: pointer;
}
.btn-save:hover {
  background-color: #2563eb;
}

.loading-state {
  padding: 20px;
  text-align: center;
  color: #999;
}
</style>