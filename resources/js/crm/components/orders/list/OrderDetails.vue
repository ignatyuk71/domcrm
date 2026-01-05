<template>
  <div class="details-wrap border-top">
    <div class="details-card p-4 shadow-lg border-light rounded-4 bg-white">
      <div class="row g-3 mb-4">
        <div class="col-12 col-lg-4 text-start">
          <div class="border rounded-4 p-3 bg-white h-100 shadow-sm border-light">
            <div class="section-title mb-3">Замовлення</div>
            <div class="info-list">
              <div class="info-row">
                <span class="muted">№ замовлення</span>
                <span class="fw-bold text-dark">#{{ order.order_number }}</span>
              </div>
              <div class="info-row">
                <span class="muted">Джерело</span>
                <span
                  class="fw-medium d-inline-flex align-items-center gap-1"
                  :style="order.source_color ? { color: order.source_color } : {}"
                >
                  <i v-if="order.source_icon" :class="order.source_icon"></i>
                  {{ order.source_name || '—' }}
                </span>
              </div>
              <div class="info-row">
                <span class="muted">Час створення</span>
                <span class="fw-medium text-dark">{{ formatDate(order.created_at) }}</span>
              </div>
              <div class="info-row align-items-center">
                <span class="muted">Статус</span>
                <a
                  href="#"
                  class="pill status-pill btn-link-style"
                  :class="getStatusClass(order.status_key)"
                  :style="getStatusStyle(order)"
                  @click.prevent.stop="$emit('open-statuses')"
                  role="button"
                >
                  <i class="bi me-1" :class="order.status_icon || getStatusIcon(order.status_key)"></i>
                  {{ order.status }}
                </a>
              </div>
              <div class="info-row align-items-center">
                <span class="muted">Статус оплати</span>
                <span class="pill" :class="getPaymentClass(order.payment_status)">
                  <i class="bi me-1" :class="getPaymentIcon(order.payment_status)"></i>
                  {{ order.payment_status_label }}
                </span>
              </div>
            </div>
            <hr class="my-3 opacity-10" />
            <div class="section-title mb-2">Теги</div>
            <div class="tagset">
              <span
                v-for="tag in order.tags"
                :key="tag.id"
                class="tag-icon shadow-sm"
                :class="'tag-' + tag.color"
                role="img"
                :aria-label="tag.name"
              >
                <i :class="'bi ' + tag.icon"></i>
                <span class="tag-text">{{ tag.name }}</span>
              </span>
              <button class="btn btn-primary btn-sm px-3 shadow-sm" type="button" @click.stop="$emit('open-tags')">
                + Додати
              </button>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-4 text-start">
          <div class="border rounded-4 p-3 bg-white h-100 shadow-sm border-light">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div class="section-title">Покупець</div>
              <button class="btn btn-light border d-flex align-items-center gap-2 px-3 py-1 shadow-sm">
                <i class="bi bi-shield"></i>
                <span class="fw-bold">Профіль</span>
              </button>
            </div>
            <div class="info-list">
              <div class="info-row column">
                <span class="muted">Одержувач</span>
                <span class="fw-bold text-dark fs-6">{{ order.client }}</span>
              </div>
              <div class="info-row column">
                <span class="muted">Телефон</span>
                <span class="fw-bold text-primary fs-6">{{ order.phone || '—' }}</span>
              </div>
              <div class="info-row column">
                <span class="muted">Email</span>
                <span class="fw-medium text-dark">{{ order.email || '—' }}</span>
              </div>
              <div v-if="order.comment" class="info-row column">
                <span class="muted">Коментар</span>
                <span class="text-dark">{{ order.comment }}</span>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-4 text-start">
          <div class="delivery-card h-100">
            <div class="delivery-card__header">
              <div class="section-title mb-0">Адресна доставка</div>
              <span class="delivery-chip">
                <i class="bi bi-geo-alt-fill"></i>
                {{ order.delivery_carrier || '—' }}
              </span>
            </div>

            <div class="delivery-status">
              <div class="delivery-status__label">
                <span
                  class="status-dot"
                  :style="order.delivery_status_color ? { backgroundColor: order.delivery_status_color } : {}"
                ></span>
                <i
                  v-if="order.delivery_status_icon"
                  :class="['bi', order.delivery_status_icon]"
                  :style="order.delivery_status_color ? { color: order.delivery_status_color } : {}"
                ></i>
                <span>{{ order.delivery_status || '—' }}</span>
              </div>
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <button
                  class="btn btn-sm btn-outline-primary d-flex align-items-center gap-2"
                  type="button"
                  :disabled="order.refreshingDelivery"
                  @click.stop="$emit('refresh-delivery')"
                >
                  <span v-if="order.refreshingDelivery" class="spinner-border spinner-border-sm"></span>
                  <i v-else class="bi bi-arrow-clockwise"></i>
                  <span>Оновити</span>
                </button>
                <span v-if="order.delivery_status_updated_at" class="small text-muted">
                  Оновлено: {{ formatDate(order.delivery_status_updated_at) }}
                </span>
              </div>
            </div>

            <div class="delivery-grid">
              <div class="delivery-field">
                <div class="field-label">
                  <i class="bi bi-building"></i>
                  <span>Місто</span>
                </div>
                <div class="field-value">{{ order.city_name || '—' }}</div>
              </div>
              <div class="delivery-field">
                <div class="field-label">
                  <i class="bi bi-map"></i>
                  <span>Адреса</span>
                </div>
                <div class="field-value">{{ order.address }}</div>
              </div>
              <div class="delivery-field">
                <div class="field-label">
                  <i class="bi bi-person-check"></i>
                  <span>Платник</span>
                </div>
                <div class="field-value">{{ order.delivery_payer }}</div>
              </div>
            </div>

            <div class="delivery-ttn">
              <div class="field-label mb-1">
                <i class="bi bi-upc-scan"></i>
                <span>Трекінг код</span>
              </div>
              <div class="tracking-block">
                <div class="fw-bold fs-6 text-dark" v-if="order.ttn">{{ order.ttn }}</div>
                <div class="text-muted" v-else>ТТН ще не створена</div>

                <div class="tracking-actions">
                  <template v-if="order.ttn">
                    <button
                      class="btn btn-outline-secondary btn-sm rounded-circle shadow-sm"
                      type="button"
                      @click.stop="$emit('copy-ttn')"
                      title="Копіювати ТТН"
                    >
                      <i class="bi bi-clipboard"></i>
                    </button>

                    <button
                      class="btn btn-primary btn-sm d-flex align-items-center gap-2 px-2 shadow-sm"
                      type="button"
                      @click.stop="$emit('print-ttn')"
                      title="Друкувати ТТН"
                    >
                      <i class="bi bi-printer"></i>
                      <span>Друк</span>
                    </button>

                    <button
                      class="btn btn-danger btn-sm d-flex align-items-center gap-2 px-2 shadow-sm"
                      type="button"
                      @click.stop="$emit('cancel-ttn')"
                      :disabled="order.loadingTtn"
                      title="Анулювати ТТН"
                    >
                      <span v-if="order.loadingTtn" class="spinner-border spinner-border-sm"></span>
                      <i v-else class="bi bi-trash"></i>
                      <span>Видалити</span>
                    </button>
                  </template>

                  <template v-else>
                    <button
                      class="btn btn-success btn-sm d-flex align-items-center gap-2 shadow-sm px-3"
                      type="button"
                      @click.stop="$emit('generate-ttn')"
                      :disabled="order.loadingTtn"
                    >
                      <span v-if="order.loadingTtn" class="spinner-border spinner-border-sm"></span>
                      <i v-else class="bi bi-plus-lg"></i>
                      Створити ТТН
                    </button>
                  </template>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-4">
        <div class="section-title mb-3 fs-6 text-start">
          Товари у замовленні <span class="muted fw-normal">({{ order.itemsCount }} поз.)</span>
        </div>
        <div class="table-responsive border overflow-hidden">
          <table class="table table-sm align-middle mb-0 prod-table bg-white text-start">
            <thead class="bg-light">
              <tr>
                <th class="ps-3" style="width: 60px;">Фото</th>
                <th>Артикул / Назва</th>
                <th class="text-center">Розмір</th>
                <th class="text-center">К-сть</th>
                <th class="text-end">Ціна</th>
                <th class="text-end pe-3">Сума</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in order.items" :key="item.id">
                <td class="ps-3">
                  <div class="prod-thumb">
                    <img v-if="item.photo" :src="item.photo" alt="product" />
                    <i v-else class="bi bi-image text-muted"></i>
                  </div>
                </td>
                <td>
                  <div class="fw-bold small text-dark">{{ item.sku }}</div>
                  <div class="small text-muted">{{ item.title }}</div>
                </td>
                <td class="text-center">{{ item.size || '—' }}</td>
                <td class="text-center fw-bold text-dark">{{ item.qty }}</td>
                <td class="text-end text-dark">{{ formatCurrency(item.price, order.currency) }}</td>
                <td class="text-end fw-bold pe-3 text-dark">{{ formatCurrency(item.total, order.currency) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="d-flex justify-content-end mt-4">
          <div class="p-3 rounded-4 border bg-light shadow-sm" style="min-width: 280px;">
            <div class="d-flex justify-content-between small mb-1">
              <span class="muted">Товари:</span
              ><span class="text-dark fw-semibold">{{ formatCurrency(order.total, order.currency) }}</span>
            </div>
            <div v-if="order.prepay_amount" class="d-flex justify-content-between small mb-1">
              <span class="muted">Передоплата:</span
              ><span class="text-success fw-bold">- {{ formatCurrency(order.prepay_amount, order.currency) }}</span>
            </div>
            <hr class="my-2 opacity-10" />
            <div class="d-flex justify-content-between align-items-center fw-bold text-dark">
              <span>Разом:</span
              ><span class="fs-5 text-primary">
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
  getStatusClass,
  getStatusIcon,
  getStatusStyle,
} from '@/crm/utils/orderDisplay';

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
.details-wrap {
  padding: 12px 16px 18px;
  background: linear-gradient(180deg, rgba(109, 94, 252, 0.05), rgba(39, 194, 160, 0.04));
  border-top: 1px solid rgba(17, 24, 39, 0.07);
}
.details-card {
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.97), rgba(255, 255, 255, 0.9));
  border: 1px solid rgba(255, 255, 255, 0.4);
  border-radius: 22px;
  box-shadow: 0 18px 55px rgba(17, 24, 39, 0.1);
  overflow: hidden;
}
.section-title {
  font-weight: 900;
  letter-spacing: 0.2px;
  font-size: 0.95rem;
}
.muted {
  color: rgba(17, 24, 39, 0.55);
}
.pill {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.28rem 0.55rem;
  border-radius: 999px;
  border: 1px solid rgba(17, 24, 39, 0.1);
  background: rgba(255, 255, 255, 0.92);
  font-size: 0.78rem;
}
.btn-link-style {
  background: none;
  border: none;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
}
.btn-link-style:hover {
  opacity: 0.9;
  box-shadow: 0 6px 18px rgba(17, 24, 39, 0.08);
}

.tagset {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}
.tag-icon {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 5px 8px;
  border-radius: 8px;
  border: 2px solid rgba(17, 24, 39, 0.14);
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 10px 24px rgba(17, 24, 39, 0.08);
  font-weight: 500;
  font-size: 0.7rem;
  color: rgba(17, 24, 39, 0.9);
}
.tag-icon i {
  font-size: 0.85rem;
}
.tag-text {
  white-space: nowrap;
  font-size: 0.7rem;
}
.tag-red {
  border-color: rgba(255, 77, 109, 0.55);
  background: rgba(255, 77, 109, 0.1);
  color: rgba(255, 77, 109, 0.95);
}
.tag-blue {
  border-color: rgba(59, 130, 246, 0.55);
  background: rgba(59, 130, 246, 0.1);
  color: rgba(59, 130, 246, 0.95);
}
.tag-amber {
  border-color: rgba(255, 176, 32, 0.55);
  background: rgba(255, 176, 32, 0.14);
  color: rgba(168, 92, 0, 0.95);
}

.info-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.info-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 0.9rem;
}
.info-row.column {
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
}
.info-row .muted {
  color: rgba(17, 24, 39, 0.55);
  font-weight: 600;
}

.prod-thumb {
  width: 70px;
  height: 70px;
  border-radius: 12px;
  background: rgba(109, 94, 252, 0.12);
  border: 1px solid rgba(17, 24, 39, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.prod-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.prod-table th {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(17, 24, 39, 0.6);
  white-space: nowrap;
  position: static !important;
  top: auto !important;
  z-index: auto !important;
  background: rgba(109, 94, 252, 0.06);
  border-bottom: 1px solid rgba(17, 24, 39, 0.07);
  padding-top: 0.75rem;
  padding-bottom: 0.75rem;
  vertical-align: middle;
}
.prod-table tbody td {
  padding-top: 0.85rem;
  padding-bottom: 0.85rem;
  white-space: nowrap;
  vertical-align: middle;
}
.prod-table td:nth-child(3) {
  white-space: normal;
}

.delivery-card {
  border: 1px solid rgba(17, 24, 39, 0.08);
  border-radius: 16px;
  padding: 16px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(243, 246, 255, 0.9));
  box-shadow: 0 16px 42px rgba(17, 24, 39, 0.08);
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.delivery-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.delivery-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 999px;
  background: rgba(17, 24, 39, 0.06);
  color: #0f172a;
  font-weight: 600;
  font-size: 0.82rem;
}
.delivery-status {
  background: #fff;
  border: 1px solid rgba(17, 24, 39, 0.06);
  border-radius: 12px;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
}
.delivery-status__label {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  font-weight: 700;
  color: #0f172a;
}
.status-dot {
  width: 10px;
  height: 10px;
  border-radius: 999px;
  background: #d1d5db;
  display: inline-block;
}
.delivery-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
}
.delivery-field {
  border: 1px solid rgba(17, 24, 39, 0.05);
  border-radius: 12px;
  padding: 10px 12px;
  background: #fff;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.5);
}
.field-label {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: rgba(17, 24, 39, 0.6);
  font-weight: 600;
  font-size: 0.9rem;
}
.field-value {
  margin-top: 4px;
  font-weight: 700;
  color: #0f172a;
}
.delivery-ttn {
  border: 1px dashed rgba(17, 24, 39, 0.12);
  border-radius: 12px;
  padding: 12px;
  background: #fff;
}
.tracking-block {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.tracking-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.muted {
  color: rgba(17, 24, 39, 0.55);
}
.bi {
  line-height: 1;
}
</style>
