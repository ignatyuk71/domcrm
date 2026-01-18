<template>
  <div class="right-sidebar">
    <transition name="toast">
      <div v-if="toast.show" class="toast-notification" :class="toast.type">
        <i class="bi" :class="toast.type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'"></i>
        <span>{{ toast.message }}</span>
      </div>
    </transition>

    <div v-if="customerId" class="profile-content">
      <div v-if="viewMode === 'profile'" class="profile-view">
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
            <button class="btn-edit-purple">
              <i class="bi bi-pencil-square"></i>
            </button>
          </div>

          <div v-else class="name-edit-flow">
            <div class="inputs-stack">
              <input v-model="form.first_name" class="modern-input" placeholder="Ім'я (кирилиця)">
              <input v-model="form.last_name" class="modern-input" placeholder="Прізвище (кирилиця)">
            </div>
            <button class="btn-confirm-tick" @click="showNameInput = false">
              <i class="bi bi-check2"></i>
            </button>
          </div>
          
          <div class="id-badge">ID: {{ customer.fb_user_id || customer.instagram_user_id || customerId }}</div>
        </div>

        <button 
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
            </div>
            <div v-else class="add-btn" @click="enableEmail"><i class="bi bi-plus-circle"></i> Додати email</div>
          </div>
        </div>

        <div class="action-row">
          <button class="btn-save-modern" @click="saveData" :disabled="isLoading || !isProfileComplete">
            <span v-if="isLoading" class="spinner"></span>
            {{ isLoading ? 'Зберігаємо...' : 'Зберегти покупця' }}
          </button>
          <button class="btn-create-order" type="button" @click="viewMode = 'create_order'">
            <i class="bi bi-bag-plus"></i>
            Створити замовлення
          </button>
        </div>
      </div>

      </div>

      <div v-else class="order-view">
        <div class="order-header">
          <button class="btn-back" type="button" @click="viewMode = 'profile'">
            <i class="bi bi-arrow-left"></i>
          </button>
          <div class="order-title">Швидке замовлення</div>
        </div>

        <div class="order-scroll">
          <section class="order-section">
            <div class="section-title">Товари</div>
            <div class="search-box">
              <i class="bi bi-search"></i>
              <input v-model="productSearch" type="text" placeholder="Пошук за назвою або артикулом">
              <span v-if="productLoading" class="mini-spinner"></span>
            </div>

            <div v-if="productResults.length" class="search-results">
              <button
                v-for="product in productResults"
                :key="product.id"
                type="button"
                class="result-item"
                @click="addProduct(product)"
              >
                <div class="result-main">
                  <div class="result-title">{{ product.title || 'Товар' }}</div>
                  <div class="result-meta">{{ product.sku || 'NO-SKU' }}</div>
                </div>
                <div class="result-price">{{ formatMoney(getProductPrice(product)) }} грн</div>
              </button>
            </div>

            <div v-if="!orderDraft.items.length" class="empty-products">
              <i class="bi bi-basket"></i>
              <span>Додайте товари до замовлення</span>
            </div>
            <div v-else class="items-list">
              <div v-for="(item, index) in orderDraft.items" :key="item.key" class="item-row">
                <div class="item-info">
                  <div class="item-title">{{ item.title }}</div>
                  <div class="item-sku">{{ item.sku || 'NO-SKU' }}</div>
                </div>
                <div class="item-controls">
                  <input v-model.number="item.qty" type="number" min="1" class="qty-input">
                  <input v-model.number="item.price" type="number" min="0" step="0.01" class="price-input">
                  <div class="item-sum">{{ formatMoney(itemTotal(item)) }} грн</div>
                  <button class="btn-remove" type="button" @click="removeItem(index)">
                    <i class="bi bi-x-lg"></i>
                  </button>
                </div>
              </div>
            </div>

            <div class="total-row">
              <span>Загальна сума</span>
              <strong>{{ formatMoney(itemsTotal) }} грн</strong>
            </div>
          </section>

          <section class="order-section">
            <div class="section-title">Доставка</div>
            <div class="delivery-toggle">
              <button
                type="button"
                class="toggle-btn"
                :class="{ active: orderDraft.delivery.carrier === 'nova_poshta' }"
                @click="setCarrier('nova_poshta')"
              >
                Нова Пошта
              </button>
              <button
                type="button"
                class="toggle-btn"
                :class="{ active: orderDraft.delivery.carrier === 'ukrposhta' }"
                @click="setCarrier('ukrposhta')"
              >
                Укрпошта
              </button>
              <button
                type="button"
                class="toggle-btn"
                :class="{ active: orderDraft.delivery.carrier === 'self_pickup' }"
                @click="setCarrier('self_pickup')"
              >
                Самовивіз
              </button>
            </div>

            <div v-if="orderDraft.delivery.carrier !== 'self_pickup'" class="delivery-fields">
              <div class="field">
                <label>Місто</label>
                <div class="dropdown-field">
                  <input
                    v-model="cityQuery"
                    type="text"
                    class="text-input"
                    placeholder="Почніть вводити..."
                  >
                  <div v-if="cityOptions.length" class="dropdown-list">
                    <button v-for="city in cityOptions" :key="city.Ref" type="button" @click="selectCity(city)">
                      {{ city.Description }}
                    </button>
                  </div>
                </div>
              </div>

              <div class="field">
                <label>Відділення</label>
                <div class="dropdown-field">
                  <input
                    v-model="warehouseQuery"
                    type="text"
                    class="text-input"
                    :disabled="!orderDraft.delivery.city_ref"
                    placeholder="Оберіть місто"
                  >
                  <div v-if="warehouseOptions.length" class="dropdown-list">
                    <button
                      v-for="warehouse in warehouseOptions"
                      :key="warehouse.Ref"
                      type="button"
                      @click="selectWarehouse(warehouse)"
                    >
                      {{ warehouse.Description }}
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div v-else class="delivery-fields">
              <div class="field">
                <label>Коментар</label>
                <input
                  v-model="orderDraft.delivery.address_note"
                  type="text"
                  class="text-input"
                  placeholder="Наприклад: забере сам"
                >
              </div>
            </div>
          </section>

          <section class="order-section">
            <div class="section-title">Оплата</div>
            <div class="payment-grid">
              <div class="field">
                <label>Метод оплати</label>
                <select v-model="orderDraft.payment.method" class="select-input">
                  <option value="cod">Накладений платіж</option>
                  <option value="card">На карту</option>
                  <option value="iban">IBAN</option>
                </select>
              </div>
              <div class="field">
                <label>Статус оплати</label>
                <select v-model="orderDraft.payment.status" class="select-input">
                  <option value="unpaid">Не оплачено</option>
                  <option value="paid">Оплачено</option>
                </select>
              </div>
            </div>
          </section>

          <section class="order-section">
            <div class="section-title">Коментар</div>
            <textarea
              v-model="orderDraft.comment"
              rows="3"
              class="textarea-input"
              placeholder="Коментар менеджера"
            ></textarea>
          </section>
        </div>

        <div class="order-footer">
          <button class="btn-save-order" @click="saveOrder" :disabled="isSavingOrder || !canSaveOrder">
            <span v-if="isSavingOrder" class="spinner"></span>
            {{ isSavingOrder ? 'Збереження...' : 'Зберегти замовлення' }}
          </button>
        </div>
      </div>

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

// Стан для сповіщень
const toast = reactive({
  show: false,
  message: '',
  type: 'success'
});

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
    city_ref: '',
    city_name: '',
    warehouse_ref: '',
    warehouse_name: '',
    address_note: '',
    recipient_name: '',
    recipient_phone: ''
  },
  payment: {
    method: 'cod',
    status: 'unpaid'
  },
  comment: ''
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
    viewMode.value = 'profile';
    resetOrderDraft();
  }
}, { immediate: true });

watch(productSearch, (query) => {
  const term = query.trim();
  if (productTimer) clearTimeout(productTimer);
  if (!term) {
    productResults.value = [];
    return;
  }
  productTimer = setTimeout(async () => {
    productLoading.value = true;
    try {
      const { data } = await searchProducts(term);
      productResults.value = Array.isArray(data) ? data : (data?.data || []);
    } catch (e) {
      console.error(e);
      productResults.value = [];
    } finally {
      productLoading.value = false;
    }
  }, 250);
});

watch(cityQuery, (query) => {
  if (orderDraft.delivery.carrier === 'self_pickup') return;
  if (skipCityFetch.value) {
    skipCityFetch.value = false;
    return;
  }
  const term = query.trim();
  if (cityTimer) clearTimeout(cityTimer);
  if (!term) {
    cityOptions.value = [];
    orderDraft.delivery.city_ref = '';
    orderDraft.delivery.city_name = '';
    orderDraft.delivery.warehouse_ref = '';
    orderDraft.delivery.warehouse_name = '';
    return;
  }
  if (term.length < 2) {
    cityOptions.value = [];
    return;
  }
  cityTimer = setTimeout(async () => {
    try {
      const { data } = await fetchCities(term);
      cityOptions.value = data?.data || [];
    } catch (e) {
      console.error(e);
      cityOptions.value = [];
    }
  }, 300);
});

watch(warehouseQuery, (query) => {
  if (orderDraft.delivery.carrier === 'self_pickup') return;
  if (!orderDraft.delivery.city_ref) return;
  if (skipWarehouseFetch.value) {
    skipWarehouseFetch.value = false;
    return;
  }
  const term = query.trim();
  if (warehouseTimer) clearTimeout(warehouseTimer);
  if (term.length < 2) {
    warehouseOptions.value = [];
    if (!term) {
      orderDraft.delivery.warehouse_ref = '';
      orderDraft.delivery.warehouse_name = '';
    }
    return;
  }
  warehouseTimer = setTimeout(async () => {
    try {
      const { data } = await fetchWarehouses({ cityRef: orderDraft.delivery.city_ref, query: term });
      warehouseOptions.value = data?.data || [];
    } catch (e) {
      console.error(e);
      warehouseOptions.value = [];
    }
  }, 300);
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
const canSaveOrder = computed(() => orderDraft.items.length > 0);

const formatMoney = (value) => {
  const number = Number(value) || 0;
  return number.toFixed(2);
};

const getProductPrice = (product) => {
  const price = product?.sale_price ?? product?.price ?? product?.cost_price ?? 0;
  return Number(price) || 0;
};

const itemTotal = (item) => {
  return (Number(item.qty) || 0) * (Number(item.price) || 0);
};

const itemsTotal = computed(() =>
  orderDraft.items.reduce((sum, item) => sum + itemTotal(item), 0)
);

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

const removeItem = (index) => {
  orderDraft.items.splice(index, 1);
};

const setCarrier = (carrier) => {
  orderDraft.delivery.carrier = carrier;
  orderDraft.delivery.delivery_type = carrier === 'self_pickup' ? 'self_pickup' : 'warehouse';
  orderDraft.delivery.city_ref = '';
  orderDraft.delivery.city_name = '';
  orderDraft.delivery.warehouse_ref = '';
  orderDraft.delivery.warehouse_name = '';
  cityQuery.value = '';
  warehouseQuery.value = '';
  cityOptions.value = [];
  warehouseOptions.value = [];
};

const selectCity = (city) => {
  orderDraft.delivery.city_ref = city.Ref || '';
  orderDraft.delivery.city_name = city.Description || '';
  skipCityFetch.value = true;
  cityQuery.value = city.Description || '';
  cityOptions.value = [];
  orderDraft.delivery.warehouse_ref = '';
  orderDraft.delivery.warehouse_name = '';
  warehouseQuery.value = '';
  warehouseOptions.value = [];
};

const selectWarehouse = (warehouse) => {
  orderDraft.delivery.warehouse_ref = warehouse.Ref || '';
  orderDraft.delivery.warehouse_name = warehouse.Description || '';
  skipWarehouseFetch.value = true;
  warehouseQuery.value = warehouse.Description || '';
  warehouseOptions.value = [];
};

function resetOrderDraft() {
  orderDraft.items = [];
  orderDraft.delivery = {
    carrier: 'nova_poshta',
    delivery_type: 'warehouse',
    city_ref: '',
    city_name: '',
    warehouse_ref: '',
    warehouse_name: '',
    address_note: '',
    recipient_name: '',
    recipient_phone: ''
  };
  orderDraft.payment = { method: 'cod', status: 'unpaid' };
  orderDraft.comment = '';
  productSearch.value = '';
  productResults.value = [];
  cityQuery.value = '';
  warehouseQuery.value = '';
  cityOptions.value = [];
  warehouseOptions.value = [];
}

const saveData = async () => {
  if (!customerId.value || !isProfileComplete.value) return;
  isLoading.value = true;
  try {
    const response = await axios.put(`/api/customers/${customerId.value}`, form);
    // Оновлюємо локальні дані даними з сервера, щоб все було синхронізовано
    const updatedCustomer = response?.data?.data;
    if (props.customer && updatedCustomer) {
      Object.assign(props.customer, updatedCustomer);
    } else if (props.customer) {
      // Фолбек, якщо сервер не повернув об'єкт
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

const saveOrder = async () => {
  if (!canSaveOrder.value) {
    showToast('Додайте хоча б один товар.', 'error');
    return;
  }
  isSavingOrder.value = true;
  try {
    const payload = {
      customer: {
        first_name: form.first_name || null,
        last_name: form.last_name || null,
        phone: form.phone || null,
        email: form.email || null
      },
      order: {
        currency: 'UAH',
        source: 'chat',
        status: 'new',
        payment_status: orderDraft.payment.status,
        comment_internal: orderDraft.comment || null
      },
      items: orderDraft.items.map((item) => ({
        product_id: item.product_id || null,
        title: item.title || null,
        sku: item.sku || null,
        qty: Number(item.qty) || 1,
        price: Number(item.price) || 0
      })),
      payment: {
        method: orderDraft.payment.method === 'iban' ? 'card' : orderDraft.payment.method,
        prepay_amount: 0,
        currency: 'UAH'
      },
      delivery: {
        carrier: orderDraft.delivery.carrier,
        delivery_type: orderDraft.delivery.delivery_type,
        payer: 'recipient',
        city_ref: orderDraft.delivery.city_ref || null,
        city_name: orderDraft.delivery.city_name || null,
        warehouse_ref: orderDraft.delivery.warehouse_ref || null,
        warehouse_name: orderDraft.delivery.warehouse_name || null,
        address_note: orderDraft.delivery.address_note || null,
        recipient_name: orderDraft.delivery.recipient_name || null,
        recipient_phone: orderDraft.delivery.recipient_phone || null
      },
      tag_ids: []
    };

    await createOrder(payload);
    showToast('Замовлення створено успішно!');
    resetOrderDraft();
    viewMode.value = 'profile';
  } catch (e) {
    console.error(e);
    showToast('Помилка збереження замовлення.', 'error');
  } finally {
    isSavingOrder.value = false;
  }
};
</script>

<style scoped>
.right-sidebar { width: 100%; height: 100%; background: #ffffff; border-left: 1px solid #edf2f7; display: flex; flex-direction: column; position: relative; }
.profile-content { padding: 10px; height: 100%; display: flex; flex-direction: column; }
.profile-view { display: flex; flex-direction: column; gap: 8px; }

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
.action-row { display: flex; flex-direction: column; gap: 10px; margin-top: 6px; }
.btn-create-order {
  background: #1f2937;
  color: #ffffff;
  border: none;
  border-radius: 10px;
  height: 42px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 700;
  width: 100%;
  cursor: pointer;
  transition: all 0.2s ease;
}
.btn-create-order:hover { background: #111827; }

.order-view { display: flex; flex-direction: column; height: 100%; gap: 12px; }
.order-header { display: flex; align-items: center; gap: 8px; }
.btn-back {
  width: 34px;
  height: 34px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #ffffff;
  color: #1f2937;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.order-title { font-size: 15px; font-weight: 700; color: #111827; }
.order-scroll { flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; padding-right: 2px; }
.order-section { background: #ffffff; border: 1px solid #edf2f7; border-radius: 12px; padding: 12px; display: flex; flex-direction: column; gap: 10px; }
.section-title { font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: #94a3b8; font-weight: 700; }
.search-box { position: relative; display: flex; align-items: center; gap: 8px; }
.search-box i { color: #94a3b8; }
.search-box input {
  flex: 1;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 8px 10px;
  font-size: 13px;
  outline: none;
}
.search-results { display: flex; flex-direction: column; gap: 6px; max-height: 180px; overflow-y: auto; }
.result-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 8px 10px;
  background: #f8fafc;
  cursor: pointer;
}
.result-title { font-size: 13px; font-weight: 600; color: #111827; }
.result-meta { font-size: 11px; color: #94a3b8; }
.result-price { font-size: 12px; font-weight: 700; color: #0f172a; white-space: nowrap; }
.empty-products { display: flex; align-items: center; gap: 8px; color: #94a3b8; font-size: 12px; }
.items-list { display: flex; flex-direction: column; gap: 10px; }
.item-row { display: flex; flex-direction: column; gap: 8px; border: 1px dashed #e2e8f0; border-radius: 10px; padding: 8px; }
.item-info { display: flex; flex-direction: column; gap: 2px; }
.item-title { font-size: 13px; font-weight: 600; color: #111827; }
.item-sku { font-size: 11px; color: #94a3b8; }
.item-controls { display: grid; grid-template-columns: 56px 80px 1fr 32px; gap: 8px; align-items: center; }
.qty-input,
.price-input {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 6px 8px;
  font-size: 12px;
  text-align: center;
}
.price-input { text-align: right; }
.item-sum { font-size: 12px; font-weight: 700; color: #1f2937; text-align: right; }
.btn-remove {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid #fee2e2;
  background: #fef2f2;
  color: #dc2626;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}
.total-row { display: flex; align-items: center; justify-content: space-between; font-size: 13px; font-weight: 700; color: #0f172a; }

.delivery-toggle { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.toggle-btn {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 8px 6px;
  font-size: 12px;
  font-weight: 600;
  background: #ffffff;
  color: #475569;
  cursor: pointer;
}
.toggle-btn.active { background: #eff6ff; border-color: #3b82f6; color: #2563eb; }
.delivery-fields { display: flex; flex-direction: column; gap: 10px; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field label { font-size: 11px; font-weight: 600; color: #64748b; }
.text-input,
.select-input,
.textarea-input {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 8px 10px;
  font-size: 13px;
  outline: none;
  background: #ffffff;
}
.textarea-input { resize: vertical; }
.dropdown-field { position: relative; }
.dropdown-list {
  position: absolute;
  top: 110%;
  left: 0;
  right: 0;
  z-index: 50;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  max-height: 180px;
  overflow-y: auto;
  box-shadow: 0 10px 15px rgba(15, 23, 42, 0.08);
}
.dropdown-list button {
  width: 100%;
  text-align: left;
  padding: 8px 10px;
  border: none;
  background: none;
  font-size: 12px;
  cursor: pointer;
}
.dropdown-list button:hover { background: #f8fafc; }

.payment-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.order-footer { padding-top: 4px; }
.btn-save-order {
  background: #2563eb;
  color: #ffffff;
  border: none;
  border-radius: 10px;
  height: 44px;
  width: 100%;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.btn-save-order:disabled { background: #93c5fd; cursor: not-allowed; }
.mini-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(148, 163, 184, 0.4);
  border-top-color: #64748b;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}



/* --- НОВІ СТИЛІ ДЛЯ КНОПКИ ЗБЕРЕЖЕННЯ --- */
.btn-save-modern {
  background: #A78BFB; /* М'який фіолетовий колір */
  color: white;
  border: none;
  border-radius: 8px;
  height: 40px; /* Фіксована менша висота */
  display: flex; /* Для центрування вмісту */
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
  background: #9061f9; /* Трохи темніший при наведенні */
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(167, 139, 251, 0.3);
}

.btn-save-modern:disabled {
  background: #e2e8f0;
  color: #94a3b8;
  cursor: not-allowed;
  box-shadow: none;
}
/* --------------------------------------- */

.spinner { width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #cbd5e0; gap: 8px; }
</style>
