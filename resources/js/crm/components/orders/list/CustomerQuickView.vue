<template>
  <Teleport to="body">
    <div v-if="open" class="customer-offcanvas-wrapper">
      <div class="offcanvas-backdrop fade show" @click="close"></div>

      <div class="offcanvas offcanvas-end show shadow-lg border-0 d-flex flex-column" tabindex="-1" style="width: 500px;">
        
        <div class="offcanvas-header border-bottom bg-white py-3">
          <div class="d-flex align-items-center gap-3 w-100 overflow-hidden">
            <div class="avatar-circle bg-primary-subtle text-primary flex-shrink-0">
              <i class="bi bi-person-fill fs-4"></i>
            </div>
            <div class="min-w-0">
              <div class="fw-bold text-dark fs-5 text-truncate">{{ customerName }}</div>
              <div class="text-muted small d-flex align-items-center gap-2">
                <span>ID: {{ customer.id }}</span>
                <span v-if="customerPhone" class="d-flex align-items-center gap-1">
                  <i class="bi bi-telephone-fill x-small"></i> {{ customerPhone }}
                </span>
              </div>
            </div>
          </div>
          <button type="button" class="btn-close ms-2" @click="close"></button>
        </div>

        <div class="offcanvas-body p-0 custom-scroll bg-light">
          
          <div v-if="loading" class="h-100 d-flex flex-column align-items-center justify-content-center text-muted">
            <div class="spinner-border text-primary mb-3" role="status"></div>
            <div>Завантаження даних...</div>
          </div>

          <div v-else-if="error" class="h-100 d-flex flex-column align-items-center justify-content-center text-danger p-4 text-center">
            <div class="bg-danger-subtle p-3 rounded-circle mb-3 text-danger">
              <i class="bi bi-exclamation-triangle-fill fs-3"></i>
            </div>
            <h6 class="fw-bold">Помилка завантаження</h6>
            <p class="small text-secondary mb-0">{{ error }}</p>
          </div>

          <div v-else-if="data" class="d-flex flex-column gap-3 p-4">
            
            <section class="d-grid gap-3" style="grid-template-columns: 1fr 1fr;">
              <div class="stat-card bg-white p-3 rounded-3 border shadow-sm">
                <div class="text-uppercase text-muted x-small fw-bold mb-1">Замовлень</div>
                <div class="fs-3 fw-bold text-dark lh-1">{{ metrics.orders_count ?? 0 }}</div>
              </div>
              <div class="stat-card bg-white p-3 rounded-3 border shadow-sm">
                <div class="text-uppercase text-muted x-small fw-bold mb-1">Оборот</div>
                <div class="fs-3 fw-bold text-success lh-1">{{ formatMoney(metrics.total_spent) }}</div>
              </div>
            </section>

            <section class="bg-white rounded-3 border shadow-sm p-3">
              <h6 class="text-uppercase text-muted x-small fw-bold mb-3">Контакти</h6>
              
              <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center gap-3">
                  <div class="icon-box bg-light text-primary"><i class="bi bi-telephone"></i></div>
                  <div>
                    <div class="fw-medium text-dark">
                      <a v-if="customerPhone" :href="`tel:${customerPhone}`" class="text-decoration-none text-dark">{{ customerPhone }}</a>
                      <span v-else class="text-muted">—</span>
                    </div>
                    <div class="x-small text-muted">Мобільний</div>
                  </div>
                </div>
                <div class="d-flex gap-1" v-if="customerPhone">
                  <a :href="`viber://chat?number=${normalizePhone(customerPhone)}`" class="btn btn-sm btn-light border text-purple" title="Viber" target="_blank">
                    <i class="bi bi-chat-dots-fill"></i>
                  </a>
                  <a :href="`tg://resolve?domain=${normalizePhone(customerPhone)}`" class="btn btn-sm btn-light border text-info" title="Telegram" target="_blank">
                    <i class="bi bi-telegram"></i>
                  </a>
                  <button class="btn btn-sm btn-light border" @click="copy(customerPhone)" title="Копіювати">
                    <i class="bi bi-copy"></i>
                  </button>
                </div>
              </div>

              <div class="d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-3">
                  <div class="icon-box bg-light text-warning"><i class="bi bi-envelope"></i></div>
                  <div>
                    <div class="fw-medium text-dark text-break">
                      <a v-if="customerEmail" :href="`mailto:${customerEmail}`" class="text-decoration-none text-dark">{{ customerEmail }}</a>
                      <span v-else class="text-muted">—</span>
                    </div>
                    <div class="x-small text-muted">Email</div>
                  </div>
                </div>
                <button v-if="customerEmail" class="btn btn-sm btn-light border" @click="copy(customerEmail)">
                  <i class="bi bi-copy"></i>
                </button>
              </div>
            </section>

            <section>
              <div class="d-flex justify-content-between align-items-center mb-2 px-1">
                <span class="fw-bold text-dark small">Останні замовлення</span>
                <span class="badge bg-secondary-subtle text-secondary rounded-pill">{{ recentOrders.length }}</span>
              </div>

              <div v-if="!recentOrders.length" class="text-center py-4 text-muted bg-white rounded-3 border">
                <i class="bi bi-inbox fs-4 d-block mb-1 opacity-50"></i>
                <span class="small">Історія порожня</span>
              </div>

              <div v-else class="d-flex flex-column gap-2">
                <a
                  v-for="order in recentOrders"
                  :key="order.id"
                  :href="`/orders/${order.id}/edit`"
                  class="order-item bg-white p-3 rounded-3 border text-decoration-none d-block shadow-sm"
                >
                  <div class="d-flex justify-content-between mb-1">
                    <div class="d-flex align-items-center gap-2">
                      <span class="fw-bold text-primary">#{{ order.id }}</span>
                      
                      <span class="x-small text-muted">{{ formatDate(order.created_at) }}</span>
                    </div>
                    <div class="fw-bold text-dark">{{ formatCurrency(order.items_sum_total, order.currency) }}</div>
                  </div>
                  
                  <div class="d-flex justify-content-between align-items-center">
                    <div class="text-truncate text-muted small pe-2" style="max-width: 280px;">
                      <i class="bi bi-geo-alt me-1 x-small opacity-50"></i>
                      {{ order.delivery?.warehouse_name || order.delivery?.city_name || 'Без доставки' }}
                    </div>
                    <span class="badge border fw-normal text-dark bg-light">
                       {{ order.statusRef?.name || order.status }}
                    </span>
                  </div>
                </a>
              </div>
            </section>

          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed } from 'vue';
import { formatCurrency, formatDate } from '@/crm/utils/orderDisplay';

const props = defineProps({
  open: { type: Boolean, default: false },
  data: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
});

const emit = defineEmits(['close']);

// --- Computeds ---
const customer = computed(() => props.data?.customer || {});
const metrics = computed(() => props.data?.metrics || {});
const recentOrders = computed(() => props.data?.recent_orders || []);

const customerName = computed(() =>
  [customer.value.first_name, customer.value.last_name].filter(Boolean).join(' ') || 'Без імені'
);
const customerPhone = computed(() => customer.value.phone || '');
const customerEmail = computed(() => customer.value.email || '');

// --- Helpers ---
const normalizePhone = (ph) => ph?.replace(/\D/g, '');

const formatMoney = (val) => {
  return new Intl.NumberFormat('uk-UA', { style: 'currency', currency: 'UAH', maximumFractionDigits: 0 }).format(val || 0);
};

const copy = (text) => {
  if (!text) return;
  navigator.clipboard.writeText(text);
  // Можна додати toast-повідомлення
};

function close() {
  emit('close');
}
</script>

<style scoped>
/* Wrapper to handle z-index properly outside of normal flow */
.customer-offcanvas-wrapper {
  position: fixed;
  inset: 0;
  z-index: 1055;
}

/* Offcanvas Styles */
.offcanvas {
  transition: transform 0.3s ease-in-out;
}

.avatar-circle {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.icon-box {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.1rem;
}

.x-small {
  font-size: 0.75rem;
}

.text-purple {
  color: #6f42c1;
}

.order-item {
  transition: all 0.2s;
  border-color: #f1f5f9 !important;
}
.order-item:hover {
  border-color: #3b82f6 !important;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.05) !important;
}

/* Scrollbar */
.custom-scroll {
  overflow-y: auto;
}
.custom-scroll::-webkit-scrollbar { width: 6px; }
.custom-scroll::-webkit-scrollbar-track { background: #f8f9fa; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
.custom-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>