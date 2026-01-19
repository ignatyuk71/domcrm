<template>
  <div class="right-sidebar">
    <transition name="toast">
      <div v-if="toast.show" class="toast-notification" :class="toast.type">
        <i class="bi" :class="toast.type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'"></i>
        <span>{{ toast.message }}</span>
      </div>
    </transition>

    <div v-if="customerId" class="profile-content custom-scrollbar" ref="profileContainer">
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
          
          <!-- ОНОВЛЕНА КНОПКА: Блокується і збільшена -->
          <button 
            class="btn-create-order" 
            type="button" 
            @click="showOrderPanel = true"
            :disabled="!isProfileComplete"
            :title="!isProfileComplete ? 'Спочатку заповніть дані клієнта' : 'Створити замовлення'"
          >
            <i class="bi bi-bag-plus"></i>
             ЗАМОВЛЕННЯ
          </button>
        </div>

        <div class="history-container">
          <div class="section-header">
            <span class="section-title">Історія замовлень</span>
            <span v-if="historyLoading" class="loader-mini"></span>
            <span v-else-if="historyOrders.length" class="counter-badge">{{ historyOrders.length }}</span>
          </div>

          <div v-if="!historyOrders.length && !historyLoading" class="empty-history">
            <div class="empty-icon"><i class="bi bi-box-seam"></i></div>
            <span>Замовлень ще немає</span>
          </div>

          <div v-else class="orders-list">
            <div 
              v-for="order in historyOrders" 
              :key="order.id" 
              class="order-card" 
              :class="{ 'is-active': order.isOpen }"
              :ref="el => { if(el) orderRefs[order.id] = el }"
            >
              <button class="order-header" type="button" @click="toggleOrder(order.id)">
                <div class="header-left">
                  <div class="order-id-row">
                    <span class="id-text">#{{ order.order_number || order.id }}</span>
                    <span class="date-text">{{ formatDate(order.created_at) }}</span>
                  </div>
                  
                  <div 
                    class="status-badge" 
                    :style="{ 
                      backgroundColor: (getStatusRef(order)?.color || '#94a3b8') + '20', 
                      color: getStatusRef(order)?.color || '#64748b' 
                    }"
                  >
                    <i v-if="getStatusRef(order)?.icon" class="bi status-icon" :class="getStatusRef(order).icon"></i>
                    {{ getStatusLabel(order) }}
                  </div>
                </div>

                <div class="header-right">
                  <div class="price-tag">{{ formatPrice(order.items_sum_total) }} <small>грн.</small></div>
                  <div class="toggle-btn">
                    <i class="bi bi-chevron-down"></i>
                  </div>
                </div>
              </button>

              <div class="order-body-wrapper">
                <div class="order-body">
                  
                  <div class="info-block">
                    <div class="block-label"><i class="bi bi-truck"></i> Доставка</div>
                    <div class="block-content">
                      <div class="delivery-dest">
                        {{ order.delivery?.city_name || 'Самовивіз/Не вказано' }}
                        <div v-if="order.delivery?.warehouse_name" class="sub-text">
                          {{ order.delivery?.warehouse_name }}
                        </div>
                      </div>
                      
                      <div v-if="order.delivery?.ttn" class="ttn-row">
                        <span class="ttn-label">ТТН:</span>
                        <span class="ttn-code">{{ order.delivery.ttn }}</span>
                        <i class="bi bi-copy copy-icon" title="Скопіювати"></i>
                      </div>
                    </div>
                  </div>

                  <div class="info-block">
                    <div class="block-label"><i class="bi bi-bag"></i> Товари</div>
                    <div class="products-stack">
                      <div v-for="item in order.items || []" :key="item.id" class="mini-product">
                        <img
                          class="mini-thumb"
                          :src="item.product?.main_photo_path ? `/storage/${item.product.main_photo_path}` : placeholderThumb"
                          loading="lazy"
                        />
                        <div class="mini-info">
                          <div class="mini-title">{{ item.product_title || 'Товар без назви' }}</div>
                          <div class="mini-meta">
                            <span class="qty">x{{ item.qty }}</span>
                            <span class="price" v-if="item.price">{{ formatPrice(item.price) }} грн.</span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <a :href="`/orders/${order.id}`" class="btn-full-order">
                    <span>Детальніше</span>
                    <i class="bi bi-arrow-right"></i>
                  </a>
                </div>
              </div>
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

// Refs для скролу
const profileContainer = ref(null);
const orderRefs = reactive({});

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
  if (target) {
    target.isOpen = !target.isOpen;
    if (target.isOpen) {
      nextTick(() => {
        const el = orderRefs[orderId];
        if (el && profileContainer.value) {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      });
    }
  }
};

const formatDate = (value) => {
  if (!value) return '—';
  const date = new Date(value);
  return date.toLocaleDateString('uk-UA', { year: 'numeric', month: '2-digit', day: '2-digit' });
};

const formatPrice = (value) => {
  const num = Number(value);
  if (isNaN(num)) return '0';
  return new Intl.NumberFormat('uk-UA', { 
    minimumFractionDigits: 0, 
    maximumFractionDigits: 2 
  }).format(num).replace(/\.00$/, ''); 
};
const formatMoney = formatPrice;

const getStatusRef = (order) => {
  return order?.statusRef || order?.status_ref || null;
};

const getStatusLabel = (order) => {
  return getStatusRef(order)?.name || '—';
};

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
.right-sidebar { width: 100%; height: 100%; background: #ffffff; border-left: 1px solid #edf2f7; display: flex; flex-direction: column; position: relative; overflow: hidden; font-family: 'Inter', sans-serif; color: #334155; }
.profile-content { flex: 1; overflow-y: auto; padding: 16px; scroll-behavior: smooth; }
.custom-scrollbar::-webkit-scrollbar { width: 6px; } .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; } .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }

/* ... (Інші стилі залишаються без змін) ... */
.header-section { display: flex; align-items: flex-start; gap: 12px; }
.avatar-wrap { position: relative; width: 52px; height: 52px; flex-shrink: 0; }
.avatar-img, .avatar-placeholder { width: 100%; height: 100%; border-radius: 12px; object-fit: cover; background: #f1f5f9; }
.avatar-placeholder { display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 700; color: #94a3b8; }
.platform-icon-indicator { position: absolute; bottom: -4px; right: -4px; width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #fff; transition: all 0.4s ease; color: white; font-size: 11px; }
.ig-bg { background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fd5949 45%, #d6249f 60%, #285AEB 90%); }
.fb-bg { background: #0084FF; }
.glow-red { box-shadow: 0 0 10px #ef4444; border-color: #ef4444; }
.glow-green { box-shadow: 0 0 10px #10b981; border-color: #10b981; }
.platform-icon-indicator i { font-size: 11px; }
.info { flex: 1; min-width: 0; padding-top: 2px; }
.name-display-wrapper { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
.name-text { font-size: 15px; font-weight: 700; color: #1a202c; }
.text-error { color: #ef4444; }
.btn-status-indicator { background: none; border: none; cursor: pointer; transition: all 0.4s ease; font-size: 24px; padding: 0; margin-left: auto; display: flex; align-items: center; }
.status-attention { color: #ef4444; filter: drop-shadow(0 0 5px rgba(239, 68, 68, 0.4)); }
.status-ready { color: #10b981; filter: drop-shadow(0 0 5px rgba(16, 185, 129, 0.4)); }
.btn-edit-purple { background: #a78bfa; color: white; border: none; width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; cursor: pointer; transition: all 0.3s ease; margin-left: 10px; }
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
.history-container { margin-top: 24px; }
.section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; padding: 0 4px; }
.section-title { font-size: 12px; font-weight: 800; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.05em; }
.counter-badge { background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px; }
.empty-history { text-align: center; padding: 24px; border: 1px dashed #e2e8f0; border-radius: 12px; color: #94a3b8; }
.empty-icon { font-size: 24px; margin-bottom: 8px; opacity: 0.5; }
.orders-list { display: flex; flex-direction: column; gap: 12px; }
.order-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; transition: all 0.2s ease; }
.order-card.is-active { border-color: #cbd5e1; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
.order-header { width: 100%; display: flex; justify-content: space-between; align-items: flex-start; padding: 12px; background: white; border: none; cursor: pointer; text-align: left; }
.header-left { display: flex; flex-direction: column; gap: 6px; }
.order-id-row { display: flex; align-items: center; gap: 8px; }
.id-text { font-size: 14px; font-weight: 700; color: #0f172a; }
.date-text { font-size: 11px; color: #94a3b8; }
.status-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 6px; width: fit-content; }
.status-icon { font-size: 10px; }
.header-right { display: flex; align-items: center; gap: 12px; }
.price-tag { font-size: 14px; font-weight: 700; color: #0f172a; white-space: nowrap; display: flex; align-items: baseline; gap: 2px; }
.price-tag small { font-size: 11px; font-weight: 600; color: #64748b; }
.toggle-btn { color: #cbd5e1; transition: transform 0.3s ease; font-size: 12px; }
.order-card.is-active .toggle-btn { transform: rotate(180deg); color: #64748b; }
.order-body-wrapper { max-height: 0; overflow: hidden; transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1); background: #f8fafc; }
.order-card.is-active .order-body-wrapper { max-height: 500px; border-top: 1px solid #f1f5f9; }
.order-body { padding: 12px; display: flex; flex-direction: column; gap: 16px; }
.info-block { display: flex; flex-direction: column; gap: 6px; }
.block-label { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; display: flex; align-items: center; gap: 6px; }
.block-content { font-size: 13px; color: #334155; background: white; padding: 8px; border-radius: 8px; border: 1px solid #f1f5f9; }
.sub-text { font-size: 11px; color: #64748b; margin-top: 2px; line-height: 1.3; }
.ttn-row { margin-top: 6px; padding-top: 6px; border-top: 1px dashed #e2e8f0; display: flex; align-items: center; gap: 6px; font-size: 12px; }
.ttn-code { font-family: monospace; font-weight: 600; color: #0f172a; background: #f1f5f9; padding: 1px 4px; border-radius: 4px; }
.copy-icon { color: #94a3b8; cursor: pointer; font-size: 12px; }
.copy-icon:hover { color: #6366f1; }
.products-stack { display: flex; flex-direction: column; gap: 8px; }
.mini-product { display: flex; align-items: center; gap: 10px; background: white; padding: 6px; border-radius: 8px; border: 1px solid #f1f5f9; }
.mini-thumb { width: 32px; height: 32px; border-radius: 6px; object-fit: cover; background: #f8fafc; border: 1px solid #e2e8f0; }
.mini-info { flex: 1; min-width: 0; }
.mini-title { font-size: 12px; font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mini-meta { display: flex; justify-content: space-between; font-size: 11px; color: #64748b; margin-top: 1px; }
.price { font-weight: 600; color: #475569; }
.btn-full-order { display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; padding: 8px; background: white; border: 1px solid #cbd5e1; border-radius: 8px; color: #4f46e5; font-size: 12px; font-weight: 600; text-decoration: none; transition: all 0.2s; }
.btn-full-order:hover { background: #eef2ff; border-color: #6366f1; }

.profile-mobile-header { display: none; margin-bottom: 12px; }
.profile-back-btn { display: inline-flex; align-items: center; gap: 8px; border: 1px solid #e2e8f0; background: #f8fafc; color: #334155; border-radius: 10px; height: 40px; padding: 0 12px; font-size: 14px; font-weight: 600; cursor: pointer; }

.btn-save-modern {
  background: #A78BFB; 
  color: white;
  border: none;
  border-radius: 8px;
  height: 60px; /* Збільшено з 40px до 60px */
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
.btn-save-modern:disabled { 
  background: #e2e8f0; 
  color: #94a3b8; 
  cursor: not-allowed; 
  box-shadow: none; 
}

/* ОНОВЛЕНО: Блокування і збільшення кнопки "Створити замовлення" */
.btn-create-order {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  height: 60px; /* Збільшено з 40px до 60px */
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
.btn-create-order:hover:not(:disabled) {
  background: #f8fafc;
  border-color: #cbd5e0;
}
.btn-create-order:disabled {
  background: #f8fafc;
  color: #cbd5e0;
  border-color: #e2e8f0;
  cursor: not-allowed;
  opacity: 0.7;
}

@media (max-width: 768px) {
  .profile-content {
    padding: 14px 16px 24px;
  }

  .profile-mobile-header {
    display: flex;
  }
}

.spinner { width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #cbd5e0; gap: 8px; }
</style>