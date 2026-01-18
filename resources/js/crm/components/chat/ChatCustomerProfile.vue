<template>
  <div class="right-sidebar">
    <transition name="toast">
      <div v-if="toast.show" class="toast-notification" :class="toast.type">
        <div class="toast-content">
          <i class="bi" :class="toast.type === 'success' ? 'bi-check2-circle' : 'bi-exclamation-octagon'"></i>
          <span>{{ toast.message }}</span>
        </div>
      </div>
    </transition>

    <div v-if="customerId" class="profile-container">
      
      <div class="profile-view">
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
              <button class="btn-edit-purple"><i class="bi bi-pencil-fill"></i></button>
            </div>

            <div v-else class="name-edit-flow">
              <div class="inputs-stack">
                <input v-model="form.first_name" class="modern-input" placeholder="Ім'я">
                <input v-model="form.last_name" class="modern-input" placeholder="Прізвище">
              </div>
              <button class="btn-confirm-tick" @click="showNameInput = false"><i class="bi bi-check2"></i></button>
            </div>
            
            <div class="id-badge">ID: {{ customer.fb_user_id || customer.instagram_user_id || customerId }}</div>
          </div>

          <div class="status-indicator-box" :class="isProfileComplete ? 'ready' : 'pending'">
            <i class="bi" :class="isProfileComplete ? 'bi-person-check-fill' : 'bi-person-x-fill'"></i>
          </div>
        </div>

        <hr class="divider" />

        <div class="fields-section">
          <div class="field-row">
            <div class="icon-col"><i class="bi bi-telephone"></i></div>
            <div class="input-col">
              <label>Телефон</label>
              <div v-if="form.phone || showPhoneInput" class="input-group" :class="{ 'is-focused': phoneFocused, 'is-invalid': form.phone && !isPhoneValid }">
                <input v-model="form.phone" class="simple-input" placeholder="380XXXXXXXXX" ref="phoneRef" type="tel" @focus="phoneFocused = true" @blur="phoneFocused = false">
                <button class="btn-clear" @click="clearPhone"><i class="bi bi-x"></i></button>
              </div>
              <div v-else class="add-btn" @click="enablePhone"><i class="bi bi-plus-lg"></i> Додати телефон</div>
              <small v-if="form.phone && !isPhoneValid" class="error-text">Має бути 12 цифр</small>
            </div>
          </div>

          <div class="field-row">
            <div class="icon-col"><i class="bi bi-envelope"></i></div>
            <div class="input-col">
              <label>E-mail</label>
              <div v-if="form.email || showEmailInput" class="input-group" :class="{ 'is-focused': emailFocused }">
                <input v-model="form.email" class="simple-input" placeholder="email@example.com" @focus="emailFocused = true" @blur="emailFocused = false">
                <button class="btn-clear" @click="clearEmail"><i class="bi bi-x"></i></button>
              </div>
              <div v-else class="add-btn" @click="enableEmail"><i class="bi bi-plus-lg"></i> Додати email</div>
            </div>
          </div>

          <div class="action-row">
            <button class="btn-save-modern" @click="saveData" :disabled="isLoading || !isProfileComplete">
              <span v-if="isLoading" class="spinner"></span>
              {{ isLoading ? 'Зберігаємо...' : 'Зберегти покупця' }}
            </button>
            
            <button class="btn-create-order" type="button" @click="viewMode = 'create_order'">
              <div class="icon-bg"><i class="bi bi-bag-plus-fill"></i></div>
              <span>Створити замовлення</span>
              <i class="bi bi-chevron-right arrow-icon"></i>
            </button>
          </div>
        </div>
      </div>

      <teleport to="body">
        <transition name="fade">
          <div v-if="viewMode === 'create_order'" class="offcanvas-backdrop" @click="viewMode = 'profile'"></div>
        </transition>
        
        <transition name="slide-global">
          <div v-if="viewMode === 'create_order'" class="order-offcanvas-global">
            
            <div class="order-header">
              <button class="btn-back" @click="viewMode = 'profile'">
                <i class="bi bi-x-lg"></i> </button>
              <div class="order-title">Нове замовлення</div>
              <div class="order-steps">
                 <span class="step-badge">{{ orderDraft.items.length }}</span>
              </div>
            </div>

            <div class="order-scroll-content">
              
              <section class="order-card">
                <div class="card-header">
                  <i class="bi bi-box-seam"></i> Товари
                </div>
                
                <div class="search-wrapper">
                  <i class="bi bi-search search-icon"></i>
                  <input v-model="productSearch" type="text" class="search-input" placeholder="Пошук товару...">
                  <span v-if="productLoading" class="mini-spinner"></span>
                  
                  <div v-if="productResults.length" class="search-dropdown">
                    <div v-for="product in productResults" :key="product.id" class="search-item" @click="addProduct(product)">
                      <div class="s-info">
                        <div class="s-title">{{ product.title }}</div>
                        <div class="s-sku">{{ product.sku || 'No SKU' }}</div>
                      </div>
                      <div class="s-price">{{ formatMoney(getProductPrice(product)) }} ₴</div>
                    </div>
                  </div>
                </div>

                <div v-if="orderDraft.items.length" class="selected-items">
                  <div v-for="(item, index) in orderDraft.items" :key="item.key" class="item-card">
                    <div class="item-top">
                      <span class="item-name">{{ item.title }}</span>
                      <button class="btn-remove-item" @click="removeItem(index)"><i class="bi bi-trash3"></i></button>
                    </div>
                    <div class="item-bottom">
                      <div class="qty-control">
                        <button @click="item.qty > 1 ? item.qty-- : null">-</button>
                        <span>{{ item.qty }}</span>
                        <button @click="item.qty++">+</button>
                      </div>
                      <div class="price-edit">
                        <input v-model.number="item.price" type="number" class="price-input-mini">
                        <span class="currency">₴</span>
                      </div>
                      <div class="item-total-display">
                        {{ formatMoney(itemTotal(item)) }} ₴
                      </div>
                    </div>
                  </div>
                </div>
                <div v-else class="empty-placeholder">
                  <i class="bi bi-basket3"></i>
                  <span>Кошик порожній</span>
                </div>

                <div class="total-summary" v-if="orderDraft.items.length">
                  <span>Разом:</span>
                  <span class="sum-value">{{ formatMoney(itemsTotal) }} ₴</span>
                </div>
              </section>

              <section class="order-card">
                <div class="card-header"><i class="bi bi-truck"></i> Доставка</div>
                
                <div class="segmented-control">
                  <button :class="{ active: orderDraft.delivery.carrier === 'nova_poshta' }" @click="setCarrier('nova_poshta')">НП</button>
                  <button :class="{ active: orderDraft.delivery.carrier === 'ukrposhta' }" @click="setCarrier('ukrposhta')">Укрпошта</button>
                  <button :class="{ active: orderDraft.delivery.carrier === 'self_pickup' }" @click="setCarrier('self_pickup')">Самовивіз</button>
                </div>

                <div v-if="orderDraft.delivery.carrier !== 'self_pickup'" class="delivery-form">
                  <div class="form-group">
                    <label>Місто</label>
                    <div class="autocomplete-wrap">
                      <input v-model="cityQuery" class="form-input" placeholder="Введіть місто...">
                      <div v-if="cityOptions.length" class="dropdown-options">
                        <div v-for="city in cityOptions" :key="city.Ref" @click="selectCity(city)">{{ city.Description }}</div>
                      </div>
                    </div>
                  </div>
                  <div class="form-group" v-if="orderDraft.delivery.city_ref">
                    <label>Відділення</label>
                    <div class="autocomplete-wrap">
                      <input v-model="warehouseQuery" class="form-input" placeholder="Номер або адреса...">
                      <div v-if="warehouseOptions.length" class="dropdown-options">
                        <div v-for="wh in warehouseOptions" :key="wh.Ref" @click="selectWarehouse(wh)">{{ wh.Description }}</div>
                      </div>
                    </div>
                  </div>
                </div>
                <div v-else class="form-group">
                   <label>Коментар до самовивозу</label>
                   <input v-model="orderDraft.delivery.address_note" class="form-input" placeholder="Коли забере?">
                </div>
              </section>

              <section class="order-card">
                <div class="card-header"><i class="bi bi-credit-card"></i> Оплата</div>
                <div class="payment-grid">
                  <select v-model="orderDraft.payment.method" class="form-select">
                    <option value="cod">Накладений платіж</option>
                    <option value="card">На карту</option>
                    <option value="iban">IBAN</option>
                  </select>
                  <select v-model="orderDraft.payment.status" class="form-select" :class="orderDraft.payment.status">
                    <option value="unpaid">Не оплачено</option>
                    <option value="paid">Оплачено</option>
                  </select>
                </div>
              </section>

               <section class="order-card">
                  <textarea v-model="orderDraft.comment" class="form-textarea" placeholder="Внутрішній коментар до замовлення..."></textarea>
               </section>

               <div style="height: 80px;"></div>
            </div>

            <div class="order-footer">
              <button class="btn-submit-order" @click="saveOrder" :disabled="isSavingOrder || !canSaveOrder">
                <span v-if="!isSavingOrder">Створити замовлення</span>
                <span v-else>Зберігаємо...</span>
                <span v-if="!isSavingOrder" class="price-tag">{{ formatMoney(itemsTotal) }} ₴</span>
              </button>
            </div>
          </div>
        </transition>
      </teleport>

    </div>

    <div v-else class="empty-state">
      <i class="bi bi-person-bounding-box"></i>
      <p>Виберіть чат</p>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, watch, nextTick, computed } from 'vue';
import axios from 'axios';
import { createOrder } from '@/crm/api/orders';
import { searchProducts } from '@/crm/api/products';
import { fetchCities, fetchWarehouses } from '@/crm/api/novaPoshta';

const props = defineProps({ customer: Object });

// --- STATES ---
const showNameInput = ref(false);
const phoneFocused = ref(false);
const emailFocused = ref(false);
const isLoading = ref(false);
const showPhoneInput = ref(false);
const showEmailInput = ref(false);
const phoneRef = ref(null);
const viewMode = ref('profile'); 
const isSavingOrder = ref(false);

const productSearch = ref('');
const productResults = ref([]);
const productLoading = ref(false);
let productTimer = null;

const cityQuery = ref('');
const warehouseQuery = ref('');
const cityOptions = ref([]);
const warehouseOptions = ref([]);
const skipCityFetch = ref(false);
const skipWarehouseFetch = ref(false);
let cityTimer = null;
let warehouseTimer = null;

const toast = reactive({ show: false, message: '', type: 'success' });

const form = reactive({ 
  first_name: '',
  last_name: '',
  phone: '', 
  email: '' 
});

const orderDraft = reactive({
  items: [],
  delivery: {
    carrier: 'nova_poshta',
    delivery_type: 'warehouse',
    city_ref: '', city_name: '',
    warehouse_ref: '', warehouse_name: '',
    address_note: '', recipient_name: '', recipient_phone: ''
  },
  payment: { method: 'cod', status: 'unpaid' },
  comment: ''
});

// --- COMPUTED & LOGIC ---
const cyrillicRegex = /^[А-Яа-яЁёЇїІіЄєҐґ' \-]+$/;
const isNameValid = computed(() => form.first_name.trim().length >= 2 && form.last_name.trim().length >= 2 && cyrillicRegex.test(form.first_name));
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
    viewMode.value = 'profile';
    resetOrderDraft();
  }
}, { immediate: true });

// --- PRODUCT SEARCH ---
watch(productSearch, (query) => {
  const term = query.trim();
  if (productTimer) clearTimeout(productTimer);
  if (!term) { productResults.value = []; return; }
  productTimer = setTimeout(async () => {
    productLoading.value = true;
    try {
      const { data } = await searchProducts(term);
      productResults.value = Array.isArray(data) ? data : (data?.data || []);
    } catch (e) { console.error(e); productResults.value = []; } 
    finally { productLoading.value = false; }
  }, 250);
});

// --- NOVA POSHTA LOGIC ---
watch(cityQuery, (query) => {
  if (orderDraft.delivery.carrier === 'self_pickup' || skipCityFetch.value) { skipCityFetch.value = false; return; }
  const term = query.trim();
  if (cityTimer) clearTimeout(cityTimer);
  if (term.length < 2) { cityOptions.value = []; return; }
  cityTimer = setTimeout(async () => {
    try {
      const { data } = await fetchCities(term);
      cityOptions.value = data?.data || [];
    } catch (e) { cityOptions.value = []; }
  }, 300);
});

watch(warehouseQuery, (query) => {
  if (!orderDraft.delivery.city_ref || skipWarehouseFetch.value) { skipWarehouseFetch.value = false; return; }
  const term = query.trim();
  if (warehouseTimer) clearTimeout(warehouseTimer);
  if (term.length < 1) { warehouseOptions.value = []; return; }
  warehouseTimer = setTimeout(async () => {
    try {
      const { data } = await fetchWarehouses({ cityRef: orderDraft.delivery.city_ref, query: term });
      warehouseOptions.value = data?.data || [];
    } catch (e) { warehouseOptions.value = []; }
  }, 300);
});

// --- UTILS ---
const showToast = (msg, type = 'success') => {
  toast.message = msg; toast.type = type; toast.show = true;
  setTimeout(() => { toast.show = false; }, 3000);
};

const enableNameEdit = () => { showNameInput.value = true; };
const enablePhone = async () => { showPhoneInput.value = true; if (!form.phone) form.phone = '380'; await nextTick(); phoneRef.value?.focus(); };
const clearPhone = () => { form.phone = ''; showPhoneInput.value = false; };
const enableEmail = async () => { showEmailInput.value = true; await nextTick(); };
const clearEmail = () => { form.email = ''; showEmailInput.value = false; };

const formatMoney = (value) => Number(value || 0).toFixed(2);
const getProductPrice = (product) => Number(product?.sale_price ?? product?.price ?? product?.cost_price ?? 0);
const itemTotal = (item) => (Number(item.qty) || 0) * (Number(item.price) || 0);
const itemsTotal = computed(() => orderDraft.items.reduce((sum, item) => sum + itemTotal(item), 0));
const canSaveOrder = computed(() => orderDraft.items.length > 0);

// --- ORDER ACTIONS ---
const addProduct = (product) => {
  const existing = orderDraft.items.find((item) => item.product_id === product.id);
  if (existing) {
    existing.qty = Number(existing.qty || 0) + 1;
  } else {
    orderDraft.items.push({
      key: `${product.id}-${Date.now()}`,
      product_id: product.id,
      title: product.title || 'Товар',
      sku: product.sku || '',
      qty: 1,
      price: getProductPrice(product)
    });
  }
  productSearch.value = '';
  productResults.value = [];
};

const removeItem = (index) => { orderDraft.items.splice(index, 1); };

const setCarrier = (carrier) => {
  orderDraft.delivery.carrier = carrier;
  orderDraft.delivery.delivery_type = carrier === 'self_pickup' ? 'self_pickup' : 'warehouse';
  orderDraft.delivery.city_ref = ''; cityQuery.value = '';
  orderDraft.delivery.warehouse_ref = ''; warehouseQuery.value = '';
};

const selectCity = (city) => {
  orderDraft.delivery.city_ref = city.Ref;
  orderDraft.delivery.city_name = city.Description;
  skipCityFetch.value = true;
  cityQuery.value = city.Description;
  cityOptions.value = [];
};

const selectWarehouse = (wh) => {
  orderDraft.delivery.warehouse_ref = wh.Ref;
  orderDraft.delivery.warehouse_name = wh.Description;
  skipWarehouseFetch.value = true;
  warehouseQuery.value = wh.Description;
  warehouseOptions.value = [];
};

function resetOrderDraft() {
  orderDraft.items = [];
  orderDraft.delivery = { carrier: 'nova_poshta', delivery_type: 'warehouse', city_ref: '', city_name: '', warehouse_ref: '', warehouse_name: '', address_note: '' };
  orderDraft.payment = { method: 'cod', status: 'unpaid' };
  orderDraft.comment = '';
}

const saveData = async () => {
  if (!customerId.value || !isProfileComplete.value) return;
  isLoading.value = true;
  try {
    const response = await axios.put(`/api/customers/${customerId.value}`, form);
    const updatedCustomer = response?.data?.data;
    if (props.customer && updatedCustomer) Object.assign(props.customer, updatedCustomer);
    else if (props.customer) Object.assign(props.customer, form);
    showNameInput.value = false;
    showToast('Дані покупця успішно збережено!');
  } catch (e) { showToast('Помилка сервера.', 'error'); } 
  finally { isLoading.value = false; }
};

const saveOrder = async () => {
  if (!canSaveOrder.value) { showToast('Додайте хоча б один товар.', 'error'); return; }
  isSavingOrder.value = true;
  try {
    const payload = {
      customer: { ...form },
      order: { currency: 'UAH', source: 'chat', status: 'new', payment_status: orderDraft.payment.status, comment_internal: orderDraft.comment },
      items: orderDraft.items.map(item => ({ product_id: item.product_id, qty: item.qty, price: item.price })),
      payment: { method: orderDraft.payment.method, prepay_amount: 0, currency: 'UAH' },
      delivery: { ...orderDraft.delivery },
      tag_ids: []
    };
    await createOrder(payload);
    showToast('Замовлення створено успішно!');
    resetOrderDraft();
    viewMode.value = 'profile';
  } catch (e) { showToast('Помилка збереження замовлення.', 'error'); } 
  finally { isSavingOrder.value = false; }
};
</script>

<style scoped>
/* GLOBAL & LAYOUT */
.right-sidebar { width: 100%; height: 100%; background: #fff; border-left: 1px solid #edf2f7; display: flex; flex-direction: column; position: relative; font-family: 'Inter', sans-serif; }
.profile-container { height: 100%; position: relative; }
.profile-view { padding: 16px; height: 100%; display: flex; flex-direction: column; }

/* HEADER PROFILE */
.header-section { display: flex; align-items: flex-start; gap: 12px; }
.avatar-wrap { position: relative; width: 56px; height: 56px; flex-shrink: 0; }
.avatar-img, .avatar-placeholder { width: 100%; height: 100%; border-radius: 14px; object-fit: cover; }
.avatar-placeholder { background: #f1f5f9; color: #94a3b8; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 22px; }
.platform-icon-indicator { position: absolute; bottom: -4px; right: -4px; width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #fff; color: white; }
.ig-bg { background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fd5949 45%, #d6249f 60%, #285AEB 90%); }
.fb-bg { background: #0084FF; }
.glow-red { box-shadow: 0 0 10px #ef4444; border-color: #ef4444; }
.glow-green { box-shadow: 0 0 10px #10b981; border-color: #10b981; }

.info { flex: 1; min-width: 0; padding-top: 2px; }
.name-text { font-size: 16px; font-weight: 700; color: #1e293b; }
.text-error { color: #ef4444; }
.btn-edit-purple { background: #f5f3ff; color: #8b5cf6; border: none; width: 28px; height: 28px; border-radius: 8px; margin-left: 8px; cursor: pointer; }
.inputs-stack { display: flex; flex-direction: column; gap: 4px; }
.modern-input { border: none; border-bottom: 2px solid #f1f5f9; font-size: 14px; font-weight: 600; padding: 4px 0; outline: none; }
.modern-input:focus { border-color: #8b5cf6; }
.id-badge { font-size: 11px; color: #94a3b8; background: #f8fafc; padding: 2px 6px; border-radius: 4px; display: inline-block; margin-top: 4px; }

.status-indicator-box { margin-left: auto; font-size: 24px; }
.ready { color: #10b981; }
.pending { color: #fca5a5; }

/* FIELDS */
.divider { border: 0; border-top: 1px solid #f1f5f9; margin: 16px 0; }
.fields-section { display: flex; flex-direction: column; gap: 14px; }
.field-row { display: flex; gap: 12px; }
.icon-col { width: 32px; color: #cbd5e0; font-size: 18px; padding-top: 18px; text-align: center; }
.input-col label { font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; display: block; margin-bottom: 4px; }
.input-group { display: flex; align-items: center; border-bottom: 2px solid #edf2f7; padding: 4px 0; }
.simple-input { flex: 1; border: none; font-size: 14px; font-weight: 600; color: #334155; outline: none; background: transparent; }
.btn-clear { background: none; border: none; color: #94a3b8; cursor: pointer; }
.add-btn { color: #8b5cf6; font-size: 13px; font-weight: 600; cursor: pointer; padding: 6px 0; }

.action-row { display: flex; flex-direction: column; gap: 10px; margin-top: 10px; }
.btn-save-modern { background: #A78BFB; color: white; border: none; border-radius: 10px; height: 40px; font-size: 14px; font-weight: 600; width: 100%; cursor: pointer; transition: 0.2s; }
.btn-save-modern:hover { background: #9061f9; }

.btn-create-order {
  background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; height: 56px;
  display: flex; align-items: center; gap: 12px; padding: 0 16px; width: 100%; cursor: pointer; transition: 0.2s;
  box-shadow: 0 2px 5px rgba(0,0,0,0.03);
}
.btn-create-order:hover { border-color: #A78BFB; background: #fcfaff; transform: translateY(-1px); }
.btn-create-order .icon-bg { width: 32px; height: 32px; background: #f5f3ff; color: #8b5cf6; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
.btn-create-order span { flex: 1; text-align: left; font-size: 14px; font-weight: 700; color: #1e293b; }
.btn-create-order .arrow-icon { color: #cbd5e0; font-size: 14px; }

/* === OFFCANVAS ORDER GLOBAL === */
.offcanvas-backdrop {
  position: fixed; inset: 0; background: rgba(0,0,0,0.3); z-index: 1000;
  backdrop-filter: blur(2px);
}
.order-offcanvas-global {
  position: fixed; top: 0; right: 0; 
  width: 450px; /* Ширина вікна, можна змінити на 500px якщо треба */
  height: 100vh;
  background: #f8fafc; z-index: 1001;
  display: flex; flex-direction: column;
  box-shadow: -10px 0 30px rgba(0,0,0,0.15);
}

/* Анімація появи глобальна */
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.slide-global-enter-active, .slide-global-leave-active { transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-global-enter-from, .slide-global-leave-to { transform: translateX(100%); }

.order-header {
  background: #fff; padding: 12px 16px; border-bottom: 1px solid #e2e8f0;
  display: flex; align-items: center; gap: 12px; height: 60px; flex-shrink: 0;
}
.btn-back { background: #f1f5f9; border-radius: 50%; width: 32px; height: 32px; border: none; font-size: 16px; color: #64748b; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.btn-back:hover { background: #e2e8f0; color: #1e293b; }
.order-title { font-size: 16px; font-weight: 800; color: #0f172a; flex: 1; }
.step-badge { background: #A78BFB; color: white; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 700; }

.order-scroll-content { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 16px; }

/* CARD STYLE */
.order-card { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
.card-header { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }

/* SEARCH */
.search-wrapper { position: relative; margin-bottom: 10px; }
.search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
.search-input {
  width: 100%; border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 8px;
  padding: 10px 10px 10px 34px; font-size: 13px; outline: none; transition: 0.2s;
}
.search-input:focus { border-color: #A78BFB; background: #fff; }
.search-dropdown {
  position: absolute; top: 105%; left: 0; width: 100%; background: #fff; border: 1px solid #e2e8f0;
  border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); z-index: 50; max-height: 200px; overflow-y: auto;
}
.search-item { padding: 8px 12px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
.search-item:hover { background: #f8fafc; }
.s-title { font-size: 13px; font-weight: 600; color: #1e293b; }
.s-sku { font-size: 11px; color: #94a3b8; }
.s-price { font-weight: 700; color: #A78BFB; font-size: 13px; }

/* SELECTED ITEMS */
.item-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; margin-bottom: 8px; }
.item-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
.item-name { font-size: 13px; font-weight: 600; color: #334155; line-height: 1.3; }
.btn-remove-item { background: none; border: none; color: #ef4444; cursor: pointer; opacity: 0.6; }
.btn-remove-item:hover { opacity: 1; }
.item-bottom { display: flex; align-items: center; justify-content: space-between; }
.qty-control { display: flex; align-items: center; gap: 4px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 2px; }
.qty-control button { width: 24px; height: 24px; background: #fff; border: none; font-weight: 700; cursor: pointer; border-radius: 4px; }
.qty-control button:hover { background: #f1f5f9; }
.qty-control span { font-size: 13px; font-weight: 600; width: 20px; text-align: center; }
.price-edit { display: flex; align-items: center; gap: 2px; }
.price-input-mini { width: 60px; border: none; background: transparent; text-align: right; font-weight: 600; font-size: 13px; border-bottom: 1px dashed #cbd5e0; }
.item-total-display { font-size: 13px; font-weight: 700; color: #1e293b; }

.empty-placeholder { text-align: center; padding: 20px; color: #94a3b8; font-size: 13px; display: flex; flex-direction: column; gap: 6px; align-items: center; }
.total-summary { display: flex; justify-content: space-between; align-items: center; font-size: 14px; font-weight: 700; color: #0f172a; border-top: 1px dashed #e2e8f0; padding-top: 10px; margin-top: 5px; }
.sum-value { color: #A78BFB; font-size: 16px; }

/* DELIVERY TABS */
.segmented-control { display: flex; background: #f1f5f9; padding: 3px; border-radius: 8px; margin-bottom: 12px; }
.segmented-control button { flex: 1; border: none; background: transparent; padding: 6px; font-size: 12px; font-weight: 600; color: #64748b; border-radius: 6px; cursor: pointer; transition: 0.2s; }
.segmented-control button.active { background: #fff; color: #A78BFB; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
.form-group { margin-bottom: 10px; }
.form-group label { font-size: 11px; font-weight: 700; color: #94a3b8; display: block; margin-bottom: 4px; }
.form-input, .form-select, .form-textarea { width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px; font-size: 13px; outline: none; background: #fff; }
.form-input:focus, .form-select:focus, .form-textarea:focus { border-color: #A78BFB; }
.autocomplete-wrap { position: relative; }
.dropdown-options { position: absolute; top: 100%; width: 100%; background: #fff; border: 1px solid #e2e8f0; z-index: 10; max-height: 150px; overflow-y: auto; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-radius: 6px; }
.dropdown-options div { padding: 8px; font-size: 12px; cursor: pointer; }
.dropdown-options div:hover { background: #f8fafc; }

/* FOOTER */
.order-footer {
  position: sticky; bottom: 0; background: #fff; border-top: 1px solid #e2e8f0;
  padding: 16px; box-shadow: 0 -4px 10px rgba(0,0,0,0.03);
}
.btn-submit-order {
  width: 100%; height: 48px; background: #1e293b; color: white; border: none; border-radius: 12px;
  font-size: 15px; font-weight: 700; display: flex; justify-content: space-between; align-items: center; padding: 0 20px;
  cursor: pointer; transition: 0.2s;
}
.btn-submit-order:hover { background: #0f172a; transform: translateY(-1px); }
.btn-submit-order:disabled { background: #cbd5e1; cursor: not-allowed; }
.price-tag { background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 6px; font-size: 13px; }

/* TOAST */
.toast-notification { position: absolute; top: 20px; left: 20px; right: 20px; z-index: 1000; background: rgba(16, 185, 129, 0.95); padding: 12px; border-radius: 10px; color: white; display: flex; justify-content: center; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
.toast-content { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 14px; }
.toast-notification.error { background: rgba(239, 68, 68, 0.95); }
.mini-spinner { width: 14px; height: 14px; border: 2px solid #cbd5e0; border-top-color: #8b5cf6; border-radius: 50%; animation: spin 0.8s linear infinite; position: absolute; right: 10px; top: 12px; }
.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #cbd5e0; gap: 8px; }
</style>