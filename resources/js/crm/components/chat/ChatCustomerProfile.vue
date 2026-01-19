<template>
  <div class="right-sidebar">
    <transition name="toast-slide">
      <div v-if="toast.show" class="toast-notification" :class="toast.type">
        <div class="toast-icon">
          <i class="bi" :class="toast.type === 'success' ? 'bi-check-lg' : 'bi-exclamation-lg'"></i>
        </div>
        <span>{{ toast.message }}</span>
      </div>
    </transition>

    <div v-if="customerId" class="profile-container custom-scrollbar" ref="profileContainer">
      <!-- ... (Header, Fields - без змін) ... -->
      <div class="profile-mobile-header">
        <button class="btn-icon-back" type="button" @click="emit('close')">
          <i class="bi bi-chevron-left"></i>
        </button>
        <span class="mobile-title">Профіль клієнта</span>
      </div>
      
      <div class="profile-header">
        <div class="avatar-section">
          <div class="avatar-ring" :class="[isInstagram ? 'ring-ig' : 'ring-fb', isProfileComplete ? 'status-ok' : 'status-warn']">
            <img v-if="avatarUrl" :src="avatarUrl" class="avatar-img">
            <div v-else class="avatar-placeholder">{{ displayInitial }}</div>
            <div class="platform-badge" :class="isInstagram ? 'bg-ig' : 'bg-fb'">
              <i class="bi" :class="isInstagram ? 'bi-instagram' : 'bi-messenger'"></i>
            </div>
          </div>
        </div>
        
        <div class="info-section">
          <div v-if="!showNameInput" class="name-display" @click="enableNameEdit">
            <h2 class="customer-name" :class="{ 'text-incomplete': !isNameValid }">{{ displayName }}</h2>
            <i class="bi bi-pencil-fill edit-icon"></i>
          </div>
          <div v-else class="name-edit-group animate-fade">
            <input v-model="form.first_name" class="modern-input" placeholder="Ім'я" auto-focus>
            <input v-model="form.last_name" class="modern-input" placeholder="Прізвище">
            <button class="btn-confirm-mini" type="button" @click="showNameInput = false"><i class="bi bi-check2"></i></button>
          </div>
          <div class="meta-row">
            <span class="id-pill">ID: {{ customer.fb_user_id || customer.instagram_user_id || customerId }}</span>
            <span class="status-pill" :class="isProfileComplete ? 'pill-success' : 'pill-warning'">{{ isProfileComplete ? 'Заповнено' : 'Не заповнено' }}</span>
          </div>
        </div>
      </div>

      <div class="section-card">
        <div class="section-title">Контакти</div>
        <div class="field-item">
          <div class="field-icon"><i class="bi bi-telephone"></i></div>
          <div class="field-content">
            <label>Телефон</label>
            <div v-if="form.phone || showPhoneInput" class="input-wrapper" :class="{ 'error': form.phone && !isPhoneValid }">
              <input v-model="form.phone" class="field-input" placeholder="380..." ref="phoneRef" type="tel" @blur="phoneFocused = false">
              <button v-if="form.phone" class="btn-clear-field" @click="clearPhone"><i class="bi bi-x-circle-fill"></i></button>
            </div>
            <button v-else class="btn-add-field" @click="enablePhone"><span>+ Додати номер</span></button>
            <div v-if="form.phone && !isPhoneValid" class="field-error">Формат: 380XXXXXXXXX (12 цифр)</div>
          </div>
        </div>
        <div class="field-item">
          <div class="field-icon"><i class="bi bi-envelope"></i></div>
          <div class="field-content">
            <label>Email</label>
            <div v-if="form.email || showEmailInput" class="input-wrapper">
              <input v-model="form.email" class="field-input" placeholder="mail@example.com">
              <button v-if="form.email" class="btn-clear-field" @click="clearEmail"><i class="bi bi-x-circle-fill"></i></button>
            </div>
            <button v-else class="btn-add-field" @click="enableEmail"><span>+ Додати email</span></button>
          </div>
        </div>
        <div class="actions-grid">
          <button class="btn-secondary" @click="saveData" :disabled="isLoading || !isProfileComplete">
            <span v-if="isLoading" class="loader-ring"></span>
            <span v-else>Зберегти</span>
          </button>
          <button class="btn-primary-gradient" type="button" @click="showOrderPanel = true">
            <i class="bi bi-plus-lg"></i>
            <span>Нове замовлення</span>
          </button>
        </div>
      </div>

      <!-- ІСТОРІЯ ЗАМОВЛЕНЬ (ОНОВЛЕНА) -->
      <div class="history-container">
        <div class="section-header">
          <span class="section-title">Історія замовлень</span>
          <span v-if="historyOrders.length" class="counter-badge">{{ historyOrders.length }}</span>
        </div>

        <div v-if="historyLoading" class="loading-state">
          <div class="skeleton-line"></div>
          <div class="skeleton-line w-75"></div>
        </div>

        <div v-else-if="!historyOrders.length" class="empty-history">
          <div class="empty-icon"><i class="bi bi-clock-history"></i></div>
          <span>Історія замовлень порожня</span>
        </div>

        <div v-else class="orders-list">
          <div v-for="order in historyOrders" :key="order.id" class="order-card" :class="{ 'is-open': order.isOpen }" :ref="el => { if (el) orderRefs[order.id] = el }">
            <button class="order-header" type="button" @click="toggleOrder(order.id)">
              <div class="order-main-info">
                <div class="order-top-row">
                   <div class="order-id">#{{ order.order_number || order.id }}</div>
                   <div class="order-date">{{ formatDate(order.created_at) }}</div>
                </div>
                
                <div class="order-status-row">
                   <div class="order-status-badge" :style="{ 
                      backgroundColor: (order.statusRef?.color || '#e2e8f0') + '20', 
                      color: order.statusRef?.color || '#64748b' 
                    }">
                      {{ getStatusLabel(order) }}
                   </div>
                </div>
              </div>

              <div class="order-meta-info">
                <!-- ОНОВЛЕНИЙ ВИВІД ЦІНИ -->
                <span class="order-price">{{ formatPrice(order.items_sum_total) }} <small>грн.</small></span>
                <i class="bi bi-chevron-down toggle-icon"></i>
              </div>
            </button>

            <div class="order-details-wrapper">
              <div class="order-details">
                <div class="detail-row">
                  <div class="detail-label"><i class="bi bi-geo-alt"></i> Доставка</div>
                  <div class="detail-value">
                    {{ order.delivery?.city_name || '—' }}
                    <div v-if="order.delivery?.warehouse_name" class="sub-text">{{ order.delivery?.warehouse_name }}</div>
                  </div>
                </div>
                
                <div v-if="order.delivery?.ttn" class="detail-row">
                  <div class="detail-label"><i class="bi bi-upc-scan"></i> ТТН</div>
                  <div class="detail-value font-mono">{{ order.delivery.ttn }}</div>
                </div>

                <div class="products-list">
                  <div v-for="item in order.items || []" :key="item.id" class="product-thumb-item">
                    <img 
                      :src="item.product?.main_photo_path ? `/storage/${item.product.main_photo_path}` : placeholderThumb" 
                      class="prod-img"
                    >
                    <div class="prod-info">
                      <div class="prod-name">{{ item.product_title || 'Товар' }}</div>
                      <div class="prod-qty">x{{ item.qty }}</div>
                    </div>
                  </div>
                </div>

                <a :href="`/orders/${order.id}`" class="btn-full-details">
                  Детальніше про замовлення <i class="bi bi-arrow-right"></i>
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- (Empty State та ChatOrderPanel - без змін) -->
    <div v-else class="empty-state-container">
      <div class="empty-illustration"><i class="bi bi-chat-square-text"></i></div>
      <h3>Виберіть чат</h3>
      <p>Оберіть діалог зі списку, щоб побачити профіль клієнта</p>
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

// ... (всі змінні стану залишаються без змін) ...
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
const profileContainer = ref(null); // Ref для контейнера прокрутки
const orderRefs = reactive({}); // Ref для карток замовлень

const showOrderPanel = ref(false);
const orderDraft = reactive({ items: [], delivery: { first_name: '', last_name: '', middle_name: '', phone: '', delivery_type: 'warehouse', carrier: 'nova_poshta', city_name: '', city_ref: '', warehouse_name: '', warehouse_ref: '', street_name: '', building: '', apartment: '', payer: 'recipient' }, payment: { method: '', prepay_amount: 0, currency: 'UAH' } });

// ... (resetOrderDraft, toast, orderSubmitState, form, validation, watchers - без змін) ...
function resetOrderDraft() {
  orderDraft.items = [];
  orderDraft.delivery = { first_name: '', last_name: '', middle_name: '', phone: '', delivery_type: 'warehouse', carrier: 'nova_poshta', city_name: '', city_ref: '', warehouse_name: '', warehouse_ref: '', street_name: '', building: '', apartment: '', payer: 'recipient' };
  orderDraft.payment = { method: '', prepay_amount: 0, currency: 'UAH' };
}
const toast = reactive({ show: false, message: '', type: 'success' });
const orderSubmitState = reactive({ status: 'idle', orderId: null, orderNumber: null, totalAmount: 0 });
const form = reactive({ first_name: '', last_name: '', phone: '', email: '' });
const cyrillicRegex = /^[А-Яа-яЁёЇїІіЄєҐґ' \-]+$/;
const isNameValid = computed(() => form.first_name.trim().length >= 2 && form.last_name.trim().length >= 2 && cyrillicRegex.test(form.first_name) && cyrillicRegex.test(form.last_name));
const isPhoneValid = computed(() => /^380\d{9}$/.test(form.phone));
const isProfileComplete = computed(() => isNameValid.value && isPhoneValid.value);

watch(() => form.phone, (newVal) => {
  if (!newVal) return;
  let cleaned = newVal.replace(/\D/g, '');
  if (cleaned.length > 0 && !cleaned.startsWith('38')) cleaned = '380' + cleaned;
  form.phone = cleaned.substring(0, 12);
});

const customerId = computed(() => props.customer?.id ?? props.customer?.customer_id ?? null);
const displayName = computed(() => { const name = `${form.first_name} ${form.last_name}`.trim(); return name || props.customer?.customer_name || 'Не заповнено'; });
const displayInitial = computed(() => (displayName.value ? displayName.value[0].toUpperCase() : '?'));
const avatarUrl = computed(() => props.customer?.fb_profile_pic || props.customer?.customer_avatar || '');
const isInstagram = computed(() => (props.customer?.source || props.customer?.platform) === 'instagram' || !!props.customer?.instagram_user_id);

watch(() => props.customer, (newVal) => {
  if (newVal) {
    form.first_name = newVal.first_name || ''; form.last_name = newVal.last_name || '';
    form.phone = newVal.phone ? newVal.phone.replace(/\D/g, '') : '';
    form.email = newVal.email || '';
    showPhoneInput.value = !!form.phone; showEmailInput.value = !!form.email;
    showNameInput.value = false; showOrderPanel.value = false;
    resetOrderDraft();
    if (customerId.value) loadCustomerHistory(customerId.value);
  }
}, { immediate: true });
watch(customerId, (id) => { if (id) loadCustomerHistory(id); });

const showToast = (msg, type = 'success') => { toast.message = msg; toast.type = type; toast.show = true; setTimeout(() => { toast.show = false; }, 3000); };
const enableNameEdit = () => { showNameInput.value = true; };
const enablePhone = async () => { showPhoneInput.value = true; if (!form.phone) form.phone = '380'; await nextTick(); phoneRef.value?.focus(); };
const clearPhone = () => { form.phone = ''; showPhoneInput.value = false; };
const enableEmail = async () => { showEmailInput.value = true; await nextTick(); };
const clearEmail = () => { form.email = ''; showEmailInput.value = false; };

const loadCustomerHistory = async (id) => {
  historyLoading.value = true;
  try {
    const { data } = await axios.get(`/customers/${id}`);
    historyOrders.value = (data?.data?.recent_orders || []).map((order) => ({ ...order, isOpen: false }));
  } catch (e) { console.error(e); historyOrders.value = []; } finally { historyLoading.value = false; }
};

// --- ОНОВЛЕНА ЛОГІКА ТОГЛА З АВТО-СКРОЛОМ ---
const toggleOrder = (orderId) => {
  const target = historyOrders.value.find((order) => order.id === orderId);
  if (target) {
    target.isOpen = !target.isOpen;
    if (target.isOpen) {
      nextTick(() => {
        const el = orderRefs[orderId];
        if (el && profileContainer.value) {
          // Плавно скролимо до елемента з невеликим відступом
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      });
    }
  }
};

// --- ОНОВЛЕНЕ ФОРМАТУВАННЯ ЦІНИ (БЕЗ КОПІЙОК І ЗАЙВИХ НУЛІВ) ---
const formatPrice = (value) => {
  const num = Number(value);
  if (isNaN(num)) return '0';
  // Видаляє дробову частину, якщо вона дорівнює нулю (.00)
  return new Intl.NumberFormat('uk-UA', { 
    minimumFractionDigits: 0, 
    maximumFractionDigits: 2 
  }).format(num).replace(/\.00$/, ''); 
};

const formatDate = (value) => { if (!value) return '—'; return new Date(value).toLocaleDateString('uk-UA', { year: 'numeric', month: '2-digit', day: '2-digit' }); };
const getStatusLabel = (order) => order?.statusRef?.name || '—';

// ... (решта методів збереження без змін) ...
const saveData = async () => {
  if (!customerId.value || !isProfileComplete.value) return;
  isLoading.value = true;
  try {
    const response = await axios.put(`/api/customers/${customerId.value}`, form);
    const updatedCustomer = response?.data?.data;
    if (props.customer && updatedCustomer) Object.assign(props.customer, updatedCustomer);
    else if (props.customer) Object.assign(props.customer, form);
    showNameInput.value = false; showToast('Збережено!');
  } catch (e) { console.error(e); showToast('Помилка збереження', 'error'); } finally { isLoading.value = false; }
};
const handleOrderSubmit = () => createOrderFromDraft();
const handleOrderSuccessClose = () => { orderSubmitState.status = 'idle'; orderSubmitState.orderId = null; resetOrderDraft(); showOrderPanel.value = false; };
const createOrderFromDraft = async () => {
  if (!orderDraft.items.length) { showToast('Додайте товари', 'error'); return; }
  if (!orderDraft.payment?.method) { showToast('Оберіть оплату', 'error'); return; }
  if (!orderDraft.delivery?.city_ref) { showToast('Оберіть місто', 'error'); return; }
  if (isOrderSaving.value) return;
  isOrderSaving.value = true; orderSubmitState.status = 'loading';
  const paymentMethod = orderDraft.payment?.method || 'cod'; const prepayAmount = Number(orderDraft.payment?.prepay_amount || 0); const paymentStatus = paymentMethod === 'card' ? 'paid' : 'unpaid';
  const rawSource = (props.customer?.source || props.customer?.platform || '').toLowerCase(); const source = rawSource.includes('instagram') || rawSource === 'ig' || isInstagram.value ? 'instagram' : 'facebook';
  const delivery = orderDraft.delivery || {}; const totalAmount = orderDraft.items.reduce((sum, item) => sum + (Number(item.price || 0) * Number(item.qty || 1)), 0);
  const payload = {
    customer: { first_name: form.first_name, last_name: form.last_name, phone: form.phone, email: form.email },
    order: { status: 'confirmed', payment_status: paymentStatus, currency: 'UAH', source },
    items: orderDraft.items.map((item) => ({ product_id: item.product_id || item.id, qty: Number(item.qty || 1), price: Number(item.price || 0) })),
    payment: { method: paymentMethod, prepay_amount: paymentMethod === 'prepay' ? prepayAmount : 0, currency: 'UAH' },
    delivery: { ...delivery, recipient_name: [delivery.last_name, delivery.first_name].join(' '), recipient_phone: delivery.phone || form.phone }
  };
  try {
    const response = await axios.post('/orders', payload); const order = response?.data?.data || {};
    showToast('Замовлення створено!'); orderSubmitState.status = 'success'; orderSubmitState.orderId = order.id; orderSubmitState.orderNumber = order.order_number; orderSubmitState.totalAmount = totalAmount;
  } catch (e) { console.error(e); showToast('Помилка', 'error'); orderSubmitState.status = 'idle'; } finally { isOrderSaving.value = false; }
};
const handleOrderMinimize = () => { showOrderPanel.value = false; };
const handleOrderClose = () => { if (orderDraft.items.length > 0 && !confirm('Видалити чернетку?')) return; resetOrderDraft(); showOrderPanel.value = false; };
</script>

<style scoped>
/* Core Layout & General Styles - без змін */
.right-sidebar { width: 100%; height: 100%; background: #f8fafc; border-left: 1px solid #e2e8f0; display: flex; flex-direction: column; position: relative; overflow: hidden; font-family: 'Inter', -apple-system, sans-serif; color: #334155; }
.profile-container { flex: 1; overflow-y: auto; padding: 24px; scroll-behavior: smooth; }
.custom-scrollbar::-webkit-scrollbar { width: 5px; } .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; } .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }

/* ... (Header, Avatar, Info, Fields - залишаються без змін) ... */
.profile-mobile-header { display: none; align-items: center; gap: 12px; margin-bottom: 20px; } .btn-icon-back { background: white; border: 1px solid #e2e8f0; border-radius: 8px; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; color: #64748b; } .profile-header { display: flex; gap: 20px; margin-bottom: 24px; background: white; padding: 20px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -1px rgba(0, 0, 0, 0.02); border: 1px solid #f1f5f9; } .avatar-section { flex-shrink: 0; } .avatar-ring { width: 72px; height: 72px; border-radius: 50%; padding: 3px; position: relative; border: 2px solid transparent; } .avatar-ring.ring-ig { background: linear-gradient(white, white) padding-box, linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888) border-box; } .avatar-ring.ring-fb { background: linear-gradient(white, white) padding-box, linear-gradient(45deg, #1877f2, #0099ff) border-box; } .avatar-img, .avatar-placeholder { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; background: #f1f5f9; } .avatar-placeholder { display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 700; color: #94a3b8; } .platform-badge { position: absolute; bottom: 0; right: 0; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; border: 2px solid white; font-size: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); } .bg-ig { background: linear-gradient(45deg, #f09433, #bc1888); } .bg-fb { background: #1877f2; } .info-section { flex: 1; display: flex; flex-direction: column; justify-content: center; gap: 8px; } .name-display { display: flex; align-items: center; gap: 8px; cursor: pointer; } .customer-name { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0; line-height: 1.2; } .text-incomplete { color: #f59e0b; } .edit-icon { font-size: 12px; color: #cbd5e1; transition: 0.2s; } .name-display:hover .edit-icon { color: #6366f1; } .name-edit-group { display: flex; gap: 8px; } .modern-input { border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 8px; padding: 6px 10px; font-size: 14px; width: 100%; outline: none; transition: 0.2s; } .modern-input:focus { border-color: #6366f1; background: white; box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.1); } .btn-confirm-mini { background: #10b981; color: white; border: none; width: 32px; border-radius: 8px; flex-shrink: 0; cursor: pointer; } .meta-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; } .id-pill { font-size: 11px; color: #94a3b8; background: #f1f5f9; padding: 2px 8px; border-radius: 12px; font-family: monospace; } .status-pill { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px; } .pill-success { background: #dcfce7; color: #166534; } .pill-warning { background: #fef3c7; color: #92400e; } .section-card { background: white; border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border: 1px solid #f1f5f9; margin-bottom: 24px; } .section-title { font-size: 13px; font-weight: 700; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.05em; margin-bottom: 16px; display: block; } .field-item { display: flex; gap: 12px; margin-bottom: 16px; } .field-icon { width: 36px; height: 36px; background: #f8fafc; color: #64748b; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; } .field-content { flex: 1; } .field-content label { font-size: 11px; color: #64748b; font-weight: 600; display: block; margin-bottom: 4px; } .input-wrapper { position: relative; } .field-input { width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; font-size: 14px; font-weight: 500; color: #0f172a; outline: none; transition: 0.2s; } .field-input:focus { border-color: #6366f1; } .input-wrapper.error .field-input { border-color: #ef4444; background: #fef2f2; } .btn-clear-field { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); border: none; background: none; color: #cbd5e1; cursor: pointer; transition: 0.2s; } .btn-clear-field:hover { color: #ef4444; } .btn-add-field { border: 1px dashed #cbd5e1; background: #f8fafc; width: 100%; padding: 8px; border-radius: 8px; color: #6366f1; font-size: 13px; font-weight: 500; cursor: pointer; transition: 0.2s; } .btn-add-field:hover { border-color: #6366f1; background: #eef2ff; } .field-error { font-size: 11px; color: #ef4444; margin-top: 4px; } .actions-grid { display: grid; grid-template-columns: 1fr 1.5fr; gap: 12px; margin-top: 24px; } .btn-secondary { background: white; border: 1px solid #cbd5e1; color: #475569; border-radius: 10px; padding: 10px; font-weight: 600; cursor: pointer; transition: 0.2s; } .btn-secondary:hover:not(:disabled) { background: #f8fafc; border-color: #94a3b8; } .btn-primary-gradient { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; border: none; border-radius: 10px; padding: 10px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.3); transition: 0.2s; } .btn-primary-gradient:hover { transform: translateY(-1px); box-shadow: 0 6px 8px -1px rgba(99, 102, 241, 0.4); }

/* --- HISTORY SECTION UPDATED --- */
.history-container { margin-top: 20px; }
.section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.counter-badge { background: #e2e8f0; color: #475569; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px; }

.order-card {
  background: white; border: 1px solid #f1f5f9; border-radius: 12px;
  margin-bottom: 12px; overflow: hidden; transition: all 0.3s ease;
  scroll-margin-top: 20px; /* Відступ для авто-скролу */
}
.order-card.is-open { border-color: #c7d2fe; box-shadow: 0 4px 12px rgba(199, 210, 254, 0.2); }

.order-header {
  padding: 12px 16px; cursor: pointer; display: flex; justify-content: space-between; align-items: flex-start;
  background: white; border: none; width: 100%; text-align: left;
}

.order-main-info { display: flex; flex-direction: column; gap: 6px; }

.order-top-row { display: flex; align-items: baseline; gap: 8px; }
.order-id { font-weight: 700; color: #0f172a; font-size: 14px; }
.order-date { font-size: 11px; color: #94a3b8; }

.order-status-badge {
  font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 6px; width: fit-content;
  text-transform: uppercase;
}

.order-meta-info { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.order-price { font-weight: 800; color: #0f172a; font-size: 15px; }
.order-price small { font-size: 11px; color: #64748b; font-weight: 600; margin-left: 2px; }
.toggle-icon { font-size: 12px; color: #cbd5e1; transition: transform 0.3s; margin-top: 4px; }
.is-open .toggle-icon { transform: rotate(180deg); color: #6366f1; }

.order-details-wrapper {
  max-height: 0; overflow: hidden; transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  background: #f8fafc; border-top: 1px solid #f1f5f9;
}
.is-open .order-details-wrapper { max-height: 600px; }
.order-details { padding: 14px; display: flex; flex-direction: column; gap: 12px; }

/* ... (інші стилі деталей без змін) ... */
.detail-row { font-size: 13px; color: #334155; }
.detail-label { color: #94a3b8; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
.detail-value { font-weight: 500; }
.sub-text { font-size: 12px; color: #64748b; margin-top: 2px; }
.font-mono { font-family: monospace; letter-spacing: 0.5px; }

.product-thumb-item { display: flex; gap: 10px; align-items: center; background: white; padding: 8px; border-radius: 8px; border: 1px solid #e2e8f0; }
.prod-img { width: 32px; height: 32px; border-radius: 4px; object-fit: cover; }
.prod-name { font-size: 12px; font-weight: 600; color: #1e293b; line-height: 1.2; flex: 1; }
.prod-qty { font-size: 11px; color: #64748b; font-weight: 600; }

.btn-full-details { display: flex; align-items: center; justify-content: center; gap: 8px; background: white; border: 1px solid #cbd5e1; color: #4f46e5; font-size: 12px; font-weight: 600; padding: 8px; border-radius: 8px; text-decoration: none; transition: 0.2s; margin-top: 8px; }
.btn-full-details:hover { border-color: #6366f1; background: #eef2ff; }

/* ... (Toast, Empty State, Loader, Media - без змін) ... */
.toast-notification { position: absolute; top: 16px; left: 50%; transform: translateX(-50%); z-index: 100; display: flex; align-items: center; gap: 12px; padding: 10px 16px; border-radius: 50px; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(8px); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); border: 1px solid rgba(255,255,255,0.2); font-size: 13px; font-weight: 600; color: #1e293b; min-width: 200px; } .toast-icon { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; flex-shrink: 0; } .toast-notification.success .toast-icon { background: #10b981; } .toast-notification.error .toast-icon { background: #ef4444; } .toast-slide-enter-active, .toast-slide-leave-active { transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); } .toast-slide-enter-from, .toast-slide-leave-to { opacity: 0; transform: translate(-50%, -20px); }
.empty-state-container { height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #94a3b8; text-align: center; padding: 40px; } .empty-illustration { font-size: 48px; margin-bottom: 16px; opacity: 0.5; } .empty-state-container h3 { font-size: 18px; font-weight: 700; color: #475569; margin-bottom: 8px; } .empty-state-container p { font-size: 14px; max-width: 250px; line-height: 1.5; }
.loader-ring { width: 16px; height: 16px; border: 2px solid #cbd5e1; border-top-color: #475569; border-radius: 50%; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (max-width: 768px) { .profile-mobile-header { display: flex; } .profile-container { padding: 16px; } .actions-grid { grid-template-columns: 1fr; } }
</style>