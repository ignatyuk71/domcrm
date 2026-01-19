<script setup>
  import OrderDetails from '@/crm/components/orders/list/OrderDetails.vue';
  import {
    formatCurrency,
    formatDate,
    getStatusClass,
    getStatusIcon,
    getStatusStyle,
  } from '@/crm/utils/orderDisplay';
  
  defineProps({
    orders: { type: Array, default: () => [] },
    expandedRows: { type: Object, required: true },
    deletingId: { type: [Number, String, null], default: null },
    loading: { type: Boolean, default: false },
  });
  
  defineEmits({
    'toggle-row': null,
    delete: null,
    'open-tags': null,
    'open-statuses': null,
    'copy-ttn': null,
    'generate-ttn': null,
    'print-ttn': null,
    'cancel-ttn': null,
    'refresh-delivery': null,
    'open-customer': null,
    'quick-fiscalize': null,
  });
  
  const getSourceStyle = (colorHex) => {
    const color = colorHex || '#64748b';
    return {
      color: color,
      borderColor: color + '40',
    };
  };
</script>

<template>
  <div
    v-if="loading && !orders.length"
    class="d-flex flex-column align-items-center justify-content-center p-5 text-muted"
  >
    <div class="spinner-border text-primary mb-3" role="status"></div>
    <div class="fw-medium">Завантаження даних...</div>
  </div>

  <div v-else-if="!orders.length" class="empty-state p-5 text-center">
    <div class="empty-icon mb-3">
      <i class="bi bi-inbox"></i>
    </div>
    <h5 class="fw-bold text-dark">Список порожній</h5>
    <p class="text-secondary mb-0">Замовлень поки немає. Спробуйте змінити фільтри.</p>
  </div>

  <div v-else class="orders-container">
    <div class="table-responsive-wrapper">
      <table class="table align-middle orders-table mb-0">
        <thead>
          <tr>
            <th class="th-w-40"></th>
            <th class="th-w-70">ID</th>
            <th class="th-w-200">Клієнт / Джерело</th>
            <th class="th-w-110">Теги</th>
            <th class="th-w-140">Контакт</th>
            <th class="th-w-140">Статус</th>
            <th class="th-w-150">Товари</th>
            <th class="th-w-100">Сума</th>
            <th class="th-w-180">Доставка</th>
            <th class="th-w-120">Дата</th>
            <th class="th-w-50"></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="order in orders" :key="order.id">
            <tr
              class="order-row"
              :class="{ 'row-expanded': expandedRows.has(order.id) }"
              @click="$emit('toggle-row', order.id)"
            >
              <td class="text-center ps-3 cell-expand">
                <button
                  class="btn-expand"
                  :class="{ active: expandedRows.has(order.id) }"
                  type="button"
                >
                  <i class="bi bi-chevron-right"></i>
                </button>
              </td>

              <td class="cell-id">
                <span class="order-id">#{{ order.id }}</span>
              </td>

              <td class="cell-client">
                <div
                  class="d-flex flex-column justify-content-center mobile-align-start"
                  style="min-height: 40px;"
                >
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <button
                      type="button"
                      class="client-name text-truncate btn btn-link p-0 text-start"
                      :title="order.client"
                      @click.stop="$emit('open-customer', order)"
                    >
                      {{ order.client }}
                    </button>

                    <div
                      v-if="order.customer_orders_count > 1"
                      class="loyalty-pill"
                      :class="order.customer_orders_count > 5 ? 'is-vip' : 'is-repeat'"
                      data-bs-toggle="tooltip"
                      :title="`Це ${order.customer_orders_count}-те замовлення клієнта`"
                    >
                      <i
                        class="bi"
                        :class="
                          order.customer_orders_count > 5
                            ? 'bi-trophy-fill'
                            : 'bi-bag-check-fill'
                        "
                      ></i>
                      <span>{{ order.customer_orders_count }}</span>
                    </div>
                  </div>

                  <div
                    v-if="order.source_name"
                    class="source-tag"
                    :style="getSourceStyle(order.source_color)"
                  >
                    <i v-if="order.source_icon" :class="order.source_icon" class="source-icon"></i>
                    {{ order.source_name }}
                  </div>
                </div>
              </td>

              <td class="cell-tags">
                <div class="tags-wrapper">
                  <span
                    v-for="tag in order.tags"
                    :key="tag.id"
                    class="tag-pill"
                    :class="'tag-' + tag.color"
                    :title="tag.name"
                  >
                    <span class="tag-dot"></span>
                    {{ tag.name }}
                  </span>
                </div>
              </td>

              <td class="cell-phone">
                <span class="phone-text">{{ order.phone }}</span>
              </td>

              <td class="cell-status">
                <div
                  class="status-badge"
                  :class="order.status_color ? '' : getStatusClass(order.status_key)"
                  :style="getStatusStyle(order)"
                >
                  <i class="bi" :class="order.status_icon || getStatusIcon(order.status_key)"></i>
                  <span>{{ order.status }}</span>
                </div>
              </td>

              <td class="cell-products">
                <div class="product-stack hover-fan">
                  <div
                    v-for="(item, index) in order.items.slice(0, 4)"
                    :key="item.id"
                    class="product-thumb"
                    :style="{ zIndex: 10 - index }"
                  >
                    <img v-if="item.photo" :src="item.photo" alt="Item" />
                    <div v-else class="no-photo"><i class="bi bi-image"></i></div>
                  </div>
                  <div v-if="order.items.length > 4" class="product-more">
                    +{{ order.items.length - 4 }}
                  </div>
                </div>
              </td>

              <td class="text-end pe-3 cell-fiscal">
                
                <a
                  v-if="
                    order.latestFiscalReceipt?.status === 'success' &&
                    order.latestFiscalReceipt?.type === 'return'
                  "
                  :href="order.latestFiscalReceipt?.check_link"
                  target="_blank"
                  class="fiscal-widget widget-return text-decoration-none"
                  title="Відкрити чек повернення"
                  @click.stop
                >
                  <div class="d-flex align-items-center justify-content-between w-100 gap-2">
                    <span class="widget-price">
                      {{ formatCurrency(order.total, order.currency) }}
                    </span>
                    <i class="bi bi-arrow-counterclockwise widget-icon"></i>
                  </div>
                  <div class="widget-label">Повернення</div>
                </a>

                <a
                  v-else-if="
                    order.latestFiscalReceipt?.status === 'success' &&
                    order.latestFiscalReceipt?.type === 'sell' &&
                    (order.payment_status === 'paid' || (order.paid_amount && order.paid_amount >= order.total))
                  "
                  :href="order.latestFiscalReceipt?.check_link"
                  target="_blank"
                  class="fiscal-widget widget-success glass-effect text-decoration-none"
                  title="Замовлення повністю фіскалізовано"
                  @click.stop
                >
                  <div class="d-flex align-items-center justify-content-between w-100 gap-2">
                    <span class="widget-price">
                      {{ formatCurrency(order.total, order.currency) }}
                    </span>
                    <div class="widget-icon-box">
                      <i class="bi bi-check-lg" style="font-size: 0.75rem;"></i>
                    </div>
                  </div>
                  <div class="widget-label">Фіскалізовано</div>
                </a>

                <a
                  v-else-if="
                    order.latestFiscalReceipt?.status === 'success' &&
                    order.latestFiscalReceipt?.type === 'sell' &&
                    order.prepay_amount > 0
                  "
                  :href="order.latestFiscalReceipt?.check_link"
                  target="_blank"
                  class="fiscal-widget widget-partial text-decoration-none"
                  title="Чек на передплату"
                  @click.stop
                >
                  <div class="d-flex align-items-center justify-content-between w-100 gap-2">
                    <span class="widget-price">
                      {{ formatCurrency(order.prepay_amount, order.currency) }}
                    </span>
                    <div class="widget-icon-box">
                      <i class="bi bi-pie-chart-fill" style="font-size: 0.65rem;"></i>
                    </div>
                  </div>
                  <div class="widget-label">Частково</div>
                </a>

                <a
                  v-else-if="
                     order.latestFiscalReceipt?.status === 'success' &&
                     order.latestFiscalReceipt?.type === 'sell'
                  "
                  :href="order.latestFiscalReceipt?.check_link"
                  target="_blank"
                  class="fiscal-widget widget-success glass-effect text-decoration-none"
                  title="Фіскалізовано"
                  @click.stop
                >
                  <div class="d-flex align-items-center justify-content-between w-100 gap-2">
                    <span class="widget-price">
                      {{ formatCurrency(order.total, order.currency) }}
                    </span>
                    <div class="widget-icon-box">
                      <i class="bi bi-check-lg" style="font-size: 0.75rem;"></i>
                    </div>
                  </div>
                  <div class="widget-label">Фіскалізовано</div>
                </a>

                <div
                  v-else
                  class="fiscal-widget widget-empty"
                  :class="{ 'widget-error': order.latestFiscalReceipt?.status === 'error' }"
                  title="Натисніть, щоб фіскалізувати"
                  role="button"
                  @click.stop="$emit('quick-fiscalize', order)"
                >
                  <div class="d-flex align-items-center justify-content-between w-100 gap-2">
                    <span class="widget-price">
                      {{ formatCurrency(order.total, order.currency) }}
                    </span>
                    <i class="bi bi-receipt widget-icon-muted"></i>
                  </div>
                  <div class="widget-label">
                    {{
                      order.latestFiscalReceipt?.status === 'error'
                        ? 'Помилка'
                        : 'Не фіскалізовано'
                    }}
                  </div>
                </div>
              </td>

              <td class="cell-delivery">
                <div v-if="order.ttn" class="delivery-widget">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <i
                      v-if="order.delivery_status_icon"
                      :class="['bi', order.delivery_status_icon]"
                      :style="{ color: order.delivery_status_color }"
                      style="font-size: 0.8rem;"
                    ></i>
                    <span
                      class="delivery-status-text text-truncate"
                      :style="{ color: order.delivery_status_color || '#475569' }"
                      :title="order.delivery_status"
                    >
                      {{ order.delivery_status }}
                    </span>
                  </div>

                  <div
                    v-if="order.delivery_status_code === 'at_warehouse' && order.delivery_hold_days >= 4"
                    class="hold-badge"
                    :title="`Посилка у відділенні ${order.delivery_hold_days} дні`"
                  >
                    <i class="bi bi-exclamation-circle"></i>
                    {{ order.delivery_hold_days }} дні
                  </div>

                  <div
                    class="d-flex align-items-center gap-2 ttn-row"
                    @click.stop="$emit('copy-ttn', order.ttn)"
                    title="Копіювати ТТН"
                  >
                    <i class="bi bi-upc-scan text-muted" style="font-size: 0.85rem;"></i>
                    <span class="ttn-number">{{ order.ttn }}</span>
                    <i class="bi bi-copy ttn-copy-icon"></i>
                  </div>
                </div>

                <div
                  v-else
                  class="delivery-widget delivery-empty"
                  role="button"
                  @click.stop="$emit('generate-ttn', order)"
                  title="Створити ТТН"
                >
                  <div class="d-flex align-items-center justify-content-center gap-2 text-muted">
                    <i class="bi bi-box-seam"></i>
                    <span>Створити ТТН</span>
                  </div>
                </div>
              </td>

              <td class="cell-date">
                <span class="date-text">{{ formatDate(order.created_at) }}</span>
                <div
                  v-if="order.delivery_status_code === 'at_warehouse' && order.delivery_hold_days >= 3"
                  class="hold-alert"
                >
                  Посилка лежить {{ order.delivery_hold_days }} дні
                </div>
              </td>

              <td class="text-end pe-3 cell-actions">
                <div class="dropdown" @click.stop>
                  <button class="btn-action" data-bs-toggle="dropdown">
                    <i class="bi bi-three-dots"></i>
                  </button>
                  <ul
                    class="dropdown-menu dropdown-menu-end border-0 shadow-lg p-2 rounded-3 animate slideIn"
                  >
                    <li>
                      <a
                        class="dropdown-item rounded-2"
                        :href="`/orders/${order.id}/edit`"
                      >
                        <i class="bi bi-pencil me-2 text-primary"></i>Редагувати
                      </a>
                    </li>
                    <li><hr class="dropdown-divider my-1" /></li>
                    <li>
                      <button
                        class="dropdown-item rounded-2 text-danger"
                        type="button"
                        :disabled="deletingId === order.id"
                        @click.stop="$emit('delete', order)"
                      >
                        <i class="bi bi-trash me-2"></i>
                        {{ deletingId === order.id ? 'Видаляємо...' : 'Видалити' }}
                      </button>
                    </li>
                  </ul>
                </div>
              </td>
            </tr>

            <tr v-if="expandedRows.has(order.id)" class="details-row">
              <td colspan="11" class="p-0 border-0">
                <div class="details-wrapper">
                  <OrderDetails
                    :order="order"
                    @open-tags="$emit('open-tags', order)"
                    @open-statuses="$emit('open-statuses', order)"
                    @copy-ttn="$emit('copy-ttn', order.ttn)"
                    @generate-ttn="$emit('generate-ttn', order)"
                    @print-ttn="$emit('print-ttn', order)"
                    @cancel-ttn="$emit('cancel-ttn', order)"
                    @refresh-delivery="$emit('refresh-delivery', order)"
                  />
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
/* =========================================
   1. ANIMATIONS & INTERACTIONS
   ========================================= */

.product-stack.hover-fan {
  transition: all 0.3s ease;
}
.product-stack.hover-fan:hover .product-thumb {
  margin-left: -2px;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
  transform: translateY(-3px) scale(1.05);
}
.product-stack.hover-fan:hover .product-thumb:first-child {
  margin-left: 0;
}

.glass-effect {
  background: rgba(220, 252, 231, 0.7) !important;
  backdrop-filter: blur(8px);
  border: 1px solid rgba(187, 247, 208, 0.6) !important;
}
.order-row:hover .glass-effect {
  background: rgba(220, 252, 231, 0.9) !important;
}

/* =========================================
   2. WIDGETS
   ========================================= */

.fiscal-widget {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  justify-content: center;
  padding: 6px 12px;
  border-radius: 10px;
  min-width: 105px;
  transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
  border: 1px solid transparent;
  position: relative;
  overflow: hidden;
  cursor: pointer;
}
.fiscal-widget:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}
.fiscal-widget:active {
  transform: translateY(0);
}
.widget-price {
  font-weight: 700;
  font-size: 0.95rem;
  line-height: 1.1;
  white-space: nowrap;
  color: inherit;
}
.widget-label {
  font-size: 0.65rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  margin-top: 3px;
  display: flex;
  align-items: center;
}
.widget-icon {
  font-size: 1rem;
  opacity: 0.8;
}
.widget-icon-box {
  background: rgba(255, 255, 255, 0.8);
  width: 20px;
  height: 20px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #15803d;
  transition: transform 0.2s;
}
.widget-success:hover .widget-icon-box {
  transform: rotate(45deg) scale(1.1);
  background: #fff;
}
.widget-success {
  color: #166534;
}
.widget-return {
  background: #fffbeb;
  color: #b45309;
  border: 1px solid #fcd34d;
  border-left: 3px solid #f59e0b;
}
.widget-return:hover {
  background: #fef3c7;
}

/* --- СТИЛЬ: ЧАСТКОВА ОПЛАТА --- */
.widget-partial {
  background: #fffbeb; /* Світло-жовтий */
  color: #b45309;      /* Помаранчевий */
  border: 1px solid #fcd34d;
}
.widget-partial:hover {
  background: #fef3c7;
  box-shadow: 0 4px 12px rgba(251, 191, 36, 0.15);
}
.widget-partial .widget-icon-box {
  background: rgba(255, 255, 255, 0.6);
  color: #b45309;
}

.widget-empty {
  background: #ffffff;
  color: #64748b;
  border: 1px dashed #cbd5e1;
}
.widget-empty:hover {
  background: #f8fafc;
  border-color: #3b82f6;
  border-style: solid;
  color: #3b82f6;
}
.widget-empty:hover .widget-price {
  color: #1e293b;
}
.widget-icon-muted {
  font-size: 0.9rem;
  opacity: 0.4;
  transition: color 0.2s;
}
.widget-empty:hover .widget-icon-muted {
  color: #3b82f6;
  opacity: 1;
}
.widget-empty.widget-error {
  background: #fef2f2;
  color: #b91c1c;
  border-color: #fca5a5;
  border-style: solid;
}

.delivery-widget {
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 6px 10px;
  background: #fff;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  min-height: 48px;
  transition: all 0.2s;
  max-width: 180px;
}
.delivery-widget:hover {
  border-color: #cbd5e1;
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.03);
}
.delivery-status-text {
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  line-height: 1.2;
}
.ttn-row {
  cursor: copy;
}
.ttn-number {
  font-family: 'Consolas', 'Monaco', monospace;
  font-weight: 600;
  font-size: 0.85rem;
  color: #1e293b;
  letter-spacing: -0.02em;
}
.ttn-copy-icon {
  font-size: 0.75rem;
  color: #94a3b8;
  opacity: 0;
  transition: all 0.2s;
  transform: scale(0.8);
}
.ttn-row:hover .ttn-copy-icon {
  opacity: 1;
  transform: scale(1);
  color: #3b82f6;
}
.ttn-row:active .ttn-copy-icon {
  transform: scale(0.9);
}
.delivery-empty {
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  cursor: pointer;
  align-items: center;
}
.delivery-empty:hover {
  background: #f1f5f9;
  border-color: #3b82f6;
  color: #3b82f6 !important;
}
.delivery-empty .text-muted {
  transition: color 0.2s;
  font-size: 0.8rem;
  font-weight: 600;
}
.delivery-empty:hover .text-muted {
  color: #3b82f6 !important;
}

.hold-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #fef3c7;
  color: #b45309;
  border: 1px solid #fcd34d;
  border-radius: 6px;
  padding: 2px 6px;
  font-size: 0.7rem;
  font-weight: 700;
  margin-bottom: 6px;
}

/* =========================================
   3. BASIC STYLES
   ========================================= */

.client-name {
  font-weight: 700;
  font-size: 0.95rem;
  color: #1e293b;
  cursor: pointer;
  transition: color 0.2s;
  max-width: 150px;
}
.client-name:hover {
  color: #3b82f6;
}
.loyalty-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 0.75rem;
  font-weight: 700;
  line-height: 1;
  letter-spacing: 0.02em;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
  cursor: help;
  transition: transform 0.2s;
}
.loyalty-pill:hover {
  transform: translateY(-1px);
}
.loyalty-pill i {
  font-size: 0.7rem;
}
.loyalty-pill.is-repeat {
  background-color: #ecfdf5;
  color: #059669;
  border: 1px solid #d1fae5;
}
.loyalty-pill.is-vip {
  background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
  color: #c2410c;
  border: 1px solid #fed7aa;
}
.source-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 0.7rem;
  font-weight: 600;
  padding: 2px 0;
  color: #64748b;
  opacity: 0.9;
}
.source-icon {
  font-size: 0.75rem;
  opacity: 0.8;
}
.orders-container {
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
  overflow: hidden;
  border: 1px solid rgba(0, 0, 0, 0.04);
}
.table-responsive-wrapper {
  width: 100%;
  overflow-x: auto;
}
.orders-table {
  --bs-table-bg: transparent;
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
}
.orders-table thead th {
  background: #f8fafc;
  color: #64748b;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  border-bottom: 1px solid #e2e8f0;
  padding: 16px 12px;
  white-space: nowrap;
}
.order-row {
  transition: all 0.2s ease;
  cursor: pointer;
  background: #fff;
}
.order-row td {
  padding: 8px 7px;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
}
.order-row:hover {
  background: #e8f1fb;
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
  z-index: 2;
  position: relative;
}
.row-expanded {
  background: #bcd9f6 !important;
  border-bottom-color: transparent;
}
.row-expanded td {
  border-bottom: none;
}
.order-id {
  font-family: 'Courier New', monospace;
  font-weight: 700;
  color: #3b82f6;
  background: rgba(59, 130, 246, 0.08);
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 0.85rem;
}
.phone-text {
  font-family: 'Consolas', monospace;
  color: #475569;
  font-size: 0.85rem;
  letter-spacing: -0.5px;
}
.date-text {
  font-size: 0.8rem;
}
.hold-alert {
  margin-top: 6px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #fef3c7;
  color: #b45309;
  border: 1px solid #fcd34d;
  border-radius: 6px;
  padding: 2px 6px;
  font-size: 0.7rem;
  font-weight: 700;
}
.tags-wrapper {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.tag-pill {
  font-size: 0.7rem;
  padding: 3px 8px;
  border-radius: 12px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border: 1px solid transparent;
}
.tag-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
  opacity: 0.6;
}
.tag-red {
  background: #fee2e2;
  color: #b91c1c;
}
.tag-blue {
  background: #dbeafe;
  color: #1d4ed8;
}
.tag-green {
  background: #dcfce7;
  color: #15803d;
}
.tag-amber {
  background: #fef3c7;
  color: #b45309;
}
.tag-gray {
  background: #f3f4f6;
  color: #4b5563;
}
.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 10px;
  border-radius: 8px;
  font-size: 0.8rem;
  font-weight: 600;
  border: 1px solid rgba(0, 0, 0, 0.03);
}
.product-stack {
  display: flex;
  align-items: center;
  padding-left: 8px;
}
.product-thumb {
  width: 62px;
  height: 62px;
  border-radius: 10px;
  border: 2px solid #fff;
  overflow: hidden;
  margin-left: -10px;
  background: #fff;
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.08);
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s ease;
}
.product-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.no-photo {
  background: #f1f5f9;
  color: #cbd5e1;
  font-size: 1.2rem;
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}
.product-more {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: #f8fafc;
  color: #64748b;
  border: 2px solid #fff;
  font-size: 0.75rem;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-left: -8px;
  z-index: 1;
}
.btn-expand {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
  font-size: 0.75rem;
}
.btn-expand:hover {
  border-color: #3b82f6;
  color: #3b82f6;
  background: #eff6ff;
}
.btn-expand.active {
  background: #3b82f6;
  border-color: #3b82f6;
  color: white;
  transform: rotate(90deg);
}
.btn-action {
  border: none;
  background: transparent;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  color: #94a3b8;
  transition: all 0.2s;
}
.btn-action:hover {
  background: #f1f5f9;
  color: #334155;
}
.details-wrapper {
  background: #bcd9f6;
  border-top: 1px solid #e2e8f0;
  box-shadow: inset 0 4px 6px -4px rgba(0, 0, 0, 0.05);
  animation: slideDown 0.3s ease-out;
}
@keyframes slideDown {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
.empty-state {
  background: #fff;
  border-radius: 16px;
  border: 2px dashed #e2e8f0;
}
.empty-icon {
  width: 64px;
  height: 64px;
  background: #f8fafc;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.8rem;
  color: #94a3b8;
}

@media (min-width: 992px) {
  .th-w-40 {
    width: 40px;
  }
  .th-w-50 {
    width: 50px;
  }
  .th-w-70 {
    width: 70px;
  }
  .th-w-100 {
    width: 100px;
  }
  .th-w-110 {
    width: 110px;
  }
  .th-w-120 {
    width: 120px;
  }
  .th-w-140 {
    width: 140px;
  }
  .th-w-150 {
    width: 150px;
  }
  .th-w-180 {
    width: 180px;
  }
  .th-w-200 {
    width: 200px;
  }
}

/* =========================================
   MOBILE RESPONSIVENESS (CARD VIEW)
   ========================================= */

@media (max-width: 991px) {
  .orders-container {
    padding: 10px;
  }

  .orders-table thead {
    display: none;
  }
  .orders-table,
  .orders-table tbody,
  .orders-table tr {
    display: block;
    width: 100%;
  }

  .order-row {
    display: grid !important;
    grid-template-columns: auto 1fr;
    grid-template-areas:
      'id client'
      'status status'
      'phone phone'
      'fiscal fiscal';
    gap: 10px;
    margin-bottom: 12px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  }

  .order-row.row-expanded {
    margin-bottom: 0;
    border-bottom: none;
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
    box-shadow: none;
    z-index: 10;
  }

  .order-row td {
    display: block;
    border: none;
    padding: 0 !important;
    width: 100% !important;
    box-sizing: border-box;
  }

  .cell-expand {
    display: none !important;
  }

  .cell-id {
    grid-area: id;
    align-self: center;
    display: flex;
    align-items: center;
  }

  .cell-client {
    grid-area: client;
    margin-bottom: 0;
    display: flex;
    align-items: center;
  }

  .cell-status {
    grid-area: status;
    justify-self: flex-start;
  }

  .cell-phone {
    grid-area: phone;
    font-size: 0.9rem;
  }

  .cell-fiscal {
    grid-area: fiscal;
    text-align: left !important;
  }

  .cell-fiscal .fiscal-widget {
    align-items: flex-start !important;
    min-width: 0;
  }

  /* ховаємо зайве на мобільній */
  .cell-tags,
  .cell-products,
  .cell-delivery,
  .cell-date,
  .cell-actions,
  .source-tag {
    display: none !important;
  }

  .details-row {
    display: block;
    border: none;
    width: 100%;
    box-sizing: border-box;
  }

  .details-row td {
    display: block;
    width: 100%;
    padding: 0 !important;
    box-sizing: border-box;
  }

  .details-wrapper {
    margin-bottom: 9px;
    border-radius: 0 0 12px 12px;
    border: none;
    background: #bcd9f6;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
    overflow: hidden;
    padding-left: 10px;
    padding-right: 10px;
  }
}
</style>
