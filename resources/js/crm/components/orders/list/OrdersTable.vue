<template>
  <div v-if="loading && !orders.length" class="d-flex flex-column align-items-center justify-content-center p-5 text-muted">
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
    <div class="table-responsive">
      <table class="table align-middle orders-table mb-0">
        <thead>
          <tr>
            <th class="th-w-40"></th> <th class="th-w-70">ID</th>
            <th class="th-w-200">Клієнт / Джерело</th>
            <th class="th-w-150">Теги</th>
            <th class="th-w-140">Контакт</th>
            <th class="th-w-140">Статус</th>
            <th class="th-w-150">Товари</th>
            <th class="th-w-100 text-end pe-3">Сума</th> <th class="th-w-140">ТТН</th>
            <th class="th-w-150">Доставка</th>
            <th class="th-w-120">Дата</th>
            <th class="th-w-50"></th> </tr>
        </thead>
        <tbody>
          <template v-for="order in orders" :key="order.id">
            <tr
              class="order-row"
              :class="{ 'row-expanded': expandedRows.has(order.id) }"
              @click="$emit('toggle-row', order.id)"
            >
              <td class="text-center ps-3">
                <button 
                  class="btn-expand" 
                  :class="{ 'active': expandedRows.has(order.id) }"
                  type="button"
                >
                  <i class="bi bi-chevron-right"></i>
                </button>
              </td>

              <td>
                <span class="order-id">#{{ order.id }}</span>
              </td>

              <td>
                <div class="d-flex flex-column justify-content-center" style="min-height: 40px;">
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
                      <i class="bi" :class="order.customer_orders_count > 5 ? 'bi-trophy-fill' : 'bi-bag-check-fill'"></i>
                      <span>{{ order.customer_orders_count }}</span>
                    </div>
                  </div>

                  <div v-if="order.source_name" class="source-tag" :style="getSourceStyle(order.source_color)">
                    <i v-if="order.source_icon" :class="order.source_icon" class="source-icon"></i>
                    {{ order.source_name }}
                  </div>
                </div>
              </td>

              <td>
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

              <td>
                <span class="phone-text">{{ order.phone }}</span>
              </td>

              <td>
                <div 
                  class="status-badge"
                  :class="order.status_color ? '' : getStatusClass(order.status_key)"
                  :style="getStatusStyle(order)"
                >
                  <i class="bi" :class="order.status_icon || getStatusIcon(order.status_key)"></i>
                  <span>{{ order.status }}</span>
                </div>
              </td>

              <td>
                <div class="product-stack">
                  <div v-for="(item, index) in order.items.slice(0, 4)" :key="item.id" class="product-thumb" :style="{ zIndex: 10 - index }">
                    <img v-if="item.photo" :src="item.photo" alt="Item" />
                    <div v-else class="no-photo"><i class="bi bi-image"></i></div>
                  </div>
                  <div v-if="order.items.length > 4" class="product-more">
                    +{{ order.items.length - 4 }}
                  </div>
                </div>
              </td>

              <td class="text-end pe-3">
                
                <a 
                  v-if="order.latestFiscalReceipt?.status === 'success' && order.latestFiscalReceipt?.type === 'sell'"
                  :href="order.latestFiscalReceipt?.check_link"
                  target="_blank"
                  class="fiscal-widget widget-success text-decoration-none"
                  title="Відкрити фіскальний чек у новій вкладці"
                  @click.stop
                >
                  <div class="d-flex align-items-center justify-content-between w-100 gap-2">
                    <span class="widget-price">{{ formatCurrency(order.total, order.currency) }}</span>
                    <div class="widget-icon-box">
                       <i class="bi bi-box-arrow-up-right" style="font-size: 0.65rem;"></i>
                    </div>
                  </div>
                  <div class="widget-label">
                    Фіскалізовано
                  </div>
                </a>

                <a 
                  v-else-if="order.latestFiscalReceipt?.status === 'success' && order.latestFiscalReceipt?.type === 'return'"
                  :href="order.latestFiscalReceipt?.check_link"
                  target="_blank"
                  class="fiscal-widget widget-return text-decoration-none"
                  title="Відкрити чек повернення"
                  @click.stop
                >
                  <div class="d-flex align-items-center justify-content-between w-100 gap-2">
                    <span class="widget-price">{{ formatCurrency(order.total, order.currency) }}</span>
                    <i class="bi bi-arrow-counterclockwise widget-icon"></i>
                  </div>
                  <div class="widget-label">Повернення</div>
                </a>

                <div 
                  v-else 
                  class="fiscal-widget widget-empty"
                  :class="{'widget-error': order.latestFiscalReceipt?.status === 'error'}"
                  title="Натисніть, щоб фіскалізувати"
                  role="button"
                  @click.stop="$emit('quick-fiscalize', order)"
                >
                  <div class="d-flex align-items-center justify-content-between w-100 gap-2">
                    <span class="widget-price">{{ formatCurrency(order.total, order.currency) }}</span>
                    <i class="bi bi-receipt widget-icon-muted"></i>
                  </div>
                  <div class="widget-label">
                    {{ order.latestFiscalReceipt?.status === 'error' ? 'Помилка' : 'Створити чек' }}
                  </div>
                </div>
              </td>

              <td>
                <span v-if="order.ttn" class="ttn-text">
                  <i class="bi bi-upc-scan me-1"></i> {{ order.ttn }}
                </span>
                <span v-else class="text-muted opacity-25">—</span>
              </td>

              <td>
                <div v-if="order.delivery_status" class="delivery-status" :style="{ color: order.delivery_status_color || '#6c757d' }">
                  <i v-if="order.delivery_status_icon" :class="['bi', order.delivery_status_icon]"></i>
                  <span>{{ order.delivery_status }}</span>
                </div>
                <span v-else class="text-muted opacity-25">—</span>
              </td>

              <td>
                <span class="date-text">{{ formatDate(order.created_at) }}</span>
              </td>

              <td class="text-end pe-3">
                <div class="dropdown" @click.stop>
                  <button class="btn-action" data-bs-toggle="dropdown">
                    <i class="bi bi-three-dots"></i>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg p-2 rounded-3 animate slideIn">
                    <li>
                      <a class="dropdown-item rounded-2" :href="`/orders/${order.id}/edit`">
                        <i class="bi bi-pencil me-2 text-primary"></i>Редагувати
                      </a>
                    </li>
                    <li><hr class="dropdown-divider my-1"></li>
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
              <td colspan="12" class="p-0 border-0">
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
    'quick-fiscalize': null, // Нова подія для швидкої фіскалізації
  });
  
  // Функція для стилізації джерела замовлення
  const getSourceStyle = (colorHex) => {
    const color = colorHex || '#64748b';
    return {
      color: color,
      borderColor: color + '40', // 25% прозорості для рамки
    };
  };
</script>

<style scoped>
/* =========================================
   1. INTERACTIVE FISCAL WIDGET (MODERN)
   ========================================= */

.fiscal-widget {
  display: flex;
  flex-direction: column;
  align-items: flex-end; /* Текст праворуч */
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

/* Ефект "піднімання" при наведенні */
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

/* --- СТИЛЬ 1: УСПІХ (Interactive) --- */
.widget-success {
  background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
  color: #166534;
  border: 1px solid #bbf7d0;
}

.widget-success:hover {
  background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
  border-color: #86efac;
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
  transform: rotate(45deg) scale(1.1); /* Анімація іконки */
  background: #fff;
}

/* --- СТИЛЬ 2: ПОВЕРНЕННЯ --- */
.widget-return {
  background: #fffbeb;
  color: #b45309;
  border: 1px solid #fcd34d;
  border-left: 3px solid #f59e0b;
}
.widget-return:hover {
  background: #fef3c7;
}

/* --- СТИЛЬ 3: ДІЯ "СТВОРИТИ ЧЕК" --- */
.widget-empty {
  background: #ffffff;
  color: #64748b;
  border: 1px dashed #cbd5e1;
}

/* При наведенні стає "активним" */
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

/* Помилка */
.widget-empty.widget-error {
  background: #fef2f2;
  color: #b91c1c;
  border-color: #fca5a5;
  border-style: solid;
}
.widget-empty.widget-error:hover {
  background: #fee2e2;
  border-color: #ef4444;
}

/* =========================================
   2. КЛІЄНТ ТА ЛОЯЛЬНІСТЬ
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
  box-shadow: 0 1px 2px rgba(0,0,0,0.05);
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

/* =========================================
   3. ОСНОВНА ТАБЛИЦЯ
   ========================================= */

.orders-container {
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
  overflow: hidden;
  border: 1px solid rgba(0, 0, 0, 0.04);
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
  background: #f8fafc;
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(0,0,0,0.02);
  z-index: 2;
  position: relative;
}

.row-expanded {
  background: #f1f5f9 !important;
  border-bottom-color: transparent;
}
.row-expanded td {
  border-bottom: none;
}

/* =========================================
   4. ТИПОГРАФІКА
   ========================================= */

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

.ttn-text {
  font-size: 0.8rem;
  color: #475569;
  background: #f1f5f9;
  padding: 2px 6px;
  border-radius: 4px;
}

.date-text {
  font-size: 0.8rem;
}

/* =========================================
   5. ЕЛЕМЕНТИ (Теги, Статуси, Товари)
   ========================================= */

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
.tag-red { background: #fee2e2; color: #b91c1c; }
.tag-blue { background: #dbeafe; color: #1d4ed8; }
.tag-green { background: #dcfce7; color: #15803d; }
.tag-amber { background: #fef3c7; color: #b45309; }
.tag-gray { background: #f3f4f6; color: #4b5563; }

.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 10px;
  border-radius: 8px;
  font-size: 0.8rem;
  font-weight: 600;
  border: 1px solid rgba(0,0,0,0.03);
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
  box-shadow: 0 2px 5px rgba(0,0,0,0.08);
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
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

/* =========================================
   6. КНОПКИ ТА ІНШЕ
   ========================================= */

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
  background: #f8fafc;
  border-top: 1px solid #e2e8f0;
  box-shadow: inset 0 4px 6px -4px rgba(0,0,0,0.05);
  animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
  from { opacity: 0; transform: translateY(-10px); }
  to { opacity: 1; transform: translateY(0); }
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
  .th-w-40 { width: 40px; }
  .th-w-50 { width: 50px; }
  .th-w-70 { width: 70px; }
  .th-w-100 { width: 100px; }
  .th-w-120 { width: 120px; }
  .th-w-140 { width: 140px; }
  .th-w-150 { width: 150px; }
  .th-w-200 { width: 200px; }
}
</style>