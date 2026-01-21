<script setup>
  import { ref, computed } from 'vue';
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
  
  const props = defineProps({
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
  
  // --- LOGIC FOR PHONE COPY & SOCIALS ---
  const isPhoneCopied = ref(false);
  const isAddressCopied = ref(false);
  
  // Очищаємо номер від зайвих символів (+, дужки, пробіли) для посилань
  const cleanPhone = computed(() => {
    const phone = props.order.phone || '';
    return phone.replace(/\D/g, ''); // Залишає тільки цифри
  });
  
  const copyPhone = async () => {
    if (!props.order.phone) return;
    try {
      await navigator.clipboard.writeText(props.order.phone);
      isPhoneCopied.value = true;
      setTimeout(() => {
        isPhoneCopied.value = false;
      }, 2000); // Повідомлення зникне через 2 сек
    } catch (err) {
      console.error('Failed to copy', err);
    }
  };

  const copyAddress = async () => {
    const city = props.order.city_name || '';
    const address = props.order.address || '';
    const text = [city, address].filter(Boolean).join('\n').trim();
    if (!text) return;
    try {
      await navigator.clipboard.writeText(text);
      isAddressCopied.value = true;
      setTimeout(() => {
        isAddressCopied.value = false;
      }, 2000);
    } catch (err) {
      console.error('Failed to copy address', err);
    }
  };
  </script>
  
  <template>
    <div class="details-wrapper">
      <div class="order-summary-bar">
        <div class="order-summary-left">
          <div class="order-id-line">
            <span class="summary-label">Замовлення</span>
            <span class="summary-number font-monospace">#{{ order.order_number }}</span>
          </div>
  
          <div class="order-meta-line">
            <span class="meta-item">
              <i class="bi bi-clock-history me-1 text-muted"></i>
              {{ formatDate(order.created_at) }}
            </span>
  
            <span
              class="meta-item source-pill"
              v-if="order.source_name"
              :style="order.source_color
                ? {
                    color: order.source_color,
                    borderColor: order.source_color + '40',
                    backgroundColor: order.source_color + '10',
                  }
                : {}"
            >
              <i v-if="order.source_icon" :class="order.source_icon" class="me-1"></i>
              {{ order.source_name }}
            </span>
          </div>
        </div>
  
        <div class="order-summary-right">
          <button
            class="status-pill-btn"
            :style="getStatusStyle(order)"
            @click.prevent.stop="$emit('open-statuses')"
          >
            <i v-if="order.status_icon" :class="order.status_icon" class="me-1"></i>
            <span v-else class="status-dot"></span>
            <span class="status-label">{{ order.status }}</span>
            <i class="bi bi-chevron-down ms-1 opacity-50" style="font-size: 0.75em;"></i>
          </button>
  
          <span class="payment-pill" :class="getPaymentClass(order.payment_status)">
            <i class="bi me-1" :class="getPaymentIcon(order.payment_status)"></i>
            {{ order.payment_status_label }}
          </span>
        </div>
      </div>
  
      <div class="order-tags-bar">
        <div class="d-flex align-items-center gap-2 flex-wrap">
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
              <i class="bi bi-plus-lg me-1"></i> Додати
            </button>
          </div>
        </div>
      </div>
  
      <div class="row gx-3 gy-3 mt-1 mt-md-2">
        <div class="col-12 col-lg-4">
          <div class="panel-card h-100">
            <div class="panel-section">
              <div class="section-header">
                <div class="d-flex align-items-center gap-2">
                  <span class="section-title">Покупець</span>
                </div>
                <button class="btn-icon-soft" title="Профіль клієнта">
                  <i class="bi bi-box-arrow-up-right"></i>
                </button>
              </div>
  
              <div class="client-card">
                <div class="avatar-box">
                  {{ order.client ? order.client.charAt(0).toUpperCase() : '?' }}
                </div>
                <div class="overflow-hidden">
                  <div class="fw-bold text-dark text-truncate client-name">
                    {{ order.client || '—' }}
                  </div>
                  <div class="small text-muted" style="font-size: 0.75rem;">Одержувач</div>
                </div>
              </div>
  
              <div class="contact-list">
                <div class="contact-item phone-row">
                  <div class="d-flex align-items-center gap-2">
                     <i class="bi bi-telephone"></i>
                     <span class="font-monospace fw-medium text-dark">
                       {{ order.phone || '—' }}
                     </span>
                  </div>
                  
                  <div class="d-flex align-items-center">
                    <span v-if="isPhoneCopied" class="copy-feedback">Скопійовано!</span>
                    <button 
                      class="btn-copy-sm" 
                      @click="copyPhone" 
                      title="Копіювати номер"
                    >
                      <i class="bi bi-clipboard"></i>
                    </button>
                  </div>
                </div>
  
                <div class="social-row mt-1" v-if="order.phone">
                  <a :href="`viber://chat?number=%2B${cleanPhone}`" class="btn-social btn-viber" target="_blank">
                    <i class="bi bi-telephone-fill"></i> Viber
                  </a>
                  <a :href="`tg://resolve?phone=${cleanPhone}`" class="btn-social btn-telegram"> 
                    <i class="bi bi-telegram"></i> Telegram
                  </a>
                </div>
  
                <div class="contact-item" v-if="order.email">
                  <i class="bi bi-envelope"></i>
                  <span class="text-truncate">{{ order.email }}</span>
                </div>
              </div>
  
              <div v-if="order.comment" class="comment-box mt-2">
                <i class="bi bi-chat-left-text-fill text-warning me-2 mt-1"></i>
                <span class="fst-italic text-dark small comment-text">
                  {{ order.comment }}
                </span>
              </div>
            </div>
  
            <div class="section-divider"></div>
  
            <div class="panel-section">
              <div class="section-header">
                <div class="d-flex align-items-center gap-2">
                  <span class="section-title">Доставка</span>
                  <span class="carrier-badge">
                    <i class="bi bi-truck me-1"></i>
                    {{ order.delivery_carrier || 'Самовивіз' }}
                  </span>
                </div>
              </div>
  
              <div class="delivery-status-box mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <div
                    class="d-flex align-items-center gap-2"
                    :style="{ color: order.delivery_status_color || '#64748b' }"
                  >
                    <i
                      v-if="order.delivery_status_icon"
                      :class="['bi', order.delivery_status_icon]"
                    ></i>
                    <span class="fw-bold delivery-status-text">
                      {{ order.delivery_status || 'Статус невідомий' }}
                    </span>
                  </div>
                  <button
                    class="btn-refresh"
                    :disabled="order.refreshingDelivery"
                    @click.stop="$emit('refresh-delivery')"
                    title="Оновити"
                  >
                    <i class="bi bi-arrow-clockwise" :class="{ spin: order.refreshingDelivery }"></i>
                  </button>
                </div>
                <div v-if="order.delivery_status_updated_at" class="extra-small text-muted">
                  Оновлено: {{ formatDate(order.delivery_status_updated_at) }}
                </div>
              </div>
  
              <div class="address-block">
                <div class="copy-anchor">
                  <button class="btn-copy-icon" type="button" @click="copyAddress" title="Копіювати адресу">
                  <i class="bi bi-clipboard"></i>
                  </button>
                  <transition name="fade-pop">
                    <span v-if="isAddressCopied" class="copy-toast-bubble">Скопійовано</span>
                  </transition>
                </div>
                <div class="address-row">
                  <i class="bi bi-geo-alt-fill text-muted"></i>
                  <span class="text-dark fw-medium">
                    {{ order.city_name || '—' }}
                  </span>
                </div>
                <div class="address-row align-items-start">
                  <i class="bi bi-building text-muted mt-1"></i>
                  <span class="text-secondary small">
                    {{ order.address || '—' }}
                  </span>
                </div>
                <div class="address-row mt-2 pt-2 border-top">
                  <span class="text-muted small me-1">Платник:</span>
                  <span class="text-dark small fw-bold">
                    {{ order.delivery_payer || '—' }}
                  </span>
                </div>
              </div>
  
              <div class="ttn-section mt-3">
                <template v-if="order.ttn">
                  <div class="ttn-display">
                    <i class="bi bi-upc-scan text-muted ms-2"></i>
                    <span
                      class="font-monospace fw-bold text-dark flex-grow-1 text-center ttn-number"
                    >
                      {{ order.ttn }}
                    </span>
                    <button class="btn-copy" @click.stop="$emit('copy-ttn')">
                      <i class="bi bi-clipboard"></i>
                    </button>
                  </div>
  
                  <div class="d-flex gap-2">
                    <button
                      class="btn-action-light flex-grow-1"
                      @click.stop="$emit('print-ttn')"
                    >
                      <i class="bi bi-printer"></i> Друк
                    </button>
                    <button
                      class="btn-action-light text-danger flex-grow-1"
                      @click.stop="$emit('cancel-ttn')"
                      :disabled="order.loadingTtn"
                    >
                      <i class="bi bi-trash"></i> Видалити
                    </button>
                  </div>
                </template>
  
                <template v-else>
                  <button
                    class="btn-create-ttn"
                    @click.stop="$emit('generate-ttn')"
                    :disabled="order.loadingTtn"
                  >
                    <span
                      v-if="order.loadingTtn"
                      class="spinner-border spinner-border-sm me-2"
                    ></span>
                    <i v-else class="bi bi-magic me-2"></i>
                    Створити ТТН
                  </button>
                </template>
              </div>
            </div>
          </div>
        </div>
  
        <div class="col-12 col-lg-8">
          <div class="panel-card h-100 p-0 d-flex flex-column">
            <div
              class="products-header d-flex justify-content-between align-items-center border-bottom"
            >
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-bag-check text-primary"></i>
                <span class="products-title">Товари</span>
              </div>
              <span class="badge bg-secondary bg-opacity-10 text-secondary count-badge">
                {{ order.items?.length || 0 }} шт.
              </span>
            </div>
  
            <div class="table-responsive">
              <table class="table align-middle mb-0 clean-table">
                <thead>
                  <tr>
                    <th class="ps-3 ps-md-4" style="width: 56px;">Фото</th>
                    <th style="min-width: 160px;">Товар</th>
                    <th class="text-center">Розмір</th>
                    <th class="text-center">К-сть</th>
                    <th class="text-end">Ціна</th>
                    <th class="text-end pe-3 pe-md-4">Сума</th>
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
                      <div class="fw-bold text-dark text-break item-sku">
                        {{ item.sku }}
                      </div>
                      <div class="small text-muted text-break item-title">
                        {{ item.title }}
                      </div>
                    </td>
                    <td class="text-center">
                      <span
                        class="badge bg-light text-dark border fw-normal size-badge"
                        v-if="item.size"
                      >
                        {{ item.size }}
                      </span>
                      <span class="text-muted small" v-else>—</span>
                    </td>
                    <td class="text-center fw-bold text-dark small">
                      {{ item.qty }}
                    </td>
                    <td class="text-end text-muted small">
                      {{ formatCurrency(item.price, order.currency) }}
                    </td>
                    <td class="text-end fw-bold pe-3 pe-md-4 text-dark small">
                      {{ formatCurrency(item.total, order.currency) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
  
            <div class="products-footer mt-auto">
              <div class="fiscal-block-wrapper">
                <div class="sub-section-header">
                  <span class="sub-section-title">
                    <i class="bi bi-receipt-cutoff me-2"></i>Фіскалізація
                  </span>
                </div>
                <div class="fiscal-scale">
                  <FiscalBlock
                    :order-id="order.id"
                    :receipt="order.latestFiscalReceipt"
                    :prepay-amount="order.prepay_amount"
                    :total-amount="order.total"
                  />
                </div>
              </div>
  
              <div class="totals-wrapper">
                <div class="sub-section-header">
                  <span class="sub-section-title">
                    <i class="bi bi-calculator me-2"></i>Підсумок
                  </span>
                </div>
  
                <div class="calc-group">
                  <div class="calc-row">
                    <span class="text-muted label-small">Вартість товарів</span>
                    <span class="fw-bold text-dark value-small">
                      {{ formatCurrency(order.total, order.currency) }}
                    </span>
                  </div>
  
                  <div v-if="order.prepay_amount" class="calc-row mt-1">
                    <span class="text-success label-small d-flex align-items-center">
                      <i class="bi bi-check-all me-1"></i>Передплата
                    </span>
                    <span class="fw-bold text-success value-small">
                      -{{ formatCurrency(order.prepay_amount, order.currency) }}
                    </span>
                  </div>
                </div>
  
                <div class="total-box mt-2">
                  <div class="d-flex justify-content-between align-items-end w-100">
                    <span class="total-label text-muted text-uppercase fw-bold">
                      До сплати
                    </span>
                    <span class="total-amount text-primary fw-800 lh-1">
                      {{
                        formatCurrency(
                          Math.max(0, order.total - (order.prepay_amount || 0)),
                          order.currency
                        )
                      }}
                    </span>
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
  /* BASE WRAPPER */
  .details-wrapper {
    padding: 14px 16px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
    box-sizing: border-box;
  }
  
  /* HEADER */
  .order-summary-bar {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 14px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    color: #1e293b;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
  }
  
  .order-summary-left { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
  .order-id-line { display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap; }
  .summary-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.12em; opacity: 0.6; font-weight: 700; color: #64748b; }
  .summary-number { font-size: 1rem; font-weight: 800; padding: 0 8px; border-radius: 6px; background: #f1f5f9; color: #0f172a; border: 1px solid #e2e8f0; }
  
  .order-meta-line { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: 0.8rem; }
  .meta-item { display: inline-flex; align-items: center; gap: 4px; color: #475569; }
  .source-pill { border-radius: 999px; padding: 2px 10px; border: 1px solid transparent; font-size: 0.75rem; font-weight: 600; }
  
  .order-summary-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
  .status-pill-btn { background: #fff; color: #334155; border: 1px solid #cbd5e1; padding: 4px 10px; border-radius: 999px; display: inline-flex; align-items: center; gap: 6px; font-weight: 600; font-size: 0.8rem; cursor: pointer; transition: all 0.15s; }
  .status-pill-btn:hover { background: #f8fafc; border-color: #94a3b8; }
  .status-dot { width: 7px; height: 7px; border-radius: 999px; background: currentColor; }
  .payment-pill { display: inline-flex; align-items: center; gap: 4px; font-size: 0.8rem; font-weight: 600; padding: 4px 10px; border-radius: 999px; background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569; }
  
  /* TAGS */
  .order-tags-bar { margin-top: 8px; padding: 8px 12px; background: rgba(255, 255, 255, 0.6); border-radius: 10px; border: 1px dashed #cbd5e1; }
  .label-text { font-size: 0.7rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; }
  .tags-wrapper { display: flex; flex-wrap: wrap; gap: 6px; }
  .tag-chip { font-size: 0.7rem; padding: 3px 9px; border-radius: 999px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; background: #fff; border: 1px solid #e2e8f0; color: #64748b; }
  .btn-add-tag { font-size: 0.7rem; padding: 3px 10px; border-radius: 999px; border: 1px dashed #cbd5e1; background: #fff; color: #64748b; cursor: pointer; transition: all 0.15s; }
  .btn-add-tag:hover { border-color: #6366f1; color: #6366f1; background: #eef2ff; }
  
  /* PANEL CARDS */
  .panel-card { background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02); padding: 12px; display: flex; flex-direction: column; }
  .panel-section { display: flex; flex-direction: column; gap: 10px; }
  .section-header { display: flex; align-items: center; justify-content: space-between; }
  .section-title { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #0f172a; }
  .section-divider { height: 1px; background: linear-gradient(to right, transparent, rgba(148, 163, 184, 0.4), transparent); margin: 10px 0; }
  .btn-icon-soft { width: 26px; height: 26px; border-radius: 8px; border: 1px solid #e2e8f0; background: #ffffff; color: #64748b; display: flex; align-items: center; justify-content: center; transition: all 0.15s; font-size: 0.85rem; }
  .btn-icon-soft:hover { background: #eef2ff; color: #4f46e5; border-color: #c7d2fe; }
  
  /* CUSTOMER CARD */
  .client-card { display: flex; align-items: center; gap: 12px; }
  .avatar-box { width: 40px; height: 40px; background: radial-gradient(circle at 0 0, #e0f2fe, #dbeafe); color: #1d4ed8; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1rem; flex-shrink: 0; }
  .contact-list { display: flex; flex-direction: column; gap: 8px; background: #f8fafc; padding: 8px 10px; border-radius: 8px; }
  
  /* NEW PHONE & SOCIAL STYLES */
  .phone-row { display: flex; justify-content: space-between; align-items: center; width: 100%; font-size: 0.85rem; color: #334155; }
  .contact-item { display: flex; align-items: center; gap: 8px; font-size: 0.8rem; color: #334155; }
  .contact-item i.bi-envelope { color: #94a3b8; font-size: 0.9rem; }
  .contact-item i.bi-telephone { color: #94a3b8; font-size: 0.9rem; }
  
  .btn-copy-sm {
    background: transparent; border: none; color: #94a3b8; cursor: pointer; padding: 2px 6px; font-size: 0.9rem; transition: color 0.2s;
  }
  .btn-copy-sm:hover { color: #3b82f6; }
  .copy-feedback { font-size: 0.7rem; color: #10b981; font-weight: 600; margin-right: 4px; animation: fadeInOut 2s forwards; }
  @keyframes fadeInOut { 0% { opacity: 0; } 10% { opacity: 1; } 80% { opacity: 1; } 100% { opacity: 0; } }
  
  .social-row { display: flex; gap: 8px; margin-top: 4px; }
  .btn-social {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px; border-radius: 6px;
    font-size: 0.75rem; font-weight: 600; text-decoration: none;
    transition: opacity 0.2s; flex: 1; justify-content: center;
  }
  .btn-social:hover { opacity: 0.85; }
  
  /* VIBER */
  .btn-viber { background: #ede9f6; color: #675dae; border: 1px solid #dcd6ef; }
  /* TELEGRAM */
  .btn-telegram { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
  
  
  /* COMMENT */
  .comment-box { background: #fffbeb; border-radius: 8px; border: 1px solid #fef3c7; padding: 8px 10px; font-size: 0.75rem; display: flex; }
  
  /* DELIVERY */
  .carrier-badge { background: #0f172a; color: #ffffff; font-size: 0.7rem; font-weight: 600; padding: 3px 8px; border-radius: 999px; }
  .delivery-status-box { background: #f8fafc; border-radius: 8px; border-left: 3px solid #cbd5e1; padding: 8px 10px; }
  .btn-refresh { border: none; background: #ffffff; width: 22px; height: 22px; border-radius: 999px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.12); display: flex; align-items: center; justify-content: center; color: #3b82f6; cursor: pointer; font-size: 0.8rem; transition: transform 0.15s; }
  .btn-refresh:hover { transform: translateY(-1px); }
  .spin { animation: spin 1s linear infinite; }
  @keyframes spin { 100% { transform: rotate(360deg); } }
  
  /* ADDRESS */
.address-block { position: relative; display: flex; flex-direction: column; gap: 6px; padding-right: 36px; }
.address-row { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; }
.copy-anchor {
  position: absolute;
  top: 0;
  right: 0;
}
.btn-copy-icon {
  position: absolute;
  top: 0;
  right: 0;
  width: 28px;
  height: 28px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  background: #ffffff;
  color: #64748b;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
}
.btn-copy-icon:hover {
  background: #f8fafc;
  border-color: #cbd5e1;
  color: #1f2937;
}
.copy-toast-bubble {
  position: absolute;
  top: -36px;
  right: 0;
  background: #111827;
  color: #fff;
  font-size: 0.7rem;
  font-weight: 600;
  padding: 4px 8px;
  border-radius: 8px;
  white-space: nowrap;
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.2);
}
.fade-pop-enter-active,
.fade-pop-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.fade-pop-enter-from,
.fade-pop-leave-to {
  opacity: 0;
  transform: translateY(4px);
}
  
  /* TTN */
  .ttn-display { background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 4px; display: flex; align-items: center; margin-bottom: 8px; }
  .ttn-number { font-size: 0.85rem; }
  .btn-copy { background: #f8fafc; border: none; border-left: 1px solid #e2e8f0; width: 32px; height: 26px; color: #64748b; display: flex; align-items: center; justify-content: center; border-radius: 6px; cursor: pointer; font-size: 0.85rem; transition: all 0.15s; }
  .btn-copy:hover { color: #2563eb; background: #e0f2fe; }
  
  .btn-action-light { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px; font-size: 0.75rem; font-weight: 600; color: #475569; cursor: pointer; transition: all 0.15s; }
  .btn-action-light:hover { background: #f8fafc; border-color: #cbd5e1; }
  .btn-create-ttn { width: 100%; background: #3b82f6; color: #ffffff; border: none; padding: 8px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; cursor: pointer; transition: background 0.15s, transform 0.1s; }
  .btn-create-ttn:hover { background: #2563eb; transform: translateY(-1px); }
  
  /* PRODUCTS */
  .products-header { padding: 10px 12px; background: #f9fafb; }
  .products-title { font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #0f172a; }
  .clean-table th { font-size: 0.7rem; text-transform: uppercase; color: #64748b; font-weight: 700; padding: 8px 12px; border-bottom: 1px solid #e2e8f0; background: #fcfcfc; }
  .clean-table td { padding: 8px 12px; border-bottom: 1px solid #f8fafc; font-size: 0.85rem; }
  .product-thumb-lg { width: 50px; height: 50px; border-radius: 8px; background: #ffffff; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center; overflow: hidden; }
  .product-thumb-lg img { width: 100%; height: 100%; object-fit: cover; }
  
  /* FOOTER */
  .products-footer { display: grid; grid-template-columns: minmax(180px, 0.65fr) 1.35fr; gap: 16px; padding: 10px 12px 12px; border-top: 1px solid #e2e8f0; background: #ffffff; align-items: start; }
  .fiscal-block-wrapper { min-width: 0; width: 100%; overflow: hidden; }
  .fiscal-scale { font-size: 0.9em; }
  .totals-wrapper { display: flex; flex-direction: column; justify-content: space-between; }
  .sub-section-header { margin-bottom: 4px; }
  .sub-section-title { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #94a3b8; }
  .calc-group { padding-top: 2px; padding-bottom: 4px; }
  .calc-row { display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; }
  .label-small { font-size: 0.76rem; }
  .total-box { background: #f8fafc; border-radius: 10px; padding: 8px 10px; }
  .total-label { font-size: 0.7rem; letter-spacing: 0.08em; }
  .total-amount { font-size: 1.1rem; }
  .fw-800 { font-weight: 800; }
  
  /* RESPONSIVE */
  @media (max-width: 991.98px) { .products-footer { grid-template-columns: 1fr; } }
  @media (max-width: 767.98px) {
    .details-wrapper { padding: 0; margin-top: -1px; }
    .order-summary-bar { flex-direction: column; align-items: stretch; gap: 8px; border-radius: 0; border: none; border-bottom: 1px solid #e2e8f0; }
    .order-summary-right { justify-content: space-between; width: 100%; border-top: 1px solid #f1f5f9; padding-top: 8px; }
    .panel-card { border-radius: 0; border-left: none; border-right: none; box-shadow: none; }
    .clean-table th, .clean-table td { padding: 6px 6px; font-size: 0.75rem; }
    .product-thumb-lg { width: 42px; height: 42px; }
    .products-footer { padding: 8px 10px 10px; gap: 10px; }
  }
  </style>
