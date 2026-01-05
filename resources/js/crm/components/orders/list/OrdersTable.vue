<template>
  <!-- Loading State -->
  <div v-if="loading && !orders.length" class="p-5 text-center text-muted">
    <div class="spinner-border text-primary mb-3" role="status"></div>
    <div>Завантаження замовлень...</div>
  </div>

  <!-- Empty State -->
  <div v-else-if="!orders.length" class="p-5 text-center text-muted">
    <div class="mb-3 opacity-25">
      <i class="bi bi-inbox fs-1"></i>
    </div>
    <h5 class="fw-bold text-dark">Замовлень не знайдено</h5>
    <p class="small">Спробуйте змінити фільтри або створіть нове замовлення.</p>
  </div>

  <!-- Table of orders -->
  <div v-else>
    <div class="table-responsive">
      <table class="table table-hover align-middle orders-table mb-0">
        <thead>
          <tr>
            <th style="width: 40px;"></th>
            <th style="width: 70px;">№</th>
            <th style="width: 150px;">Клієнт</th>
            <th style="width: 120px;">Теги</th>
            <th style="width: 140px;">Телефон</th>
            <th style="width: 140px;">Статус</th>
            <th style="width: 130px;">Товар</th>
            <th style="width: 90px;">Сума</th>
            <th style="width: 150px;">ТТН</th>
            <th style="width: 150px;">Статус доставки</th>
            <th style="width: 120px;">Створено</th>
            <th class="text-end pe-4" style="width: 70px;"></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="order in orders" :key="order.id">
            <tr
              class="row-hover cursor-pointer"
              :class="{ 'bg-light-subtle': expandedRows.has(order.id) }"
              @click="$emit('toggle-row', order.id)"
            >
              <td>
                <button class="btn btn-sm exp-btn shadow-none" type="button">
                  <i
                    class="bi transition-transform"
                    :class="expandedRows.has(order.id) ? 'bi-chevron-down' : 'bi-chevron-right'"
                  ></i>
                </button>
              </td>
              <td class="fw-bold text-dark w-nowrap">#{{ order.id }}</td>
              <td>
                <div class="fw-semibold text-dark w-nowrap">{{ order.client }}</div>
                <div v-if="order.source_name" class="small muted">
                  <div>Джерело:</div>
                  <div
                    class="d-flex align-items-center gap-1 mt-1"
                    :style="order.source_color ? { color: order.source_color } : {}"
                  >
                    <i v-if="order.source_icon" :class="order.source_icon"></i>
                    <span>{{ order.source_name }}</span>
                  </div>
                </div>
              </td>
              <td>
                <div class="d-flex gap-1 flex-wrap">
                  <span
                    v-for="tag in order.tags"
                    :key="tag.id"
                    class="tag-mini"
                    :class="'tag-' + tag.color"
                    data-bs-toggle="tooltip"
                    :title="tag.name"
                    role="img"
                    :aria-label="tag.name"
                  >
                    <i :class="'bi ' + tag.icon"></i>
                    <span class="tag-text">{{ tag.name }}</span>
                  </span>
                </div>
              </td>
              <td class="fw-semibold text-dark w-nowrap">{{ order.phone }}</td>
              <td class="w-nowrap">
                <span
                  class="pill status-pill"
                  :class="getStatusClass(order.status_key)"
                  :style="getStatusStyle(order)"
                >
                  <i class="bi" :class="order.status_icon || getStatusIcon(order.status_key)"></i>
                  {{ order.status }}
                </span>
              </td>
              <td>
                <div class="thumbstack">
                  <div v-for="item in order.items.slice(0, 5)" :key="item.id" class="thumb border">
                    <img v-if="item.photo" :src="item.photo" alt="product" />
                    <i v-else class="bi bi-image"></i>
                  </div>
                  <div v-if="order.items.length > 5" class="thumb border thumb-more">
                    +{{ order.items.length - 5 }}
                  </div>
                </div>
              </td>
              <td class=" ">{{ formatCurrency(order.total, order.currency) }}</td>
              <td class="w-nowrap">
                <span v-if="order.ttn" class="fw-semibold small text-secondary">{{ order.ttn }}</span>
                <span v-else class="muted small">—</span>
              </td>
              <td class="w-nowrap">
                <div v-if="order.delivery_status" class="d-flex align-items-center gap-1 small" :style="{ color: order.delivery_status_color || '#6c757d' }">
                  <i v-if="order.delivery_status_icon" :class="['bi', order.delivery_status_icon]"></i>
                  <span class="fw-medium">{{ order.delivery_status }}</span>
                </div>
                <span v-else class="muted small">—</span>
              </td>
              <td class="small muted w-nowrap">{{ formatDate(order.created_at) }}</td>
              <td class="text-end pe-4">
                <div class="dropdown" @click.stop>
                  <button class="btn btn-sm btn-outline-secondary rounded-circle shadow-sm" data-bs-toggle="dropdown">
                    <i class="bi bi-three-dots fs-6"></i>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3">
                    <li>
                      <a class="dropdown-item" :href="`/orders/${order.id}/edit`">
                        <i class="bi bi-pencil me-2"></i>Редагувати
                      </a>
                    </li>
                    <li>
                      <button
                        class="dropdown-item text-danger d-flex align-items-center gap-2"
                        type="button"
                        :disabled="deletingId === order.id"
                        @click.stop="$emit('delete', order)"
                      >
                        <i class="bi bi-trash me-1"></i>
                        <span v-if="deletingId === order.id">Видалення…</span>
                        <span v-else>Видалити</span>
                      </button>
                    </li>
                  </ul>
                </div>
              </td>
            </tr>

            <tr v-if="expandedRows.has(order.id)" class="details-row">
              <td colspan="12">
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
  'toggleRow': null,
  delete: null,
  'open-tags': null,
  openTags: null,
  'open-statuses': null,
  openStatuses: null,
  'copy-ttn': null,
  copyTtn: null,
  'generate-ttn': null,
  generateTtn: null,
  'print-ttn': null,
  printTtn: null,
  'cancel-ttn': null,
  cancelTtn: null,
  'refresh-delivery': null,
  refreshDelivery: null,
});
</script>

<style scoped>
.orders-table {
  margin: 0;
  --bs-table-bg: #fff;
  --bs-table-accent-bg: transparent;
  --bs-table-striped-bg: #fff;
  --bs-table-hover-bg: #f8f9fa;
  --bs-table-border-color: rgba(39, 46, 61, 0.07);
}

.orders-table tbody td {
  border-color: rgba(17, 24, 39, 0.07);
  white-space: normal;
  vertical-align: middle;
  background: #fff;
  font-size: 0.86rem;
}
.orders-table tbody tr:hover {
  background: rgba(109, 94, 252, 0.04);
}
.w-nowrap {
  white-space: nowrap;
}

.pill {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.11rem 0.24rem;
  border-radius: 999px;
  border: 1px solid rgba(17, 24, 39, 0.08);
  background: rgba(255, 255, 255, 0.92);
  font-size: 0.68rem;
}

.status-pill {
  font-weight: 500;
}

.exp-btn {
  border-radius: 12px;
  border: 1px solid rgba(109, 94, 252, 0.25);
  background: rgba(109, 94, 252, 0.08);
}
.exp-btn .bi {
  transition: transform 0.18s ease;
  color: rgba(109, 94, 252, 0.95);
}

.details-row > td {
  padding: 0;
  border-top: 0;
}

.thumbstack {
  display: flex;
  align-items: center;
  gap: 8px;
  max-width: 220px;
  overflow: hidden;
}
.thumb {
  width: 64px;
  height: 64px;
  border-radius: 6px;
  border: 1px solid rgba(17, 24, 39, 0.12);
  background: rgba(255, 255, 255, 0.96);
  box-shadow: 0 14px 30px rgba(17, 24, 39, 0.12);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.thumb + .thumb {
  margin-left: -10px;
}
.thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.thumb .bi {
  font-size: 1.4rem;
  color: rgba(109, 94, 252, 0.95);
}
.thumb-more {
  background: rgba(17, 24, 39, 0.08);
  color: rgba(17, 24, 39, 0.75);
  font-weight: 800;
  font-size: 0.95rem;
}

.tag-mini {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 7px;
  border-radius: 8px;
  border: 1.5px solid rgba(17, 24, 39, 0.14);
  background: rgba(255, 255, 255, 0.92);
  font-weight: 500;
  font-size: 0.66rem;
  color: rgba(17, 24, 39, 0.78);
}
.tag-mini i {
  font-size: 0.82rem;
}
.tag-mini.tag-red {
  border-color: rgba(255, 77, 109, 0.45);
  background: rgba(255, 77, 109, 0.1);
  color: rgba(255, 77, 109, 0.95);
}
.tag-mini.tag-blue {
  border-color: rgba(59, 130, 246, 0.45);
  background: rgba(59, 130, 246, 0.1);
  color: rgba(59, 130, 246, 0.95);
}
.tag-mini.tag-amber {
  border-color: rgba(255, 176, 32, 0.45);
  background: rgba(255, 176, 32, 0.14);
  color: rgba(168, 92, 0, 0.95);
}

@media (min-width: 992px) {
  .orders-table {
    table-layout: fixed;
    width: 100%;
  }
  .orders-table th,
  .orders-table td {
    padding-left: 14px;
    padding-right: 14px;
  }
  .orders-table th:nth-child(6),
  .orders-table td:nth-child(6) {
    width: 200px;
  }
}
</style>
