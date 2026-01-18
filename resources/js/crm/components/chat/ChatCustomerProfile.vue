<template>
  <div class="customer-profile">
    
    <div v-if="mode === 'view'" class="view-mode h-100 d-flex flex-column">
      
      <div class="profile-header">
        <div class="avatar-section">
          <div class="avatar-circle">
            <img v-if="customer?.fb_profile_pic" :src="customer.fb_profile_pic" alt="Avatar">
            <span v-else>{{ getInitials(customer) }}</span>
            <div class="platform-icon" :class="customer?.source">
              <i :class="getPlatformIcon(customer?.source)"></i>
            </div>
          </div>
        </div>

        <div class="info-section">
          
          <div class="name-container">
            <input 
              v-if="isEditingName"
              ref="nameInputRef"
              v-model="tempName"
              class="name-input"
              @blur="saveName"
              @keyup.enter="saveName"
              placeholder="Ім'я Прізвище"
            />
            <h4 
              v-else 
              class="customer-name clickable" 
              @click="startEditName"
              title="Натисніть, щоб змінити"
            >
              {{ customerFullName }}
            </h4>
          </div>

          <div class="customer-id text-muted">
            {{ customer?.fb_user_id || customer?.instagram_user_id || 'ID: ' + customer?.id }}
          </div>
        </div>

        <button class="btn-unlink" title="Відв'язати/Видалити">
          <i class="bi bi-person-x-fill"></i>
        </button>
      </div>

      <div class="content custom-scrollbar">
        
        <div class="contact-row">
          <div class="icon-col"><i class="bi bi-telephone"></i></div>
          <div class="data-col">
            <label>Телефон</label>
            <input 
              v-model="customer.phone" 
              @change="updateField('phone', customer.phone)"
              class="editable-input" 
              placeholder="+ Додати телефон"
            >
          </div>
        </div>

        <div class="contact-row">
          <div class="icon-col"><i class="bi bi-envelope"></i></div>
          <div class="data-col">
            <label>E-mail</label>
            <input 
              v-model="customer.email" 
              @change="updateField('email', customer.email)"
              class="editable-input" 
              placeholder="+ Додати email"
            >
          </div>
        </div>

        <div class="contact-row mt-3">
          <div class="icon-col"><i class="bi bi-sticky"></i></div>
          <div class="data-col">
            <label>Нотатка</label>
            <textarea 
              v-model="customer.note" 
              @change="updateField('note', customer.note)"
              class="editable-textarea"
              placeholder="Коментар про клієнта..."
              rows="2"
            ></textarea>
          </div>
        </div>

        <div class="actions mt-4">
          <button class="btn-primary w-100 mb-2" @click="startNewOrder">
            Додати замовлення
          </button>
          
          </div>

        <div class="history-section mt-4">
          <h6 class="text-muted text-uppercase small fw-bold mb-3">Останні замовлення</h6>
          <div v-if="!orders.length" class="text-center text-muted small py-2">
            Замовлень поки немає
          </div>
          </div>

      </div>
    </div>


    <div v-else class="order-mode h-100 d-flex flex-column">
      <div class="header-back">
        <button class="btn-back" @click="mode = 'view'">
          <i class="bi bi-arrow-left"></i> Назад до профілю
        </button>
      </div>

      <div class="order-content custom-scrollbar p-3">
        <h5 class="mb-3">Нове замовлення</h5>
        
        <div class="section-box">
          <label class="lbl">Товари</label>
          <div v-for="(item, i) in orderForm.items" :key="i" class="product-item">
            <div class="d-flex gap-2 mb-2">
              <input v-model="item.title" class="form-control form-control-sm" placeholder="Назва товару">
              <button v-if="orderForm.items.length > 1" @click="removeItem(i)" class="btn-del">
                <i class="bi bi-trash"></i>
              </button>
            </div>
            <div class="d-flex gap-2">
              <input v-model.number="item.price" type="number" class="form-control form-control-sm" placeholder="Ціна">
              <input v-model.number="item.qty" type="number" class="form-control form-control-sm" placeholder="К-сть" style="width: 60px">
              <input v-model="item.size" class="form-control form-control-sm" placeholder="Розмір">
            </div>
          </div>
          <button class="btn-add-link" @click="addItem">+ Додати ще товар</button>
        </div>

        <div class="section-box mt-3">
          <label class="lbl">Доставка</label>
          <select v-model="orderForm.delivery.method" class="form-select form-select-sm mb-2">
            <option value="novaposhta">Нова Пошта</option>
            <option value="ukrposhta">Укрпошта</option>
          </select>
          <input v-model="orderForm.delivery.city" class="form-control form-control-sm mb-2" placeholder="Місто">
          <input v-model="orderForm.delivery.warehouse" class="form-control form-control-sm mb-2" placeholder="Відділення / Адреса">
        </div>

        <div class="section-box mt-3">
          <label class="lbl">Оплата</label>
          <select v-model="orderForm.payment.method" class="form-select form-select-sm mb-2">
            <option value="cod">Накладений платіж</option>
            <option value="card">На карту</option>
          </select>
          <input v-if="orderForm.payment.method === 'cod'" 
                 v-model.number="orderForm.payment.prepay" 
                 type="number" 
                 class="form-control form-control-sm" 
                 placeholder="Передплата (грн)">
        </div>
      </div>

      <div class="order-footer p-3 border-top">
        <div class="d-flex justify-content-between fw-bold mb-2">
          <span>Разом:</span>
          <span class="text-primary">{{ totalSum }} грн</span>
        </div>
        <button class="btn-primary w-100" @click="submitOrder" :disabled="isLoading">
          {{ isLoading ? 'Обробка...' : 'Створити замовлення' }}
        </button>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, reactive, watch, nextTick } from 'vue';
import axios from 'axios';

const props = defineProps({
  customer: Object
});

const mode = ref('view'); // 'view' | 'order'
const isLoading = ref(false);
const orders = ref([]);

// --- ЛОГІКА РЕДАГУВАННЯ ІМЕНІ ---
const isEditingName = ref(false);
const tempName = ref('');
const nameInputRef = ref(null);

const customerFullName = computed(() => {
  return props.customer 
    ? `${props.customer.first_name || ''} ${props.customer.last_name || ''}`.trim() || 'Без імені'
    : '';
});

const startEditName = async () => {
  tempName.value = customerFullName.value;
  isEditingName.value = true;
  await nextTick();
  nameInputRef.value?.focus();
};

const saveName = async () => {
  isEditingName.value = false;
  if (tempName.value === customerFullName.value) return;

  // Розбиваємо рядок "Іван Іванов" на First та Last name
  const parts = tempName.value.trim().split(/\s+/);
  const firstName = parts[0] || '';
  const lastName = parts.slice(1).join(' ') || '';

  // Оновлюємо локально для миттєвого ефекту
  props.customer.first_name = firstName;
  props.customer.last_name = lastName;

  // Відправляємо на сервер
  await updateCustomerData({
    first_name: firstName,
    last_name: lastName
  });
};

// Універсальна функція оновлення поля
const updateField = async (field, value) => {
  await updateCustomerData({ [field]: value });
};

const updateCustomerData = async (payload) => {
  if (!props.customer?.id) return;
  try {
    // Припускаємо, що такий роут є (створимо його, якщо немає)
    await axios.put(`/api/customers/${props.customer.id}`, payload);
  } catch (e) {
    console.error('Failed to update customer', e);
    // Тут можна додати тост notification "Помилка збереження"
  }
};

// --- ЛОГІКА ЗАМОВЛЕННЯ ---
const orderForm = reactive({
  items: [{ title: '', price: 0, qty: 1, size: '' }],
  delivery: { method: 'novaposhta', city: '', warehouse: '' },
  payment: { method: 'cod', prepay: 0 }
});

const totalSum = computed(() => {
  return orderForm.items.reduce((acc, item) => acc + (item.price * item.qty), 0);
});

const startNewOrder = () => {
  orderForm.items = [{ title: '', price: 0, qty: 1, size: '' }];
  // Підтягуємо дані з профілю
  orderForm.delivery.recipient_phone = props.customer.phone;
  mode.value = 'order';
};

const addItem = () => orderForm.items.push({ title: '', price: 0, qty: 1, size: '' });
const removeItem = (i) => orderForm.items.splice(i, 1);

const submitOrder = async () => {
  if (totalSum.value === 0) return alert('Сума замовлення 0 грн');
  isLoading.value = true;
  try {
    await axios.post('/api/orders', {
      customer_id: props.customer.id,
      items: orderForm.items,
      delivery: orderForm.delivery,
      payment: orderForm.payment
    });
    alert('Замовлення успішно створено!');
    mode.value = 'view';
  } catch (e) {
    alert('Помилка: ' + e.message);
  } finally {
    isLoading.value = false;
  }
};

// Helpers
const getInitials = (c) => (c?.first_name?.[0] || '') + (c?.last_name?.[0] || '');
const getPlatformIcon = (src) => src === 'instagram' ? 'bi bi-instagram' : 'bi bi-messenger';

// При зміні клієнта завантажуємо його історію (Заглушка)
watch(() => props.customer?.id, (newId) => {
  if (newId) {
    mode.value = 'view';
    // fetchOrders(newId);
  }
});
</script>

<style scoped>
.customer-profile {
  width: 100%;
  height: 100%;
  background: #f8fafc; /* Світлий фон як на скріні */
  border-left: 1px solid #e2e8f0;
  display: flex;
  flex-direction: column;
}

/* --- HEADER PROFILE --- */
.profile-header {
  background: #f1f5f9; /* Трохи темніший фон для шапки */
  padding: 20px 16px;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  border-bottom: 1px solid #e2e8f0;
  position: relative;
}

.avatar-circle {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  background: #fff;
  overflow: visible; /* Щоб іконку було видно */
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  font-size: 1.2rem;
  color: #64748b;
  border: 2px solid #fff;
  box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}
.avatar-circle img {
  width: 100%; height: 100%; border-radius: 50%; object-fit: cover;
}

.platform-icon {
  position: absolute;
  bottom: -2px;
  right: -2px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  color: #fff;
  border: 2px solid #f1f5f9;
}
.platform-icon.instagram { background: #E1306C; }
.platform-icon.messenger { background: #0084FF; }

.info-section {
  flex: 1;
  overflow: hidden;
}

.name-container {
  min-height: 24px;
  display: flex;
  align-items: center;
}

.customer-name {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  color: #3b82f6; /* Синій колір як на скріні */
  cursor: pointer;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.customer-name:hover {
  text-decoration: underline;
}

.name-input {
  width: 100%;
  padding: 2px 6px;
  font-size: 1rem;
  font-weight: 600;
  border: 1px solid #3b82f6;
  border-radius: 4px;
  outline: none;
}

.customer-id {
  font-size: 0.75rem;
  margin-top: 2px;
  color: #64748b;
}

.btn-unlink {
  background: none;
  border: none;
  color: #fbbf24; /* Жовтий колір іконки */
  font-size: 1.2rem;
  cursor: pointer;
  padding: 0;
}
.btn-unlink:hover { color: #d97706; }

/* --- CONTACTS --- */
.content {
  flex: 1;
  padding: 20px 16px;
  overflow-y: auto;
}

.contact-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 16px;
}

.icon-col {
  color: #94a3b8;
  font-size: 1.1rem;
  padding-top: 2px;
  width: 20px;
  text-align: center;
}

.data-col {
  flex: 1;
}
.data-col label {
  display: block;
  font-size: 0.75rem;
  color: #334155;
  margin-bottom: 2px;
  font-weight: 500;
}

.editable-input, .editable-textarea {
  width: 100%;
  border: none;
  background: transparent;
  color: #0ea5e9; /* Голубий колір тексту */
  font-size: 0.95rem;
  padding: 0;
  cursor: pointer;
}
.editable-input:focus, .editable-textarea:focus {
  outline: none;
  border-bottom: 1px solid #0ea5e9;
  cursor: text;
}
.editable-input::placeholder {
  color: #0ea5e9;
  font-style: normal;
}

/* --- ORDER MODE --- */
.header-back {
  padding: 10px 16px;
  border-bottom: 1px solid #e2e8f0;
  background: #fff;
}
.btn-back {
  background: none;
  border: none;
  color: #64748b;
  font-weight: 500;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 5px;
}

.section-box {
  background: #fff;
  padding: 12px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
}
.lbl {
  display: block;
  font-size: 0.8rem;
  font-weight: 600;
  color: #64748b;
  margin-bottom: 8px;
}
.btn-del {
  border: none;
  background: #fee2e2;
  color: #ef4444;
  border-radius: 4px;
  width: 30px;
}
.btn-add-link {
  background: none;
  border: none;
  color: #3b82f6;
  font-size: 0.85rem;
  font-weight: 600;
  padding: 0;
  margin-top: 5px;
  cursor: pointer;
}

.btn-primary {
  background: #3b82f6;
  color: #fff;
  border: none;
  padding: 10px;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.2s;
}
.btn-primary:hover {
  background: #2563eb;
}

/* Scrollbar */
.custom-scrollbar::-webkit-scrollbar { width: 5px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
</style>