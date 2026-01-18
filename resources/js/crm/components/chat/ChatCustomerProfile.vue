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
      </div>

      <div class="fields-section">
        
        <div class="field-row">
          <div class="icon-col">
            <i class="bi bi-telephone"></i>
          </div>
          <div class="input-col">
            <label>Телефон</label>
            <input 
              v-model="form.phone" 
              class="editable-input" 
              placeholder="+ Додати телефон"
              type="text"
            >
          </div>
        </div>

        <div class="field-row">
          <div class="icon-col">
            <i class="bi bi-envelope"></i>
          </div>
          <div class="input-col">
            <label>E-mail</label>
            <input 
              v-model="form.email" 
              class="editable-input" 
              placeholder="+ Додати email"
              type="email"
            >
          </div>
        </div>

        <div class="field-row">
          <div class="icon-col">
            <i class="bi bi-card-text"></i>
          </div>
          <div class="input-col">
            <label>Коментар</label>
            <textarea 
              v-model="form.note" 
              class="editable-input" 
              placeholder="+ Додати коментар"
              rows="1"
            ></textarea>
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
import { ref, reactive, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
  customer: Object
});

const isLoading = ref(false);

// Форма, куди менеджер вводить дані
const form = reactive({
  phone: '',
  email: '',
  note: ''
});

// Коли відкриваємо клієнта - підставляємо те, що вже є в базі, або лишаємо пустим
watch(() => props.customer, (newVal) => {
  if (newVal) {
    form.phone = newVal.phone || '';
    form.email = newVal.email || '';
    form.note = newVal.note || '';
  }
}, { immediate: true });

const saveData = async () => {
  if (!props.customer?.id) return;

  isLoading.value = true;
  try {
    // Відправляємо те, що ввів менеджер
    await axios.put(`/api/customers/${props.customer.id}`, {
      phone: form.phone,
      email: form.email,
      note: form.note
    });

    // Оновлюємо дані в інтерфейсі, щоб не треба було перезавантажувати сторінку
    props.customer.phone = form.phone;
    props.customer.email = form.email;
    props.customer.note = form.note;

    // alert('Збережено'); // Можна розкоментувати якщо треба підтвердження
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

.avatar-img {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
}

.avatar-placeholder {
  width: 100%;
  height: 100%;
  background: #e2e8f0;
  border-radius: 50%;
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
  font-weight: 600;
  color: #0ea5e9;
  margin-bottom: 2px;
}

.id-text {
  font-size: 12px;
  color: #334155;
}

/* FIELDS */
.fields-section {
  flex: 1;
}

.field-row {
  display: flex;
  margin-bottom: 25px;
}

.icon-col {
  width: 30px;
  color: #94a3b8;
  font-size: 18px;
  padding-top: 2px;
}

.input-col {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.input-col label {
  font-size: 13px;
  color: #1e293b;
  margin-bottom: 4px;
}

.editable-input {
  width: 100%;
  border: none;
  background: transparent;
  border-bottom: 1px solid transparent;
  color: #3b82f6; /* Синій колір тексту, як просив */
  font-size: 15px;
  padding: 2px 0;
  outline: none;
  transition: border-color 0.2s;
}

.editable-input::placeholder {
  color: #60a5fa; /* Світло-синій плейсхолдер */
}

.editable-input:focus {
  border-bottom: 1px solid #3b82f6;
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
  font-weight: 600;
  cursor: pointer;
}

.btn-save:hover {
  background: #2563eb;
}

.btn-save:disabled {
  background: #93c5fd;
  cursor: not-allowed;
}

.empty-state {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #94a3b8;
}
</style>