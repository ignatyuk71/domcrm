<template>
  <section class="workspace">
    <OrdersTopbar
      v-model:search="filters.search"
      :status-chips="statusChips"
      :is-status-active="isStatusActive"
      :hold-filter-enabled="true"
      :hold-filter-days="holdFilterDays"
      :hold-filter-options="holdFilterOptions"
      @search="handleSearch"
      @toggle-status="toggleStatus"
      @update:hold-days="updateHoldDays"
    />

    <div class="card border-0 shadow-sm overflow-hidden">
      <OrdersTable
        :orders="orders"
        :expanded-rows="expandedRows"
        :deleting-id="deletingId"
        :loading="loading"
        :hold-filter-days="holdFilterDays"
        @toggle-row="toggleRow"
        @delete="handleDelete"
        @open-tags="openTagsModal"
        @open-statuses="openStatusesModal"
        @copy-ttn="copyTtn"
        @generate-ttn="generateTtn"
        @print-ttn="printTtn"
        @cancel-ttn="handleCancelTtn"
        @refresh-delivery="refreshDeliveryStatus"
        @open-customer="openCustomer"
      />

      <OrdersPagination v-if="meta.last_page > 1" :meta="meta" @change-page="changePage" />
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

    <CustomerQuickView
      :open="customerPanelOpen"
      :data="customerData"
      :loading="customerLoading"
      :error="customerError"
      @close="closeCustomerPanel"
    />
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import OrdersTable from '@/crm/components/orders/list/OrdersTable.vue';
import OrdersTopbar from '@/crm/components/orders/list/OrdersTopbar.vue';
import OrdersPagination from '@/crm/components/orders/list/OrdersPagination.vue';
import OrderTagsModal from '@/crm/components/orders/list/OrderTagsModal.vue';
import OrderStatusesModal from '@/crm/components/orders/list/OrderStatusesModal.vue';
import CustomerQuickView from '@/crm/components/orders/list/CustomerQuickView.vue';
import { listOrders, deleteOrder, updateOrderTags, updateOrderStatus } from '@/crm/api/orders';
import { fetchTags } from '@/crm/api/tags';
import { fetchStatuses } from '@/crm/api/statuses';
import { getCustomer } from '@/crm/api/customers';
import {
  buildPhotoUrl,
  paymentLabels,
  statusLabels,
} from '@/crm/utils/orderDisplay';

const orders = ref([]);
const expandedRows = ref(new Set());
const loading = ref(false);
const deletingId = ref(null);
const customerPanelOpen = ref(false);
const customerLoading = ref(false);
const customerError = ref('');
const customerData = ref(null);
const tagsModalOpen = ref(false);
const tagsModalLoading = ref(false);
const tagsModalOrder = ref(null);
const availableTags = ref([]);
const selectedTags = ref([]);
const statusesModalOpen = ref(false);
const statusesModalLoading = ref(false);
const statuses = ref([]);
const statusesOrder = ref(null);
const selectedStatusId = ref(null);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const holdFilterOptions = [3, 4, 5, 6];
const holdFilterDays = ref(3);
const filters = reactive({ search: '', statuses: [], payment_status: '', delivery_hold_days: holdFilterDays.value, page: 1, per_page: 20 });
let searchTimer;

const statusChips = ref([{ value: '', label: 'Всі', icon: 'bi-grid', color: null }]);

/** ГЕНЕРАЦІЯ ТТН */
async function generateTtn(order) {
  if (order.loadingTtn) return;

  order.loadingTtn = true;
  try {
    const response = await fetch(`/orders/${order.id}/generate-ttn`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
        Accept: 'application/json',
      },
    });

    const result = await response.json();

    if (result.success || result.ttn) {
      const ttn = result.ttn || result.data[0].IntDocNumber;
      const ref = result.ref || result.data[0].Ref;

      order.ttn = ttn;
      order.ttn_ref = ref;

      alert(`ТТН ${ttn} створена успішно!`);
    } else {
      const errorMsg = result.errors ? JSON.stringify(result.errors) : result.message || 'Помилка API';
      alert('Не вдалося створити ТТН: ' + errorMsg);
    }
  } catch (error) {
    console.error(error);
    alert('Помилка сервера при створенні ТТН');
  } finally {
    order.loadingTtn = false;
  }
}

/** АНУЛЮВАННЯ ТТН */
async function handleCancelTtn(order) {
  if (!order.ttn) return;
  if (!confirm('Ви впевнені, що хочете анулювати ТТН у системі Нової Пошти?')) return;

  order.loadingTtn = true;
  try {
    const response = await axios.post(`/orders/${order.id}/cancel-ttn`);

    if (response.data.success) {
      order.ttn = null;
      order.ttn_ref = null;
      alert('ТТН успішно анульовано');
    }
  } catch (error) {
    console.error(error);
    alert(error.response?.data?.message || 'Помилка при анулюванні ТТН');
  } finally {
    order.loadingTtn = false;
  }
}

/** ДРУК ТТН */
async function printTtn(order) {
  if (!order.ttn) return;
  try {
    const response = await axios.get(`/orders/${order.id}/print-ttn`);
    if (response.data.success && response.data.print_url) {
      window.open(response.data.print_url, '_blank');
    } else {
      alert('Не вдалося отримати посилання на друк');
    }
  } catch (error) {
    console.error(error);
    alert('Помилка при спробі друку');
  }
}

async function refreshDeliveryStatus(order) {
  if (!order?.id || order.refreshingDelivery) return;
  order.refreshingDelivery = true;
  try {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const response = await axios.post(
      `/orders/${order.id}/track-delivery`,
      {},
      csrf ? { headers: { 'X-CSRF-TOKEN': csrf } } : undefined
    );
    const data = response.data || {};
    order.delivery_status = data.delivery_status_label || order.delivery_status;
    order.delivery_status_code = data.delivery_status_code || order.delivery_status_code;
    order.delivery_status_updated_at = data.delivery_status_updated_at || order.delivery_status_updated_at;
    order.last_tracked_at = data.last_tracked_at || order.last_tracked_at;
  } catch (error) {
    console.error('Не вдалося оновити статус доставки', error);
    alert(error.response?.data?.message || 'Не вдалося оновити статус доставки');
  } finally {
    order.refreshingDelivery = false;
  }
}

async function fetchData() {
  loading.value = true;
  try {
    const { data } = await listOrders({
      page: filters.page,
      per_page: filters.per_page,
      search: filters.search || undefined,
      status: filters.statuses?.length ? filters.statuses : undefined,
      payment_status: filters.payment_status || undefined,
      delivery_hold_days: filters.delivery_hold_days || undefined,
    });

    const payload = data.data || data?.data?.data || [];
    orders.value = payload.map(mapOrder);

    const metaPayload = data.meta || data?.data?.meta || {};
    meta.value = {
      current_page: metaPayload.current_page ?? 1,
      last_page: metaPayload.last_page ?? 1,
      total: metaPayload.total ?? orders.value.length,
    };
  } catch (error) {
    console.error(error);
  } finally {
    loading.value = false;
  }
}

function mapOrder(order) {
  const customer = order.customer || {};
  const delivery = order.delivery || {};
  let payment = order.payment;
  if (Array.isArray(payment)) payment = payment[0];
  payment = payment || {};
  const statusRef = order.statusRef || order.status_ref || {};
  const sourceRef = order.source || {};
  const latestReceipt = order.latest_fiscal_receipt || order.latestFiscalReceipt || null;
  const deliveryStatusUpdatedAt = delivery.delivery_status_updated_at || '';
  const deliveryHoldDays = deliveryStatusUpdatedAt
    ? Math.floor((Date.now() - new Date(deliveryStatusUpdatedAt).getTime()) / (1000 * 60 * 60 * 24))
    : null;

  return {
    ...order,
    payment_method: payment.method || order.payment_method || '',
    customer_id: order.customer_id || customer.id || null,
    order_number: order.order_number || order.id,
    client: customer.full_name || [customer.first_name, customer.last_name].filter(Boolean).join(' ') || 'Гість',
    customer_orders_count: Number(customer.orders_count ?? 1),
    phone: customer.phone || '',
    email: customer.email || '',
    source_code: sourceRef.code || order.source || '',
    source_name: sourceRef.name || order.source || '—',
    source_icon: sourceRef.icon ? `bi ${sourceRef.icon}` : '',
    source_color: sourceRef.color || '',
    status_key: statusRef.code || order.status || 'new',
    status: statusRef.name || statusLabels[order.status] || order.status || '—',
    status_icon: statusRef.icon || '',
    status_color: statusRef.color || '',
    payment_status: order.payment_status,
    payment_status_label: paymentLabels[order.payment_status] || '—',
    tags: order.tags || [],
    itemsCount: order.items_count || (order.items ? order.items.length : 0),
    items: (order.items || []).map((item) => ({
      ...item,
      title: item.product_title || item.title || 'Товар',
      photo: buildPhotoUrl(
        item.product?.main_photo_url ||
          item.product?.main_photo_path ||
          item.main_photo_url ||
          item.main_photo_path ||
          item.photo
      ),
      total: Number(item.total ?? Number(item.qty || 0) * Number(item.price || 0)),
    })),
    total: Number(order.items_sum_total ?? order.total_sum ?? 0),
    currency: order.currency || 'UAH',
    ttn: delivery.ttn || '',
    ttn_ref: delivery.ttn_ref || '',
    loadingTtn: false,
    refreshingDelivery: false,
    address: delivery.warehouse_name || [delivery.city_name, delivery.street_name].filter(Boolean).join(', ') || '—',
    city_name: delivery.city_name || '',
    delivery_status: delivery.delivery_status_label || '',
    delivery_status_code: delivery.delivery_status_code || '',
    delivery_status_updated_at: deliveryStatusUpdatedAt,
    last_tracked_at: delivery.last_tracked_at || '',
    delivery_status_color: delivery.delivery_status_color || '',
    delivery_status_icon: delivery.delivery_status_icon || '',
    delivery_carrier: delivery.carrier === 'nova_poshta' ? 'Нова Пошта' : delivery.carrier || '',
    delivery_payer: delivery.delivery_payer === 'sender' ? 'Відправник' : 'Отримувач',
    delivery_cost: Number(delivery.delivery_cost ?? 0),
    prepay_amount: Number(
      order.prepay_amount ??
      payment.prepay_amount ??
      payment.prepayment ??
      0
    ),
    comment: order.comment_internal || '',
    created_at: order.created_at,
    latestFiscalReceipt: latestReceipt,
    delivery_hold_days: deliveryHoldDays,
  };
}

function updateHoldDays(value) {
  holdFilterDays.value = value;
  filters.delivery_hold_days = value;
  filters.page = 1;
  fetchData();
}

function handleSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    filters.page = 1;
    fetchData();
  }, 300);
}

function isStatusActive(val) {
  if (!val) return !filters.statuses.length;
  const values = Array.isArray(val) ? val : [val];
  return values.some((item) => filters.statuses.includes(item));
}

function toggleStatus(value) {
  if (!value) {
    filters.statuses = [];
  } else {
    const next = new Set(filters.statuses);
    const values = Array.isArray(value) ? value : [value];
    const allSelected = values.every((item) => next.has(item));
    if (allSelected) {
      values.forEach((item) => next.delete(item));
    } else {
      values.forEach((item) => next.add(item));
    }
    filters.statuses = Array.from(next);
  }
  filters.page = 1;
  fetchData();
}

function changePage(page) {
  if (page < 1 || page > meta.value.last_page) return;
  filters.page = page;
  fetchData();
}

async function copyTtn(ttn) {
  if (!ttn || typeof navigator === 'undefined' || !navigator.clipboard) return;
  try {
    await navigator.clipboard.writeText(ttn);
  } catch (err) {
    console.error('Не вдалося скопіювати ТТН', err);
  }
}

async function openTagsModal(order) {
  if (!order) return;
  tagsModalOrder.value = order;
  selectedTags.value = order.tags.map((t) => t.id);
  tagsModalOpen.value = true;
  if (!availableTags.value.length) {
    await loadTags();
  }
}

async function loadTags() {
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

function closeTagsModal() {
  tagsModalOpen.value = false;
  tagsModalOrder.value = null;
  selectedTags.value = [];
}

async function saveTags() {
  if (!tagsModalOrder.value) return;
  try {
    const { data } = await updateOrderTags(tagsModalOrder.value.id, selectedTags.value);
    const updatedTags = data?.data || data || [];
    tagsModalOrder.value.tags = updatedTags;
    const idx = orders.value.findIndex((o) => o.id === tagsModalOrder.value.id);
    if (idx !== -1) orders.value[idx].tags = updatedTags;
    closeTagsModal();
  } catch (e) {
    console.error('Не вдалося оновити теги', e);
    alert('Не вдалося оновити теги');
  }
}

async function handleDelete(order) {
  if (!order?.id) return;
  const confirmed = window.confirm(`Видалити замовлення #${order.order_number || order.id}?`);
  if (!confirmed) return;
  deletingId.value = order.id;
  try {
    await deleteOrder(order.id);
    orders.value = orders.value.filter((o) => o.id !== order.id);
    meta.value.total = Math.max(0, (meta.value.total || 1) - 1);
    if (!orders.value.length && meta.value.current_page > 1) {
      filters.page = Math.max(1, filters.page - 1);
      await fetchData();
    }
  } catch (error) {
    console.error('Не вдалося видалити замовлення', error);
    alert('Не вдалося видалити замовлення');
  } finally {
    deletingId.value = null;
  }
}

async function openStatusesModal(order) {
  if (!order) return;
  statusesOrder.value = order;
  selectedStatusId.value = order.status_id || null;
  statusesModalOpen.value = true;
  if (!statuses.value.length) {
    await loadStatuses();
  }
}

async function loadStatuses() {
  statusesModalLoading.value = true;
  try {
    const { data } = await fetchStatuses({ type: 'order' });
    const list = data?.data || data || [];
    statuses.value = Array.isArray(list) ? list : [];
    const primaryStatusNames = new Set([
      'Новий',
      'В обробці',
      'Підтверджено (на склад)',
      'Упакування (ТТН)',
      'Відправлено',
      'Успішно завершено',
      'Повернення',
    ]);
    const primaryStatuses = statuses.value.filter((st) => primaryStatusNames.has(st.name));
    const groupedByName = new Map();
    for (const status of primaryStatuses) {
      if (!groupedByName.has(status.name)) groupedByName.set(status.name, []);
      groupedByName.get(status.name).push(status);
    }
    statusChips.value = [
      { value: '', label: 'Всі', icon: 'bi-grid', color: null },
      ...Array.from(groupedByName.entries()).map(([name, statusesGroup]) => {
        const base = statusesGroup[0];
        return {
          value: statusesGroup.map((st) => st.code),
          label: name,
          icon: base.icon,
          color: base.color,
        };
      }),
    ];
  } catch (e) {
    console.error('Не вдалося завантажити статуси', e);
    statuses.value = [];
  } finally {
    statusesModalLoading.value = false;
  }
}

function closeStatusesModal() {
  statusesModalOpen.value = false;
  statusesOrder.value = null;
  selectedStatusId.value = null;
}

async function saveStatus() {
  if (!statusesOrder.value || !selectedStatusId.value) return;
  try {
    const { data } = await updateOrderStatus(statusesOrder.value.id, selectedStatusId.value);
    const status = data?.data || data || {};
    const idx = orders.value.findIndex((o) => o.id === statusesOrder.value.id);
    const target = idx !== -1 ? orders.value[idx] : statusesOrder.value;
    target.status_id = status.id;
    target.status_key = status.code;
    target.status = status.name;
    target.status_icon = status.icon;
    target.status_color = status.color;
    closeStatusesModal();
  } catch (e) {
    console.error('Не вдалося оновити статус', e);
    alert('Не вдалося оновити статус');
  }
}

async function openCustomer(order) {
  if (!order?.customer_id) return;
  customerPanelOpen.value = true;
  customerLoading.value = true;
  customerError.value = '';
  customerData.value = null;
  try {
    const { data } = await getCustomer(order.customer_id);
    customerData.value = data?.data || data || null;
  } catch (e) {
    console.error('Не вдалося завантажити клієнта', e);
    customerError.value = e.response?.data?.message || 'Не вдалося завантажити дані клієнта';
  } finally {
    customerLoading.value = false;
  }
}

function closeCustomerPanel() {
  customerPanelOpen.value = false;
}

function toggleRow(id) {
  if (expandedRows.value.has(id)) {
    expandedRows.value = new Set();
    return;
  }

  expandedRows.value = new Set([id]);
}

onMounted(fetchData);
onMounted(loadStatuses);
</script>

<style scoped>
.workspace {
  --bg: #f6f7fb;
  --card: #ffffff;
  --ink: #111827;
  --muted: rgba(17, 24, 39, 0.6);
  --line: rgba(17, 24, 39, 0.1);
  --line2: rgba(17, 24, 39, 0.07);
  --shadow: 0 18px 55px rgba(17, 24, 39, 0.1);
  --shadow2: 0 10px 26px rgba(17, 24, 39, 0.08);
  --r16: 16px;
  --r22: 22px;
  --accent: #6d5efc;
  --mint: #27c2a0;
  --warn: #ffb020;
  --danger: #ff4d6d;
  background: radial-gradient(1200px 450px at 8% 0%, rgba(109, 94, 252, 0.12), transparent 60%),
    radial-gradient(900px 360px at 92% 10%, rgba(39, 194, 160, 0.1), transparent 55%),
    var(--bg);
  color: var(--ink);
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  min-height: 100vh;
  font-size: 14.5px;
}
</style>
