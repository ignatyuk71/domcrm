<template>
  <div class="right-sidebar" :class="{ 'is-order-mode': showOrderPanel }">
    <transition name="toast">
      <div v-if="toast.show" class="toast-notification" :class="toast.type">
        <i class="bi" :class="toast.type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'"></i>
        <div class="toast-copy">
          <strong>{{ toast.type === 'success' ? 'Готово' : 'Помилка' }}</strong>
          <span>{{ toast.message }}</span>
        </div>
      </div>
    </transition>

    <div v-if="props.customer" v-show="!showOrderPanel" class="profile-content custom-scrollbar" ref="profileContainer">
      <div class="profile-mobile-header">
        <button class="profile-back-btn" type="button" @click="emit('close')">
          <i class="bi bi-arrow-left"></i>
          Назад
        </button>
      </div>
      
      <div class="header-section">
        <div class="avatar-wrap">
          <img v-if="safeAvatarUrl" :src="safeAvatarUrl" class="avatar-img" @error="avatarFailed = true">
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
        <div class="contacts-deck">
          <div class="contact-card contact-card--stack" :class="{ 'is-invalid': (form.phone && !isPhoneValid) || (form.email && !isEmailValid) }">
            <div class="contact-row" :class="{ 'is-active': phoneFocused, 'is-invalid': form.phone && !isPhoneValid }">
              <div class="contact-card-head">
                <div class="contact-label">
                  <i class="bi bi-telephone"></i>
                  <span>Телефон</span>
                </div>
                <button v-if="form.phone" type="button" class="contact-clear-btn" title="Очистити телефон" @click="form.phone = ''">
                  <i class="bi bi-x-lg"></i>
                </button>
              </div>
              <input
                v-model="form.phone"
                type="tel"
                class="contact-input"
                placeholder="380XXXXXXXXX"
                @input="applyLegacyPhoneFilter"
                @focus="phoneFocused = true"
                @blur="handlePhoneBlur"
              >
              <small v-if="form.phone && !isPhoneValid" class="contact-error">Телефон має бути у форматі 380XXXXXXXXX</small>
            </div>

            <div class="contact-divider"></div>

            <div class="contact-row" :class="{ 'is-active': emailFocused, 'is-invalid': form.email && !isEmailValid }">
              <div class="contact-card-head">
                <div class="contact-label">
                  <i class="bi bi-envelope"></i>
                  <span>E-mail</span>
                </div>
                <button v-if="form.email" type="button" class="contact-clear-btn" title="Очистити email" @click="form.email = ''">
                  <i class="bi bi-x-lg"></i>
                </button>
              </div>
              <input
                v-model="form.email"
                type="email"
                class="contact-input"
                placeholder="email@example.com"
                @focus="emailFocused = true"
                @blur="emailFocused = false"
              >
              <small v-if="form.email && !isEmailValid" class="contact-error">Вкажіть коректний email</small>
            </div>
          </div>
        </div>

        <div class="action-row">
          <button class="btn-save-modern" @click="saveData" :disabled="!canSaveProfile">
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

          <div v-if="historyReady && !historyOrders.length && !historyLoading" class="empty-history">
            <div class="empty-icon"><i class="bi bi-box-seam"></i></div>
            <span>Замовлень ще немає</span>
          </div>

          <div v-else-if="historyOrders.length" class="orders-list">
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

        <div class="ai-container">
          <div class="section-header">
            <span class="section-title">Поля для збору</span>
            <span class="counter-badge" :class="aiEnabled ? 'is-on' : 'is-off'">
              {{ aiEnabled ? 'ON' : 'OFF' }}
            </span>
          </div>

          <div class="ai-card" :class="{ 'is-open': aiBlockOpen }">
            <button type="button" class="ai-header" @click="aiBlockOpen = !aiBlockOpen">
              <div class="ai-header-main">
                <i class="bi bi-robot"></i>
                <div>
                  <div class="ai-title">AI-агент у цьому діалозі</div>
                  <div class="ai-subtitle">{{ aiStageLabel }}</div>
                </div>
              </div>
              <i class="bi" :class="aiBlockOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
            </button>

            <div class="ai-body-wrap">
              <div class="ai-body">
                <div class="ai-switch-row">
                  <div>
                    <div class="ai-switch-title">Відповідати автоматично</div>
                    <div class="ai-switch-hint">Можна вимкнути AI тільки для цього клієнта.</div>
                  </div>
                  <button
                    type="button"
                    class="ai-switch-btn"
                    :class="{ 'is-on': aiEnabled, 'is-loading': aiToggleLoading }"
                    :disabled="aiToggleLoading || !props.customer?.conversation_id"
                    @click="toggleAiForConversation"
                  >
                    <span class="ai-switch-knob"></span>
                  </button>
                </div>

                <div class="ai-pipeline-card">
                  <div class="ai-pipeline-head">
                    <span class="ai-pipeline-title">Етап процесу</span>
                    <span class="ai-stage-chip">{{ aiStageBadge }}</span>
                  </div>

                  <div class="ai-steps ai-steps--compact">
                    <div
                      v-for="step in aiSteps"
                      :key="step.code"
                      class="ai-step"
                      :class="`state-${step.state}`"
                    >
                      <span class="ai-step-dot"></span>
                      <span class="ai-step-label">{{ step.title }}</span>
                    </div>
                  </div>
                </div>

                <div class="ai-pipeline-card">
                  <div class="ai-pipeline-head">
                    <span class="ai-pipeline-title">Поля для збору</span>
                    <span class="ai-stage-mini">{{ aiStageBadge }}</span>
                  </div>

                  <div class="ai-collected-block">
                    <div class="ai-collected-subtitle">
                      Кошик ({{ aiCartHeaderText }})
                    </div>

                    <div v-if="aiCartItems.length" class="ai-cart-list">
                      <div
                        v-for="(item, index) in aiCartItems"
                        :key="`cart-item-${index}`"
                        class="ai-cart-item"
                      >
                        <div class="ai-cart-item-head">
                          <span class="ai-cart-item-index">{{ index + 1 }})</span>
                          <span class="ai-cart-item-model">{{ item.model || 'Товар без назви' }}</span>
                        </div>
                        <div class="ai-cart-item-meta">
                          Колір: {{ item.color || '—' }} • Розмір: {{ item.size || '—' }}
                        </div>
                        <div v-if="item.price !== null && item.line_total !== null" class="ai-cart-item-price">
                          {{ aiFormatMoney(item.price) }} грн × {{ item.qty }} = {{ aiFormatMoney(item.line_total) }} грн
                        </div>
                        <div v-else class="ai-cart-item-price ai-cart-item-price--muted">Ціна уточнюється</div>
                      </div>
                    </div>

                    <div v-else class="ai-summary-box">
                      Кошик ще не сформований.
                    </div>
                  </div>

                  <div class="ai-collected-block">
                    <div class="ai-collected-subtitle">Для менеджера</div>
                    <div class="ai-summary-box ai-summary-box--manager">{{ aiManagerNote }}</div>
                  </div>

                  <div class="ai-collected-block">
                    <div class="ai-collected-subtitle">Статус діалогу</div>
                    <div class="ai-dialog-status">{{ aiDialogStatus }}</div>
                  </div>

                  <div class="ai-collected-block">
                    <div class="ai-collected-subtitle">Поля оформлення</div>
                    <div class="ai-delivery-grid">
                      <div v-for="row in aiDeliveryRows" :key="row.key" class="ai-delivery-item" :class="{ 'is-done': row.done }">
                        <i class="bi" :class="row.done ? 'bi-check-circle-fill' : 'bi-circle'"></i>
                        <span>{{ row.label }}</span>
                      </div>
                    </div>
                  </div>

                  <div v-if="aiMissingSlotsText" class="ai-missing-slots">
                    Потрібно ще: {{ aiMissingSlotsText }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>

    </div>

    <div v-else-if="!showOrderPanel" class="empty-state">
      <i class="bi bi-person-bounding-box"></i>
      <p>Виберіть чат</p>
    </div>

    <ChatOrderPanel
      :open="showOrderPanel"
      :embedded="true"
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
import { updateConversationAiSettings } from '@/crm/services/chatApi';

const props = defineProps({ customer: Object });
const emit = defineEmits(['close', 'update-stage']);

const showNameInput = ref(false);
const phoneFocused = ref(false);
const emailFocused = ref(false);
const isLoading = ref(false);
const isOrderSaving = ref(false);
const historyOrders = ref([]);
const historyLoading = ref(false);
const historyReady = ref(false);
const placeholderThumb = 'https://via.placeholder.com/48x48?text=%20';
const avatarFailed = ref(false);
let historyRequestToken = 0;

// Refs для скролу
const profileContainer = ref(null);
const orderRefs = reactive({});

// Стан для панелі замовлення
const showOrderPanel = ref(false);
const aiBlockOpen = ref(true);
const aiToggleLoading = ref(false);
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
    settlement_ref: '',
    warehouse_name: '',
    warehouse_ref: '',
    street_name: '',
    street_ref: '',
    building: '',
    apartment: '',
    address_note: '',
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
    settlement_ref: '',
    warehouse_name: '',
    warehouse_ref: '',
    street_name: '',
    street_ref: '',
    building: '',
    apartment: '',
    address_note: '',
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
  email: '',
});

const cyrillicRegex = /^[А-Яа-яЁёЇїІіЄєҐґ' \-]+$/;
const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const isNameValid = computed(() => {
  return form.first_name.trim().length >= 2 && 
         form.last_name.trim().length >= 2 && 
         cyrillicRegex.test(form.first_name) && 
         cyrillicRegex.test(form.last_name);
});

const normalizePhone = (value) => String(value || '').replace(/\D/g, '').slice(0, 12);

function applyLegacyPhoneFilter() {
  let cleaned = String(form.phone || '').replace(/\D/g, '');

  if (cleaned.startsWith('0')) {
    cleaned = `38${cleaned}`;
  } else if (cleaned.startsWith('38') && !cleaned.startsWith('380')) {
    cleaned = `380${cleaned.slice(2)}`;
  }

  if (cleaned.startsWith('3800')) {
    cleaned = `380${cleaned.slice(4)}`;
  }

  form.phone = cleaned.slice(0, 12);
}

function handlePhoneBlur() {
  phoneFocused.value = false;
  applyLegacyPhoneFilter();
}

const isPhoneValid = computed(() => /^380\d{9}$/.test(normalizePhone(form.phone)));
const isEmailValid = computed(() => !String(form.email || '').trim() || emailRegex.test(String(form.email || '').trim()));
const isProfileComplete = computed(() => isNameValid.value && isPhoneValid.value);
const normalizedProfilePayload = computed(() => ({
  first_name: form.first_name.trim(),
  last_name: form.last_name.trim(),
  phone: normalizePhone(form.phone),
  email: String(form.email || '').trim(),
}));
const originalProfilePayload = computed(() => ({
  first_name: String(props.customer?.first_name || '').trim(),
  last_name: String(props.customer?.last_name || '').trim(),
  phone: normalizePhone(props.customer?.phone || ''),
  email: String(props.customer?.email || '').trim(),
}));
const hasProfileChanges = computed(() => (
  normalizedProfilePayload.value.first_name !== originalProfilePayload.value.first_name ||
  normalizedProfilePayload.value.last_name !== originalProfilePayload.value.last_name ||
  normalizedProfilePayload.value.phone !== originalProfilePayload.value.phone ||
  normalizedProfilePayload.value.email !== originalProfilePayload.value.email
));
const canSaveProfile = computed(() => (
  !isLoading.value &&
  hasProfileChanges.value &&
  (!normalizedProfilePayload.value.phone || isPhoneValid.value) &&
  isEmailValid.value
));

const customerId = computed(() => {
  const rawId = props.customer?.id ?? props.customer?.customer_id ?? null;
  const normalizedId = Number(rawId);

  return Number.isFinite(normalizedId) && normalizedId > 0 ? normalizedId : null;
});
const displayName = computed(() => {
  const name = `${form.first_name} ${form.last_name}`.trim();
  return name || props.customer?.customer_name || 'Не заповнено';
});
const displayInitial = computed(() => (displayName.value ? displayName.value[0].toUpperCase() : '?'));
const avatarUrl = computed(() => props.customer?.fb_profile_pic || props.customer?.customer_avatar || '');
const safeAvatarUrl = computed(() => (avatarFailed.value ? '' : avatarUrl.value));
const isInstagram = computed(() => (props.customer?.source || props.customer?.platform) === 'instagram' || !!props.customer?.instagram_user_id);
const aiPayload = computed(() => (
  props.customer?.ai && typeof props.customer.ai === 'object'
    ? props.customer.ai
    : {}
));
const aiEnabled = computed(() => aiPayload.value.enabled !== false);
const aiStageCode = computed(() => String(aiPayload.value.stage || '').trim());
const aiStageLabel = computed(() => {
  const map = {
    interest: 'Зацікавлення',
    selection: 'Підбір',
    checkout_ready: 'Готовність до оформлення',
    checkout: 'Оформлення',
  };

  return String(aiPayload.value.stage_label || map[aiStageCode.value] || 'Зацікавлення');
});
const aiStageBadge = computed(() => {
  const map = {
    interest: 'Консультація',
    selection: 'Підбір',
    checkout_ready: 'Перед оформленням',
    checkout: 'Оформлення',
  };

  return String(aiPayload.value.stage_badge || map[aiStageCode.value] || 'Консультація');
});
const aiCollected = computed(() => (
  aiPayload.value.collected && typeof aiPayload.value.collected === 'object'
    ? aiPayload.value.collected
    : {}
));
const aiCurrentStep = computed(() => {
  const raw = Number(aiPayload.value.stage_order || 0);
  if (raw >= 1 && raw <= 4) {
    return raw;
  }

  const map = {
    interest: 1,
    selection: 2,
    checkout_ready: 3,
    checkout: 4,
  };

  return map[aiStageCode.value] || 1;
});
const aiSteps = computed(() => {
  const steps = [
    { code: 'interest', title: 'Зацікавлення', order: 1 },
    { code: 'selection', title: 'Підбір', order: 2 },
    { code: 'checkout_ready', title: 'Готовність', order: 3 },
    { code: 'checkout', title: 'Оформлення', order: 4 },
  ];

  return steps.map((step) => ({
    ...step,
    state: step.order < aiCurrentStep.value
      ? 'done'
      : step.order === aiCurrentStep.value
        ? 'current'
        : 'pending',
  }));
});
const aiCartItems = computed(() => {
  const rawItems = Array.isArray(aiCollected.value?.cart_items) ? aiCollected.value.cart_items : [];
  const normalized = rawItems
    .map((item) => {
      if (!item || typeof item !== 'object') {
        return null;
      }

      const qty = Math.max(1, Number(item.qty || 1) || 1);
      const priceNum = Number(item.price);
      const lineTotalNum = Number(item.line_total);
      const price = Number.isFinite(priceNum) ? priceNum : null;
      const lineTotal = Number.isFinite(lineTotalNum)
        ? lineTotalNum
        : (price !== null ? price * qty : null);

      return {
        model: String(item.model || '').trim() || null,
        color: String(item.color || '').trim() || null,
        size: String(item.size || '').trim() || null,
        price,
        qty,
        line_total: lineTotal,
      };
    })
    .filter(Boolean);

  if (normalized.length) {
    return normalized;
  }

  const fallbackModel = String(aiCollected.value?.product?.title || '').trim();
  const fallbackColor = String(aiCollected.value?.color?.name || '').trim();
  const fallbackSize = String(aiCollected.value?.size || aiCollected.value?.variant?.size || '').trim();
  if (!fallbackModel && !fallbackColor && !fallbackSize) {
    return [];
  }

  return [{
    model: fallbackModel || null,
    color: fallbackColor || null,
    size: fallbackSize || null,
    price: null,
    qty: 1,
    line_total: null,
  }];
});
const aiCartMeta = computed(() => (
  aiCollected.value?.cart && typeof aiCollected.value.cart === 'object'
    ? aiCollected.value.cart
    : {}
));
const aiCartPositions = computed(() => {
  const backendCount = Number(aiCartMeta.value.positions);
  if (Number.isFinite(backendCount) && backendCount > 0) {
    return backendCount;
  }

  return aiCartItems.value.length;
});
const aiCartPairs = computed(() => {
  const backendPairs = Number(aiCartMeta.value.pairs);
  if (Number.isFinite(backendPairs) && backendPairs > 0) {
    return backendPairs;
  }

  return aiCartItems.value.reduce((sum, item) => sum + Number(item.qty || 0), 0);
});
const aiCartHeaderText = computed(() => (
  `${aiCartPositions.value} ${pluralizeUa(aiCartPositions.value, ['позиція', 'позиції', 'позицій'])} • `
  + `${aiCartPairs.value} ${pluralizeUa(aiCartPairs.value, ['пара', 'пари', 'пар'])}`
));
const aiDelivery = computed(() => (
  aiCollected.value.delivery && typeof aiCollected.value.delivery === 'object'
    ? aiCollected.value.delivery
    : {}
));
const aiDeliveryRows = computed(() => ([
  { key: 'name', label: 'Імʼя та прізвище', done: !!String(aiDelivery.value.name || '').trim() },
  { key: 'phone', label: 'Телефон', done: !!String(aiDelivery.value.phone || '').trim() },
  { key: 'city', label: 'Місто', done: !!String(aiDelivery.value.city || '').trim() },
  { key: 'warehouse', label: 'Відділення/поштомат', done: !!String(aiDelivery.value.warehouse || '').trim() },
]));
const aiMissingSlotsText = computed(() => {
  const missing = Array.isArray(aiCollected.value?.missing_slots) ? aiCollected.value.missing_slots : [];
  if (!missing.length) {
    return '';
  }

  const labels = {
    selected_product: 'модель товару',
    selected_color: 'колір',
    selected_size: 'розмір',
    selected_variant: 'варіант',
    purchase_intent: 'підтвердження наміру купити',
    name: 'імʼя',
    phone: 'телефон',
    city: 'місто',
    warehouse: 'відділення/поштомат',
  };

  return missing
    .map((key) => labels[key] || key)
    .join(', ');
});
const aiDialogStatus = computed(() => {
  const status = String(aiCollected.value?.dialog_status || '').trim();
  if (status) {
    return status;
  }

  if (aiStageCode.value === 'checkout' && aiDeliveryRows.value.every((row) => row.done)) {
    return 'Оформлення завершено';
  }
  if (aiStageCode.value === 'checkout_ready' || aiCollected.value?.intent_purchase) {
    return 'Підтверджено клієнтом';
  }
  if (aiStageCode.value === 'selection') {
    return 'Підбір позицій';
  }

  return 'Консультація';
});
const aiManagerNote = computed(() => {
  const note = String(aiCollected.value?.manager_note || '').trim();
  if (note) {
    return note;
  }

  if (!aiCartItems.value.length) {
    return 'Клієнт ще формує вибір. Поки немає підтверджених позицій у кошику.';
  }

  const positions = aiCartItems.value
    .map((item) => {
      const color = item.color ? item.color.toLowerCase() : 'без кольору';
      const size = item.size || 'без розміру';
      return `${color} ${size} (${item.qty})`;
    })
    .join(', ');

  const prefix = aiCollected.value?.intent_purchase
    ? 'Клієнт підтвердив замовлення.'
    : 'Клієнт сформував позиції у кошику, очікуємо підтвердження.';
  const suffix = aiMissingSlotsText.value
    ? `Потрібно дозібрати: ${aiMissingSlotsText.value}.`
    : 'Дані доставки зібрано.';

  return `${prefix}\nПозиції: ${positions}.\n${suffix}`;
});

function syncFormFromCustomer(customer, { resetPanels = false } = {}) {
  if (!customer) {
    return;
  }

  form.first_name = customer.first_name || '';
  form.last_name = customer.last_name || '';
  form.phone = String(customer.phone || '');
  form.email = String(customer.email || '');

  if (resetPanels) {
    showNameInput.value = false;
    showOrderPanel.value = false;
    resetOrderDraft();
  }
}

watch(
  customerId,
  async (id, oldId) => {
    if (!id) {
      historyOrders.value = [];
      historyReady.value = false;
      return;
    }

    syncFormFromCustomer(props.customer, { resetPanels: id !== oldId });

    if (id !== oldId) {
      await loadCustomerHistory(id);
    }
  },
  { immediate: true }
);

watch(avatarUrl, () => {
  avatarFailed.value = false;
});

const showToast = (msg, type = 'success') => {
  toast.message = msg;
  toast.type = type;
  toast.show = true;
  setTimeout(() => { toast.show = false; }, 3000);
};

const enableNameEdit = () => { showNameInput.value = true; };

const loadCustomerHistory = async (id) => {
  const requestToken = ++historyRequestToken;
  historyLoading.value = true;
  historyReady.value = false;
  try {
    const { data } = await axios.get(`/customers/${id}`);
    const recent = data?.data?.recent_orders || [];

    if (requestToken !== historyRequestToken) {
      return;
    }

    historyOrders.value = recent.map((order) => ({ ...order, isOpen: false }));
  } catch (e) {
    console.error(e);
    if (requestToken === historyRequestToken) {
      historyOrders.value = [];
    }
  } finally {
    if (requestToken === historyRequestToken) {
      historyLoading.value = false;
      historyReady.value = true;
    }
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
const aiFormatMoney = (value) => formatMoney(Number.isFinite(Number(value)) ? Number(value) : 0);
const pluralizeUa = (count, forms) => {
  const abs = Math.abs(Number(count) || 0);
  const mod10 = abs % 10;
  const mod100 = abs % 100;

  if (mod10 === 1 && mod100 !== 11) {
    return forms[0];
  }
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
    return forms[1];
  }

  return forms[2];
};

const getStatusRef = (order) => {
  return order?.statusRef || order?.status_ref || null;
};

const getStatusLabel = (order) => {
  return getStatusRef(order)?.name || '—';
};

const saveData = async () => {
  if (!customerId.value) return;
  if (!canSaveProfile.value) {
    if (normalizedProfilePayload.value.phone && !isPhoneValid.value) {
      showToast('Телефон має бути у форматі 380XXXXXXXXX.', 'error');
      return;
    }
    if (!isEmailValid.value) {
      showToast('Вкажіть коректний email.', 'error');
      return;
    }
    showToast('Немає змін для збереження.', 'error');
    return;
  }

  isLoading.value = true;
  try {
    const payload = { ...normalizedProfilePayload.value };
    const response = await axios.put(`/api/customers/${customerId.value}`, payload, {
      headers: { Accept: 'application/json' },
    });
    const updatedCustomer = response?.data?.data;
    if (props.customer && updatedCustomer) {
      Object.assign(props.customer, updatedCustomer);
    } else if (props.customer) {
      Object.assign(props.customer, payload);
    }

    showNameInput.value = false;
    showToast('Покупця успішно збережено.');
  } catch (e) { 
    console.error(e);
    const validationErrors = Object.values(e?.response?.data?.errors || {})
      .flat()
      .filter(Boolean);
    showToast(validationErrors[0] || e?.response?.data?.message || 'Не вдалося зберегти дані покупця.', 'error');
  } finally { 
    isLoading.value = false; 
  }
};

const toggleAiForConversation = async () => {
  const conversationId = Number(props.customer?.conversation_id || 0);
  if (!conversationId || aiToggleLoading.value) return;

  const nextValue = !aiEnabled.value;
  const prevMeta = {
    ...(props.customer?.ai && typeof props.customer.ai === 'object' ? props.customer.ai : {}),
  };

  if (!props.customer.ai || typeof props.customer.ai !== 'object') {
    props.customer.ai = {};
  }
  props.customer.ai.enabled = nextValue;
  props.customer.ai.updated_at = new Date().toISOString();

  aiToggleLoading.value = true;
  try {
    const { data } = await updateConversationAiSettings(conversationId, nextValue);
    const snapshot = data?.data || null;
    if (snapshot && props.customer) {
      Object.assign(props.customer, snapshot);
    }
    showToast(nextValue ? 'AI увімкнено для цього діалогу.' : 'AI вимкнено для цього діалогу.');
  } catch (e) {
    console.error(e);
    props.customer.ai = prevMeta;
    showToast('Не вдалося оновити AI режим діалогу.', 'error');
  } finally {
    aiToggleLoading.value = false;
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
  if (!normalizedProfilePayload.value.phone) {
    showToast('Вкажіть телефон клієнта.', 'error');
    return;
  }
  if (!isPhoneValid.value) {
    showToast('Телефон має бути у форматі 380XXXXXXXXX.', 'error');
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
      phone: normalizedProfilePayload.value.phone || '',
      email: normalizedProfilePayload.value.email || '',
    },
    order: {
      status: 'confirmed',
      payment_status: paymentStatus,
      currency: orderDraft.payment?.currency || 'UAH',
      source,
    },
    items: orderDraft.items.map((item) => ({
      product_id: item.product_id || item.id || null,
      product_variant_id: item.product_variant_id || null,
      title: item.title || '',
      sku: item.sku || '',
      size: item.size || '',
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
      settlement_ref: delivery.settlement_ref || '',
      city_name: delivery.city_name || '',
      warehouse_ref: delivery.warehouse_ref || '',
      warehouse_name: delivery.warehouse_name || '',
      street_name: delivery.street_name || '',
      street_ref: delivery.street_ref || '',
      building: delivery.building || '',
      apartment: delivery.apartment || '',
      address_note: delivery.address_note || '',
      recipient_name: [delivery.last_name, delivery.first_name, delivery.middle_name].filter(Boolean).join(' '),
      recipient_phone: normalizePhone(delivery.phone || '') || normalizedProfilePayload.value.phone || '',
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
    if (props.customer?.conversation_id) {
      emit('update-stage', { conversationId: props.customer.conversation_id, stage: 'done' });
    }
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
.right-sidebar { width: 100%; height: 100%; background: #ffffff; border-left: 1px solid #e5e7eb; display: flex; flex-direction: column; position: relative; overflow: hidden; font-family: 'Segoe UI', sans-serif; color: #334155; }
.right-sidebar.is-order-mode { background: #ffffff; }
.profile-content { flex: 1; min-height: 0; overflow-y: auto; padding: 0 0 20px; scroll-behavior: smooth; overscroll-behavior: contain; }
.custom-scrollbar::-webkit-scrollbar { width: 6px; } .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; } .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }

.toast-notification {
  position: absolute;
  top: 14px;
  right: 14px;
  z-index: 30;
  min-width: 220px;
  max-width: calc(100% - 28px);
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 10px;
  border: 1px solid #d1fae5;
  background: rgba(240, 253, 244, 0.98);
  box-shadow: 0 14px 30px -20px rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(6px);
}

.toast-notification.success {
  border-color: #bbf7d0;
  background: rgba(240, 253, 244, 0.98);
}

.toast-notification.error {
  border-color: #fecaca;
  background: rgba(254, 242, 242, 0.98);
}

.toast-notification > i {
  margin-top: 1px;
  font-size: 18px;
  flex-shrink: 0;
}

.toast-notification.success > i {
  color: #16a34a;
}

.toast-notification.error > i {
  color: #dc2626;
}

.toast-copy {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.toast-copy strong {
  font-size: 12px;
  line-height: 1.2;
  font-weight: 700;
  color: #0f172a;
}

.toast-copy span {
  font-size: 13px;
  line-height: 1.35;
  color: #334155;
}

.toast-enter-active,
.toast-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

/* ... (Інші стилі залишаються без змін) ... */
.header-section { display: flex; align-items: center; gap: 12px; padding: 16px; }
.avatar-wrap { position: relative; width: 56px; height: 56px; flex-shrink: 0; }
.avatar-img, .avatar-placeholder { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; background: #f1f5f9; }
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
.btn-status-indicator { background: none; border: none; cursor: pointer; transition: all 0.2s ease; font-size: 20px; padding: 0; margin-left: auto; display: flex; align-items: center; }
.status-attention { color: #ef4444; filter: drop-shadow(0 0 5px rgba(239, 68, 68, 0.4)); }
.status-ready { color: #10b981; filter: drop-shadow(0 0 5px rgba(16, 185, 129, 0.4)); }
.btn-edit-purple { background: #f3f4f6; color: #4b5563; border: 1px solid #d1d5db; width: 30px; height: 30px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 14px; cursor: pointer; transition: all 0.2s ease; margin-left: 8px; }
.btn-edit-purple:hover { background: #e5e7eb; }
.name-edit-flow { display: flex; align-items: center; gap: 8px; }
.inputs-stack { display: flex; flex-direction: column; gap: 4px; flex: 1; }
.modern-input { border: none; border-bottom: 1.5px solid #e2e8f0; font-size: 13px; font-weight: 600; outline: none; transition: 0.3s; padding: 2px 0; }
.modern-input:focus { border-color: #6366f1; }
.btn-confirm-tick { background: #6366f1; color: white; border: none; width: 26px; height: 26px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.id-badge { font-size: 11px; color: #a0aec0; margin-top: 4px; display: inline-block; background: #f7fafc; padding: 2px 6px; border-radius: 4px; }
.divider { border: 0; border-top: 1px solid #e5e7eb; margin: 0; }
.fields-section { display: flex; flex-direction: column; gap: 10px; padding: 12px; }
.contacts-deck { display: grid; gap: 8px; }
.contact-card { border: 1px solid #e2e8f0; border-radius: 10px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); padding: 8px 10px; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
.contact-card.is-active { border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12); }
.contact-card.is-invalid { border-color: #fca5a5; }
.contact-card--stack { padding: 6px 8px; }
.contact-row { border: 1px solid transparent; border-radius: 7px; padding: 4px 6px; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
.contact-row.is-active { border-color: #bfdbfe; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.08); }
.contact-row.is-invalid { border-color: #fecaca; }
.contact-divider { height: 1px; background: #d1d5db; margin: 4px 1px; }
.contact-card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 2px; }
.contact-label { display: inline-flex; align-items: center; gap: 5px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; color: #64748b; }
.contact-label i { font-size: 12px; color: #94a3b8; }
.contact-input { width: 100%; border: none; background: transparent; color: #0f172a; font-size: 13px; font-weight: 600; line-height: 1.25; outline: none; padding: 0; }
.contact-input::placeholder { color: #94a3b8; font-weight: 500; }
.contact-clear-btn { width: 18px; height: 18px; border: none; border-radius: 5px; background: transparent; color: #94a3b8; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
.contact-clear-btn:hover { background: #e2e8f0; color: #475569; }
.contact-clear-btn i { font-size: 9px; }
.contact-error { display: block; margin-top: 2px; color: #dc2626; font-size: 9px; line-height: 1.2; }
.action-row { display: flex; flex-direction: column; gap: 8px; margin-top: 6px; }
.history-container { margin-top: 14px; }
.section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; padding: 0 4px; }
.section-title { font-size: 12px; font-weight: 800; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.05em; }
.counter-badge { background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px; }
.counter-badge.is-on { background: #dcfce7; color: #15803d; }
.counter-badge.is-off { background: #fee2e2; color: #b91c1c; }
.counter-badge--success { background: #dcfce7; color: #15803d; }
.counter-badge--neutral { background: #e2e8f0; color: #475569; }
.ai-container { margin-top: 16px; }
.ai-card { border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: #fff; }
.ai-header {
  width: 100%;
  border: none;
  background: #fff;
  padding: 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  cursor: pointer;
  text-align: left;
}
.ai-header-main { display: flex; gap: 10px; align-items: center; }
.ai-header-main i { color: #0ea5e9; font-size: 18px; }
.ai-title { font-size: 13px; font-weight: 700; color: #0f172a; }
.ai-subtitle { font-size: 11px; color: #64748b; margin-top: 2px; }
.ai-body-wrap { max-height: 0; overflow: hidden; transition: max-height 0.3s ease; }
.ai-card.is-open .ai-body-wrap { max-height: 6000px; border-top: 1px solid #f1f5f9; }
.ai-body { padding: 12px; display: flex; flex-direction: column; gap: 12px; background: #f8fafc; }
.ai-switch-row {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  align-items: center;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 10px;
}
.ai-switch-title { font-size: 13px; font-weight: 700; color: #0f172a; }
.ai-switch-hint { font-size: 11px; color: #64748b; margin-top: 2px; }
.ai-switch-btn {
  width: 48px;
  height: 28px;
  border-radius: 999px;
  border: none;
  background: #cbd5e1;
  padding: 2px;
  position: relative;
  transition: background 0.2s ease;
}
.ai-switch-btn.is-on { background: #16a34a; }
.ai-switch-btn.is-loading { opacity: 0.7; }
.ai-switch-knob {
  width: 24px;
  height: 24px;
  background: #fff;
  border-radius: 50%;
  display: block;
  transform: translateX(0);
  transition: transform 0.2s ease;
}
.ai-switch-btn.is-on .ai-switch-knob { transform: translateX(20px); }
.ai-pipeline-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 10px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.ai-pipeline-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.ai-pipeline-title {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: .04em;
  text-transform: uppercase;
  color: #64748b;
}
.ai-stage-chip {
  background: #fff7ed;
  color: #c2410c;
  border: 1px solid #fed7aa;
  border-radius: 999px;
  padding: 3px 8px;
  font-size: 11px;
  font-weight: 700;
}
.ai-stage-mini {
  background: #f1f5f9;
  color: #334155;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  padding: 2px 8px;
  font-size: 11px;
  font-weight: 700;
}
.ai-steps {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.ai-steps--compact {
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px;
}
.ai-step {
  display: flex;
  align-items: center;
  gap: 7px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  border-radius: 8px;
  padding: 6px 8px;
}
.ai-step-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  background: #cbd5e1;
  flex-shrink: 0;
}
.ai-step-label {
  font-size: 11px;
  font-weight: 700;
  color: #475569;
}
.ai-step.state-done {
  border-color: #bbf7d0;
  background: #f0fdf4;
}
.ai-step.state-done .ai-step-dot { background: #16a34a; }
.ai-step.state-done .ai-step-label { color: #166534; }
.ai-step.state-current {
  border-color: #bfdbfe;
  background: #eff6ff;
}
.ai-step.state-current .ai-step-dot { background: #2563eb; }
.ai-step.state-current .ai-step-label { color: #1d4ed8; }

.ai-collected-block {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.ai-collected-subtitle {
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  color: #64748b;
  letter-spacing: .04em;
}
.ai-cart-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.ai-cart-item {
  border: 1px solid #dbeafe;
  background: #f8fbff;
  border-radius: 10px;
  padding: 10px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.ai-cart-item-head {
  display: flex;
  align-items: flex-start;
  gap: 6px;
}
.ai-cart-item-index {
  font-size: 13px;
  font-weight: 700;
  color: #334155;
}
.ai-cart-item-model {
  font-size: 13px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.25;
}
.ai-cart-item-meta {
  font-size: 12px;
  color: #475569;
}
.ai-cart-item-price {
  font-size: 12px;
  color: #0f172a;
  font-weight: 600;
}
.ai-cart-item-price--muted {
  color: #64748b;
  font-weight: 500;
}
.ai-summary-box {
  border: 1px solid #dbeafe;
  background: #f8fbff;
  color: #1e293b;
  border-radius: 10px;
  padding: 10px;
  font-size: 13px;
  line-height: 1.35;
}
.ai-summary-box--manager {
  white-space: pre-line;
}
.ai-dialog-status {
  border: 1px solid #bbf7d0;
  background: #f0fdf4;
  border-radius: 10px;
  padding: 8px 10px;
  font-size: 12px;
  font-weight: 700;
  color: #166534;
}
.ai-delivery-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 7px;
}
.ai-delivery-item {
  display: flex;
  align-items: center;
  gap: 6px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  border-radius: 8px;
  padding: 6px 8px;
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
}
.ai-delivery-item i {
  color: #94a3b8;
  font-size: 12px;
}
.ai-delivery-item.is-done {
  border-color: #bbf7d0;
  background: #f0fdf4;
  color: #166534;
}
.ai-delivery-item.is-done i {
  color: #16a34a;
}
.ai-missing-slots {
  border-top: 1px dashed #e2e8f0;
  padding-top: 8px;
  font-size: 11px;
  color: #64748b;
}
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
.loader-mini { width: 14px; height: 14px; border: 2px solid #cbd5e1; border-top-color: #3b82f6; border-radius: 50%; animation: spin 0.8s linear infinite; }

.profile-mobile-header { display: none; margin-bottom: 12px; }
.profile-back-btn { display: inline-flex; align-items: center; gap: 8px; border: 1px solid #e2e8f0; background: #f8fafc; color: #334155; border-radius: 10px; height: 40px; padding: 0 12px; font-size: 14px; font-weight: 600; cursor: pointer; }

.btn-save-modern {
  background: #1877f2; 
  color: white;
  border: none;
  border-radius: 7px;
  height: 40px;
  display: flex; 
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
  width: 100%;
  cursor: pointer;
  transition: all 0.3s ease;
}
.btn-save-modern:hover:not(:disabled) {
  background: #1664d9; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(24, 119, 242, 0.22);
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
  border: 1px solid #d1d5db;
  border-radius: 7px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: #1f2937;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  width: 100%;
  transition: 0.2s;
}
.btn-create-order:hover:not(:disabled) {
  background: #f9fafb;
  border-color: #9ca3af;
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

  .ai-steps {
    grid-template-columns: 1fr;
  }

  .ai-steps--compact {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .ai-delivery-grid {
    grid-template-columns: 1fr;
  }
}

.spinner { width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #cbd5e0; gap: 8px; }
</style>
