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
  
  <template>
    <div class="fiscal-card-modern h-100">
      <!-- Header -->
      <div class="card-header-modern">
        <div class="d-flex align-items-center gap-2">
          <div class="status-indicator-ring" :class="statusClass">
              <div class="status-dot"></div>
          </div>
          <div class="d-flex flex-column" style="line-height: 1;">
            <span class="header-title">Фіскалізація</span>
            <span v-if="lastReceiptDate" class="header-date">{{ lastReceiptDate }}</span>
          </div>
        </div>
        <div class="status-badge-pill" :class="statusClass">
          <i class="bi" :class="statusIcon"></i>
          <span>{{ statusLabel }}</span>
        </div>
      </div>
  
      <!-- Body -->
      <div class="card-body-modern">
        
        <!-- Summary Block -->
        <div class="summary-block" :class="{'is-complete': isFullyPaid}">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="label-muted">Сума замовлення</span>
            <span class="amount-large">{{ formatMoney(totalOrderAmount) }} <small>грн</small></span>
          </div>
          
          <div class="progress-container mb-1">
              <div class="progress-track">
                <div 
                  class="progress-fill" 
                  :class="isFullyPaid ? 'bg-success-gradient' : 'bg-primary-gradient'"
                  :style="{ width: percentPaid + '%' }" 
                ></div>
              </div>
              <div class="progress-label">{{ percentPaid.toFixed(0) }}%</div>
          </div>
  
          <div class="d-flex justify-content-between align-items-center text-xs mt-1">
             <div class="d-flex align-items-center gap-1 text-success fw-medium">
                 <i class="bi bi-check2-circle"></i>
                 <span>Сплачено: {{ formatMoney(alreadyFiscalizedAmount) }}</span>
             </div>
             <div v-if="!isFullyPaid" class="text-danger fw-bold">
                 Залишок: {{ formatMoney(remainingAmount) }}
             </div>
          </div>
        </div>
          
        <!-- Receipts List (Compact) -->
        <div v-if="allReceipts.length" class="receipts-list">
          <div v-for="rec in allReceipts" :key="rec.id" class="receipt-item">
              <div class="d-flex align-items-center gap-2">
                  <div class="icon-circle bg-success-subtle text-success">
                      <i class="bi bi-receipt"></i>
                  </div>
                  <div class="d-flex flex-column" style="line-height: 1.1;">
                      <span class="receipt-amount">{{ formatMoney(rec.amount) }} <small class="text-muted">грн</small></span>
                      <span class="receipt-time">{{ extractTime(rec.created_at) }}</span>
                  </div>
              </div>
              <a
              v-if="rec.link"
              class="btn-view-receipt"
              :href="rec.link"
              target="_blank"
              title="Переглянути чек"
              >
              <i class="bi bi-arrow-up-right"></i>
              </a>
          </div>
        </div>
  
        <!-- Error Message -->
        <transition name="fade">
          <div v-if="errorMessage" class="error-box">
              <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
              <span>{{ errorMessage }}</span>
          </div>
        </transition>
  
        <!-- Actions (Compact Grid) -->
        <div class="actions-grid mt-auto pt-2">
          <button
            v-if="showPrepayButton"
            class="btn-modern btn-prepay"
            :disabled="actionDisabled"
            @click="runFiscalize('prepay')"
          >
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-wallet2"></i>
                <div class="d-flex flex-column align-items-start lh-1">
                    <span class="btn-label">Аванс</span>
                    <span class="btn-value">{{ formatMoney(prepayAmountNumber) }}</span>
                </div>
            </div>
          </button>
  
          <button
            v-if="showMainButton"
            class="btn-modern btn-main"
            :disabled="actionDisabled"
            @click="runFiscalize('sell')"
          >
             <div class="d-flex align-items-center gap-2">
                 <i class="bi bi-printer"></i>
                 <div class="d-flex flex-column align-items-start lh-1">
                     <span class="btn-label">{{ alreadyFiscalizedAmount > 0.01 ? 'Доплатити' : 'Повний чек' }}</span>
                     <span class="btn-value">{{ formatMoney(remainingAmount) }}</span>
                 </div>
             </div>
          </button>
          
           <!-- Refund & Loading -->
           <div class="d-flex gap-1 w-100" v-if="canRefund || isPending">
              <button
                v-if="canRefund"
                class="btn-modern btn-refund flex-grow-1"
                :disabled="actionDisabled"
                @click="runFiscalize('return')"
              >
                <i class="bi bi-arrow-counterclockwise"></i>
                <span>Повернення</span>
              </button>
  
              <button
                v-if="isPending"
                class="btn-modern btn-loading flex-grow-1"
                disabled
              >
                <i class="bi bi-arrow-repeat spin-icon"></i>
                <span>Обробка...</span>
              </button>
           </div>
        </div>
      </div>
    </div>
  </template>
  
  <style scoped>
  /* Main Card - Compact */
  .fiscal-card-modern {
    background: #ffffff;
    border-radius: 10px; /* Зменшив радіус */
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
    border: 1px solid rgba(0, 0, 0, 0.06);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    font-family: 'Inter', sans-serif;
    font-size: 13px; /* Базовий шрифт менший */
  }
  
  /* Header - Compact */
  .card-header-modern {
    background: #f9fafb;
    padding: 8px 12px; /* Менші відступи */
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  
  .header-title {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #334155;
  }
  
  .header-date {
    font-size: 0.65rem;
    color: #94a3b8;
  }
  
  /* Status Indicator */
  .status-indicator-ring {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid currentColor;
  }
  .status-dot {
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background-color: currentColor;
  }
  
  /* Status Badge - Smaller */
  .status-badge-pill {
    font-size: 0.65rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 99px;
    display: flex;
    align-items: center;
    gap: 4px;
  }
  
  /* Body - Compact */
  .card-body-modern {
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    flex-grow: 1;
  }
  
  .summary-block {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px 10px;
    transition: all 0.3s ease;
  }
  .summary-block.is-complete {
    background: linear-gradient(145deg, #f0fdf4, #dcfce7);
    border-color: #bbf7d0;
  }
  
  .label-muted {
    font-size: 0.7rem;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
  }
  
  .amount-large {
    font-size: 0.95rem; /* Менший шрифт ціни */
    font-weight: 800;
    color: #0f172a;
  }
  
  /* Progress Bar - Thinner */
  .progress-container {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .progress-track {
    flex-grow: 1;
    height: 6px; /* Тонший трек */
    background: #e2e8f0;
    border-radius: 99px;
    overflow: hidden;
  }
  .progress-fill {
    height: 100%;
    border-radius: 99px;
    transition: width 0.5s cubic-bezier(0.4, 0, 0.2, 1);
  }
  .progress-label {
    font-size: 0.65rem;
    font-weight: 700;
    color: #64748b;
    width: 30px;
    text-align: right;
  }
  
  .bg-success-gradient { background: linear-gradient(90deg, #22c55e, #16a34a); }
  .bg-primary-gradient { background: linear-gradient(90deg, #3b82f6, #2563eb); }
  
  /* Receipts List - Compact */
  .receipts-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .receipt-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 8px;
    background: #fff;
    border: 1px solid #f1f5f9;
    border-radius: 6px;
    transition: transform 0.2s;
  }
  .receipt-item:hover {
    transform: translateX(2px);
    border-color: #cbd5e1;
  }
  
  .icon-circle {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
  }
  
  .receipt-amount {
    font-weight: 700;
    font-size: 0.85rem;
    color: #1e293b;
  }
  .receipt-time {
    font-size: 0.65rem;
    color: #94a3b8;
  }
  
  .btn-view-receipt {
    color: #94a3b8;
    transition: color 0.2s;
    padding: 2px;
    font-size: 0.9rem;
  }
  .btn-view-receipt:hover {
    color: #3b82f6;
  }
  
  /* Actions - Compact Buttons */
  .actions-grid {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  
  .btn-modern {
    border: none;
    border-radius: 8px;
    padding: 8px 12px;
    display: flex;
    justify-content: center; /* Центруємо контент */
    align-items: center;
    width: 100%;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
    font-size: 0.8rem;
  }
  .btn-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
  }
  .btn-modern:active { transform: translateY(0); }
  .btn-modern:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }
  
  .btn-label {
    font-size: 0.65rem;
    font-weight: 600;
    text-transform: uppercase;
    opacity: 0.9;
  }
  .btn-value {
    font-size: 0.85rem;
    font-weight: 800;
  }
  
  /* Action Variants */
  .btn-prepay { background: #fffbeb; color: #b45309; border: 1px solid #fcd34d; }
  .btn-prepay:hover { background: #fef3c7; }
  
  .btn-main {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border: 1px solid #2563eb;
    box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);
  }
  .btn-main:hover { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); }
  
  .btn-refund {
    gap: 6px;
    background: #fff;
    border: 1px solid #fee2e2;
    color: #ef4444;
    padding: 6px;
    font-size: 0.75rem;
  }
  .btn-refund:hover { background: #fef2f2; border-color: #ef4444; }
  
  .btn-loading {
    gap: 6px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #64748b;
    padding: 6px;
    font-size: 0.75rem;
  }
  
  .icon-bg {
    font-size: 1.2rem;
    opacity: 0.2;
    position: absolute;
    right: 10px;
    transform: rotate(-15deg);
  }
  
  .error-box {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 0.75rem;
    display: flex;
    align-items: center;
    gap: 6px;
  }
  
  /* Status Colors */
  .status-success { color: #16a34a; background-color: #dcfce7; }
  .status-indicator-ring.status-success { color: #16a34a; background-color: transparent; border-color: #16a34a; }
  
  .status-pending { color: #d97706; background-color: #fef3c7; }
  .status-indicator-ring.status-pending { color: #d97706; background-color: transparent; border-color: #d97706; }
  
  .status-error { color: #dc2626; background-color: #fee2e2; }
  .status-indicator-ring.status-error { color: #dc2626; background-color: transparent; border-color: #dc2626; }
  
  .status-absent { color: #64748b; background-color: #f1f5f9; }
  .status-indicator-ring.status-absent { color: #94a3b8; background-color: transparent; border-color: #cbd5e1; }
  
  .spin-icon { animation: spin 1s linear infinite; }
  @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
  .fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
  .fade-enter-from, .fade-leave-to { opacity: 0; }
  </style>