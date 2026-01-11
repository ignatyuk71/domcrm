<template>
  <div class="details-wrapper">
    <div class="row g-4">
      
      <div class="col-12 col-lg-4">
        <div class="clean-card h-100">
          <div class="card-section-title">Замовлення</div>
          
          <div class="d-flex flex-column gap-3">
            <div class="d-flex justify-content-between align-items-center">
              <span class="label-text">№ замовлення</span>
              <span class="value-text font-monospace">#{{ order.order_number }}</span>
            </div>

            <div class="d-flex justify-content-between align-items-center">
              <span class="label-text">Джерело</span>
              <span
                class="source-badge"
                :style="order.source_color ? { color: order.source_color, borderColor: order.source_color + '40', backgroundColor: order.source_color + '10' } : {}"
              >
                <i v-if="order.source_icon" :class="order.source_icon"></i>
                {{ order.source_name || '—' }}
              </span>
            </div>

            <div class="d-flex justify-content-between align-items-center">
              <span class="label-text">Створено</span>
              <span class="value-text">{{ formatDate(order.created_at) }}</span>
            </div>

            <div class="d-flex justify-content-between align-items-center" style="min-height: 28px;">
              <span class="label-text">Статус</span>
              
              <button
                class="status-pill-btn"
                :style="getStatusStyle(order)"
                @click.prevent.stop="$emit('open-statuses')"
              >
                <i v-if="order.status_icon" :class="order.status_icon" class="me-1"></i>
                <span v-else class="status-dot"></span>
                
                <span class="status-label">{{ order.status }}</span>
                <i class="bi bi-chevron-down ms-2 opacity-50" style="font-size: 0.75em;"></i>
              </button>
            </div>

            <div class="d-flex justify-content-between align-items-center">
              <span class="label-text">Оплата</span>
              <span class="payment-badge" :class="getPaymentClass(order.payment_status)">
                <i class="bi" :class="getPaymentIcon(order.payment_status)"></i>
                {{ order.payment_status_label }}
              </span>
            </div>
          </div>

          <div class="divider"></div>

          <div class="card-section-title mb-2">Теги</div>
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

      <div class="col-12 col-lg-4">
        <div class="clean-card h-100">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="card-section-title mb-0">Покупець</div>
            <button class="btn btn-sm btn-white border shadow-sm fw-medium">
              <i class="bi bi-box-arrow-up-right me-1"></i> Профіль
            </button>
          </div>

          <div class="d-flex flex-column gap-3">
            <div class="d-flex align-items-center gap-3">
              <div class="avatar-box">
                {{ order.client ? order.client.charAt(0).toUpperCase() : '?' }}
              </div>
              <div>
                <div class="fw-bold text-dark">{{ order.client }}</div>
                <div class="small text-muted">Одержувач</div>
              </div>
            </div>

            <div class="info-group">
              <div class="label-text mb-1">Телефон</div>
              <div class="value-text font-monospace text-dark fs-6">{{ order.phone || '—' }}</div>
            </div>

            <div class="info-group">
              <div class="label-text mb-1">Email</div>
              <div class="value-text">{{ order.email || '—' }}</div>
            </div>

            <div v-if="order.comment" class="comment-box mt-2">
              <i class="bi bi-chat-left-text text-muted me-2"></i>
              <span>{{ order.comment }}</span>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-4">
        <div class="clean-card h-100 border-primary-subtle">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="card-section-title mb-0">Доставка</div>
            <span class="carrier-badge">
              <i class="bi bi-truck me-1"></i>
              {{ order.delivery_carrier || 'Самовивіз' }}
            </span>
          </div>

          <div class="delivery-status-box mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <div class="d-flex align-items-center gap-2" :style="{ color: order.delivery_status_color || '#64748b' }">
                <i v-if="order.delivery_status_icon" :class="['bi', order.delivery_status_icon]"></i>
                <span class="fw-bold">{{ order.delivery_status || 'Статус невідомий' }}</span>
              </div>
              <button 
                class="btn-icon-refresh" 
                :disabled="order.refreshingDelivery"
                @click.stop="$emit('refresh-delivery')"
                title="Оновити статус"
              >
                <i class="bi bi-arrow-clockwise" :class="{'spin': order.refreshingDelivery}"></i>
              </button>
            </div>
            <div v-if="order.delivery_status_updated_at" class="extra-small text-muted">
              {{ formatDate(order.delivery_status_updated_at) }}
            </div>
          </div>

          <div class="d-flex flex-column gap-2 mb-3">
             <div class="info-row-sm">
               <i class="bi bi-geo-alt text-muted"></i>
               <span class="fw-bold text-dark">{{ order.city_name || '—' }}</span>
             </div>
             <div class="info-row-sm ps-4">
               <span class="text-secondary small">{{ order.address }}</span>
             </div>
             <div class="info-row-sm">
               <i class="bi bi-person-check text-muted"></i>
               <span class="text-dark small">Платник: <strong>{{ order.delivery_payer }}</strong></span>
             </div>
            <div v-if="order.payment_method" class="info-row-sm">
              <i class="bi bi-credit-card text-muted"></i>
              <span class="text-dark small">
                Спосіб оплати: <strong>{{ paymentLabel(order.payment_method) }}</strong>
              </span>
            </div>
          </div>

          <div class="ttn-section">
            <div class="label-text mb-2">Трекінг (ТТН)</div>
            
            <div v-if="order.ttn" class="d-flex flex-column gap-2">
              <div class="ttn-display">
                <span class="font-monospace fw-bold text-dark">{{ order.ttn }}</span>
                <button class="btn-copy" @click.stop="$emit('copy-ttn')">
                  <i class="bi bi-clipboard"></i>
                </button>
              </div>
              
              <div class="d-flex gap-2">
                <button class="btn btn-sm btn-light border flex-grow-1 fw-medium" @click.stop="$emit('print-ttn')">
                  <i class="bi bi-printer me-1"></i> Друк
                </button>
                <button class="btn btn-sm btn-light border text-danger flex-grow-1 fw-medium" @click.stop="$emit('cancel-ttn')" :disabled="order.loadingTtn">
                  <i class="bi bi-trash me-1"></i> Видалити
                </button>
              </div>
            </div>

            <div v-else>
               <button 
                  class="btn btn-primary w-100 shadow-sm btn-sm py-2 fw-medium" 
                  @click.stop="$emit('generate-ttn')"
                  :disabled="order.loadingTtn"
                >
                  <span v-if="order.loadingTtn" class="spinner-border spinner-border-sm me-1"></span>
                  <i v-else class="bi bi-magic me-1"></i>
                  Створити ТТН
                </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="mt-4">
      <div class="clean-card p-0 overflow-hidden">
        <div class="p-3 border-bottom bg-light bg-opacity-50">
          <div class="card-section-title mb-0">
            Товари <span class="text-muted fw-normal ms-1">({{ order.itemsCount }} шт.)</span>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table align-middle mb-0 clean-table">
            <thead class="bg-light">
              <tr>
                <th class="ps-4" style="width: 70px;">Фото</th>
                <th>Товар</th>
                <th class="text-center">Розмір</th>
                <th class="text-center">К-сть</th>
                <th class="text-end">Ціна</th>
                <th class="text-end pe-4">Сума</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in order.items" :key="item.id">
                <td class="ps-4">
                  <div class="product-thumb">
                    <img v-if="item.photo" :src="item.photo" alt="product" />
                    <i v-else class="bi bi-image text-muted"></i>
                  </div>
                </td>
                <td>
                  <div class="fw-bold text-dark">{{ item.sku }}</div>
                  <div class="small text-muted">{{ item.title }}</div>
                </td>
                <td class="text-center">
                  <span class="badge bg-light text-dark border fw-normal" v-if="item.size">{{ item.size }}</span>
                  <span class="text-muted" v-else>—</span>
                </td>
                <td class="text-center fw-bold text-dark">{{ item.qty }}</td>
                <td class="text-end text-muted">{{ formatCurrency(item.price, order.currency) }}</td>
                <td class="text-end fw-bold pe-4 text-dark">{{ formatCurrency(item.total, order.currency) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 p-4 bg-light bg-opacity-25 border-top">
          <div class="fiscal-slot order-1 order-lg-1 me-lg-auto">
            <FiscalBlock
              :order-id="order.id"
              :receipt="order.latestFiscalReceipt"
              :payment-method="order.payment_method"
              :prepay-amount="order.prepay_amount"
              :total-amount="order.total"
            />
          </div>

          <div class="summary-box flex-grow-1 order-2 order-lg-2">
            <div class="d-flex justify-content-between mb-1 text-sm">
              <span class="text-muted">Товари:</span>
              <span class="fw-bold text-dark">{{ formatCurrency(order.total, order.currency) }}</span>
            </div>
            <div v-if="order.prepay_amount" class="d-flex justify-content-between mb-1 text-sm">
              <span class="text-muted">Передоплата:</span>
              <span class="text-success fw-bold">- {{ formatCurrency(order.prepay_amount, order.currency) }}</span>
            </div>
            <div class="divider my-2"></div>
            <div class="d-flex justify-content-between align-items-center">
              <span class="fw-bold text-dark">Разом:</span>
              <span class="fs-5 fw-bold text-primary">
                {{ formatCurrency(Math.max(0, order.total - order.prepay_amount), order.currency) }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import {
  formatCurrency,
  formatDate,
  getPaymentClass,
  getPaymentIcon,
  // getStatusClass більше не потрібен у шаблоні, але не видаляю з імпорту, щоб не ламати логіку, якщо використовується десь ще
  getStatusClass, 
  getStatusIcon,
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

<style scoped>
  /* --- WRAPPER & LAYOUT --- */
  .details-wrapper {
    padding: 24px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
  }
  
  .clean-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    height: 100%;
  }
  
  .divider {
    height: 1px;
    background: #f1f5f9;
    margin: 16px 0;
  }
  
  /* --- TYPOGRAPHY --- */
  .card-section-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 16px;
  }
  
  .label-text {
    font-size: 0.75rem;
    text-transform: uppercase;
    color: #94a3b8;
    font-weight: 600;
    letter-spacing: 0.02em;
  }
  
  .value-text {
    font-size: 0.9rem;
    font-weight: 500;
    color: #334155;
  }
  
  /* --- BADGES & STATUSES --- */
  .source-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 8px;
    border-radius: 6px;
    border: 1px solid;
    font-size: 0.8rem;
    font-weight: 600;
  }
  
  .payment-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.85rem;
    font-weight: 600;
  }
  
  /* --- ОНОВЛЕНИЙ СТИЛЬ ДЛЯ СТАТУСУ --- */
  .status-pill-btn {
    /* Базові стилі (якщо немає кольору в базі) */
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
    
    /* Розміри та вирівнювання */
    padding: 6px 12px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    
    /* Текст */
    font-weight: 600;
    font-size: 0.85rem;
    line-height: 1.2;
    text-align: center;
    
    cursor: pointer;
    transition: all 0.2s ease;
  }

  /* Ефект наведення */
  .status-pill-btn:hover {
    filter: brightness(0.96);
    transform: translateY(-1px);
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
  }
  
  .status-pill-btn:active {
    transform: translateY(0);
    filter: brightness(0.92);
    box-shadow: none;
  }

  /* Крапка статусу */
  .status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background-color: currentColor;
    opacity: 0.8;
    box-shadow: 0 0 0 1px rgba(255,255,255,0.4);
  }

  .status-label {
    white-space: nowrap;
  }
  
  /* --- TAGS --- */
  .tags-wrapper {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }
  .tag-chip {
    font-size: 0.75rem;
    padding: 4px 10px;
    border-radius: 20px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #f1f5f9;
    color: #64748b;
  }
  .btn-add-tag {
    font-size: 0.75rem;
    padding: 4px 10px;
    border-radius: 20px;
    border: 1px dashed #cbd5e1;
    background: transparent;
    color: #64748b;
    transition: all 0.2s;
    cursor: pointer;
  }
  .btn-add-tag:hover {
    border-color: #6366f1;
    color: #6366f1;
  }
  
  /* --- CLIENT & INFO BLOCKS --- */
  .btn-white {
    background: #fff;
    color: #475569;
  }
  .btn-white:hover {
    background: #f8fafc;
    color: #1e293b;
  }
  
  .avatar-box {
    width: 42px;
    height: 42px;
    background: #eff6ff;
    color: #3b82f6;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.1rem;
  }
  
  .comment-box {
    background: #fffbeb;
    border: 1px solid #fef3c7;
    color: #b45309;
    padding: 10px;
    border-radius: 8px;
    font-size: 0.85rem;
    display: flex;
    align-items: start;
  }
  
  .info-row-sm {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
  }
  
  /* --- DELIVERY & TTN --- */
  .carrier-badge {
    background: #0f172a;
    color: #fff;
    font-size: 0.7rem;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
  }
  
  .delivery-status-box {
    background: #f8fafc;
    border-left: 3px solid #cbd5e1;
    padding: 10px 12px;
    border-radius: 6px;
  }
  
  .btn-icon-refresh {
    border: none;
    background: #fff;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #3b82f6;
    cursor: pointer;
  }
  .spin { animation: spin 1s linear infinite; }
  @keyframes spin { 100% { transform: rotate(360deg); } }
  
  .ttn-section {
    background: #fff;
    border: 1px solid #f1f5f9;
    border-radius: 8px;
    padding: 12px;
    margin-top: 16px;
  }
  .ttn-display {
    background: #f8fafc;
    border-radius: 6px;
    padding: 2px;
    display: flex;
    margin-bottom: 8px;
    border: 1px solid #e2e8f0;
  }
  .ttn-display span {
    flex-grow: 1;
    padding: 6px 10px;
  }
  .btn-copy {
    background: #fff;
    border: none;
    border-left: 1px solid #e2e8f0;
    width: 32px;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    border-top-right-radius: 6px;
    border-bottom-right-radius: 6px;
    cursor: pointer;
  }
  .btn-copy:hover { color: #3b82f6; background: #eff6ff; }
  
  /* --- TABLE & PRODUCTS --- */
  .clean-table th {
    font-size: 0.75rem;
    text-transform: uppercase;
    color: #64748b;
    font-weight: 700;
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
  }
  .clean-table td {
    padding: 12px;
    border-bottom: 1px solid #f8fafc;
    font-size: 0.9rem;
  }
  .product-thumb {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }
  .product-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  
  /* --- FOOTER SUMMARY --- */
  .fiscal-slot {
    min-width: 280px;
    max-width: 360px;
  }
  .summary-box {
    min-width: 280px;
  }
  .text-sm { font-size: 0.85rem; }
  .extra-small { font-size: 0.7rem; }
</style>