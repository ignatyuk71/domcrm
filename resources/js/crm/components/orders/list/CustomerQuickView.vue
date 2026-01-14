<template>
  <Teleport to="body">
    <div v-if="open" class="offcanvas-wrapper">
      <!-- Backdrop з розмиттям -->
      <div class="backdrop" @click="close"></div>

      <!-- Offcanvas Panel -->
      <div class="panel-container">
        
        <!-- Header -->
        <div class="panel-header">
          <div class="d-flex align-items-center gap-3 w-100 overflow-hidden">
            <div class="avatar-circle">
              <span v-if="customerName">{{ customerName.charAt(0).toUpperCase() }}</span>
              <i v-else class="bi bi-person-fill"></i>
            </div>
            <div class="min-w-0 flex-grow-1">
              <div class="customer-name text-truncate">{{ customerName }}</div>
              <div class="customer-meta">
                <span class="id-badge">ID: {{ customer.id }}</span>
                <span v-if="customerPhone" class="phone-display">
                  <i class="bi bi-telephone-fill"></i> {{ customerPhone }}
                </span>
              </div>
            </div>
          </div>
          <button type="button" class="btn-close-custom" @click="close">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>

        <!-- Body -->
        <div class="panel-body custom-scroll">
          
          <!-- Loading State -->
          <div v-if="loading" class="state-container">
            <div class="spinner-border text-primary mb-3" role="status"></div>
            <div class="text-muted fw-medium">Завантаження профілю...</div>
          </div>

          <!-- Error State -->
          <div v-else-if="error" class="state-container text-danger">
            <div class="icon-circle bg-danger-subtle text-danger mb-3">
              <i class="bi bi-exclamation-triangle-fill fs-4"></i>
            </div>
            <h6 class="fw-bold">Помилка завантаження</h6>
            <p class="small opacity-75 mb-0">{{ error }}</p>
          </div>

          <!-- Data Content -->
          <div v-else-if="data" class="content-wrapper">
            
            <!-- Metrics Cards -->
            <section class="metrics-grid">
              <div class="metric-card bg-blue">
                <div class="metric-icon"><i class="bi bi-bag-check-fill"></i></div>
                <div class="metric-info">
                  <div class="metric-label">Замовлень</div>
                  <div class="metric-value">{{ metrics.orders_count ?? 0 }}</div>
                </div>
              </div>
              <div class="metric-card bg-green">
                <div class="metric-icon"><i class="bi bi-wallet2"></i></div>
                <div class="metric-info">
                  <div class="metric-label">Оборот</div>
                  <div class="metric-value">{{ formatMoney(metrics.total_spent) }}</div>
                </div>
              </div>
            </section>

            <!-- Contacts Section -->
            <section class="section-block">
              <h6 class="section-title">Контакти</h6>
              
              <div class="contacts-list">
                <!-- Phone Row -->
                <div class="contact-card">
                  <div class="contact-main">
                    <div class="icon-box text-primary bg-primary-subtle">
                      <i class="bi bi-telephone-fill"></i>
                    </div>
                    <div class="contact-details">
                      <div class="contact-type">Мобільний</div>
                      <a v-if="customerPhone" :href="`tel:${customerPhone}`" class="contact-value">{{ customerPhone }}</a>
                      <span v-else class="text-muted small">—</span>
                    </div>
                  </div>
                  
                  <div class="contact-actions" v-if="customerPhone">
                    <a :href="`viber://chat?number=${normalizePhone(customerPhone)}`" class="action-btn btn-viber" title="Viber">
                      <i class="bi bi-chat-fill"></i>
                    </a>
                    <a :href="`tg://resolve?domain=${normalizePhone(customerPhone)}`" class="action-btn btn-telegram" title="Telegram">
                      <i class="bi bi-telegram"></i>
                    </a>
                    <button class="action-btn btn-copy" @click="copy(customerPhone)" title="Копіювати">
                      <i class="bi bi-copy"></i>
                    </button>
                  </div>
                </div>

                <!-- Email Row -->
                <div class="contact-card">
                  <div class="contact-main">
                    <div class="icon-box text-warning bg-warning-subtle">
                      <i class="bi bi-envelope-fill"></i>
                    </div>
                    <div class="contact-details">
                      <div class="contact-type">Email</div>
                      <a v-if="customerEmail" :href="`mailto:${customerEmail}`" class="contact-value">{{ customerEmail }}</a>
                      <span v-else class="text-muted small">—</span>
                    </div>
                  </div>
                  <button v-if="customerEmail" class="action-btn btn-copy ms-auto" @click="copy(customerEmail)">
                    <i class="bi bi-copy"></i>
                  </button>
                </div>
              </div>
            </section>

            <!-- Recent Orders Section -->
            <section class="section-block">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="section-title mb-0">Історія замовлень</h6>
                <span class="badge bg-secondary-subtle text-secondary rounded-pill" style="font-size: 0.7rem;">
                  Останні {{ recentOrders.length }}
                </span>
              </div>

              <div v-if="!recentOrders.length" class="empty-history">
                <i class="bi bi-inbox"></i>
                <span>Історія замовлень порожня</span>
              </div>

              <div v-else class="orders-list">
                <a
                  v-for="order in recentOrders"
                  :key="order.id"
                  :href="`/orders/${order.id}/edit`"
                  class="order-card"
                >
                  <div class="order-header">
                    <div class="d-flex align-items-center gap-2">
                      <span class="order-id">#{{ order.id }}</span>
                      <span class="order-date">{{ formatDate(order.created_at) }}</span>
                    </div>
                    <div class="order-total">{{ formatCurrency(order.items_sum_total, order.currency) }}</div>
                  </div>
                  
                  <div class="order-footer">
                    <div class="order-location text-truncate">
                      <i class="bi bi-geo-alt-fill"></i>
                      {{ order.delivery?.city_name || 'Самовивіз' }}
                    </div>
                    <span class="status-badge">
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
};

function close() {
  emit('close');
}
</script>

<style scoped>
/* --- ANIMATIONS & LAYOUT --- */
.offcanvas-wrapper {
  position: fixed;
  inset: 0;
  z-index: 1055;
  display: flex;
  justify-content: flex-end;
}

.backdrop {
  position: absolute;
  inset: 0;
  background-color: rgba(15, 23, 42, 0.2);
  backdrop-filter: blur(4px);
  animation: fadeIn 0.3s ease-out;
}

.panel-container {
  position: relative;
  width: 100%;
  max-width: 480px;
  height: 100%;
  background: #ffffff;
  box-shadow: -10px 0 30px rgba(0, 0, 0, 0.1);
  display: flex;
  flex-direction: column;
  animation: slideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }

/* --- HEADER --- */
.panel-header {
  padding: 20px 24px;
  border-bottom: 1px solid #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #fff;
}

.avatar-circle {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
  color: #3b82f6;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  font-weight: 700;
  border: 1px solid #e2e8f0;
}

.customer-name {
  font-size: 1.1rem;
  font-weight: 800;
  color: #1e293b;
  line-height: 1.2;
  margin-bottom: 4px;
}

.customer-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.8rem;
  color: #64748b;
}

.id-badge {
  background: #f1f5f9;
  padding: 1px 6px;
  border-radius: 4px;
  font-family: monospace;
  font-weight: 600;
}

.btn-close-custom {
  background: transparent;
  border: none;
  color: #94a3b8;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
  cursor: pointer;
  flex-shrink: 0;
}
.btn-close-custom:hover { background: #f1f5f9; color: #1e293b; transform: rotate(90deg); }

/* --- BODY & SCROLL --- */
.panel-body {
  flex-grow: 1;
  overflow-y: auto;
  background: #f8fafc;
}
.custom-scroll::-webkit-scrollbar { width: 6px; }
.custom-scroll::-webkit-scrollbar-track { background: transparent; }
.custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
.custom-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

.state-container {
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 40px;
}

.content-wrapper {
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 24px;
}

/* --- METRICS --- */
.metrics-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

.metric-card {
  padding: 16px;
  border-radius: 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  color: #fff;
  box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.metric-card.bg-blue { background: linear-gradient(135deg, #3b82f6, #2563eb); }
.metric-card.bg-green { background: linear-gradient(135deg, #10b981, #059669); }

.metric-icon {
  width: 40px; height: 40px; border-radius: 10px;
  background: rgba(255,255,255,0.2);
  display: flex; align-items: center; justify-content: center; font-size: 1.2rem;
}
.metric-label { font-size: 0.75rem; opacity: 0.9; text-transform: uppercase; letter-spacing: 0.03em; font-weight: 600; }
.metric-value { font-size: 1.4rem; font-weight: 800; line-height: 1; }

/* --- SECTION BLOCKS --- */
.section-block {
  background: #fff;
  border-radius: 16px;
  padding: 20px;
  border: 1px solid #f1f5f9;
  box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}

.section-title {
  font-size: 0.8rem;
  font-weight: 700;
  text-transform: uppercase;
  color: #94a3b8;
  margin-bottom: 16px;
  letter-spacing: 0.05em;
}

/* --- CONTACTS --- */
.contacts-list { display: flex; flex-direction: column; gap: 12px; }

.contact-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px;
  border-radius: 12px;
  border: 1px solid #f1f5f9;
  transition: background 0.2s;
}
.contact-card:hover { background: #fafafa; }

.contact-main { display: flex; align-items: center; gap: 12px; }

.icon-box {
  width: 36px; height: 36px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center; font-size: 1rem;
}

.contact-type { font-size: 0.7rem; color: #94a3b8; line-height: 1; margin-bottom: 2px; }
.contact-value { font-weight: 600; color: #334155; text-decoration: none; font-size: 0.95rem; }
.contact-value:hover { color: #3b82f6; }

.contact-actions { display: flex; gap: 6px; }

.action-btn {
  width: 32px; height: 32px; border-radius: 8px; border: 1px solid #e2e8f0;
  background: #fff; color: #64748b; display: flex; align-items: center; justify-content: center;
  transition: all 0.2s; cursor: pointer; text-decoration: none; font-size: 0.9rem;
}
.action-btn:hover { transform: translateY(-2px); box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
.btn-viber:hover { border-color: #8b5cf6; color: #8b5cf6; background: #f5f3ff; }
.btn-telegram:hover { border-color: #0ea5e9; color: #0ea5e9; background: #e0f2fe; }
.btn-copy:hover { border-color: #cbd5e1; color: #334155; background: #f1f5f9; }

/* --- ORDER HISTORY --- */
.orders-list { display: flex; flex-direction: column; gap: 8px; }

.order-card {
  display: block;
  background: #f8fafc;
  border: 1px solid #f1f5f9;
  border-radius: 12px;
  padding: 12px 16px;
  text-decoration: none;
  transition: all 0.2s ease;
}
.order-card:hover {
  background: #fff;
  border-color: #3b82f6;
  box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
  transform: translateY(-1px);
}

.order-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}
.order-id { font-family: 'Courier New', monospace; font-weight: 700; color: #3b82f6; }
.order-date { font-size: 0.75rem; color: #94a3b8; }
.order-total { font-weight: 700; color: #1e293b; }

.order-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.order-location {
  font-size: 0.8rem;
  color: #64748b;
  display: flex;
  align-items: center;
  gap: 4px;
}
.status-badge {
  font-size: 0.7rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  padding: 2px 8px;
  border-radius: 6px;
  color: #475569;
  font-weight: 500;
}

.empty-history {
  text-align: center; padding: 30px; color: #94a3b8;
  background: #f9fafb; border-radius: 12px; border: 1px dashed #e2e8f0;
  display: flex; flex-direction: column; gap: 8px; font-size: 0.9rem;
}
.empty-history i { font-size: 1.5rem; opacity: 0.5; }
</style>