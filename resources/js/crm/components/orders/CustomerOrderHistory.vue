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
    <template v-else>
      <!-- Reliability summary -->
      <div class="reliability">
        <div class="rel-item rel-green">
          <span class="rel-num">{{ stats.received }}</span>
          <span class="rel-label">Забрав</span>
        </div>
        <div class="rel-item rel-red">
          <span class="rel-num">{{ stats.returned }}</span>
          <span class="rel-label">Повернув</span>
        </div>
        <div class="rel-item rel-blue">
          <span class="rel-num">{{ stats.buyoutLabel }}</span>
          <span class="rel-label">% викупу</span>
        </div>
      </div>

      <!-- Orders list -->
      <div class="orders-list">
        <a
          v-for="order in orders"
          :key="order.id"
          :href="`/orders/${order.id}/edit`"
          target="_blank"
          rel="noopener"
          class="order-card"
        >
          <div class="order-top">
            <span class="order-product text-truncate">{{ productSummary(order) }}</span>
            <span class="order-price">{{ formatCurrency(order.items_sum_total, order.currency) }}</span>
          </div>
          <div class="order-bottom">
            <span class="order-meta">
              <span class="order-number font-monospace">#{{ order.order_number || order.id }}</span>
              <span class="order-date">
                <i class="bi bi-calendar3 me-1"></i>{{ formatDate(order.created_at) }}
              </span>
            </span>
            <span class="status-badge" :style="badgeStyle(order)">
              <i v-if="statusRef(order)?.icon" :class="statusRef(order).icon"></i>
              {{ statusRef(order)?.name || order.status || '—' }}
            </span>
          </div>
        </a>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { getCustomer } from '@/crm/api/customers';
import { formatCurrency, formatDate } from '@/crm/utils/orderDisplay';

const props = defineProps({
  customerId: { type: [Number, String], default: null },
});

const orders = ref([]);
const loading = ref(false);
const error = ref('');

// Коди статусів CRM, що відповідають за «надійність» клієнта
const RECEIVED_CODES = ['delivered_paid'];
const RETURNED_CODES = ['returned'];

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

const stats = computed(() => {
  let received = 0;
  let returned = 0;
  for (const order of orders.value) {
    const code = statusRef(order)?.code;
    if (RECEIVED_CODES.includes(code)) received += 1;
    else if (RETURNED_CODES.includes(code)) returned += 1;
  }
  const total = received + returned;
  const buyoutLabel = total ? `${Math.round((received / total) * 100)}%` : '—';
  return { received, returned, buyoutLabel };
});

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
    orders.value = data?.recent_orders || [];
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

/* --- Reliability summary --- */
.reliability {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  margin-bottom: 12px;
}
.rel-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 8px 4px;
  border-radius: 12px;
  border: 1px solid transparent;
}
.rel-num { font-size: 1.2rem; font-weight: 800; line-height: 1.1; }
.rel-label { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.03em; font-weight: 600; opacity: 0.85; }
.rel-green { background: #f0fdf4; border-color: #bbf7d0; color: #16a34a; }
.rel-red { background: #fef2f2; border-color: #fecaca; color: #ef4444; }
.rel-blue { background: #eff6ff; border-color: #bfdbfe; color: #2563eb; }

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

.order-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}
.order-product { font-weight: 600; color: #1e293b; font-size: 0.85rem; min-width: 0; }
.order-price { font-weight: 700; color: #1e293b; font-size: 0.85rem; white-space: nowrap; }

.order-bottom {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}
.order-meta { display: inline-flex; align-items: center; gap: 8px; min-width: 0; }
.order-number { font-size: 0.72rem; font-weight: 700; color: #475569; white-space: nowrap; }
.order-date { font-size: 0.72rem; color: #94a3b8; white-space: nowrap; }

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
