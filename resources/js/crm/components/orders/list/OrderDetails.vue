<script setup>
  import {
    formatCurrency,
    formatDate,
    getPaymentClass,
    getPaymentIcon,
    getStatusStyle,
  } from '@/crm/utils/orderDisplay';
  import FiscalBlock from '@/crm/components/orders/list/FiscalBlock.vue';
  
  const paymentLabels = {
    cod: 'Накладений платіж',
    card: 'На рахунок IBAN',
    prepay: 'Часткова передоплата',
    cash: 'Готівка',
    cashless: 'Безготівковий',
  };
  
  function paymentLabel(value) {
    return paymentLabels[value] || value || '—';
  }
  
  defineProps({
    order: { type: Object, required: true },
  });
  
  defineEmits([
    'open-tags',
    'open-statuses',
    'copy-ttn',
    'generate-ttn',
    'print-ttn',
    'cancel-ttn',
    'refresh-delivery',
  ]);
  </script>
  
  <template>
    <div class="details-wrapper">
      <!-- Grid Layout: 1 col mobile (g-2), larger screens (g-3) -->
      <div class="row g-2 g-md-3 m-0"> <!-- m-0 для фіксу горизонтального скролу -->
        
        <!-- 1. ORDER INFO -->
        <div class="col-12 col-md-6 col-xl-4">
          <div class="clean-card h-100">
            <div class="card-header-simple">
              <span class="card-title">Замовлення</span>
              <span class="order-number font-monospace">#{{ order.order_number }}</span>
            </div>
            
            <div class="d-flex flex-column gap-2 mt-2 mt-md-3">
              <!-- Source -->
              <div class="info-row">
                <span class="label-text">Джерело</span>
                <span
                  class="source-badge"
                  :style="order.source_color ? { color: order.source_color, borderColor: order.source_color + '40', backgroundColor: order.source_color + '10' } : {}"
                >
                  <i v-if="order.source_icon" :class="order.source_icon"></i>
                  {{ order.source_name || '—' }}
                </span>
              </div>
  
              <!-- Date -->
              <div class="info-row">
                <span class="label-text">Створено</span>
                <span class="value-text">{{ formatDate(order.created_at) }}</span>
              </div>
  
              <!-- Status -->
              <div class="info-row">
                <span class="label-text">Статус</span>
                <button
                  class="status-pill-btn w-auto"
                  :style="getStatusStyle(order)"
                  @click.prevent.stop="$emit('open-statuses')"
                >
                  <i v-if="order.status_icon" :class="order.status_icon" class="me-1"></i>
                  <span v-else class="status-dot"></span>
                  <span class="status-label">{{ order.status }}</span>
                  <i class="bi bi-chevron-down ms-2 opacity-50" style="font-size: 0.75em;"></i>
                </button>
              </div>
  
              <!-- Payment -->
              <div class="info-row">
                <span class="label-text">Оплата</span>
                <span class="payment-badge" :class="getPaymentClass(order.payment_status)">
                  <i class="bi" :class="getPaymentIcon(order.payment_status)"></i>
                  {{ order.payment_status_label }}
                </span>
              </div>
            </div>
  
            <div class="divider"></div>
  
            <!-- Tags -->
            <div class="d-flex flex-column gap-1 gap-md-2">
              <span class="label-text">Теги</span>
              <div class="tags-wrapper">
                <span
                  v-for="tag in order.tags"
                  :key="tag.id"
                  class="tag-chip"
                  :class="'tag-' + tag.color"
                >
                  <i :class="'bi ' + tag.icon"></i>
                  {{ tag.name }}
                </span>
                <button class="btn-add-tag" @click.stop="$emit('open-tags')">
                  + Додати
                </button>
              </div>
            </div>
          </div>
        </div>
  
        <!-- 2. CUSTOMER INFO -->
        <div class="col-12 col-md-6 col-xl-4">
          <div class="clean-card h-100">
            <div class="card-header-simple mb-2 mb-md-3">
              <span class="card-title">Покупець</span>
              <button class="btn-icon-soft" title="Профіль клієнта">
                <i class="bi bi-box-arrow-up-right"></i>
              </button>
            </div>
  
            <div class="d-flex flex-column gap-2 gap-md-3">
              <!-- Client Avatar & Name -->
              <div class="client-card">
                <div class="avatar-box">
                  {{ order.client ? order.client.charAt(0).toUpperCase() : '?' }}
                </div>
                <div class="overflow-hidden">
                  <div class="fw-bold text-dark text-truncate client-name">{{ order.client }}</div>
                  <div class="small text-muted" style="font-size: 0.75rem;">Одержувач</div>
                </div>
              </div>
  
              <!-- Contacts -->
              <div class="contact-list">
                  <div class="contact-item">
                      <i class="bi bi-telephone"></i>
                      <span class="font-monospace fw-medium">{{ order.phone || '—' }}</span>
                  </div>
                  <div class="contact-item" v-if="order.email">
                      <i class="bi bi-envelope"></i>
                      <span class="text-truncate">{{ order.email }}</span>
                  </div>
              </div>
  
              <!-- Comment -->
              <div v-if="order.comment" class="comment-box">
                <i class="bi bi-chat-left-text-fill text-warning me-2 mt-1"></i>
                <span class="fst-italic text-dark small comment-text">{{ order.comment }}</span>
              </div>
            </div>
          </div>
        </div>
  
        <!-- 3. DELIVERY INFO -->
        <div class="col-12 col-xl-4">
          <div class="clean-card h-100 border-primary-subtle">
            <div class="card-header-simple mb-2 mb-md-3">
              <span class="card-title">Доставка</span>
              <span class="carrier-badge">
                <i class="bi bi-truck me-1"></i>
                {{ order.delivery_carrier || 'Самовивіз' }}
              </span>
            </div>
  
            <!-- Status Widget -->
            <div class="delivery-status-box mb-2 mb-md-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <div class="d-flex align-items-center gap-2" :style="{ color: order.delivery_status_color || '#64748b' }">
                  <i v-if="order.delivery_status_icon" :class="['bi', order.delivery_status_icon]"></i>
                  <span class="fw-bold delivery-status-text">{{ order.delivery_status || 'Статус невідомий' }}</span>
                </div>
                <button 
                  class="btn-refresh" 
                  :disabled="order.refreshingDelivery"
                  @click.stop="$emit('refresh-delivery')"
                  title="Оновити"
                >
                  <i class="bi bi-arrow-clockwise" :class="{'spin': order.refreshingDelivery}"></i>
                </button>
              </div>
              <div v-if="order.delivery_status_updated_at" class="extra-small text-muted">
                {{ formatDate(order.delivery_status_updated_at) }}
              </div>
            </div>
  
            <!-- Address Info -->
            <div class="address-block mb-2 mb-md-3">
               <div class="address-row">
                 <i class="bi bi-geo-alt-fill text-muted"></i>
                 <span class="text-dark fw-medium">{{ order.city_name || '—' }}</span>
               </div>
               <div class="address-row align-items-start">
                 <i class="bi bi-building text-muted mt-1"></i>
                 <span class="text-secondary small text-truncate">{{ order.address }}</span>
               </div>
               <div class="address-row mt-1 pt-2 border-top">
                  <span class="text-muted small me-1">Платник:</span>
                  <span class="text-dark small fw-bold">{{ order.delivery_payer }}</span>
               </div>
            </div>
  
            <!-- TTN Actions -->
            <div class="ttn-section">
              <div v-if="order.ttn" class="d-flex flex-column gap-2">
                <div class="ttn-display">
                  <i class="bi bi-upc-scan text-muted ms-2"></i>
                  <span class="font-monospace fw-bold text-dark flex-grow-1 text-center">{{ order.ttn }}</span>
                  <button class="btn-copy" @click.stop="$emit('copy-ttn')">
                    <i class="bi bi-clipboard"></i>
                  </button>
                </div>
                
                <div class="d-flex gap-2">
                  <button class="btn-action-light flex-grow-1" @click.stop="$emit('print-ttn')">
                    <i class="bi bi-printer"></i> Друк
                  </button>
                  <button class="btn-action-light text-danger flex-grow-1" @click.stop="$emit('cancel-ttn')" :disabled="order.loadingTtn">
                    <i class="bi bi-trash"></i> Вид.
                  </button>
                </div>
              </div>
  
              <div v-else>
                 <button 
                    class="btn-create-ttn" 
                    @click.stop="$emit('generate-ttn')"
                    :disabled="order.loadingTtn"
                  >
                    <span v-if="order.loadingTtn" class="spinner-border spinner-border-sm me-2"></span>
                    <i v-else class="bi bi-magic me-2"></i>
                    Створити ТТН
                  </button>
              </div>
            </div>
          </div>
        </div>
      </div>
  
      <!-- 4. PRODUCTS & TOTALS -->
      <div class="row mt-2 mt-md-3 g-0"> <!-- g-0 для уникнення відступів по краях -->
        <div class="col-12">
          <div class="clean-card p-0 overflow-hidden">
            <!-- Table Header -->
            <div class="p-2 px-3 border-bottom bg-light bg-opacity-50 d-flex justify-content-between align-items-center">
              <span class="fw-bold text-dark small-header">Товари</span>
              <span class="badge bg-secondary bg-opacity-10 text-secondary count-badge">{{ order.items?.length || 0 }} шт.</span>
            </div>
  
            <!-- Table -->
            <div class="table-responsive">
              <table class="table align-middle mb-0 clean-table">
                <thead>
                  <tr>
                    <th class="ps-3 ps-md-4" style="width: 50px;">Фото</th>
                    <th style="min-width: 140px;">Товар</th>
                    <th class="text-center">Розмір</th>
                    <th class="text-center">К-сть</th>
                    <th class="text-end" style="white-space: nowrap;">Ціна</th>
                    <th class="text-end pe-3 pe-md-4" style="white-space: nowrap;">Сума</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in order.items" :key="item.id">
                    <td class="ps-3 ps-md-4">
                      <div class="product-thumb-lg">
                        <img v-if="item.photo" :src="item.photo" alt="product" />
                        <i v-else class="bi bi-image text-muted fs-6"></i>
                      </div>
                    </td>
                    <td>
                      <div class="fw-bold text-dark text-break item-sku">{{ item.sku }}</div>
                      <div class="small text-muted text-break item-title">{{ item.title }}</div>
                    </td>
                    <td class="text-center">
                      <span class="badge bg-light text-dark border fw-normal size-badge" v-if="item.size">{{ item.size }}</span>
                      <span class="text-muted small" v-else>—</span>
                    </td>
                    <td class="text-center fw-bold text-dark small">{{ item.qty }}</td>
                    <td class="text-end text-muted small">{{ formatCurrency(item.price, order.currency) }}</td>
                    <td class="text-end fw-bold pe-3 pe-md-4 text-dark small">{{ formatCurrency(item.total, order.currency) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
  
            <!-- Footer: Fiscal & Summary -->
            <div class="footer-section">
              <!-- Left: Fiscal Widget (Reusable) -->
              <div class="fiscal-wrapper">
                <FiscalBlock
                  :order-id="order.id"
                  :receipt="order.latestFiscalReceipt"
                  :prepay-amount="order.prepay_amount"
                  :total-amount="order.total"
                />
              </div>
  
              <!-- Right: Totals -->
              <div class="summary-wrapper">
                <!-- Calculation Rows -->
                <div class="calc-group">
                  <div class="calc-row mb-1 mb-md-2">
                    <span class="text-muted label-small">Вартість товарів</span>
                    <span class="fw-bold text-dark value-small">{{ formatCurrency(order.total, order.currency) }}</span>
                  </div>
                  <div v-if="order.prepay_amount" class="calc-row">
                    <span class="text-success label-small d-flex align-items-center"><i class="bi bi-check-all me-1"></i>Передплата</span>
                    <span class="fw-bold text-success value-small">-{{ formatCurrency(order.prepay_amount, order.currency) }}</span>
                  </div>
                </div>
                
                <!-- Total Box -->
                <div class="total-box mt-2 mt-md-3">
                  <div class="d-flex justify-content-between align-items-end w-100">
                    <span class="label text-muted text-uppercase fw-bold" style="font-size: 0.65rem; letter-spacing: 0.05em; margin-bottom: 2px;">До сплати</span>
                    <span class="amount text-primary fw-800 lh-1 total-amount-text">{{ formatCurrency(Math.max(0, order.total - order.prepay_amount), order.currency) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </template>
  
  <style scoped>
  /* --- LAYOUT --- */
  .details-wrapper {
    padding: 16px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden; /* Забороняємо горизонтальний скрол */
    box-sizing: border-box;
  }
  
  .clean-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    height: 100%;
    box-sizing: border-box; /* Важливо для розрахунку ширини */
  }
  
  .divider { height: 1px; background: #f1f5f9; margin: 12px 0; }
  .divider-dashed { height: 1px; border-top: 1px dashed #cbd5e1; margin: 10px 0; }
  
  /* --- HEADER STYLES --- */
  .card-header-simple {
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .card-title {
    font-size: 0.85rem;
    font-weight: 700;
    color: #1e293b;
    text-transform: uppercase;
    letter-spacing: 0.03em;
  }
  .order-number {
    background: #f1f5f9;
    padding: 2px 8px;
    border-radius: 6px;
    color: #475569;
    font-size: 0.8rem;
    font-weight: 600;
  }
  
  /* --- INFO ROWS --- */
  .info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .label-text {
    font-size: 0.7rem;
    color: #94a3b8;
    font-weight: 600;
  }
  .value-text {
    font-size: 0.85rem;
    font-weight: 500;
    color: #334155;
  }
  
  /* --- BUTTONS & BADGES --- */
  .source-badge {
    display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px;
    border-radius: 6px; border: 1px solid; font-size: 0.7rem; font-weight: 600;
  }
  .payment-badge {
    display: inline-flex; align-items: center; gap: 4px; font-size: 0.8rem; font-weight: 600;
  }
  
  .status-pill-btn {
    background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;
    padding: 4px 10px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px;
    font-weight: 600; font-size: 0.8rem; cursor: pointer; transition: all 0.2s;
  }
  .status-pill-btn:hover { filter: brightness(0.97); transform: translateY(-1px); }
  .status-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; opacity: 0.8; }
  
  .btn-icon-soft {
    width: 26px; height: 26px; border-radius: 6px; border: 1px solid #e2e8f0; background: #fff;
    color: #64748b; display: flex; align-items: center; justify-content: center; transition: all 0.2s;
    font-size: 0.85rem;
  }
  .btn-icon-soft:hover { background: #f8fafc; color: #3b82f6; border-color: #cbd5e1; }
  
  /* --- TAGS --- */
  .tags-wrapper { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
  .tag-chip {
    font-size: 0.65rem; padding: 3px 8px; border-radius: 6px; font-weight: 600;
    display: inline-flex; align-items: center; gap: 4px; background: #f1f5f9; color: #64748b;
  }
  .btn-add-tag {
    font-size: 0.65rem; padding: 3px 8px; border-radius: 6px; border: 1px dashed #cbd5e1;
    background: transparent; color: #64748b; cursor: pointer; transition: all 0.2s;
  }
  .btn-add-tag:hover { border-color: #6366f1; color: #6366f1; background: #eef2ff; }
  
  /* --- CLIENT CARD --- */
  .client-card { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
  .avatar-box {
    width: 38px; height: 38px; background: linear-gradient(135deg, #eff6ff, #dbeafe);
    color: #3b82f6; border-radius: 8px; display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 1rem; flex-shrink: 0;
  }
  .contact-list { display: flex; flex-direction: column; gap: 8px; background: #f8fafc; padding: 10px; border-radius: 8px; }
  .contact-item { display: flex; align-items: center; gap: 8px; font-size: 0.8rem; color: #334155; }
  .contact-item i { color: #94a3b8; font-size: 0.9rem; }
  
  .comment-box {
    background: #fffbeb; border: 1px solid #fef3c7; color: #b45309; padding: 8px 10px;
    border-radius: 6px; font-size: 0.75rem; display: flex; align-items: start;
  }
  
  /* --- DELIVERY --- */
  .carrier-badge { background: #0f172a; color: #fff; font-size: 0.65rem; font-weight: 600; padding: 3px 8px; border-radius: 6px; }
  .delivery-status-box { background: #f8fafc; border-left: 3px solid #cbd5e1; padding: 8px 12px; border-radius: 6px; }
  .btn-refresh {
    border: none; background: #fff; width: 22px; height: 22px; border-radius: 50%;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: center; color: #3b82f6; cursor: pointer; font-size: 0.8rem;
  }
  .spin { animation: spin 1s linear infinite; }
  @keyframes spin { 100% { transform: rotate(360deg); } }
  
  .address-block { display: flex; flex-direction: column; gap: 6px; }
  .address-row { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; }
  
  .ttn-display {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 3px;
    display: flex; align-items: center; margin-bottom: 8px;
  }
  .btn-copy {
    background: #f8fafc; border: none; border-left: 1px solid #e2e8f0; width: 30px; height: 26px;
    color: #64748b; display: flex; align-items: center; justify-content: center; border-radius: 4px; cursor: pointer; font-size: 0.85rem;
  }
  .btn-copy:hover { color: #3b82f6; }
  
  .btn-action-light {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px;
    font-size: 0.75rem; font-weight: 600; color: #475569; cursor: pointer; transition: all 0.2s;
  }
  .btn-action-light:hover { background: #f8fafc; border-color: #cbd5e1; }
  
  .btn-create-ttn {
    width: 100%; background: #3b82f6; color: white; border: none; padding: 8px;
    border-radius: 6px; font-weight: 600; font-size: 0.85rem; cursor: pointer; transition: background 0.2s;
  }
  .btn-create-ttn:hover { background: #2563eb; }
  
  /* --- TABLE & PRODUCTS --- */
  .clean-table th { font-size: 0.7rem; text-transform: uppercase; color: #64748b; font-weight: 700; padding: 10px 12px; border-bottom: 1px solid #e2e8f0; background: #fcfcfc; }
  .clean-table td { padding: 10px 12px; border-bottom: 1px solid #f8fafc; font-size: 0.85rem; }
  .product-thumb-lg {
    width: 48px; height: 48px; border-radius: 6px; background: #fff;
    border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center; overflow: hidden;
  }
  .product-thumb-lg img { width: 100%; height: 100%; object-fit: cover; }
  
  /* --- FOOTER SECTION (FISCAL + SUMMARY) --- */
  .footer-section {
    display: flex;
    gap: 16px;
    padding: 16px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
  }
  
  .fiscal-wrapper {
    flex: 1;
    min-width: 280px;
    max-width: 420px;
  }
  
  /* --- SUMMARY BOX --- */
  .summary-wrapper {
    flex: 1;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }
  
  .calc-group {
    padding: 4px 8px;
  }
  
  .calc-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.85rem;
  }
  
  .total-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 12px;
    display: flex;
    align-items: center;
  }
  
  .fw-800 { font-weight: 800; }
  .label-small { font-size: 0.75rem; }
  
  /* --- MOBILE RESPONSIVE --- */
  @media (max-width: 767.98px) {
    .details-wrapper { padding: 0; margin-top: -1px; /* Стиковка з карткою */ }
    .clean-card { border-radius: 0; border-left: none; border-right: none; margin-bottom: 8px; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; box-shadow: none; padding: 12px; }
    
    .card-title { font-size: 0.8rem; }
    .value-text { font-size: 0.85rem; }
    
    /* Compact Table */
    .clean-table th, .clean-table td { padding: 8px 6px; font-size: 0.75rem; }
    .product-thumb-lg { width: 40px; height: 40px; }
    .item-sku { font-size: 0.8rem; }
    .item-title { font-size: 0.7rem; }
    .size-badge { font-size: 0.65rem !important; }
    
    /* Stacked Footer */
    .footer-section { flex-direction: column; padding: 12px; gap: 12px; }
    .fiscal-wrapper, .summary-wrapper { width: 100%; min-width: 0; max-width: none; }
    .summary-wrapper { padding: 12px; }
    .total-amount-text { font-size: 1.1rem; }
  }
  </style>