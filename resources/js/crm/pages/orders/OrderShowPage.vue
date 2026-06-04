<template>
  <section class="order-show-page">
    <div class="toast-container position-fixed top-50 start-50 translate-middle p-3" style="z-index: 1080;">
      <div
        v-if="notice.message"
        class="toast show"
        :class="`text-bg-${notice.type}`"
        style="opacity: 0.95;"
        role="alert"
        aria-live="assertive"
        aria-atomic="true"
      >
        <div class="d-flex">
          <div class="toast-body">{{ notice.message }}</div>
          <button
            type="button"
            class="btn-close btn-close-white me-2 m-auto"
            aria-label="Close"
            @click="notice.message = ''"
          ></button>
        </div>
      </div>
    </div>

    <div class="container-fluid py-3 py-md-4">
      <div class="mx-auto show-shell">
        <div class="hero-panel">
          <div class="hero-main">
            <a href="/orders" class="btn btn-light border shadow-sm btn-back">
              <i class="bi bi-arrow-left me-2"></i>До списку
            </a>

            <div class="hero-title-block">
              <h1 class="hero-title">
                Замовлення #{{ order?.order_number || props.initialOrderId || '—' }}
              </h1>
              <p class="hero-subtitle mb-0">
                <span v-if="order?.created_at">Створено: {{ formatDate(order.created_at) }}</span>
                <span v-if="order?.client"> • Клієнт: {{ order.client }}</span>
                <span v-if="order?.phone"> • {{ order.phone }}</span>
              </p>
            </div>
          </div>

          <div class="hero-actions">
            <button
              class="btn btn-light border shadow-sm"
              type="button"
              :disabled="reloading || loading"
              @click="loadOrder({ silent: true })"
            >
              <span v-if="reloading" class="spinner-border spinner-border-sm me-2" role="status"></span>
              <i v-else class="bi bi-arrow-clockwise me-2"></i>
              Оновити
            </button>

            <button
              class="btn btn-light border shadow-sm"
              type="button"
              :disabled="!order?.ttn"
              @click="printTtn(order)"
            >
              <i class="bi bi-printer me-2"></i>Друк ТТН
            </button>

            <a
              v-if="order?.id"
              :href="`/orders/${order.id}/edit`"
              class="btn btn-primary-gradient shadow-sm"
            >
              <i class="bi bi-pencil-square me-2"></i>Редагувати
            </a>
          </div>
        </div>

        <div v-if="loading" class="state-card text-center py-5">
          <div class="spinner-border text-primary mb-3" role="status"></div>
          <div class="text-muted">Завантаження деталей замовлення...</div>
        </div>

        <div v-else-if="error" class="state-card py-4 px-4">
          <div class="alert alert-danger mb-3">
            <div class="fw-semibold mb-1">Не вдалося відкрити замовлення</div>
            <div class="small">{{ error }}</div>
          </div>
          <button class="btn btn-outline-secondary" type="button" @click="loadOrder()">
            Спробувати ще раз
          </button>
        </div>

        <template v-else-if="order">
          <div class="kpi-grid">
            <article class="kpi-card">
              <div class="kpi-label">Статус</div>
              <div class="d-flex align-items-center gap-2">
                <span class="status-chip" :style="statusChipStyle">
                  <i v-if="order.status_icon" :class="['bi', order.status_icon]"></i>
                  {{ order.status || '—' }}
                </span>
              </div>
            </article>

            <article class="kpi-card">
              <div class="kpi-label">Оплата</div>
              <div class="kpi-value">
                <span class="payment-chip" :class="paymentChipClass(order.payment_status)">
                  {{ order.payment_status_label }}
                </span>
              </div>
            </article>

            <article class="kpi-card">
              <div class="kpi-label">Сума замовлення</div>
              <div class="kpi-value">{{ formatCurrency(order.total, order.currency) }}</div>
            </article>

            <article class="kpi-card">
              <div class="kpi-label">До сплати</div>
              <div class="kpi-value text-primary fw-bold">{{ formatCurrency(amountDue, order.currency) }}</div>
            </article>
          </div>

          <div class="details-frame">
            <OrderDetails
              :order="order"
              @open-tags="openTagsModal"
              @open-statuses="openStatusesModal"
              @copy-ttn="copyTtn(order.ttn)"
              @generate-ttn="generateTtn(order)"
              @print-ttn="printTtn(order)"
              @cancel-ttn="cancelTtn(order)"
              @refresh-delivery="refreshDeliveryStatus(order)"
              @save-comment="saveComment"
            />
          </div>
        </template>
      </div>
    </div>

    <OrderTagsModal
      v-model="selectedTags"
      :open="tagsModalOpen"
      :loading="tagsModalLoading"
      :available-tags="availableTags"
      @close="closeTagsModal"
      @save="saveTags"
    />

    <OrderStatusesModal
      v-model="selectedStatusId"
      :open="statusesModalOpen"
      :loading="statusesModalLoading"
      :statuses="statuses"
      @close="closeStatusesModal"
      @save="saveStatus"
    />
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import { getOrder, updateOrderComment, updateOrderStatus, updateOrderTags } from '@/crm/api/orders';
import { fetchTags } from '@/crm/api/tags';
import { fetchStatuses } from '@/crm/api/statuses';
import OrderDetails from '@/crm/components/orders/list/OrderDetails.vue';
import OrderTagsModal from '@/crm/components/orders/list/OrderTagsModal.vue';
import OrderStatusesModal from '@/crm/components/orders/list/OrderStatusesModal.vue';
import { buildPhotoUrl, formatCurrency, formatDate, paymentLabels, statusLabels } from '@/crm/utils/orderDisplay';

const props = defineProps({
  initialOrderId: { type: [String, Number], default: null },
});

const loading = ref(true);
const reloading = ref(false);
const error = ref('');
const order = ref(null);

const tagsModalOpen = ref(false);
const tagsModalLoading = ref(false);
const availableTags = ref([]);
const selectedTags = ref([]);

const statusesModalOpen = ref(false);
const statusesModalLoading = ref(false);
const statuses = ref([]);
const selectedStatusId = ref(null);

const notice = reactive({ type: 'success', message: '' });
let noticeTimer = null;

const amountDue = computed(() => {
  const total = Number(order.value?.total || 0);
  const prepay = Number(order.value?.prepay_amount || 0);
  return Math.max(0, total - prepay);
});

const statusChipStyle = computed(() => {
  const color = String(order.value?.status_color || '').trim();
  if (!color) return {};
  if (color.startsWith('#')) {
    return {
      color,
      borderColor: `${color}55`,
      backgroundColor: `${color}16`,
    };
  }

  return {
    color,
    borderColor: color,
    backgroundColor: 'transparent',
  };
});

function paymentChipClass(status) {
  const map = {
    paid: 'chip-paid',
    unpaid: 'chip-unpaid',
    prepayment: 'chip-prepay',
    prepaid: 'chip-prepay',
    refund: 'chip-refund',
  };
  return map[status] || 'chip-default';
}

function showNotice(message, type = 'success') {
  notice.type = type;
  notice.message = message;
  if (noticeTimer) {
    clearTimeout(noticeTimer);
  }
  noticeTimer = setTimeout(() => {
    notice.message = '';
  }, 2500);
}

function mapOrder(payload) {
  const customer = payload.customer || {};
  const delivery = payload.delivery || {};
  const statusRef = payload.statusRef || payload.status_ref || {};
  const sourceRef = payload.source || {};

  let payment = payload.payment;
  if (Array.isArray(payment)) payment = payment[0];
  payment = payment || {};

  const items = (payload.items || []).map((item) => ({
    ...item,
    title: item.product?.title || item.product_title || item.title || 'Товар',
    size: item.variant?.size || item.size || '',
    photo: buildPhotoUrl(item.product?.main_photo_url || item.product?.main_photo_path),
    total: Number(item.total ?? Number(item.qty || 0) * Number(item.price || 0)),
  }));

  const itemsTotal = items.reduce((sum, item) => sum + Number(item.total || 0), 0);
  const total = Number(payload.items_sum_total ?? payload.total_sum ?? itemsTotal);

  const paymentMethod = payment.method || payload.payment_method || '';
  const paymentMethodLabel = paymentMethod === 'cod'
    ? 'Накладений платіж'
    : paymentMethod === 'card'
      ? 'Оплата на рахунок'
      : paymentMethod === 'prepay'
        ? 'Часткова передоплата'
        : paymentMethod || '—';

  const deliveryPayer = delivery.delivery_payer === 'sender'
    ? 'Відправник'
    : delivery.delivery_payer === 'recipient'
      ? 'Отримувач'
      : (delivery.delivery_payer || '—');

  const sourceLabel = sourceRef.name || (typeof payload.source === 'string' ? payload.source : (payload.source_name || '—'));

  const address = (delivery.delivery_type === 'courier'
    ? [delivery.city_name, delivery.street_name].filter(Boolean).join(', ')
    : delivery.warehouse_name) || '—';

  return {
    ...payload,
    id: payload.id,
    order_number: payload.order_number || payload.id,
    customer_id: payload.customer_id || customer.id || null,
    client: customer.full_name || [customer.first_name, customer.last_name].filter(Boolean).join(' ') || 'Гість',
    phone: customer.phone || '',
    email: customer.email || '',
    source_name: sourceLabel,
    source_icon: sourceRef.icon ? `bi ${sourceRef.icon}` : '',
    source_color: sourceRef.color || '',
    status_id: payload.status_id || statusRef.id || null,
    status_key: statusRef.code || payload.status || 'new',
    status: statusRef.name || statusLabels[payload.status] || payload.status || '—',
    status_icon: statusRef.icon || '',
    status_color: statusRef.color || '',
    payment_status: payload.payment_status || '',
    payment_status_label: paymentLabels[payload.payment_status] || payload.payment_status || '—',
    tags: payload.tags || [],
    items,
    total,
    currency: payload.currency || payment.currency || 'UAH',
    prepay_amount: Number(payload.prepay_amount ?? payment.prepay_amount ?? 0),
    payment_method_label: paymentMethodLabel,
    ttn: delivery.ttn || '',
    ttn_ref: delivery.ref || delivery.ttn_ref || '',
    loadingTtn: false,
    refreshingDelivery: false,
    address,
    city_name: delivery.city_name || '',
    delivery_status: delivery.delivery_status_label || '',
    delivery_status_code: delivery.delivery_status_code || '',
    delivery_status_updated_at: delivery.delivery_status_updated_at || '',
    delivery_status_color: delivery.delivery_status_color || '',
    delivery_status_icon: delivery.delivery_status_icon || '',
    delivery_carrier: delivery.carrier === 'nova_poshta' ? 'Нова Пошта' : (delivery.carrier || 'Самовивіз'),
    delivery_payer: deliveryPayer,
    delivery_type: delivery.delivery_type || 'warehouse',
    street_name: delivery.street_name || '',
    building: delivery.building || '',
    apartment: delivery.apartment || '',
    comment: payload.comment_internal || '',
    latestFiscalReceipt: payload.latest_fiscal_receipt || payload.latestFiscalReceipt || null,
    created_at: payload.created_at,
  };
}

async function loadOrder({ silent = false } = {}) {
  if (!props.initialOrderId) {
    error.value = 'Не передано ідентифікатор замовлення.';
    loading.value = false;
    return;
  }

  if (silent) {
    reloading.value = true;
  } else {
    loading.value = true;
    error.value = '';
  }

  try {
    const { data } = await getOrder(props.initialOrderId);
    const payload = data?.data || data || {};
    order.value = mapOrder(payload);
    if (tagsModalOpen.value) {
      selectedTags.value = order.value.tags.map((tag) => tag.id);
    }
  } catch (e) {
    const message = e.response?.status === 404
      ? 'Замовлення не знайдено.'
      : (e.response?.data?.message || 'Помилка завантаження даних.');

    if (silent) {
      showNotice(message, 'danger');
    } else {
      error.value = message;
    }
  } finally {
    loading.value = false;
    reloading.value = false;
  }
}

async function ensureTagsLoaded() {
  if (availableTags.value.length) return;
  tagsModalLoading.value = true;
  try {
    const { data } = await fetchTags();
    const list = data?.data || data || [];
    availableTags.value = Array.isArray(list) ? list : [];
  } catch (e) {
    console.error('Не вдалося завантажити теги', e);
    availableTags.value = [];
  } finally {
    tagsModalLoading.value = false;
  }
}

async function ensureStatusesLoaded() {
  if (statuses.value.length) return;
  statusesModalLoading.value = true;
  try {
    const { data } = await fetchStatuses({ type: 'order' });
    const list = data?.data || data || [];
    statuses.value = Array.isArray(list) ? list : [];
  } catch (e) {
    console.error('Не вдалося завантажити статуси', e);
    statuses.value = [];
  } finally {
    statusesModalLoading.value = false;
  }
}

async function preloadDictionaries() {
  await Promise.allSettled([ensureTagsLoaded(), ensureStatusesLoaded()]);
}

async function openTagsModal() {
  if (!order.value) return;
  tagsModalOpen.value = true;
  selectedTags.value = order.value.tags.map((tag) => tag.id);
  await ensureTagsLoaded();
}

function closeTagsModal() {
  tagsModalOpen.value = false;
  selectedTags.value = [];
}

async function saveTags() {
  if (!order.value?.id) return;
  try {
    const { data } = await updateOrderTags(order.value.id, selectedTags.value);
    const updated = data?.data || data || [];
    order.value.tags = Array.isArray(updated) ? updated : [];
    showNotice('Теги оновлено');
    closeTagsModal();
  } catch (e) {
    console.error('Не вдалося оновити теги', e);
    showNotice('Не вдалося оновити теги', 'danger');
  }
}

async function openStatusesModal() {
  if (!order.value) return;
  statusesModalOpen.value = true;
  selectedStatusId.value = order.value.status_id || null;
  await ensureStatusesLoaded();
}

function closeStatusesModal() {
  statusesModalOpen.value = false;
  selectedStatusId.value = null;
}

async function saveStatus() {
  if (!order.value?.id || !selectedStatusId.value) return;
  try {
    const { data } = await updateOrderStatus(order.value.id, selectedStatusId.value);
    const status = data?.data || data || {};
    order.value.status_id = status.id || order.value.status_id;
    order.value.status_key = status.code || order.value.status_key;
    order.value.status = status.name || order.value.status;
    order.value.status_icon = status.icon || '';
    order.value.status_color = status.color || '';
    showNotice('Статус оновлено');
    closeStatusesModal();
  } catch (e) {
    console.error('Не вдалося оновити статус', e);
    showNotice('Не вдалося оновити статус', 'danger');
  }
}

async function saveComment(comment) {
  if (!order.value?.id) return;
  const trimmed = typeof comment === 'string' ? comment.trim() : '';
  const nextValue = trimmed === '' ? null : trimmed;

  try {
    const { data } = await updateOrderComment(order.value.id, nextValue);
    order.value.comment = data?.comment_internal ?? nextValue ?? '';
    showNotice('Нотатку збережено');
  } catch (e) {
    console.error('Не вдалося зберегти нотатку', e);
    showNotice('Не вдалося зберегти нотатку', 'danger');
  }
}

async function copyTtn(ttn) {
  if (!ttn || typeof navigator === 'undefined' || !navigator.clipboard) return;
  try {
    await navigator.clipboard.writeText(ttn);
    showNotice('ТТН скопійовано');
  } catch (err) {
    console.error('Не вдалося скопіювати ТТН', err);
    showNotice('Не вдалося скопіювати ТТН', 'danger');
  }
}

async function generateTtn(targetOrder) {
  if (!targetOrder?.id || targetOrder.loadingTtn) return;
  targetOrder.loadingTtn = true;

  try {
    const deliveryType = targetOrder?.delivery_type || 'warehouse';
    const endpoint = deliveryType === 'courier'
      ? `/orders/${targetOrder.id}/generate-ttn-courier`
      : `/orders/${targetOrder.id}/generate-ttn`;

    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
        Accept: 'application/json',
      },
    });

    const result = await response.json();
    if (result.success || result.ttn) {
      targetOrder.ttn = result.ttn || result.data?.[0]?.IntDocNumber || '';
      targetOrder.ttn_ref = result.ref || result.data?.[0]?.Ref || '';
      showNotice(`ТТН ${targetOrder.ttn} створена`);
    } else {
      const message = result.message || 'Помилка API Нової Пошти';
      showNotice(message, 'danger');
    }
  } catch (e) {
    console.error('Помилка при створенні ТТН', e);
    showNotice('Не вдалося створити ТТН', 'danger');
  } finally {
    targetOrder.loadingTtn = false;
  }
}

async function cancelTtn(targetOrder) {
  if (!targetOrder?.id || !targetOrder.ttn || targetOrder.loadingTtn) return;
  if (!window.confirm('Ви впевнені, що хочете анулювати ТТН?')) return;

  targetOrder.loadingTtn = true;
  try {
    const { data } = await axios.post(`/orders/${targetOrder.id}/cancel-ttn`);
    if (data?.success) {
      targetOrder.ttn = '';
      targetOrder.ttn_ref = '';
      showNotice('ТТН успішно анульовано');
    } else {
      showNotice(data?.message || 'Не вдалося анулювати ТТН', 'danger');
    }
  } catch (e) {
    console.error('Помилка при анулюванні ТТН', e);
    showNotice(e.response?.data?.message || 'Не вдалося анулювати ТТН', 'danger');
  } finally {
    targetOrder.loadingTtn = false;
  }
}

async function printTtn(targetOrder) {
  if (!targetOrder?.id || !targetOrder.ttn) return;
  try {
    const { data } = await axios.get(`/orders/${targetOrder.id}/print-ttn`);
    if (data?.success && data?.print_url) {
      window.open(data.print_url, '_blank');
      return;
    }
    showNotice('Не вдалося отримати посилання на друк', 'danger');
  } catch (e) {
    console.error('Помилка при друці ТТН', e);
    showNotice('Помилка при друці ТТН', 'danger');
  }
}

async function refreshDeliveryStatus(targetOrder) {
  if (!targetOrder?.id || targetOrder.refreshingDelivery) return;
  targetOrder.refreshingDelivery = true;
  try {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const response = await axios.post(
      `/orders/${targetOrder.id}/track-delivery`,
      {},
      csrf ? { headers: { 'X-CSRF-TOKEN': csrf } } : undefined
    );

    const payload = response.data?.data || response.data || {};
    if (payload.delivery_status_label || payload.delivery_status_code) {
      targetOrder.delivery_status = payload.delivery_status_label || targetOrder.delivery_status;
      targetOrder.delivery_status_code = payload.delivery_status_code || targetOrder.delivery_status_code;
      targetOrder.delivery_status_updated_at = payload.delivery_status_updated_at || targetOrder.delivery_status_updated_at;
      targetOrder.delivery_status_color = payload.delivery_status_color || targetOrder.delivery_status_color;
      targetOrder.delivery_status_icon = payload.delivery_status_icon || targetOrder.delivery_status_icon;
      showNotice('Статус доставки оновлено');
    } else {
      showNotice(payload.message || 'Дані про доставку вже актуальні', 'success');
    }
  } catch (e) {
    console.error('Помилка оновлення доставки', e);
    showNotice(e.response?.data?.message || 'Не вдалося оновити доставку', 'danger');
  } finally {
    targetOrder.refreshingDelivery = false;
  }
}

onMounted(async () => {
  await Promise.all([loadOrder(), preloadDictionaries()]);
});

onBeforeUnmount(() => {
  if (noticeTimer) {
    clearTimeout(noticeTimer);
  }
});
</script>

<style scoped>
.order-show-page {
  --bg: #f6f7fb;
  --card: #ffffff;
  --ink: #111827;
  --line: rgba(17, 24, 39, 0.08);
  --shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
  background:
    radial-gradient(950px 380px at 6% 0%, rgba(109, 94, 252, 0.12), transparent 65%),
    radial-gradient(760px 310px at 95% 10%, rgba(39, 194, 160, 0.11), transparent 58%),
    var(--bg);
  min-height: calc(100vh - 58px);
  color: var(--ink);
}

.show-shell {
  max-width: 1520px;
}

.hero-panel {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  padding: 16px;
  border-radius: 18px;
  border: 1px solid var(--line);
  background: linear-gradient(135deg, #ffffff, #f8fbff);
  box-shadow: var(--shadow);
  margin-bottom: 14px;
}

.hero-main {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  min-width: 0;
}

.btn-back {
  white-space: nowrap;
}

.hero-title {
  font-size: 1.32rem;
  line-height: 1.2;
  margin-bottom: 4px;
  font-weight: 800;
  letter-spacing: -0.01em;
}

.hero-subtitle {
  font-size: 0.88rem;
  color: #475569;
}

.hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.btn-primary-gradient {
  background: linear-gradient(135deg, #6366f1, #4f46e5);
  border: none;
  color: #fff;
}

.btn-primary-gradient:hover {
  color: #fff;
  box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);
}

.state-card {
  border-radius: 16px;
  border: 1px solid var(--line);
  background: var(--card);
  box-shadow: var(--shadow);
}

.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 12px;
}

.kpi-card {
  background: rgba(255, 255, 255, 0.94);
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 12px 14px;
  box-shadow: 0 6px 14px rgba(15, 23, 42, 0.04);
}

.kpi-label {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 700;
  color: #64748b;
  margin-bottom: 6px;
}

.kpi-value {
  font-size: 1rem;
  font-weight: 700;
  color: #0f172a;
}

.status-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 0.82rem;
  font-weight: 700;
  border: 1px solid #d1d5db;
  border-radius: 999px;
  padding: 4px 10px;
  background: #fff;
}

.payment-chip {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 700;
  padding: 4px 10px;
  border: 1px solid transparent;
}

.chip-paid {
  color: #15803d;
  background: #dcfce7;
  border-color: #bbf7d0;
}

.chip-unpaid {
  color: #b91c1c;
  background: #fee2e2;
  border-color: #fecaca;
}

.chip-prepay {
  color: #b45309;
  background: #fef3c7;
  border-color: #fde68a;
}

.chip-refund {
  color: #1f2937;
  background: #e5e7eb;
  border-color: #d1d5db;
}

.chip-default {
  color: #475569;
  background: #f1f5f9;
  border-color: #e2e8f0;
}

.details-frame {
  border-radius: 16px;
  overflow: hidden;
  box-shadow: var(--shadow);
  border: 1px solid var(--line);
}

@media (max-width: 1199.98px) {
  .kpi-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 767.98px) {
  .order-show-page {
    min-height: auto;
  }

  .hero-panel {
    border-radius: 14px;
    padding: 12px;
  }

  .hero-main {
    width: 100%;
    flex-direction: column;
    gap: 10px;
  }

  .hero-actions {
    width: 100%;
  }

  .hero-actions .btn {
    flex: 1;
    justify-content: center;
  }

  .hero-title {
    font-size: 1.1rem;
  }

  .hero-subtitle {
    font-size: 0.8rem;
  }

  .kpi-grid {
    grid-template-columns: 1fr;
  }
}
</style>
