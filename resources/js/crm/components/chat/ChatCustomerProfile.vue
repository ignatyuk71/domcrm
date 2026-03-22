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

    <div v-if="customerId" v-show="!showOrderPanel" class="profile-content custom-scrollbar" ref="profileContainer">
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

        <div class="ai-settings-container">
          <div class="section-header">
            <span class="section-title">AI first line</span>
            <span class="counter-badge" :class="conversationAiEnabled ? 'counter-badge--success' : 'counter-badge--neutral'">
              {{ conversationAiEnabled ? 'ON' : 'OFF' }}
            </span>
          </div>

          <div class="ai-settings-card" :class="{ 'is-active': aiPanelOpen }">
            <button class="ai-settings-header" type="button" @click="aiPanelOpen = !aiPanelOpen">
              <div class="header-left">
                <div class="order-id-row">
                  <span class="id-text">AI у цьому чаті</span>
                  <span class="date-text">{{ aiRuntime.assistant_name || 'DomCRM AI' }}</span>
                </div>

                <div class="ai-pill-row">
                  <span class="status-badge" :class="conversationAiEnabled ? 'status-on' : 'status-off'">
                    {{ conversationAiEnabled ? 'Увімкнено' : 'Вимкнено' }}
                  </span>
                  <span class="status-badge status-model">{{ aiRuntime.model || 'Модель не задано' }}</span>
                </div>
              </div>

              <div class="header-right">
                <div class="toggle-btn">
                  <i class="bi bi-chevron-down"></i>
                </div>
              </div>
            </button>

            <div class="ai-settings-body-wrapper">
              <div class="ai-settings-body">
                <div v-if="aiRuntimeLoading" class="ai-inline-loader">
                  <span class="loader-mini"></span>
                  <span>Завантаження налаштувань...</span>
                </div>

                <template v-else>
                  <div class="ai-control-row">
                    <div class="ai-control-copy">
                      <strong>Автовідповідь у цьому діалозі</strong>
                      <span v-if="!globalAiEnabled">Глобально AI вимкнений у системі. Увімкни в налаштуваннях AI.</span>
                      <span v-else>Коли увімкнено, AI відповідає на вхідні повідомлення клієнта.</span>
                    </div>

                    <label class="ai-switch" :class="{ 'is-disabled': aiActionLoading || !globalAiEnabled }">
                      <input
                        type="checkbox"
                        :checked="conversationAiEnabled"
                        :disabled="aiActionLoading || !globalAiEnabled"
                        @change="onConversationAiToggle"
                      >
                      <span class="ai-switch-track"></span>
                    </label>
                  </div>

                  <div class="ai-status-note" :class="aiStatusClass">
                    <i class="bi" :class="aiStatusIcon"></i>
                    <span>{{ aiStatusNote }}</span>
                  </div>

                  <div v-if="props.customer?.ai?.handoff_reason" class="ai-handoff-note">
                    <i class="bi bi-person-raised-hand"></i>
                    <span>Причина передачі менеджеру: {{ props.customer.ai.handoff_reason }}</span>
                  </div>

                  <div class="ai-actions-row">
                    <button
                      type="button"
                      class="btn-ai-secondary"
                      :disabled="aiActionLoading"
                      @click="takeoverToManager"
                    >
                      <span v-if="aiActionLoading" class="loader-mini"></span>
                      Передати менеджеру
                    </button>
                  </div>

                  <div class="ai-qualification-block">
                    <span class="ai-qualification-title">Поля для збору</span>
                    <div class="ai-qualification-list">
                      <span
                        v-for="field in qualificationFields"
                        :key="field"
                        class="ai-qualification-label"
                        :class="isFieldCollected(field) ? 'is-filled' : 'is-empty'"
                      >
                        {{ field }}
                      </span>
                    </div>

                    <div class="ai-collected-list">
                      <span class="ai-collected-title">Вже зібрано</span>
                      <ul v-if="collectedFieldRows.length > 0" class="ai-collected-items">
                        <li v-for="row in collectedFieldRows" :key="row.field">
                          <strong>{{ row.field }}:</strong> {{ row.value }}
                        </li>
                      </ul>
                      <span v-else class="ai-qualification-collected">Ще немає зібраних полів.</span>
                    </div>
                  </div>
                </template>
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

const props = defineProps({ customer: Object });
const emit = defineEmits(['close', 'update-stage']);

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
const historyReady = ref(false);
const aiPanelOpen = ref(false);
const aiRuntimeLoading = ref(false);
const aiActionLoading = ref(false);
const aiRuntime = reactive({
  enabled: false,
  assistant_name: '',
  model: '',
  max_messages: 12,
  qualification_fields: [],
});
const placeholderThumb = 'https://via.placeholder.com/48x48?text=%20';
const avatarFailed = ref(false);
let historyRequestToken = 0;
let aiSettingsRequestToken = 0;

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
  if (cleaned.startsWith('0')) {
    cleaned = '38' + cleaned;
  } else if (cleaned.startsWith('38') && !cleaned.startsWith('380')) {
    cleaned = '380' + cleaned.slice(2);
  }
  if (cleaned.startsWith('3800')) {
    cleaned = '380' + cleaned.slice(4);
  }
  form.phone = cleaned.substring(0, 12);
});

const customerId = computed(() => props.customer?.id ?? props.customer?.customer_id ?? null);
const displayName = computed(() => {
  const name = `${form.first_name} ${form.last_name}`.trim();
  return name || props.customer?.customer_name || 'Не заповнено';
});
const displayInitial = computed(() => (displayName.value ? displayName.value[0].toUpperCase() : '?'));
const avatarUrl = computed(() => props.customer?.fb_profile_pic || props.customer?.customer_avatar || '');
const safeAvatarUrl = computed(() => (avatarFailed.value ? '' : avatarUrl.value));
const isInstagram = computed(() => (props.customer?.source || props.customer?.platform) === 'instagram' || !!props.customer?.instagram_user_id);
const globalAiEnabled = computed(() => {
  if (typeof props.customer?.ai?.global_enabled === 'boolean') {
    return props.customer.ai.global_enabled;
  }

  return Boolean(aiRuntime.enabled);
});
const conversationAiEnabled = computed(() => {
  if (typeof props.customer?.ai?.enabled === 'boolean') {
    return props.customer.ai.enabled;
  }

  return globalAiEnabled.value;
});
const qualificationFields = computed(() => {
  const fields = Array.isArray(aiRuntime.qualification_fields)
    ? aiRuntime.qualification_fields
    : [];

  const normalized = fields
    .map((field) => String(field || '').trim())
    .filter((field) => field !== '');

  if (normalized.length > 0) {
    return normalized;
  }

  return ['імʼя', 'телефон', 'товар', 'бюджет', 'термін', 'місто'];
});
const collectedFieldRows = computed(() => {
  return qualificationFields.value
    .map((field) => ({
      field,
      value: resolveFieldValue(field),
    }))
    .filter((row) => row.value !== '');
});
const aiStatusNote = computed(() => {
  const lastError = String(props.customer?.ai?.last_error || '').trim();
  if (lastError !== '') {
    return `Помилка AI: ${lastError}`;
  }

  const statusNote = String(props.customer?.ai?.status_note || '').trim();
  if (statusNote !== '') {
    return statusNote;
  }

  const handoffReason = String(props.customer?.ai?.handoff_reason || '').trim();
  if (handoffReason !== '') {
    return `Передано менеджеру: ${handoffReason}`;
  }

  if (!globalAiEnabled.value) {
    return 'AI глобально вимкнений у системі.';
  }

  if (!conversationAiEnabled.value) {
    return 'AI вимкнено в цьому діалозі менеджером.';
  }

  return 'AI активний у цьому чаті та обробляє нові вхідні повідомлення клієнта.';
});
const aiStatusClass = computed(() => {
  const hasError = String(props.customer?.ai?.last_error || '').trim() !== '';
  if (hasError) {
    return 'is-error';
  }

  if (!conversationAiEnabled.value || String(props.customer?.ai?.handoff_reason || '').trim() !== '') {
    return 'is-warning';
  }

  return 'is-info';
});
const aiStatusIcon = computed(() => {
  if (aiStatusClass.value === 'is-error') {
    return 'bi-exclamation-octagon';
  }

  if (aiStatusClass.value === 'is-warning') {
    return 'bi-person-raised-hand';
  }

  return 'bi-info-circle';
});

const normalizeFieldKey = (value) => String(value || '')
  .toLowerCase()
  .replace(/’/g, "'")
  .trim();

const resolveFieldValue = (field) => {
  const key = normalizeFieldKey(field);

  if (key.includes('ім') || key.includes('прізв')) {
    const fullName = `${form.first_name || ''} ${form.last_name || ''}`.trim();
    return fullName;
  }

  if (key.includes('тел')) {
    return isPhoneValid.value ? form.phone : '';
  }

  if (key.includes('email') || key.includes('e-mail') || key.includes('емейл') || key.includes('пошта')) {
    return String(form.email || '').trim();
  }

  return '';
};

const isFieldCollected = (field) => {
  return resolveFieldValue(field) !== '';
};

function syncFormFromCustomer(customer, { resetPanels = false } = {}) {
  if (!customer) {
    return;
  }

  form.first_name = customer.first_name || '';
  form.last_name = customer.last_name || '';
  form.phone = customer.phone ? customer.phone.replace(/\D/g, '') : '';
  form.email = customer.email || '';
  showPhoneInput.value = !!form.phone;
  showEmailInput.value = !!form.email;

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
      aiPanelOpen.value = false;
      await loadAiRuntimeSettings();
    }
  },
  { immediate: true }
);

watch(
  () => [props.customer?.first_name, props.customer?.last_name, props.customer?.phone, props.customer?.email],
  () => {
    if (!props.customer || showNameInput.value) {
      return;
    }

    syncFormFromCustomer(props.customer, { resetPanels: false });
  }
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
const enablePhone = async () => { showPhoneInput.value = true; if (!form.phone) form.phone = '380'; await nextTick(); phoneRef.value?.focus(); };
const clearPhone = () => { form.phone = ''; showPhoneInput.value = false; };
const enableEmail = async () => { showEmailInput.value = true; await nextTick(); };
const clearEmail = () => { form.email = ''; showEmailInput.value = false; };

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

const loadAiRuntimeSettings = async () => {
  const requestToken = ++aiSettingsRequestToken;
  aiRuntimeLoading.value = true;

  try {
    const { data } = await axios.get('/settings/ai', {
      headers: { Accept: 'application/json' },
    });

    if (requestToken !== aiSettingsRequestToken) {
      return;
    }

    const settings = data?.settings || {};
    aiRuntime.enabled = Boolean(settings.enabled);
    aiRuntime.assistant_name = settings.assistant_name || '';
    aiRuntime.model = settings.model || '';
    aiRuntime.max_messages = Number(settings.max_messages || 12);
    aiRuntime.qualification_fields = Array.isArray(settings.qualification_fields)
      ? settings.qualification_fields
      : [];
  } catch (e) {
    console.error('Не вдалося завантажити AI налаштування', e);
  } finally {
    if (requestToken === aiSettingsRequestToken) {
      aiRuntimeLoading.value = false;
    }
  }
};

const applyConversationSnapshot = (snapshot) => {
  if (!props.customer || !snapshot) {
    return;
  }

  Object.assign(props.customer, snapshot);
};

const onConversationAiToggle = async (event) => {
  const enabled = Boolean(event?.target?.checked);

  if (!props.customer?.conversation_id) {
    showToast('Для цього чату немає conversation_id.', 'error');
    return;
  }

  aiActionLoading.value = true;
  try {
    const { data } = await axios.patch(`/api/chat/conversations/${props.customer.conversation_id}/ai`, {
      enabled,
    });

    if (data?.conversation) {
      applyConversationSnapshot(data.conversation);
    } else if (props.customer) {
      props.customer.ai = data?.ai || { enabled };
    }

    showToast(enabled ? 'AI увімкнено для цього діалогу.' : 'AI вимкнено для цього діалогу.');
  } catch (e) {
    console.error('Не вдалося оновити AI стан діалогу', e);
    showToast('Не вдалося змінити стан AI.', 'error');
  } finally {
    aiActionLoading.value = false;
  }
};

const takeoverToManager = async () => {
  if (!props.customer?.conversation_id) {
    showToast('Для цього чату немає conversation_id.', 'error');
    return;
  }

  aiActionLoading.value = true;
  try {
    const { data } = await axios.post(`/api/chat/conversations/${props.customer.conversation_id}/takeover`, {
      reason: 'Передано менеджеру вручну',
    });

    if (data?.conversation) {
      applyConversationSnapshot(data.conversation);
    } else if (props.customer) {
      props.customer.ai = data?.ai || { enabled: false };
    }

    showToast('Діалог передано менеджеру.');
  } catch (e) {
    console.error('Не вдалося передати діалог менеджеру', e);
    showToast('Не вдалося передати менеджеру.', 'error');
  } finally {
    aiActionLoading.value = false;
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
    showToast('Покупця успішно збережено.');
  } catch (e) { 
    console.error(e); 
    showToast('Не вдалося зберегти дані покупця.', 'error');
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
.profile-content { flex: 1; overflow-y: auto; padding: 0; scroll-behavior: smooth; }
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
.fields-section { display: flex; flex-direction: column; gap: 12px; padding: 16px; }
.field-row { display: flex; align-items: flex-start; }
.icon-col { width: 32px; color: #cbd5e0; font-size: 18px; padding-top: 18px; }
.input-col label { font-size: 10px; font-weight: 700; color: #a0aec0; text-transform: uppercase; margin-bottom: 2px; display: block; }
.input-group { display: flex; align-items: center; border-bottom: 2px solid #edf2f7; padding: 2px 0; }
.simple-input { flex: 1; border: none; background: transparent; font-size: 14px; color: #2d3748; outline: none; font-weight: 600; }
.error-text { color: #ef4444; font-size: 10px; margin-top: 2px; }
.add-btn { color: #6366f1; font-size: 13px; font-weight: 600; cursor: pointer; padding: 4px 0; }
.action-row { display: flex; flex-direction: column; gap: 10px; margin-top: 8px; }
.history-container { margin-top: 18px; }
.section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; padding: 0 4px; }
.section-title { font-size: 12px; font-weight: 800; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.05em; }
.counter-badge { background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px; }
.counter-badge--success { background: #dcfce7; color: #15803d; }
.counter-badge--neutral { background: #e2e8f0; color: #475569; }
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
.ai-settings-container { margin-top: 18px; }
.ai-settings-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; transition: all 0.2s ease; }
.ai-settings-card.is-active { border-color: #cbd5e1; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
.ai-settings-header { width: 100%; display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #fff; border: none; cursor: pointer; text-align: left; }
.ai-settings-body-wrapper { max-height: 0; overflow: hidden; transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1); background: #f8fafc; }
.ai-settings-card.is-active .ai-settings-body-wrapper { max-height: 520px; border-top: 1px solid #f1f5f9; }
.ai-settings-body { padding: 12px; display: flex; flex-direction: column; gap: 12px; }
.ai-pill-row { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
.status-badge.status-on { background: #dcfce7; color: #15803d; }
.status-badge.status-off { background: #f1f5f9; color: #64748b; }
.status-badge.status-model { background: #eef2ff; color: #4f46e5; }
.ai-control-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; }
.ai-control-copy { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.ai-control-copy strong { font-size: 12px; color: #0f172a; }
.ai-control-copy span { font-size: 11px; color: #64748b; line-height: 1.35; }
.ai-switch { display: inline-flex; align-items: center; cursor: pointer; }
.ai-switch.is-disabled { cursor: not-allowed; opacity: 0.65; }
.ai-switch input { display: none; }
.ai-switch-track { width: 46px; height: 26px; border-radius: 999px; background: #cbd5e1; position: relative; transition: all 0.2s ease; }
.ai-switch-track::after { content: ''; position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.25); }
.ai-switch input:checked + .ai-switch-track { background: #22c55e; }
.ai-switch input:checked + .ai-switch-track::after { transform: translateX(20px); }
.ai-qualification-block { display: flex; flex-direction: column; gap: 8px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; }
.ai-qualification-title { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: #94a3b8; font-weight: 700; }
.ai-qualification-list { display: flex; flex-wrap: wrap; gap: 6px; }
.ai-qualification-label { font-size: 11px; font-weight: 700; border-radius: 999px; padding: 4px 10px; border: 1px solid #cbd5e1; background: #f8fafc; color: #475569; }
.ai-qualification-label.is-filled { background: #dcfce7; border-color: #bbf7d0; color: #15803d; }
.ai-qualification-label.is-empty { background: #f8fafc; border-color: #cbd5e1; color: #64748b; }
.ai-qualification-collected { font-size: 11px; color: #64748b; }
.ai-collected-list { display: flex; flex-direction: column; gap: 6px; margin-top: 2px; }
.ai-collected-title { font-size: 11px; font-weight: 700; color: #475569; }
.ai-collected-items { margin: 0; padding-left: 18px; display: flex; flex-direction: column; gap: 4px; }
.ai-collected-items li { font-size: 12px; color: #334155; line-height: 1.35; }
.ai-collected-items li strong { color: #0f172a; font-weight: 700; }
.ai-status-note { display: flex; align-items: flex-start; gap: 8px; border-radius: 10px; padding: 9px 10px; font-size: 12px; line-height: 1.4; border: 1px solid #bfdbfe; background: #eff6ff; color: #1e3a8a; }
.ai-status-note.is-warning { background: #fffbeb; border-color: #fde68a; color: #854d0e; }
.ai-status-note.is-error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
.ai-status-note i { font-size: 14px; margin-top: 1px; }
.ai-handoff-note { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #854d0e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 9px 10px; }
.ai-actions-row { display: flex; gap: 8px; }
.btn-ai-secondary { height: 38px; border-radius: 8px; padding: 0 12px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; }
.btn-ai-secondary { border: 1px solid #cbd5e1; background: #fff; color: #1e293b; cursor: pointer; flex: 1; }
.btn-ai-secondary:hover:not(:disabled) { background: #f8fafc; border-color: #94a3b8; }
.btn-ai-secondary:disabled { opacity: 0.7; cursor: not-allowed; }
.ai-inline-loader { display: inline-flex; align-items: center; gap: 8px; color: #64748b; font-size: 12px; }
.loader-mini { width: 14px; height: 14px; border: 2px solid #cbd5e1; border-top-color: #3b82f6; border-radius: 50%; animation: spin 0.8s linear infinite; }

.profile-mobile-header { display: none; margin-bottom: 12px; }
.profile-back-btn { display: inline-flex; align-items: center; gap: 8px; border: 1px solid #e2e8f0; background: #f8fafc; color: #334155; border-radius: 10px; height: 40px; padding: 0 12px; font-size: 14px; font-weight: 600; cursor: pointer; }

.btn-save-modern {
  background: #1877f2; 
  color: white;
  border: none;
  border-radius: 8px;
  height: 44px;
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
  border-radius: 8px;
  height: 44px;
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

  .ai-actions-row {
    flex-direction: column;
  }
}

.spinner { width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #cbd5e0; gap: 8px; }
</style>
