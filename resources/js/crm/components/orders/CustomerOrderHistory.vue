<template>
  <div v-if="customerId" class="history-block animate-fade-in">
    <div class="history-header">
      <span class="history-title">
        <i class="bi bi-clock-history me-1"></i>Історія замовлень
      </span>
      <span v-if="orders.length" class="badge bg-secondary-subtle text-secondary rounded-pill history-count">
        Останні {{ orders.length }}
      </span>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="history-state">
      <div class="spinner-border spinner-border-sm text-primary"></div>
      <span>Завантаження історії...</span>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="history-state text-danger">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span>{{ error }}</span>
    </div>

    <!-- Empty -->
    <div v-else-if="!orders.length" class="history-state">
      <i class="bi bi-inbox"></i>
      <span>Перше замовлення, історії ще немає</span>
    </div>

    <!-- Data -->
    <div v-else class="orders-list">
      <a
        v-for="order in orders"
        :key="order.id"
        :href="`/orders/${order.id}/edit`"
        target="_blank"
        rel="noopener"
        class="order-card"
      >
        <div class="oc-head">
          <span class="oc-number font-monospace">#{{ order.order_number || order.id }}</span>
          <span class="oc-sum">{{ formatCurrency(order.items_sum_total, order.currency) }}</span>
        </div>
        <div v-if="productSummary(order)" class="oc-product text-truncate">{{ productSummary(order) }}</div>
        <div class="oc-foot">
          <span class="oc-date"><i class="bi bi-calendar3 me-1"></i>{{ formatDate(order.created_at) }}</span>
          <span class="status-badge" :style="badgeStyle(order)">
            <i v-if="statusRef(order)?.icon" :class="statusRef(order).icon"></i>
            {{ statusRef(order)?.name || order.status || '—' }}
          </span>
        </div>
      </a>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { getCustomer } from '@/crm/api/customers';
import { formatCurrency, formatDate } from '@/crm/utils/orderDisplay';

const props = defineProps({
  customerId: { type: [Number, String], default: null },
});

const orders = ref([]);
const loading = ref(false);
const error = ref('');

const statusRef = (order) => order?.status_ref || order?.statusRef || null;

const productSummary = (order) => {
  const first = order?.items?.[0];
  if (!first) return `Замовлення #${order.id}`;
  const parts = [first.product_title || 'Товар'];
  if (first.size) parts.push(String(first.size).trim());
  let label = parts.join(', ');
  const more = (order.items?.length || 0) - 1;
  if (more > 0) label += ` +${more}`;
  return label;
};

// Колір беремо живий зі статусу CRM; текст контрастимо до фону
const badgeStyle = (order) => {
  const color = statusRef(order)?.color;
  if (!color) return {};
  return {
    background: color,
    borderColor: 'transparent',
    color: readableText(color),
  };
};

function readableText(hex) {
  const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
  if (!m) return '#1e293b';
  const r = parseInt(m[1], 16);
  const g = parseInt(m[2], 16);
  const b = parseInt(m[3], 16);
  const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return luminance > 0.6 ? '#1e293b' : '#ffffff';
}

async function load(id) {
  if (!id) {
    orders.value = [];
    error.value = '';
    return;
  }
  loading.value = true;
  error.value = '';
  try {
    const { data } = await getCustomer(id);
    // Контролер віддає { data: { recent_orders } }, а http не розгортає відповідь —
    // тож реальний шлях data.data.recent_orders (як у OrderListPage).
    const payload = data?.data || data || {};
    orders.value = payload.recent_orders || [];
  } catch (e) {
    console.error('Не вдалося завантажити історію замовлень', e);
    error.value = 'Не вдалося завантажити історію';
    orders.value = [];
  } finally {
    loading.value = false;
  }
}

watch(() => props.customerId, (id) => load(id), { immediate: true });
</script>

<style scoped>
.history-block {
  background: #fff;
  border: 1px solid #edf2f7;
  border-radius: 16px;
  padding: 16px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
}

.history-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.history-title {
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #64748b;
}
.history-count { font-size: 0.7rem; }

.history-state {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 20px;
  color: #94a3b8;
  font-size: 0.85rem;
  background: #f9fafb;
  border-radius: 12px;
  border: 1px dashed #e2e8f0;
}
.history-state i { font-size: 1.1rem; opacity: 0.7; }

/* --- Orders list --- */
.orders-list { display: flex; flex-direction: column; gap: 8px; }

.order-card {
  display: block;
  background: #f8fafc;
  border: 1px solid #f1f5f9;
  border-radius: 12px;
  padding: 10px 12px;
  text-decoration: none;
  transition: all 0.2s ease;
}
.order-card:hover {
  background: #fff;
  border-color: #3b82f6;
  box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
  transform: translateY(-1px);
}

.oc-head { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
.oc-number { font-size: 0.9rem; font-weight: 700; color: #0f172a; }
.oc-sum { font-size: 0.9rem; font-weight: 800; color: #0f172a; white-space: nowrap; }

.oc-product { font-size: 0.78rem; color: #64748b; margin-top: 2px; }

.oc-foot { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: 8px; }
.oc-date { font-size: 0.72rem; color: #94a3b8; white-space: nowrap; }

.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 0.7rem;
  font-weight: 600;
  background: #fff;
  border: 1px solid #e2e8f0;
  color: #475569;
  padding: 2px 8px;
  border-radius: 6px;
  white-space: nowrap;
}

.animate-fade-in { animation: fadeIn 0.3s ease-out; }
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>
