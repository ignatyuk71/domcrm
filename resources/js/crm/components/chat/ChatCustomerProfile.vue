<template>
  <div class="customer-profile">
    
    <div v-if="customer" class="h-100 d-flex flex-column">

      <div v-if="mode === 'view'" class="view-mode h-100 d-flex flex-column">
        
        <div class="profile-header">
          <div class="avatar-section">
            <div class="avatar-circle">
              <img v-if="customer.fb_profile_pic" :src="customer.fb_profile_pic" alt="Avatar">
              <span v-else>{{ getInitials(customer) }}</span>
              
              <div class="platform-icon" :class="customer.source || 'messenger'">
                <i :class="getPlatformIcon(customer.source)"></i>
              </div>
            </div>
          </div>

          <div class="info-section">
            
            <div v-if="isEditingName" class="name-edit-inputs">
              <input 
                ref="firstNameRef"
                v-model="editForm.first_name" 
                class="form-control form-control-sm mb-1" 
                placeholder="Ім'я"
                @keyup.enter="saveName"
              >
              <input 
                v-model="editForm.last_name" 
                class="form-control form-control-sm" 
                placeholder="Прізвище"
                @keyup.enter="saveName"
              >
              <div class="d-flex gap-2 mt-1">
                <button class="btn btn-xs btn-primary w-100" @click="saveName">ОК</button>
                <button class="btn btn-xs btn-light w-100" @click="isEditingName = false">Скасувати</button>
              </div>
            </div>

            <div v-else>
              <h5 class="customer-name clickable" @click="startEditName" title="Натисніть, щоб змінити">
                {{ customerFullName }}
                <i class="bi bi-pencil-fill small-icon"></i>
              </h5>
              <div class="customer-id text-muted">
                {{ customer.fb_user_id || customer.instagram_user_id || 'ID: ' + customer.id }}
              </div>
            </div>
          </div>
        </div>

        <div class="content custom-scrollbar">
          
          <div class="form-group mb-3">
            <label class="section-label"><i class="bi bi-telephone me-1"></i> Телефон</label>
            <input 
              v-model="customer.phone" 
              class="form-control" 
              placeholder="+380..."
              @change="updateCustomer('phone', customer.phone)"
            >
          </div>

          <div class="form-group mb-3">
            <label class="section-label"><i class="bi bi-envelope me-1"></i> E-mail</label>
            <input 
              v-model="customer.email" 
              class="form-control" 
              placeholder="user@example.com"
              @change="updateCustomer('email', customer.email)"
            >
          </div>

          <div class="form-group mb-4">
            <label class="section-label"><i class="bi bi-sticky me-1"></i> Нотатка</label>
            <textarea 
              v-model="customer.note" 
              class="form-control" 
              rows="3" 
              placeholder="Важлива інформація про клієнта..."
              @change="updateCustomer('note', customer.note)"
            ></textarea>
          </div>

          <button class="btn btn-primary w-100 py-2 fw-bold" @click="startNewOrder">
            <i class="bi bi-plus-lg me-1"></i> Додати замовлення
          </button>

          <div class="mt-4 pt-3 border-top">
             <small class="text-muted text-uppercase fw-bold">Останні замовлення</small>
             <div class="text-center text-muted small py-3">Історія порожня</div>
          </div>

        </div>
      </div>


      <div v-else class="order-mode h-100 d-flex flex-column">
        
        <div class="header-back">
          <button class="btn-back" @click="mode = 'view'">
            <i class="bi bi-arrow-left"></i> Назад до профілю
          </button>
        </div>

        <div class="order-content custom-scrollbar">
          <h5 class="mb-3 fw-bold">Нове замовлення</h5>
          
          <div class="section-box">
            <label class="lbl">Товари</label>
            <div v-for="(item, i) in orderForm.items" :key="i" class="product-item mb-2 pb-2 border-bottom">
              <div class="d-flex gap-2 mb-2">
                <input v-model="item.title" class="form-control form-control-sm" placeholder="Назва товару">
                <button v-if="orderForm.items.length > 1" @click="removeItem(i)" class="btn btn-sm btn-light text-danger">
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
              <option value="pickup">Самовивіз</option>
            </select>
            <input v-model="orderForm.delivery.city" class="form-control form-control-sm mb-2" placeholder="Місто">
            <input v-model="orderForm.delivery.warehouse" class="form-control form-control-sm mb-2" placeholder="Відділення / Адреса">
            <input v-model="orderForm.delivery.recipient_phone" class="form-control form-control-sm" placeholder="Телефон одержувача">
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
                   placeholder="Сума передплати (грн)">
          </div>
          
          <div class="section-box mt-3">
            <label class="lbl">Коментар</label>
            <textarea v-model="orderForm.comment" class="form-control form-control-sm" rows="2"></textarea>
          </div>
        </div>

        <div class="order-footer">
          <div class="d-flex justify-content-between fw-bold mb-2 align-items-center">
            <span>Разом:</span>
            <span class="fs-5 text-primary">{{ totalSum }} грн</span>
          </div>
          <button class="btn btn-primary w-100 py-2" @click="submitOrder" :disabled="isLoading">
            <span v-if="isLoading" class="spinner-border spinner-border-sm me-2"></span>
            {{ isLoading ? 'Збереження...' : 'Створити замовлення' }}
          </button>
        </div>
      </div>

    </div>

    <div v-else class="h-100 d-flex align-items-center justify-content-center text-muted">
      <div class="spinner-border text-primary me-2" role="status" style="width: 1.5rem; height: 1.5rem;"></div>
      <span>Завантаження...</span>
    </div>

  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, nextTick } from 'vue';
import axios from 'axios';

const props = defineProps({
  customer: Object
});

const mode = ref('view'); // 'view' | 'order'
const isLoading = ref(false);

// --- ЛОГІКА ІМЕНІ ---
const isEditingName = ref(false);
const firstNameRef = ref(null);
const editForm = reactive({ first_name: '', last_name: '' });

const customerFullName = computed(() => {
  if (!props.customer) return '';
  const f = props.customer.first_name || '';
  const l = props.customer.last_name || '';
  return `${f} ${l}`.trim() || 'Без імені';
});

const startEditName = async () => {
  editForm.first_name = props.customer.first_name || '';
  editForm.last_name = props.customer.last_name || '';
  isEditingName.value = true;
  await nextTick();
  if (firstNameRef.value) firstNameRef.value.focus();
};

const saveName = async () => {
  if (props.customer) {
    props.customer.first_name = editForm.first_name;
    props.customer.last_name = editForm.last_name;
  }
  isEditingName.value = false;
  await updateCustomerData({
    first_name: editForm.first_name,
    last_name: editForm.last_name
  });
};

// --- ЛОГІКА ЗАМОВЛЕННЯ ---
const orderForm = reactive({
  items: [],
  delivery: { method: 'novaposhta', city: '', warehouse: '', recipient_phone: '' },
  payment: { method: 'cod', prepay: 0 },
  comment: ''
});

const startNewOrder = () => {
  // Скидаємо форму і підтягуємо телефон
  orderForm.items = [{ title: '', price: 0, qty: 1, size: '' }];
  orderForm.delivery = { 
    method: 'novaposhta', 
    city: '', 
    warehouse: '', 
    recipient_phone: props.customer?.phone || '' 
  };
  orderForm.payment = { method: 'cod', prepay: 0 };
  orderForm.comment = '';
  
  mode.value = 'order';
};

const addItem = () => orderForm.items.push({ title: '', price: 0, qty: 1, size: '' });
const removeItem = (i) => orderForm.items.splice(i, 1);

const totalSum = computed(() => {
  return orderForm.items.reduce((acc, item) => acc + (item.price * item.qty), 0);
});

const submitOrder = async () => {
  if (!props.customer?.id) return;
  if (totalSum.value === 0) return alert('Сума замовлення 0 грн');
  
  isLoading.value = true;
  try {
    const payload = {
      customer_id: props.customer.id,
      items: orderForm.items,
      delivery: orderForm.delivery,
      payment: orderForm.payment,
      comment: orderForm.comment
    };
    
    await axios.post('/api/orders', payload);
    
    alert('Замовлення успішно створено!');
    mode.value = 'view';
  } catch (e) {
    console.error(e);
    alert('Помилка при створенні замовлення');
  } finally {
    isLoading.value = false;
  }
};

// --- ЗАГАЛЬНІ ФУНКЦІЇ ---
const updateCustomer = async (field, value) => {
  await updateCustomerData({ [field]: value });
};

const updateCustomerData = async (payload) => {
  if (!props.customer?.id) return;
  try {
    await axios.put(`/api/customers/${props.customer.id}`, payload);
  } catch (e) {
    console.error('Save failed', e);
  }
};

const getInitials = (c) => (c?.first_name?.[0] || '') + (c?.last_name?.[0] || '');
const getPlatformIcon = (src) => src === 'instagram' ? 'bi bi-instagram' : 'bi bi-messenger';

// Скидаємо режим на перегляд, якщо змінюється клієнт
watch(() => props.customer?.id, () => {
  mode.value = 'view';
  isEditingName.value = false;
});
</script>

<style scoped>
.customer-profile {
  background: #f8fafc;
  border-left: 1px solid #e2e8f0;
  height: 100%;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

/* HEADER */
.profile-header {
  padding: 20px;
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  gap: 15px;
  align-items: flex-start;
}

.avatar-circle {
  width: 56px;
  height: 56px;
  background: #eff6ff;
  color: #3b82f6;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 1.2rem;
  position: relative;
  box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}
.avatar-circle img {
  width: 100%; height: 100%; border-radius: 50%; object-fit: cover;
}

.platform-icon {
  position: absolute;
  bottom: -2px;
  right: -2px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  color: #fff;
  border: 2px solid #fff;
}
.platform-icon.instagram { background: #E1306C; }
.platform-icon.messenger { background: #0084FF; } /* Default blue */

.info-section { flex: 1; overflow: hidden; }

.customer-name {
  margin: 0;
  font-weight: 700;
  font-size: 1.05rem;
  color: #1e293b;
  cursor: pointer;
  transition: color 0.2s;
}
.customer-name:hover { color: #3b82f6; }
.small-icon { font-size: 0.8rem; opacity: 0.4; margin-left: 6px; }

.customer-id {
  font-size: 0.8rem;
  color: #94a3b8;
  margin-top: 2px;
}

/* CONTENT & FORMS */
.content { padding: 20px; flex: 1; overflow-y: auto; }

.section-label {
  display: block;
  font-size: 0.8rem;
  font-weight: 600;
  color: #64748b;
  margin-bottom: 6px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.form-control {
  font-size: 0.95rem;
  border-color: #cbd5e1;
  padding: 8px 12px;
}
.form-control:focus {
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.btn-xs {
  padding: 2px 8px;
  font-size: 0.8rem;
}

/* ORDER MODE STYLES */
.header-back {
  padding: 12px 20px;
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
}
.btn-back {
  background: none;
  border: none;
  color: #64748b;
  font-weight: 600;
  font-size: 0.9rem;
  padding: 0;
  display: flex;
  align-items: center;
  gap: 6px;
  transition: color 0.2s;
}
.btn-back:hover { color: #3b82f6; }

.order-content {
  flex: 1;
  padding: 20px;
  overflow-y: auto;
}

.section-box {
  background: #fff;
  padding: 16px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 2px rgba(0,0,0,0.03);
  margin-bottom: 16px;
}
.lbl {
  font-size: 0.85rem;
  font-weight: 700;
  color: #334155;
  margin-bottom: 10px;
  display: block;
}

.btn-add-link {
  background: none;
  border: none;
  color: #3b82f6;
  font-weight: 600;
  font-size: 0.9rem;
  padding: 0;
  margin-top: 8px;
}

.order-footer {
  padding: 20px;
  background: #fff;
  border-top: 1px solid #e2e8f0;
}

/* Scrollbar */
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
</style>