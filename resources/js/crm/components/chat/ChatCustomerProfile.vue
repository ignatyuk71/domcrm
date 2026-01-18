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
          <div class="icon-col"><i class="bi bi-telephone"></i></div>
          <div class="input-col">
            <label>Телефон</label>
            <input 
              v-model="form.phone" 
              class="simple-input" 
              type="text" 
              placeholder="+ Додати телефон"
            >
          </div>
        </div>

        <div class="field-row">
          <div class="icon-col"><i class="bi bi-envelope"></i></div>
          <div class="input-col">
            <label>E-mail</label>
            <input 
              v-model="form.email" 
              class="simple-input" 
              type="email" 
              placeholder="+ Додати email"
            >
          </div>
        </div>

        <div class="field-row">
          <div class="icon-col"><i class="bi bi-card-text"></i></div>
          <div class="input-col">
            <label>Коментар</label>
            <textarea 
              v-model="form.note" 
              class="simple-input" 
              rows="1" 
              placeholder="+ Додати коментар"
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

// Локальна форма для вводу
const form = reactive({
  phone: '',
  email: '',
  note: ''
});

// Коли міняється клієнт - заповнюємо форму тим, що є в базі
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
    // Відправляємо на сервер введені дані
    await axios.put(`/api/customers/${props.customer.id}`, {
      phone: form.phone,
      email: form.email,
      note: form.note
    });

    // Оновлюємо дані в об'єкті клієнта (щоб в списку теж оновилось)
    props.customer.phone = form.phone;
    props.customer.email = form.email;
    props.customer.note = form.note;

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

.info { flex: 1; overflow: hidden; }

.name {
  font-size: 16px;
  font-weight: 600;
  color: #0ea5e9; /* Голубий колір імені */
  margin-bottom: 2px;
}

.id-text {
  font-size: 12px;
  color: #334155;
}

/* ПОЛЯ ВВОДУ */
.fields-section { flex: 1; }

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

.simple-input {
  border: none;
  background: transparent;
  width: 100%;
  font-size: 15px;
  color: #3b82f6; /* Синій текст, як на скріні */
  outline: none;
  padding: 0;
}

.simple-input::placeholder {
  color: #60a5fa; /* Світло-синій placeholder (+ Додати телефон) */
}

.simple-input:focus {
  border-bottom: 1px solid #3b82f6; /* Підкреслення при вводі */
}

/* КНОПКА */
.footer-section { margin-top: auto; }

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