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
  <!-- Стан завантаження -->
  <div v-if="loading && !orders.length" class="d-flex flex-column align-items-center justify-content-center p-5 text-muted">
    <div class="spinner-border text-primary mb-3" role="status"></div>
    <div class="fw-medium">Завантаження даних...</div>
  </div>

  <!-- Порожній стан -->
  <div v-else-if="!orders.length" class="empty-state p-5 text-center">
    <div class="empty-icon mb-3">
      <i class="bi bi-inbox"></i>
    </div>
    <h5 class="fw-bold text-dark">Список порожній</h5>
    <p class="text-secondary mb-0">Замовлень поки немає. Спробуйте змінити фільтри.</p>
  </div>

  <!-- Таблиця -->
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
            <th class="th-w-100 text-end pe-3">Сума</th>
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
              <!-- 1. Розгортання (Стрілка) -->
              <td class="text-center ps-3 cell-expand">
                <button 
                  class="btn-expand" 
                  :class="{ 'active': expandedRows.has(order.id) }"
                  type="button"
                >
                  <i class="bi bi-chevron-right"></i>
                </button>
              </td>

              <!-- 2. ID -->
              <td class="cell-id">
                <span class="order-id">#{{ order.id }}</span>
              </td>

              <!-- 3. Клієнт -->
              <td class="cell-client">
                <div class="d-flex flex-column justify-content-center mobile-align-start" style="min-height: 40px;">
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
                    >
                      <i class="bi" :class="order.customer_orders_count > 5 ? 'bi-trophy-fill' : 'bi-bag-check-fill'"></i>
                      <span>{{ order.customer_orders_count }}</span>
                    </div>
                  </div>

                  <!-- Джерело (приховано на мобільному для компактності) -->
                  <div v-if="order.source_name" class="source-tag d-none d-lg-inline-flex" :style="getSourceStyle(order.source_color)">
                    <i v-if="order.source_icon" :class="order.source_icon" class="source-icon"></i>
                    {{ order.source_name }}
                  </div>
                </div>
              </td>

              <!-- 4. Теги -->
              <td class="cell-tags">
                <div class="tags-wrapper">
                  <span v-for="tag in order.tags" :key="tag.id" class="tag-pill" :class="'tag-' + tag.color">
                    <span class="tag-dot"></span> {{ tag.name }}
                  </span>
                </div>
              </td>

              <!-- 5. Телефон -->
              <td class="cell-phone">
                <span class="phone-text">{{ order.phone }}</span>
              </td>

              <!-- 6. Статус -->
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

              <!-- 7. Товари -->
              <td class="cell-products">
                <div class="product-stack hover-fan">
                  <div v-for="(item, index) in order.items.slice(0, 4)" :key="item.id" class="product-thumb" :style="{ zIndex: 10 - index }">
                    <img v-if="item.photo" :src="item.photo" alt="Item" />
                    <div v-else class="no-photo"><i class="bi bi-image"></i></div>
                  </div>
                  <div v-if="order.items.length > 4" class="product-more">+{{ order.items.length - 4 }}</div>
                </div>
              </td>

              <!-- 8. СУМА (Mobile: Compact Sum, Desktop: Widget) -->
              <td class="text-end pe-3 cell-fiscal">
                <!-- Мобільна версія суми (просто текст) -->
                <div class="d-lg-none fw-bold text-dark fs-6 text-nowrap">
                  {{ formatCurrency(order.total, order.currency) }}
                </div>

                <!-- Десктопна версія (Віджет) -->
                <div class="d-none d-lg-block">
                    <a 
                      v-if="order.latestFiscalReceipt?.status === 'success' && order.latestFiscalReceipt?.type === 'sell'"
                      :href="order.latestFiscalReceipt?.check_link"
                      target="_blank"
                      class="fiscal-widget widget-success text-decoration-none"
                      @click.stop
                    >
                      <div class="d-flex align-items-center justify-content-between w-100 gap-2">
                        <span class="widget-price">{{ formatCurrency(order.total, order.currency) }}</span>
                        <div class="widget-icon-box"><i class="bi bi-box-arrow-up-right" style="font-size: 0.65rem;"></i></div>
                      </div>
                      <div class="widget-label">Фіскалізовано</div>
                    </a>
                    <a 
                      v-else-if="order.latestFiscalReceipt?.status === 'success' && order.latestFiscalReceipt?.type === 'return'"
                      :href="order.latestFiscalReceipt?.check_link"
                      target="_blank"
                      class="fiscal-widget widget-return text-decoration-none"
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
                      @click.stop="$emit('quick-fiscalize', order)"
                    >
                         <div class="d-flex align-items-center justify-content-between w-100 gap-2">
                            <span class="widget-price">{{ formatCurrency(order.total, order.currency) }}</span>
                            <i class="bi bi-receipt widget-icon-muted"></i>
                         </div>
                         <div class="widget-label">{{ order.latestFiscalReceipt?.status === 'error' ? 'Помилка' : 'Створити чек' }}</div>
                    </div>
                </div>
              </td>

              <!-- 9. ДОСТАВКА -->
              <td class="cell-delivery">
                <div v-if="order.ttn" class="delivery-widget">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <i v-if="order.delivery_status_icon" :class="['bi', order.delivery_status_icon]" :style="{color: order.delivery_status_color}" style="font-size: 0.8rem;"></i>
                    <span class="delivery-status-text text-truncate" :style="{color: order.delivery_status_color || '#475569'}">{{ order.delivery_status }}</span>
                  </div>
                  <div class="d-flex align-items-center gap-2 ttn-row" @click.stop="$emit('copy-ttn', order.ttn)">
                    <i class="bi bi-upc-scan text-muted" style="font-size: 0.85rem;"></i>
                    <span class="ttn-number">{{ order.ttn }}</span>
                  </div>
                </div>
                <div v-else class="delivery-widget delivery-empty" role="button" @click.stop="$emit('generate-ttn', order)">
                  <div class="d-flex align-items-center justify-content-center gap-2 text-muted">
                    <i class="bi bi-box-seam"></i><span>Створити ТТН</span>
                  </div>
                </div>
              </td>

              <!-- 10. Дата -->
              <td class="cell-date">
                <span class="date-text">{{ formatDate(order.created_at) }}</span>
              </td>

              <!-- 11. Меню -->
              <td class="text-end pe-3 cell-actions">
                <div class="dropdown" @click.stop>
                  <button class="btn-action" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                  <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg p-2 rounded-3 animate slideIn">
                    <li><a class="dropdown-item rounded-2" :href="`/orders/${order.id}/edit`"><i class="bi bi-pencil me-2 text-primary"></i>Редагувати</a></li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li><button class="dropdown-item rounded-2 text-danger" type="button" @click.stop="$emit('delete', order)"><i class="bi bi-trash me-2"></i>Видалити</button></li>
                  </ul>
                </div>
              </td>
            </tr>

            <!-- Розгорнутий рядок (ДЕТАЛІ НА ВЕСЬ ЕКРАН) -->
            <tr v-if="expandedRows.has(order.id)" class="details-row">
              <td colspan="11" class="p-0 border-0">
                <div class="details-wrapper-full">
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
   MOBILE LIST VIEW (COMPACT ROW)
   ========================================= */
@media (max-width: 991px) {
  .orders-table thead { display: none; }
  .orders-table, .orders-table tbody, .orders-table tr { display: block; width: 100%; }
  
  /* Рядок як компактний Flex-список */
  .order-row {
    display: flex !important;
    flex-wrap: wrap;
    align-items: center;
    padding: 12px 10px;
    border-bottom: 1px solid #eef2f6;
    background: #fff;
    position: relative;
    gap: 10px;
  }

  /* Приховуємо менш важливе на мобільному */
  .cell-phone, .cell-tags, .cell-products, .cell-delivery, .cell-date {
     display: none !important; 
  }

  /* 1. ID */
  .cell-id { 
    order: 1; 
    font-weight: 700; 
    color: #94a3b8; 
    font-size: 0.8rem;
    margin-right: 4px;
    width: auto !important;
  }
  
  /* 2. Клієнт (Займає весь доступний простір) */
  .cell-client { 
    order: 2; 
    flex-grow: 1; 
    font-weight: 600;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    width: auto !important;
  }

  /* 3. Сума (Справа) */
  .cell-fiscal {
    order: 3;
    width: auto !important;
    text-align: right !important;
    margin-left: auto;
  }

  /* 4. Статус (Новий рядок) */
  .cell-status {
    order: 4;
    width: 100% !important;
    margin-top: 2px;
    display: flex;
  }
  
  /* Кнопка меню (Абсолютна позиція справа зверху) */
  .cell-actions {
    position: absolute;
    right: 0px;
    top: 8px;
    display: block !important;
    width: auto !important;
  }
  
  /* Приховуємо кнопку розгортання, бо клікабельний весь рядок */
  .cell-expand {
    display: none !important;
  }

  /* Стиль розгорнутого рядка */
  .order-row.row-expanded {
    background: #f8fafc;
    border-bottom: none; /* Прибираємо межу, щоб злилося з деталями */
  }

  /* Контейнер деталей на весь екран */
  .details-row { display: block; width: 100%; }
  .details-row td { display: block; width: 100%; padding: 0 !important; }
  
  /* Вирівнювання деталей по ширині екрана */
  .details-wrapper-full {
    width: 100vw; 
    margin-left: -10px; /* Компенсація padding батьківського контейнера, якщо є */
    margin-right: -10px;
    background: #f1f5f9;
    border-top: 1px solid #e2e8f0;
    border-bottom: 1px solid #e2e8f0;
    box-sizing: border-box;
  }
}

/* =========================================
   DESKTOP STYLES (STANDARD TABLE)
   ========================================= */
.orders-container { background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03); overflow: hidden; border: 1px solid rgba(0, 0, 0, 0.04); }
.table-responsive-wrapper { width: 100%; overflow-x: auto; }
.orders-table { --bs-table-bg: transparent; width: 100%; border-collapse: separate; border-spacing: 0; }
.orders-table thead th { background: #f8fafc; color: #64748b; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e2e8f0; padding: 16px 12px; white-space: nowrap; }
.order-row { transition: all 0.2s ease; cursor: pointer; background: #fff; }
.order-row td { padding: 8px 7px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.order-row:hover { background: #f8fafc; }
.row-expanded { background: #f1f5f9 !important; }

/* Widgets */
.fiscal-widget { display: flex; flex-direction: column; align-items: flex-end; justify-content: center; padding: 6px 12px; border-radius: 10px; min-width: 105px; border: 1px solid transparent; cursor: pointer; }
.widget-success { background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); color: #166534; border: 1px solid #bbf7d0; }
.widget-return { background: #fffbeb; color: #b45309; border: 1px solid #fcd34d; border-left: 3px solid #f59e0b; }
.widget-empty { background: #ffffff; color: #64748b; border: 1px dashed #cbd5e1; }
.widget-price { font-weight: 700; font-size: 0.95rem; line-height: 1.1; }
.widget-label { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; margin-top: 3px; }
.widget-empty.widget-error { background: #fef2f2; color: #b91c1c; border-color: #fca5a5; border-style: solid; }

.delivery-widget { display: flex; flex-direction: column; justify-content: center; padding: 6px 10px; background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; min-height: 48px; max-width: 180px; }
.delivery-status-text { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; line-height: 1.2; }
.ttn-number { font-family: 'Consolas', 'Monaco', monospace; font-weight: 600; font-size: 0.85rem; color: #1e293b; letter-spacing: -0.02em; }
.delivery-empty { background: #f8fafc; border: 1px dashed #cbd5e1; cursor: pointer; align-items: center; }

/* Other Styles */
.client-name { font-weight: 700; font-size: 0.95rem; color: #1e293b; cursor: pointer; transition: color 0.2s; max-width: 150px; }
.loyalty-pill { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
.loyalty-pill.is-repeat { background-color: #ecfdf5; color: #059669; border: 1px solid #d1fae5; }
.loyalty-pill.is-vip { background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%); color: #c2410c; border: 1px solid #fed7aa; }
.source-tag { display: inline-flex; align-items: center; gap: 4px; font-size: 0.7rem; font-weight: 600; padding: 2px 0; color: #64748b; opacity: 0.9; }
.order-id { font-family: 'Courier New', monospace; font-weight: 700; color: #3b82f6; background: rgba(59, 130, 246, 0.08); padding: 2px 6px; border-radius: 4px; font-size: 0.85rem; }
.phone-text { font-family: 'Consolas', monospace; color: #475569; font-size: 0.85rem; letter-spacing: -0.5px; }
.date-text { font-size: 0.8rem; }
.tags-wrapper { display: flex; flex-wrap: wrap; gap: 4px; }
.tag-pill { font-size: 0.7rem; padding: 3px 8px; border-radius: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; border: 1px solid transparent; }
.tag-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; opacity: 0.6; }
.tag-red { background: #fee2e2; color: #b91c1c; }
.tag-blue { background: #dbeafe; color: #1d4ed8; }
.tag-green { background: #dcfce7; color: #15803d; }
.tag-amber { background: #fef3c7; color: #b45309; }
.tag-gray { background: #f3f4f6; color: #4b5563; }
.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 8px; font-size: 0.8rem; font-weight: 600; border: 1px solid rgba(0,0,0,0.03); }
.product-stack { display: flex; align-items: center; padding-left: 8px; }
.product-thumb { width: 62px; height: 62px; border-radius: 10px; border: 2px solid #fff; overflow: hidden; margin-left: -10px; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.08); position: relative; display: flex; align-items: center; justify-content: center; }
.product-thumb img { width: 100%; height: 100%; object-fit: cover; }
.no-photo { background: #f1f5f9; color: #cbd5e1; font-size: 1.2rem; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
.product-more { width: 32px; height: 32px; border-radius: 50%; background: #f8fafc; color: #64748b; border: 2px solid #fff; font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; justify-content: center; margin-left: -8px; z-index: 1; }
.btn-expand { width: 28px; height: 28px; border-radius: 50%; border: 1px solid #e2e8f0; background: #fff; color: #64748b; display: flex; align-items: center; justify-content: center; transition: all 0.2s; font-size: 0.75rem; }
.btn-expand:hover { border-color: #3b82f6; color: #3b82f6; background: #eff6ff; }
.btn-expand.active { background: #3b82f6; border-color: #3b82f6; color: white; transform: rotate(90deg); }
.btn-action { border: none; background: transparent; width: 32px; height: 32px; border-radius: 8px; color: #94a3b8; transition: all 0.2s; }
.btn-action:hover { background: #f1f5f9; color: #334155; }

@media (min-width: 992px) {
  .th-w-40 { width: 40px; }
  .th-w-70 { width: 70px; }
  .th-w-100 { width: 100px; }
  .th-w-110 { width: 110px; }
  .th-w-120 { width: 120px; }
  .th-w-140 { width: 140px; }
  .th-w-150 { width: 150px; }
  .th-w-180 { width: 180px; }
  .th-w-200 { width: 200px; }
}
</style>