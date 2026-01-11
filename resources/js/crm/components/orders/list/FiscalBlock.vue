<template>
  <div class="fiscal-card-compact mb-3">
    <div class="card-header-compact">
      <div class="d-flex align-items-center gap-2">
        <div class="status-dot" :class="statusClass"></div>
        <div>
          <div class="fw-bold text-dark text-uppercase text-xs tracking-wide">Фіскалізація ПРРО</div>
          <div v-if="lastReceiptDate" class="text-muted text-xxs">
            {{ lastReceiptDate }}
          </div>
        </div>
      </div>
      <div class="status-badge-compact" :class="statusClass">
        <i class="bi me-1" :class="statusIcon"></i>
        <span>{{ statusLabel }}</span>
      </div>
    </div>

    <div class="card-body-compact p-3">
      <div class="summary-row mb-3" :class="{'fully-paid-bg': isFullyPaid}">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span class="text-muted text-xs fw-bold">СУМА ЗАМОВЛЕННЯ</span>
          <span class="fw-bold text-dark">{{ formatMoney(totalOrderAmount) }} <small>грн</small></span>
        </div>
        
        <div class="d-flex align-items-center gap-2">
            <div class="progress flex-grow-1" style="height: 6px; background: #e2e8f0;">
              <div 
                class="progress-bar" 
                :class="isFullyPaid ? 'bg-success' : 'bg-primary'"
                role="progressbar" 
                :style="{ width: percentPaid + '%' }" 
              ></div>
            </div>
            <span class="text-xs fw-bold text-end" style="min-width: 35px;">{{ percentPaid.toFixed(0) }}%</span>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-1 text-xs">
           <span>Сплачено: <b :class="isFullyPaid ? 'text-success' : 'text-primary'">{{ formatMoney(alreadyFiscalizedAmount) }}</b></span>
           <span v-if="!isFullyPaid" class="text-danger fw-bold">Залишок: {{ formatMoney(remainingAmount) }}</span>
        </div>
      </div>
        
      <div v-if="allReceipts.length" class="mb-3">
        <div class="d-flex flex-column gap-1">
            <div v-for="rec in allReceipts" :key="rec.id" class="receipt-row-compact">
              <div class="d-flex align-items-center gap-2">
                  <i class="bi bi-check-circle-fill text-success text-xs"></i>
                  <span class="fw-bold text-sm text-dark">{{ formatMoney(rec.amount) }} <small class="text-muted">грн</small></span>
                  <span class="text-muted text-xxs border-start ps-2 ms-1">{{ extractTime(rec.created_at) }}</span>
              </div>
              <a
                v-if="rec.link"
                class="link-view-compact"
                :href="rec.link"
                target="_blank"
                title="Відкрити"
              >
                <i class="bi bi-box-arrow-up-right"></i>
              </a>
            </div>
        </div>
      </div>

      <transition name="fade">
        <div v-if="errorMessage" class="alert alert-danger py-1 px-2 mb-3 text-xs d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-circle-fill"></i>
            <span class="text-break">{{ errorMessage }}</span>
        </div>
      </transition>

      <div class="actions-compact border-top pt-2">
        <button
          v-if="showPrepayButton"
          class="btn btn-sm btn-warning w-100 d-flex justify-content-between align-items-center"
          :disabled="actionDisabled"
          @click="runFiscalize('prepay')"
        >
          <span><i class="bi bi-wallet2 me-1"></i> Аванс</span>
          <span class="fw-bold bg-white text-warning px-1 rounded" style="font-size: 0.75rem;">{{ formatMoney(prepayAmountNumber) }}</span>
        </button>

        <button
          v-if="showMainButton"
          class="btn btn-sm btn-primary w-100 d-flex justify-content-between align-items-center"
          :disabled="actionDisabled"
          @click="runFiscalize('sell')"
        >
           <span><i class="bi bi-printer me-1"></i> {{ alreadyFiscalizedAmount > 0.01 ? 'Доплатити' : 'Повний чек' }}</span>
           <span class="fw-bold bg-white text-primary px-1 rounded" style="font-size: 0.75rem;">{{ formatMoney(remainingAmount) }}</span>
        </button>
        
         <div class="d-flex gap-1 w-100" v-if="canRefund || isPending">
            <button
              v-if="canRefund"
              class="btn btn-sm btn-outline-danger flex-grow-1"
              :disabled="actionDisabled"
              @click="runFiscalize('return')"
              title="Повернення"
            >
              <i class="bi bi-arrow-counterclockwise me-1"></i> Повернення
            </button>

            <button
              v-if="isPending"
              class="btn btn-sm btn-light border"
              :disabled="loading"
              @click="pollStatus"
            >
              <i class="bi bi-arrow-repeat" :class="{ 'spin-icon': loading }"></i>
            </button>
         </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, watch } from 'vue';
import { fiscalize, refund, fetchFiscalStatus } from '@/crm/api/fiscal';
import { formatDate } from '@/crm/utils/orderDisplay';

// --- PROPS ---
const props = defineProps({
  orderId: { type: [Number, String], required: true },
  receipt: { type: Object, default: null }, 
  prepayAmount: { type: [Number, String], default: 0 },
  totalAmount: { type: [Number, String], default: 0 }, 
});

// --- STATE ---
const state = reactive({
  receipt: props.receipt,
  loading: false,
  error: '',
});

let pollTimer = null;

// --- COMPUTED ---
const status = computed(() => state.receipt?.status || 'absent');
const isPending = computed(() => ['pending', 'processing'].includes(status.value));
const loading = computed(() => state.loading);

// Фінансова математика
const totalOrderAmount = computed(() => {
  if (state.receipt?.total_order_cents) {
    return Number(state.receipt.total_order_cents) / 100;
  }
  return parseFloat(props.totalAmount) || 0;
});

const allReceipts = computed(() => state.receipt?.all_success_receipts || []);

// ЛОГІКА СУМИ (Та сама, що працює)
const alreadyFiscalizedAmount = computed(() => {
  if (allReceipts.value.length > 0) {
    return allReceipts.value.reduce((sum, r) => sum + Number(r.amount), 0);
  }
  if (state.receipt?.already_paid_cents !== undefined) {
    return Number(state.receipt.already_paid_cents) / 100;
  }
  if (status.value === 'success' && state.receipt?.total_amount) {
    return Number(state.receipt.total_amount) / 100;
  }
  return 0;
});

const remainingAmount = computed(() => {
  const diff = totalOrderAmount.value - alreadyFiscalizedAmount.value;
  return diff > 0.05 ? diff : 0;
});

const percentPaid = computed(() => {
  if (totalOrderAmount.value <= 0) return 0;
  let pct = (alreadyFiscalizedAmount.value / totalOrderAmount.value) * 100;
  return Math.min(pct, 100);
});

const isFullyPaid = computed(() => remainingAmount.value <= 0 && totalOrderAmount.value > 0);
const prepayAmountNumber = computed(() => parseFloat(props.prepayAmount) || 0);

// --- VISIBILITY ---
const showPrepayButton = computed(() => {
  return prepayAmountNumber.value > 0 
      && alreadyFiscalizedAmount.value < 0.01 
      && !isPending.value;
});

const showMainButton = computed(() => {
  return remainingAmount.value > 0.05 && !isPending.value;
});

const canRefund = computed(() => {
  return alreadyFiscalizedAmount.value > 0 
      && state.receipt?.type !== 'return' 
      && !isPending.value;
});

const actionDisabled = computed(() => state.loading || isPending.value);
const errorMessage = computed(() => state.error || state.receipt?.error_message || '');

const lastReceiptDate = computed(() => {
  const lastSuccess = allReceipts.value[allReceipts.value.length - 1];
  return formatDateSafe(lastSuccess?.created_at || state.receipt?.created_at);
});

// --- UI HELPERS ---
const statusLabel = computed(() => {
  if (isFullyPaid.value) return 'Сплачено';
  const labels = {
    success: 'Частково',
    processing: 'Обробка...',
    pending: 'В черзі...',
    error: 'Помилка',
    absent: 'Не фіскалізовано'
  };
  return labels[status.value] || status.value;
});

const statusIcon = computed(() => {
  if (isFullyPaid.value) return 'bi-check-all';
  if (status.value === 'success') return 'bi-check-circle-fill';
  if (isPending.value) return 'bi-hourglass-split';
  if (status.value === 'error') return 'bi-exclamation-diamond-fill';
  return 'bi-circle';
});

const statusClass = computed(() => {
  if (isFullyPaid.value) return 'status-success';
  return `status-${status.value}`;
});

// --- METHODS ---
function formatMoney(val) {
  return Number(val).toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDateSafe(value) {
  return value ? formatDate(value) : '';
}

function extractTime(dateString) {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' });
}

async function runFiscalize(type = 'sell') {
  if (state.loading) return;
  state.loading = true;
  state.error = '';
  try {
    const apiCall = type === 'return' ? refund(props.orderId) : fiscalize(props.orderId, type);
    const { data } = await apiCall;
    state.receipt = data;
    startPolling();
  } catch (err) {
    state.error = err.response?.data?.message || err.message || 'Помилка API';
  } finally {
    state.loading = false;
  }
}

async function pollStatus() {
  try {
    const { data } = await fetchFiscalStatus(props.orderId);
    state.receipt = data;
    if (!isPending.value) stopPolling();
  } catch (err) {
    console.error("Polling error:", err);
  }
}

function startPolling() {
  stopPolling();
  pollTimer = setInterval(pollStatus, 2000);
}

function stopPolling() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

watch(() => props.receipt, (val) => { state.receipt = val; if(isPending.value) startPolling(); });
onMounted(() => { pollStatus(); });
onBeforeUnmount(stopPolling);
</script>

<style scoped>
/* Стилі: Compact & Clean */
.fiscal-card-compact {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  font-size: 13px; /* Базовий шрифт менший */
}

/* Header */
.card-header-compact {
  background: #f8fafc;
  padding: 8px 12px;
  border-bottom: 1px solid #edf2f7;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.status-dot { width: 8px; height: 8px; border-radius: 50%; }
.status-badge-compact {
  font-size: 0.7rem; font-weight: 600; padding: 2px 8px; border-radius: 4px;
  display: flex; align-items: center;
}
.text-xxs { font-size: 0.65rem; }
.tracking-wide { letter-spacing: 0.03em; }

/* Body */
.summary-row {
  background: #fdfdfd; border: 1px solid #f1f5f9; border-radius: 6px; padding: 8px 10px;
}
.summary-row.fully-paid-bg { background: #f0fdf4; border-color: #dcfce7; }

.receipt-row-compact {
  display: flex; justify-content: space-between; align-items: center;
  padding: 6px 10px; border: 1px solid #f1f5f9; border-radius: 6px; background: #fff;
}
.link-view-compact { color: #64748b; transition: color 0.2s; font-size: 0.9rem; }
.link-view-compact:hover { color: #2563eb; }

/* Status Colors */
.status-success { background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.status-dot.status-success { background-color: #10b981; border: none; }

.status-pending { background-color: #fffbeb; color: #b45309; border: 1px solid #fcd34d; }
.status-dot.status-pending { background-color: #f59e0b; }

.status-error { background-color: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.status-dot.status-error { background-color: #ef4444; }

.status-absent { background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
.status-dot.status-absent { background-color: #94a3b8; }

/* Actions */
.actions-compact { display: flex; flex-direction: column; gap: 8px; }

/* Animations */
.spin-icon { animation: spin 1s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>