<template>
  <div class="customer-profile">
    
    <div v-if="mode === 'order'" class="order-mode h-100 d-flex flex-column">
      <div class="header">
        <button class="btn-icon" @click="mode = 'view'">
          <i class="bi bi-arrow-left"></i>
        </button>
        <h4>Нове замовлення</h4>
      </div>

      <div class="content custom-scrollbar">
        <div class="section">
          <label class="section-label">Товари</label>
          <div v-for="(item, i) in orderForm.items" :key="i" class="product-row">
            <div class="d-flex gap-2 mb-2">
              <input v-model="item.title" class="form-input flex-grow-1" placeholder="Назва товару">
              <button v-if="orderForm.items.length > 1" @click="removeItem(i)" class="btn-remove">
                <i class="bi bi-trash"></i>
              </button>
            </div>
            <div class="d-flex gap-2">
              <input v-model.number="item.price" type="number" class="form-input" placeholder="Ціна">
              <input v-model.number="item.qty" type="number" class="form-input" placeholder="К-сть" style="width: 70px">
            </div>
          </div>
          <button class="btn-link mt-2" @click="addItem">+ Додати товар</button>
        </div>

        <hr class="divider">

        <div class="section">
          <label class="section-label">Доставка</label>
          <select v-model="orderForm.delivery.method" class="form-select mb-2">
            <option value="novaposhta">Нова Пошта</option>
            <option value="ukrposhta">Укрпошта</option>
          </select>
          <input v-model="orderForm.delivery.city" class="form-input mb-2" placeholder="Місто">
          <input v-model="orderForm.delivery.warehouse" class="form-input mb-2" placeholder="Відділення">
          <input v-model="orderForm.delivery.recipient_phone" class="form-input" placeholder="Телефон (якщо інший)">
        </div>

        <hr class="divider">

        <div class="section">
          <label class="section-label">Оплата</label>
          <select v-model="orderForm.payment.method" class="form-select mb-2">
            <option value="cod">Накладений</option>
            <option value="card">На карту</option>
          </select>
          <input v-if="orderForm.payment.method === 'cod'" 
                 v-model.number="orderForm.payment.prepay" 
                 type="number" 
                 class="form-input" 
                 placeholder="Передплата (грн)">
        </div>
      </div>

      <div class="footer">
        <div class="total-sum mb-2">Сума: {{ totalSum }} грн</div>
        <button class="btn-primary w-100" @click="submitOrder" :disabled="isLoading">
          {{ isLoading ? 'Збереження...' : 'Створити замовлення' }}
        </button>
      </div>
    </div>


    <div v-else class="view-mode h-100 d-flex flex-column">
      
      <div class="profile-header text-center p-4">
        <div class="avatar-large mx-auto mb-3">
          <img v-if="customer?.fb_profile_pic" :src="customer.fb_profile_pic" alt="Avatar">
          <span v-else>{{ getInitials(customer) }}</span>
        </div>
        <h3 class="customer-name">{{ customerName }}</h3>
        <div class="platform-badge">
          <i :class="getPlatformIcon(customer?.source)"></i> {{ customer?.source || 'Клієнт' }}
        </div>
        
        <button class="btn-primary w-100 mt-3" @click="startNewOrder">
          Додати замовлення
        </button>
      </div>

      <div class="content custom-scrollbar px-3">
        <div class="info-group">
          <label>Телефон</label>
          <div class="info-value">
            {{ customer?.phone || '—' }}
            <i class="bi bi-pencil-square ms-2 text-muted" style="cursor:pointer"></i>
          </div>
        </div>

        <div class="info-group">
          <label>Коментар</label>
          <textarea 
            class="form-textarea" 
            :value="customer?.note" 
            @change="updateNote"
            placeholder="Нотатка про клієнта..."
          ></textarea>
        </div>

        <div class="history-section mt-4">
          <h5>Останні замовлення</h5>
          <div v-if="orders.length === 0" class="text-muted text-center py-3">
            Історія порожня
          </div>
          <div v-else class="order-list">
             <div v-for="order in orders" :key="order.id" class="order-card">
               <div class="d-flex justify-content-between">
                 <strong>#{{ order.order_number }}</strong>
                 <span class="badge" :class="getStatusClass(order.status)">{{ order.status }}</span>
               </div>
               <div class="small text-muted">{{ order.total_amount }} грн • {{ formatDate(order.created_at) }}</div>
             </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, reactive, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
  customer: Object
});

const emit = defineEmits(['update-customer']);

const mode = ref('view'); // 'view' | 'order'
const isLoading = ref(false);
const orders = ref([]); // Тут будуть замовлення клієнта

// Форма замовлення
const orderForm = reactive({
  items: [],
  delivery: {},
  payment: {}
});

// Скидання форми
const resetForm = () => {
  orderForm.items = [{ title: '', price: 0, qty: 1 }];
  orderForm.delivery = { method: 'novaposhta', city: '', warehouse: '', recipient_phone: props.customer?.phone || '' };
  orderForm.payment = { method: 'cod', prepay: 0 };
};

// При зміні клієнта завантажуємо його історію і скидаємо форму
watch(() => props.customer?.id, async (newId) => {
  if (!newId) return;
  mode.value = 'view';
  // Тут можна завантажити реальну історію:
  // const { data } = await axios.get(`/api/customers/${newId}/orders`);
  // orders.value = data;
}, { immediate: true });

const customerName = computed(() => {
  return props.customer ? `${props.customer.first_name || ''} ${props.customer.last_name || ''}`.trim() : 'Гість';
});

const totalSum = computed(() => {
  return orderForm.items.reduce((acc, item) => acc + (item.price * item.qty), 0);
});

// Дії
const startNewOrder = () => {
  resetForm();
  mode.value = 'order';
};

const addItem = () => orderForm.items.push({ title: '', price: 0, qty: 1 });
const removeItem = (i) => orderForm.items.splice(i, 1);

const submitOrder = async () => {
  if (totalSum.value === 0) return alert('Вкажіть ціну товару');
  isLoading.value = true;
  try {
    await axios.post('/api/orders', {
      customer_id: props.customer.id,
      items: orderForm.items,
      delivery: orderForm.delivery,
      payment: orderForm.payment
    });
    alert('Замовлення створено!');
    mode.value = 'view';
    // Тут можна оновити список замовлень orders.value.push(...)
  } catch (e) {
    alert('Помилка: ' + e.message);
  } finally {
    isLoading.value = false;
  }
};

const updateNote = async (e) => {
  // Логіка оновлення нотатки
  try {
     await axios.put(`/api/customers/${props.customer.id}`, { note: e.target.value });
  } catch(err) { console.error(err); }
};

// Допоміжні функції
const getInitials = (c) => (c?.first_name?.[0] || '') + (c?.last_name?.[0] || '');
const getPlatformIcon = (src) => src === 'instagram' ? 'bi bi-instagram' : 'bi bi-chat-dots';
const getStatusClass = (s) => s === 'new' ? 'bg-primary' : 'bg-secondary';
const formatDate = (d) => new Date(d).toLocaleDateString();

</script>

<style scoped>
.customer-profile {
  width: 100%;
  height: 100%;
  background: #fff;
  border-left: 1px solid #e2e8f0;
}

.header {
  padding: 16px;
  border-bottom: 1px solid #f1f5f9;
  display: flex;
  align-items: center;
  gap: 10px;
}
.header h4 { margin: 0; font-size: 1rem; font-weight: 700; }

.btn-icon {
  border: none;
  background: none;
  font-size: 1.2rem;
  color: #64748b;
  cursor: pointer;
}

.content {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
}

.avatar-large {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  background: #eff6ff;
  color: #3b82f6;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  font-weight: 700;
  overflow: hidden;
}
.avatar-large img { width: 100%; height: 100%; object-fit: cover; }

.customer-name { font-size: 1.1rem; font-weight: 700; margin-bottom: 5px; }
.platform-badge { font-size: 0.85rem; color: #64748b; }

/* Форми */
.form-input, .form-select, .form-textarea {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  font-size: 0.9rem;
}
.form-input:focus { border-color: #3b82f6; outline: none; }
.btn-remove { border: none; background: #fee2e2; color: #ef4444; border-radius: 4px; }
.btn-link { background: none; border: none; color: #3b82f6; padding: 0; font-weight: 600; cursor: pointer; }

.divider { border: 0; border-top: 1px solid #f1f5f9; margin: 20px 0; }
.section-label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 0.9rem; }

/* Footer */
.footer {
  padding: 16px;
  border-top: 1px solid #f1f5f9;
  background: #fff;
}
.total-sum { font-weight: 700; font-size: 1.1rem; text-align: right; }

.btn-primary {
  background: #3b82f6;
  color: white;
  border: none;
  padding: 10px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
}
.btn-primary:hover { background: #2563eb; }

/* Інфо профілю */
.info-group { margin-bottom: 15px; }
.info-group label { display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 4px; }
.info-value { font-size: 0.95rem; color: #334155; }

.order-card {
  border: 1px solid #f1f5f9;
  border-radius: 8px;
  padding: 10px;
  margin-bottom: 8px;
  background: #f8fafc;
}

/* Скрол */
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
</style>