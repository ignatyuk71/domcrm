<template>
  <div class="right-sidebar">
    <transition name="toast">
      <div v-if="toast.show" class="toast-notification" :class="toast.type">
        <i class="bi" :class="toast.type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'"></i>
        <span>{{ toast.message }}</span>
      </div>
    </transition>

    <div v-if="customerId" class="profile-content">
      <div class="profile-mobile-header">
        <button class="profile-back-btn" type="button" @click="emit('close')">
          <i class="bi bi-arrow-left"></i>
          Назад
        </button>
      </div>
      
      <div class="header-section">
        <div class="avatar-wrap">
          <img v-if="avatarUrl" :src="avatarUrl" class="avatar-img">
          <div v-else class="avatar-placeholder">{{ displayInitial }}</div>
          
          <div 
            class="platform-icon-indicator" 
            :class="[isInstagram ? 'ig-bg' : 'fb-bg', isProfileComplete ? 'glow-green' : 'glow-red']"
          >
            <i class="bi" :class="isInstagram ? 'bi-instagram' : 'bi-messenger'"></i>
          </div>
        </div>
        
        <div class="info">
          <div v-if="!showNameInput" class="name-display-wrapper" @click="enableNameEdit">
            <span class="name-text" :class="{ 'text-error': !isNameValid }">{{ displayName }}</span>
            <button class="btn-edit-purple" type="button">
              <i class="bi bi-pencil-square"></i>
            </button>
          </div>

          <div v-else class="name-edit-flow">
            <div class="inputs-stack">
              <input v-model="form.first_name" class="modern-input" placeholder="Ім'я (кирилиця)">
              <input v-model="form.last_name" class="modern-input" placeholder="Прізвище (кирилиця)">
            </div>
            <button class="btn-confirm-tick" type="button" @click="showNameInput = false">
              <i class="bi bi-check2"></i>
            </button>
          </div>
          
          <div class="id-badge">ID: {{ customer.fb_user_id || customer.instagram_user_id || customerId }}</div>
        </div>

        <button
          type="button"
          class="btn-status-indicator" 
          :class="isProfileComplete ? 'status-ready' : 'status-attention'"
          title="Статус заповнення"
        >
          <i class="bi" :class="isProfileComplete ? 'bi-person-check-fill' : 'bi-person-x-fill'"></i>
        </button>
      </div>

      <hr class="divider" />

      <div class="fields-section">
        <div class="field-row">
          <div class="icon-col"><i class="bi bi-telephone"></i></div>
          <div class="input-col">
            <label>Телефон</label>
            <div v-if="form.phone || showPhoneInput" class="input-group" :class="{ 'is-focused': phoneFocused, 'is-invalid': form.phone && !isPhoneValid }">
              <input 
                v-model="form.phone" 
                class="simple-input" 
                placeholder="380XXXXXXXXX" 
                ref="phoneRef"
                type="tel"
                @focus="phoneFocused = true"
                @blur="phoneFocused = false"
              >
              <button class="btn-clear" type="button" @click="clearPhone"><i class="bi bi-x-circle-fill"></i></button>
            </div>
            <div v-else class="add-btn" @click="enablePhone"><i class="bi bi-plus-circle"></i> Додати телефон</div>
            <small v-if="form.phone && !isPhoneValid" class="error-text">Має бути 12 цифр (380...)</small>
          </div>
        </div>

        <div class="field-row">
          <div class="icon-col"><i class="bi bi-envelope"></i></div>
          <div class="input-col">
            <label>E-mail</label>
            <div v-if="form.email || showEmailInput" class="input-group" :class="{ 'is-focused': emailFocused }">
              <input v-model="form.email" class="simple-input" placeholder="email@example.com" @focus="emailFocused = true" @blur="emailFocused = false">
              <button class="btn-clear" type="button" @click="clearEmail"><i class="bi bi-x-circle-fill"></i></button>
            </div>
            <div v-else class="add-btn" @click="enableEmail"><i class="bi bi-plus-circle"></i> Додати email</div>
          </div>
        </div>

        <div class="action-row">
          <button class="btn-save-modern" @click="saveData" :disabled="isLoading || !isProfileComplete">
            <span v-if="isLoading" class="spinner"></span>
            {{ isLoading ? 'Зберігаємо...' : 'Зберегти покупця' }}
          </button>
          
          <button class="btn-create-order" type="button" @click="showOrderPanel = true">
            <i class="bi bi-bag-plus"></i>
            Створити замовлення
          </button>
        </div>

        <div class="customer-history">
          <div class="history-title">
            Активні замовлення
            <span v-if="historyLoading" class="history-loading">Завантаження...</span>
          </div>
          <div v-if="!historyOrders.length && !historyLoading" class="history-empty">
            Немає замовлень
          </div>
          <div v-for="order in historyOrders" :key="order.id" class="history-card">
            <button class="history-header" type="button" @click="toggleOrder(order.id)">
              <div class="history-main">
                <a :href="`/orders/${order.id}`" class="order-number">№ {{ order.order_number || order.id }}</a>
                <span class="order-status" :style="{ backgroundColor: order.statusRef?.color || '#e2e8f0' }">
                  {{ order.statusRef?.name || order.status }}
                </span>
              </div>
              <div class="history-meta">
                <span class="order-total">{{ formatMoney(order.items_sum_total) }} ₴</span>
                <span class="order-date">{{ formatDate(order.created_at) }}</span>
                <i class="bi" :class="order.isOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
              </div>
            </button>

            <div class="history-body" :class="{ open: order.isOpen }">
              <div class="history-section">
                <div class="history-label">Доставка</div>
                <div class="history-text">
                  {{ order.delivery?.city_name || '—' }}
                  <span v-if="order.delivery?.warehouse_name">, {{ order.delivery?.warehouse_name }}</span>
                </div>
                <div v-if="order.delivery?.ttn" class="history-text">
                  ТТН: {{ order.delivery.ttn }}
                </div>
              </div>

              <div class="history-section">
                <div class="history-label">Товари</div>
                <div class="history-items">
                  <div v-for="item in order.items || []" :key="item.id" class="history-item">
                    <img
                      class="history-thumb"
                      :src="item.product?.main_photo_path ? `/storage/${item.product.main_photo_path}` : placeholderThumb"
                      alt=""
                    />
                    <div class="history-item-info">
                      <div class="history-item-title">{{ item.product_title || 'Товар' }}</div>
                      <div class="history-item-qty">К-сть: {{ item.qty }}</div>
                    </div>
                  </div>
                </div>
              </div>

              <a class="history-link" :href="`/orders/${order.id}`">Відкрити повну картку замовлення</a>
            </div>
          </div>
        </div>
      </div>

    </div>

    <div v-else class="empty-state">
      <i class="bi bi-person-bounding-box"></i>
      <p>Виберіть чат</p>
    </div>

    <ChatOrderPanel
      v-show="showOrderPanel"
      :open="showOrderPanel"
      :customer="customer"
      :order-draft="orderDraft"
      :submit-state="orderSubmitState"
      @close="handleOrderClose"
      @minimize="handleOrderMinimize"
      @submit="handleOrderSubmit"
      @close-success="handleOrderSuccessClose"
    />

  </div>
</template>

<script setup>
import { ref, reactive, watch, nextTick, computed } from 'vue';
import axios from 'axios';
import ChatOrderPanel from '@/crm/components/chat/ChatOrderPanel.vue';

const props = defineProps({ customer: Object });
const emit = defineEmits(['close']);

const showNameInput = ref(false);
const phoneFocused = ref(false);
const emailFocused = ref(false);
const isLoading = ref(false);
const showPhoneInput = ref(false);
const showEmailInput = ref(false);
const phoneRef = ref(null);
const isOrderSaving = ref(false);
const historyOrders = ref([]);
const historyLoading = ref(false);
const placeholderThumb = 'https://via.placeholder.com/48x48?text=%20';

// Стан для панелі замовлення
const showOrderPanel = ref(false);
const orderDraft = reactive({
  items: [],
  delivery: {
    first_name: '',
    last_name: '',
    middle_name: '',
    phone: '',
    delivery_type: 'warehouse',
    carrier: 'nova_poshta',
    city_name: '',
    city_ref: '',
    warehouse_name: '',
    warehouse_ref: '',
    street_name: '',
    building: '',
    apartment: '',
    payer: 'recipient',
  },
  payment: {
    method: '',
    prepay_amount: 0,
    currency: 'UAH',
  },
});

function resetOrderDraft() {
  orderDraft.items = [];
  orderDraft.delivery = {
    first_name: '',
    last_name: '',
    middle_name: '',
    phone: '',
    delivery_type: 'warehouse',
    carrier: 'nova_poshta',
    city_name: '',
    city_ref: '',
    warehouse_name: '',
    warehouse_ref: '',
    street_name: '',
    building: '',
    apartment: '',
    payer: 'recipient',
  };
  orderDraft.payment = {
    method: '',
    prepay_amount: 0,
    currency: 'UAH',
  };
}

// Стан для сповіщень
const toast = reactive({
  show: false,
  message: '',
  type: 'success'
});

const orderSubmitState = reactive({
  status: 'idle',
  orderId: null,
  orderNumber: null,
  totalAmount: 0,
});

const form = reactive({ 
  first_name: '',
  last_name: '',
  phone: '', 
  email: '' 
});

const cyrillicRegex = /^[А-Яа-яЁёЇїІіЄєҐґ' \-]+$/;

const isNameValid = computed(() => {
  return form.first_name.trim().length >= 2 && 
         form.last_name.trim().length >= 2 && 
         cyrillicRegex.test(form.first_name) && 
         cyrillicRegex.test(form.last_name);
});

const isPhoneValid = computed(() => /^380\d{9}$/.test(form.phone));
const isProfileComplete = computed(() => isNameValid.value && isPhoneValid.value);

watch(() => form.phone, (newVal) => {
  if (!newVal) return;
  let cleaned = newVal.replace(/\D/g, '');
  if (cleaned.length > 0 && !cleaned.startsWith('38')) cleaned = '380' + cleaned;
  form.phone = cleaned.substring(0, 12);
});

const customerId = computed(() => props.customer?.id ?? props.customer?.customer_id ?? null);
const displayName = computed(() => {
  const name = `${form.first_name} ${form.last_name}`.trim();
  return name || props.customer?.customer_name || 'Не заповнено';
});
const displayInitial = computed(() => (displayName.value ? displayName.value[0].toUpperCase() : '?'));
const avatarUrl = computed(() => props.customer?.fb_profile_pic || props.customer?.customer_avatar || '');
const isInstagram = computed(() => (props.customer?.source || props.customer?.platform) === 'instagram' || !!props.customer?.instagram_user_id);

watch(() => props.customer, (newVal) => {
  if (newVal) {
    form.first_name = newVal.first_name || '';
    form.last_name = newVal.last_name || '';
    form.phone = newVal.phone ? newVal.phone.replace(/\D/g, '') : '';
    form.email = newVal.email || '';
    showPhoneInput.value = !!form.phone;
    showEmailInput.value = !!form.email;
    showNameInput.value = false;
    showOrderPanel.value = false;
    resetOrderDraft();
    if (customerId.value) loadCustomerHistory(customerId.value);
  }
}, { immediate: true });

watch(customerId, (id) => {
  if (id) loadCustomerHistory(id);
});

const showToast = (msg, type = 'success') => {
  toast.message = msg;
  toast.type = type;
  toast.show = true;
  setTimeout(() => { toast.show = false; }, 3000);
};

const enableNameEdit = () => { showNameInput.value = true; };
const enablePhone = async () => { showPhoneInput.value = true; if (!form.phone) form.phone = '380'; await nextTick(); phoneRef.value?.focus(); };
const clearPhone = () => { form.phone = ''; showPhoneInput.value = false; };
const enableEmail = async () => { showEmailInput.value = true; await nextTick(); };
const clearEmail = () => { form.email = ''; showEmailInput.value = false; };

const loadCustomerHistory = async (id) => {
  historyLoading.value = true;
  try {
    const { data } = await axios.get(`/customers/${id}`);
    const recent = data?.data?.recent_orders || [];
    historyOrders.value = recent.map((order) => ({ ...order, isOpen: false }));
  } catch (e) {
    console.error(e);
    historyOrders.value = [];
  } finally {
    historyLoading.value = false;
  }
};

const toggleOrder = (orderId) => {
  const target = historyOrders.value.find((order) => order.id === orderId);
  if (target) target.isOpen = !target.isOpen;
};

const formatDate = (value) => {
  if (!value) return '—';
  const date = new Date(value);
  return date.toLocaleDateString('uk-UA', { year: 'numeric', month: '2-digit', day: '2-digit' });
};

const formatMoney = (value) => Number(value || 0).toFixed(2);

const saveData = async () => {
  if (!customerId.value || !isProfileComplete.value) return;
  isLoading.value = true;
  try {
    const response = await axios.put(`/api/customers/${customerId.value}`, form);
    const updatedCustomer = response?.data?.data;
    if (props.customer && updatedCustomer) {
      Object.assign(props.customer, updatedCustomer);
    } else if (props.customer) {
      Object.assign(props.customer, form);
    }

    showNameInput.value = false;
    showToast('Дані покупця успішно збережено!');
  } catch (e) { 
    console.error(e); 
    showToast('Помилка сервера. Дані не збережено.', 'error');
  } finally { 
    isLoading.value = false; 
  }
};

const handleOrderSubmit = () => {
  createOrderFromDraft();
};

const handleOrderSuccessClose = () => {
  orderSubmitState.status = 'idle';
  orderSubmitState.orderId = null;
  orderSubmitState.orderNumber = null;
  orderSubmitState.totalAmount = 0;
  resetOrderDraft();
  showOrderPanel.value = false;
};

const createOrderFromDraft = async () => {
  if (!orderDraft.items.length) {
    showToast('Додайте товари до замовлення.', 'error');
    return;
  }
  if (!orderDraft.payment?.method) {
    showToast('Оберіть спосіб оплати.', 'error');
    return;
  }
  if (!orderDraft.delivery?.delivery_type) {
    showToast('Оберіть тип доставки.', 'error');
    return;
  }
  if (!orderDraft.delivery?.city_ref) {
    showToast('Оберіть місто доставки.', 'error');
    return;
  }
  if (orderDraft.delivery.delivery_type === 'warehouse' && !orderDraft.delivery.warehouse_ref) {
    showToast('Оберіть відділення.', 'error');
    return;
  }
  if (!form.phone) {
    showToast('Вкажіть телефон клієнта.', 'error');
    return;
  }
  if (isOrderSaving.value) return;
  isOrderSaving.value = true;
  orderSubmitState.status = 'loading';

  const paymentMethod = orderDraft.payment?.method || 'cod';
  const prepayAmount = Number(orderDraft.payment?.prepay_amount || 0);
  const paymentStatus = paymentMethod === 'card' ? 'paid' : 'unpaid';

  const rawSource = (props.customer?.source || props.customer?.platform || '').toLowerCase();
  const source = rawSource.includes('instagram') || rawSource === 'ig' || isInstagram.value ? 'instagram' : 'facebook';
  const delivery = orderDraft.delivery || {};
  const totalAmount = orderDraft.items.reduce((sum, item) => sum + (Number(item.price || 0) * Number(item.qty || 1)), 0);

  const payload = {
    customer: {
      first_name: form.first_name || '',
      last_name: form.last_name || '',
      phone: form.phone || '',
      email: form.email || '',
    },
    order: {
      status: 'confirmed',
      payment_status: paymentStatus,
      currency: orderDraft.payment?.currency || 'UAH',
      source,
    },
    items: orderDraft.items.map((item) => ({
      product_id: item.product_id || item.id || null,
      title: item.title || '',
      sku: item.sku || '',
      qty: Number(item.qty || 1),
      price: Number(item.price || 0),
    })),
    payment: {
      method: paymentMethod,
      prepay_amount: paymentMethod === 'prepay' ? prepayAmount : 0,
      currency: orderDraft.payment?.currency || 'UAH',
    },
    delivery: {
      carrier: delivery.carrier || 'nova_poshta',
      delivery_type: delivery.delivery_type || 'warehouse',
      payer: delivery.payer || 'recipient',
      city_ref: delivery.city_ref || '',
      city_name: delivery.city_name || '',
      warehouse_ref: delivery.warehouse_ref || '',
      warehouse_name: delivery.warehouse_name || '',
      street_name: delivery.street_name || '',
      building: delivery.building || '',
      apartment: delivery.apartment || '',
      recipient_name: [delivery.last_name, delivery.first_name, delivery.middle_name].filter(Boolean).join(' '),
      recipient_phone: delivery.phone || form.phone || '',
    },
  };

  try {
    const response = await axios.post('/orders', payload);
    const order = response?.data?.data || {};
    showToast('Замовлення створено!');
    orderSubmitState.status = 'success';
    orderSubmitState.orderId = order.id || null;
    orderSubmitState.orderNumber = order.order_number || order.id || null;
    orderSubmitState.totalAmount = totalAmount;
  } catch (e) {
    console.error(e);
    showToast('Не вдалося створити замовлення.', 'error');
    orderSubmitState.status = 'idle';
  } finally {
    isOrderSaving.value = false;
  }
};

const handleOrderMinimize = () => {
  showOrderPanel.value = false;
};

const handleOrderClose = () => {
  orderSubmitState.status = 'idle';
  orderSubmitState.orderId = null;
  orderSubmitState.orderNumber = null;
  orderSubmitState.totalAmount = 0;
  if (orderDraft.items.length > 0) {
    const confirmed = window.confirm('Видалити чернетку замовлення?');
    if (!confirmed) return;
  }
  resetOrderDraft();
  showOrderPanel.value = false;
};
</script>

<style scoped>
.right-sidebar { width: 100%; height: 100%; background: #ffffff; border-left: 1px solid #edf2f7; display: flex; flex-direction: column; position: relative; overflow: hidden; }
.profile-content { padding: 16px; }

/* TOAST STYLES */
.toast-notification {
  position: absolute; top: 10px; left: 10%; right: 10%; z-index: 100;
  padding: 10px 15px; border-radius: 10px; display: flex; align-items: center; gap: 10px;
  font-size: 13px; font-weight: 600; color: white; box-shadow: 0 5px 15px rgba(0,0,0,0.15);
}
.toast-notification.success { background: #10b981; }
.toast-notification.error { background: #ef4444; }
.toast-enter-active, .toast-leave-active { transition: all 0.4s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(-20px); }

/* HEADER */
.header-section { display: flex; align-items: flex-start; gap: 12px; }
.avatar-wrap { position: relative; width: 52px; height: 52px; flex-shrink: 0; }
.avatar-img, .avatar-placeholder { width: 100%; height: 100%; border-radius: 12px; object-fit: cover; }
.avatar-placeholder { background: #edf2f7; color: #a0aec0; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 20px; }

.platform-icon-indicator {
  position: absolute; bottom: -4px; right: -4px; width: 22px; height: 22px;
  border-radius: 50%; display: flex; align-items: center; justify-content: center;
  border: 2px solid #fff; transition: all 0.4s ease; color: white;
}
.ig-bg { background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fd5949 45%, #d6249f 60%, #285AEB 90%); }
.fb-bg { background: #0084FF; }
.glow-red { box-shadow: 0 0 10px #ef4444; border-color: #ef4444; }
.glow-green { box-shadow: 0 0 10px #10b981; border-color: #10b981; }
.platform-icon-indicator i { font-size: 11px; }

.info { flex: 1; min-width: 0; padding-top: 2px; }
.name-display-wrapper { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
.name-text { font-size: 15px; font-weight: 700; color: #1a202c; }
.text-error { color: #ef4444; }

.btn-status-indicator {
  background: none; border: none; cursor: pointer; transition: all 0.4s ease;
  font-size: 24px; padding: 0; margin-left: auto; display: flex; align-items: center;
}
.status-attention { color: #ef4444; filter: drop-shadow(0 0 5px rgba(239, 68, 68, 0.4)); }
.status-ready { color: #10b981; filter: drop-shadow(0 0 5px rgba(16, 185, 129, 0.4)); }

.btn-edit-purple {
  background: #a78bfa; color: white; border: none;
  width: 30px; height: 30px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; cursor: pointer; transition: all 0.3s ease; margin-left: 10px;
}
.btn-edit-purple:hover { background: #8b5cf6; transform: scale(1.1); }

.name-edit-flow { display: flex; align-items: center; gap: 8px; }
.inputs-stack { display: flex; flex-direction: column; gap: 4px; flex: 1; }
.modern-input { border: none; border-bottom: 1.5px solid #e2e8f0; font-size: 13px; font-weight: 600; outline: none; transition: 0.3s; padding: 2px 0; }
.modern-input:focus { border-color: #6366f1; }
.btn-confirm-tick { background: #6366f1; color: white; border: none; width: 26px; height: 26px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; }

.id-badge { font-size: 11px; color: #a0aec0; margin-top: 4px; display: inline-block; background: #f7fafc; padding: 2px 6px; border-radius: 4px; }
.divider { border: 0; border-top: 1px solid #f3f4f6; margin: 16px 0; }

.fields-section { display: flex; flex-direction: column; gap: 12px; }
.field-row { display: flex; align-items: flex-start; }
.icon-col { width: 32px; color: #cbd5e0; font-size: 18px; padding-top: 18px; }
.input-col label { font-size: 10px; font-weight: 700; color: #a0aec0; text-transform: uppercase; margin-bottom: 2px; display: block; }
.input-group { display: flex; align-items: center; border-bottom: 2px solid #edf2f7; padding: 2px 0; }
.simple-input { flex: 1; border: none; background: transparent; font-size: 14px; color: #2d3748; outline: none; font-weight: 600; }
.error-text { color: #ef4444; font-size: 10px; margin-top: 2px; }
.add-btn { color: #6366f1; font-size: 13px; font-weight: 600; cursor: pointer; padding: 4px 0; }

.action-row { display: flex; flex-direction: column; gap: 10px; margin-top: 8px; }

.customer-history { margin-top: 18px; display: flex; flex-direction: column; gap: 10px; }
.history-title { font-size: 11px; font-weight: 800; color: #94a3b8; letter-spacing: 0.08em; text-transform: uppercase; display: flex; align-items: center; gap: 8px; }
.history-loading { font-size: 11px; color: #a0aec0; font-weight: 600; }
.history-empty { font-size: 12px; color: #94a3b8; padding: 8px 0; }
.history-card { background: #fff; border: 1px solid #f1f5f9; border-radius: 14px; overflow: hidden; transition: all 0.3s ease; }
.history-header { width: 100%; text-align: left; background: transparent; border: none; padding: 12px 14px; cursor: pointer; display: flex; flex-direction: column; gap: 6px; }
.history-main { display: flex; align-items: center; gap: 8px; }
.order-number { font-weight: 800; color: #1e293b; text-decoration: none; }
.order-number:hover { text-decoration: underline; }
.order-status { font-size: 10px; font-weight: 800; color: #0f172a; padding: 2px 6px; border-radius: 6px; }
.history-meta { display: flex; align-items: center; gap: 10px; color: #64748b; font-size: 12px; }
.order-total { font-weight: 800; color: #0f172a; }
.order-date { font-size: 11px; color: #94a3b8; }
.history-body { max-height: 0; overflow: hidden; transition: max-height 0.3s ease; padding: 0 14px; }
.history-body.open { max-height: 600px; padding-bottom: 12px; }
.history-section { margin-top: 10px; }
.history-label { font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px; }
.history-text { font-size: 12px; color: #475569; }
.history-items { display: flex; flex-direction: column; gap: 8px; margin-top: 6px; }
.history-item { display: flex; align-items: center; gap: 8px; }
.history-thumb { width: 36px; height: 36px; border-radius: 8px; object-fit: cover; background: #f8fafc; }
.history-item-title { font-size: 12px; font-weight: 700; color: #1e293b; }
.history-item-qty { font-size: 11px; color: #94a3b8; }
.history-link { display: inline-flex; margin-top: 10px; font-size: 12px; font-weight: 700; color: #6366f1; text-decoration: none; }
.history-link:hover { text-decoration: underline; }

.profile-mobile-header {
  display: none;
  margin-bottom: 12px;
}

.profile-back-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  color: #334155;
  border-radius: 10px;
  height: 40px;
  padding: 0 12px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}

/* КНОПКА ЗБЕРЕЖЕННЯ */
.btn-save-modern {
  background: #A78BFB; 
  color: white;
  border: none;
  border-radius: 8px;
  height: 40px; 
  display: flex; 
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 600;
  width: 100%;
  cursor: pointer;
  transition: all 0.3s ease;
}
.btn-save-modern:hover:not(:disabled) {
  background: #9061f9; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(167, 139, 251, 0.3);
}
.btn-save-modern:disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; box-shadow: none; }

/* НОВА КНОПКА: СТВОРИТИ ЗАМОВЛЕННЯ */
.btn-create-order {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: #1f2937;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  width: 100%;
  transition: 0.2s;
}
.btn-create-order:hover {
  background: #f8fafc;
  border-color: #cbd5e0;
}

@media (max-width: 768px) {
  .profile-content {
    padding: 14px 16px 24px;
  }

  .profile-mobile-header {
    display: flex;
  }

  .btn-save-modern,
  .btn-create-order {
    height: 48px;
  }
}

.spinner { width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #cbd5e0; gap: 8px; }
</style>
