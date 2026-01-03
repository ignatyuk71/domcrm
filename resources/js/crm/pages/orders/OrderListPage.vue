<template>
  <section class="workspace">
    <!-- Topbar: title, search, create -->
    <header class="topbar border-bottom bg-white sticky-top">
    <div class="d-flex align-items-center justify-content-between px-4 py-2">
      <div class="d-flex align-items-center gap-3">
        <h1 class="h5 fw-bold m-0">Замовлення</h1>
        
        <div class="input-group input-group-sm rounded-pill border overflow-hidden bg-light" style="width: 250px;">
          <span class="input-group-text bg-transparent border-0 text-muted ps-3">
            <i class="bi bi-search"></i>
          </span>
          <input
            v-model="filters.search"
            @input="handleSearch"
            type="text"
            class="form-control border-0 shadow-none ps-1 bg-transparent"
            placeholder="Пошук..."
          />
        </div>
      </div>

      <a href="/orders/create" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold shadow-sm">
        <i class="bi bi-plus-lg me-1"></i> Створити
      </a>
    </div>

    <div class="px-4 pb-2 d-flex gap-2 overflow-auto no-scrollbar border-top pt-2">
      <button
        v-for="opt in statusChips"
        :key="opt.value"
        class="status-chip"
        :class="{ 'active': isStatusActive(opt.value) }"
        :style="isStatusActive(opt.value) && opt.color ? { 
          backgroundColor: opt.color, 
          borderColor: opt.color,
          color: '#111827' 
        } : {}"
        @click="toggleStatus(opt.value)"
      >
        <i v-if="opt.icon" :class="`bi ${opt.icon}`"></i>
        <span>{{ opt.label }}</span>
      </button>
    </div>
  </header>
    <!-- Content: table + pagination -->
      <div class="card border-0 shadow-sm overflow-hidden">
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
        <div v-else class="">
            <div class="table-responsive">
          <table class="table table-hover align-middle orders-table mb-0">
            <thead>
              <tr>
                <th style="width: 54px;"></th>
                <th style="width: 110px;">№</th>
                <th>Клієнт</th>
                <th>Теги</th>
                <th style="width: 150px;">Телефон</th>
                <th style="width: 140px;">Статус</th>
                <th style="width: 220px;">Товар</th>
                <th style="width: 140px;">Сума</th>
                <th style="width: 170px;">ТТН</th>
                <th style="width: 120px;">Створено</th>
                <th class="text-end pe-4" style="width: 70px;"></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="order in orders" :key="order.id">
                <tr 
                  class="row-hover cursor-pointer" 
                  :class="{ 'bg-light-subtle': expandedRows.has(order.id) }"
                  @click="toggleRow(order.id)"
                >
                  <td>
                    <button class="btn btn-sm exp-btn shadow-none" type="button">
                      <i class="bi transition-transform" 
                        :class="expandedRows.has(order.id) ? 'bi-chevron-down' : 'bi-chevron-right'"></i>
                    </button>
                  </td>
                  <td class="fw-bold text-dark w-nowrap">#{{ order.id }}</td>
                  <td>
                    <div class="fw-semibold text-dark w-nowrap">{{ order.client }}</div>
                    <div v-if="order.source_name" class="small muted">
                      <div>Джерело:</div>
                      <div class="d-flex align-items-center gap-1 mt-1" :style="order.source_color ? { color: order.source_color } : {}">
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
                <div
                  v-for="item in order.items.slice(0, 5)"
                  :key="item.id"
                  class="thumb border"
                >
                  <img v-if="item.photo" :src="item.photo" alt="product" />
                  <i v-else class="bi bi-image"></i>
                </div>
                <div
                  v-if="order.items.length > 5"
                  class="thumb border thumb-more"
                >
                  +{{ order.items.length - 5 }}
                </div>
              </div>
            </td>
            <td class="fw-bold text-dark w-nowrap">{{ formatCurrency(order.total, order.currency) }}</td>
            <td class="w-nowrap">
                    <span v-if="order.ttn" class="fw-semibold small text-secondary">{{ order.ttn }}</span>
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
                          <a
                            class="dropdown-item"
                            :href="`/orders/${order.id}/edit`"
                          >
                            <i class="bi bi-pencil me-2"></i>Редагувати
                          </a>
                        </li>
                        <li>
                          <button
                            class="dropdown-item text-danger d-flex align-items-center gap-2"
                            type="button"
                            :disabled="deletingId === order.id"
                            @click.stop="handleDelete(order)"
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
                  <td colspan="11">
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
                                  <span class="fw-medium d-inline-flex align-items-center gap-1" :style="order.source_color ? { color: order.source_color } : {}">
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
                                    @click.prevent.stop="openStatusesModal(order)"
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
                              <hr class="my-3 opacity-10"/>
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
                                <button
                                  class="btn btn-primary btn-sm px-3 shadow-sm"
                                  type="button"
                                  @click.stop="openTagsModal(order)"
                                >+ Додати</button>
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
                            <div class="border rounded-4 p-3 bg-white h-100 shadow-sm border-light">
                              <div class="d-flex justify-content-between align-items-center mb-3">
                                <div class="section-title">Адресна доставка</div>
                              </div>
                              <div class="info-list">
                                <div class="info-row column">
                                  <span class="muted">Місто</span>
                                  <span class="fw-bold text-dark">{{ order.city_name || '—' }}</span>
                                </div>
                                <div class="info-row column">
                                  <span class="muted">Адреса доставки</span>
                                  <span class="fw-semibold text-dark">{{ order.address }}</span>
                                </div>
                                <div class="info-row column">
                                  <span class="muted">Служба доставки</span>
                                  <span class="fw-bold text-dark">{{ order.delivery_carrier || '—' }}</span>
                                </div>
                                <div class="info-row column">
                                  <span class="muted">Трекінг код</span>
                                  <div class="d-flex align-items-center gap-2">
                                    <span class="fw-bold fs-6 text-dark">{{ order.ttn || '—' }}</span>
                                    <button
                                      v-if="order.ttn"
                                      class="btn btn-outline-secondary btn-sm rounded-circle copy-btn"
                                      type="button"
                                      @click.stop="copyTtn(order.ttn)"
                                      :title="'Копіювати ТТН'"
                                    >
                                      <i class="bi bi-clipboard"></i>
                                    </button>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>

                        <div class="mt-4">
                          <div class="section-title mb-3 fs-6 text-start">Товари у замовленні <span class="muted fw-normal">({{ order.itemsCount }} поз.)</span></div>
          <div class="table-responsive border overflow-hidden">
                            <table class="table table-sm align-middle mb-0 prod-table bg-white text-start">
                              <thead class="bg-light">
                                <tr>
                                  <th class="ps-3" style="width:60px;">Фото</th>
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
                        <td><div class="fw-bold small text-dark">{{ item.sku }}</div><div class="small text-muted">{{ item.title }}</div></td>
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
                              <div class="d-flex justify-content-between small mb-1"><span class="muted">Товари:</span><span class="text-dark fw-semibold">{{ formatCurrency(order.total, order.currency) }}</span></div>
                              <div class="d-flex justify-content-between small mb-1"><span class="muted">Доставка:</span><span class="text-success fw-bold">{{ formatCurrency(order.delivery_cost, order.currency) }}</span></div>
                              <hr class="my-2 opacity-10"/>
                              <div class="d-flex justify-content-between align-items-center fw-bold text-dark">
                                <span>Разом:</span><span class="fs-5 text-primary">{{ formatCurrency(order.total + order.delivery_cost, order.currency) }}</span>
                              </div>
                            </div>
                          </div>
                        </div>

                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
          </div>
      </div>

      <!-- Pagination -->
      <div v-if="meta.last_page > 1" class="card-footer bg-white border-top py-3">
        <nav class="d-flex justify-content-center">
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item" :class="{ disabled: meta.current_page === 1 }">
              <button class="page-link border-0 bg-transparent text-secondary" @click="changePage(meta.current_page - 1)">
                <i class="bi bi-chevron-left"></i>
              </button>
            </li>
            <li class="page-item disabled">
              <span class="page-link border-0 bg-transparent text-dark fw-medium">
                {{ meta.current_page }} / {{ meta.last_page }}
              </span>
            </li>
            <li class="page-item" :class="{ disabled: meta.current_page === meta.last_page }">
              <button class="page-link border-0 bg-transparent text-secondary" @click="changePage(meta.current_page + 1)">
                <i class="bi bi-chevron-right"></i>
              </button>
            </li>
          </ul>
        </nav>
      </div>
    </div>

    <!-- Tags Modal -->
    <div v-if="tagsModalOpen">
      <div class="modal-backdrop fade show"></div>
      <div class="modal fade show d-block" tabindex="-1" @click.self="closeTagsModal">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
              <h5 class="modal-title fw-bold">Обрати теги</h5>
              <button type="button" class="btn-close" @click="closeTagsModal"></button>
            </div>
            <div class="modal-body">
              <div v-if="tagsModalLoading" class="text-center py-4 text-muted">
                <div class="spinner-border text-primary mb-2" role="status"></div>
                <div>Завантаження тегів…</div>
              </div>
              <div v-else class="d-flex flex-column gap-2">
                <label
                  v-for="tag in availableTags"
                  :key="tag.id"
                  class="d-flex align-items-center justify-content-between border rounded-3 px-3 py-2 tag-check"
                >
                  <div class="d-flex align-items-center gap-2">
                    <span class="tag-icon shadow-sm" :class="'tag-' + tag.color">
                      <i :class="'bi ' + tag.icon"></i>
                      <span class="tag-text">{{ tag.name }}</span>
                    </span>
                  </div>
                  <input
                    class="form-check-input"
                    type="checkbox"
                    v-model="selectedTags"
                    :value="tag.id"
                  />
                </label>
                <div v-if="!availableTags.length" class="text-muted small">Теги відсутні</div>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-light border" type="button" @click="closeTagsModal">Скасувати</button>
              <button class="btn btn-primary" type="button" @click="saveTags" :disabled="tagsModalLoading">
                Зберегти
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Statuses Modal -->
    <div v-if="statusesModalOpen">
      <div class="modal-backdrop fade show"></div>
      <div class="modal fade show d-block" tabindex="-1" @click.self="closeStatusesModal">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
              <h5 class="modal-title fw-bold">Змінити статус</h5>
              <button type="button" class="btn-close" @click="closeStatusesModal"></button>
            </div>
            <div class="modal-body">
              <div v-if="statusesModalLoading" class="text-center py-4 text-muted">
                <div class="spinner-border text-primary mb-2" role="status"></div>
                <div>Завантаження статусів…</div>
              </div>
              <div v-else class="d-flex flex-column gap-2">
                <label
                  v-for="st in statuses"
                  :key="st.id"
                  class="d-flex align-items-center justify-content-between border rounded-3 px-3 py-2 tag-check"
                >
                  <div class="d-flex align-items-center gap-2">
                    <span class="tag-icon shadow-sm" :class="'tag-' + (st.color ? '' : 'gray')" :style="st.color ? { backgroundColor: st.color, borderColor: st.color } : {}">
                      <i :class="'bi ' + st.icon"></i>
                      <span class="tag-text">{{ st.name }}</span>
                    </span>
                  </div>
                  <input
                    class="form-check-input"
                    type="radio"
                    v-model="selectedStatusId"
                    :value="st.id"
                    name="status-select"
                  />
                </label>
                <div v-if="!statuses.length" class="text-muted small">Статуси відсутні</div>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-light border" type="button" @click="closeStatusesModal">Скасувати</button>
              <button class="btn btn-primary" type="button" @click="saveStatus" :disabled="statusesModalLoading || !selectedStatusId">
                Зберегти
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { listOrders, deleteOrder, updateOrderTags, updateOrderStatus } from '@/crm/api/orders';
import { fetchTags } from '@/crm/api/tags';
import { fetchStatuses } from '@/crm/api/statuses';

const orders = ref([]);
const expandedRows = ref(new Set());
const loading = ref(false);
const deletingId = ref(null);
const tagsModalOpen = ref(false);
const tagsModalLoading = ref(false);
const tagsModalOrder = ref(null);
const availableTags = ref([]);
const selectedTags = ref([]);
const statusesModalOpen = ref(false);
const statusesModalLoading = ref(false);
const statuses = ref([]);
const statusesOrder = ref(null);
const selectedStatusId = ref(null);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const filters = reactive({ search: '', statuses: [], payment_status: '', page: 1, per_page: 20 });
let searchTimer;

const statusChips = ref([
  { value: '', label: 'Всі', icon: 'bi-grid', color: null },
]);

const statusLabels = {
  new: 'Новий',
  confirmed: 'Підтверджено',
  pending: 'Очікує підтвердження',
  in_process: 'В обробці',
  packed: 'Упаковане',
  delivered: 'Доставлене',
  returned: 'Повернене',
  in_progress: 'В роботі',
  done: 'Готово',
  completed: 'Виконано',
  cancelled: 'Скасовано',
  shipped: 'Відправлено',
};

const paymentLabels = {
  paid: 'Оплачено',
  unpaid: 'Не оплачено',
  prepaid: 'Передоплата',
  prepayment: 'Передоплата',
  refund: 'Повернення',
};

async function fetchData() {
  loading.value = true;
  try {
    const { data } = await listOrders({
      page: filters.page,
      per_page: filters.per_page,
      search: filters.search || undefined,
      status: filters.statuses?.length ? filters.statuses : undefined,
      payment_status: filters.payment_status || undefined,
    });

    const payload = data.data || data?.data?.data || [];
    orders.value = payload.map(mapOrder);

    const metaPayload = data.meta || data?.data?.meta || {};
    meta.value = {
      current_page: metaPayload.current_page ?? 1,
      last_page: metaPayload.last_page ?? 1,
      total: metaPayload.total ?? orders.value.length,
    };
    // Зберігаємо розгорнуті рядки, якщо це можливо, або очищаємо
  } catch (error) {
    console.error(error);
  } finally {
    loading.value = false;
  }
}

function mapOrder(order) {
  const customer = order.customer || {};
  const delivery = order.delivery || {};
  const statusRef = order.statusRef || order.status_ref || {};
  const sourceRef = order.source || {};
  return {
    ...order,
    order_number: order.order_number || order.id,
    client: customer.full_name || [customer.first_name, customer.last_name].filter(Boolean).join(' ') || 'Гість',
    phone: customer.phone || '',
    email: customer.email || '',
    source_code: sourceRef.code || order.source || '',
    source_name: sourceRef.name || order.source || '—',
    source_icon: sourceRef.icon ? `bi ${sourceRef.icon}` : '',
    source_color: sourceRef.color || '',
    status_key: statusRef.code || order.status || 'new',
    status: statusRef.name || statusLabels[order.status] || order.status || '—',
    status_icon: statusRef.icon || '',
    status_color: statusRef.color || '',
    payment_status: order.payment_status,
    payment_status_label: paymentLabels[order.payment_status] || '—',
    tags: order.tags || [],
    itemsCount: order.items_count || (order.items ? order.items.length : 0),
    items: (order.items || []).map((item) => ({
      ...item,
      title: item.product_title || item.title || 'Товар',
      photo: buildPhotoUrl(
        item.product?.main_photo_url ||
        item.product?.main_photo_path ||
        item.main_photo_url ||
        item.main_photo_path ||
        item.photo
      ),
      total: Number(item.total ?? (Number(item.qty || 0) * Number(item.price || 0))),
    })),
    total: Number(order.items_sum_total ?? order.total_sum ?? 0),
    currency: order.currency || 'UAH',
    ttn: delivery.ttn || '',
    address: delivery.warehouse_name || [delivery.city_name, delivery.street_name].filter(Boolean).join(', ') || '—',
    city_name: delivery.city_name || '',
    delivery_carrier: delivery.carrier === 'nova_poshta' ? 'Нова Пошта' : (delivery.carrier || ''),
    delivery_cost: Number(delivery.delivery_cost ?? 0),
    comment: order.comment_internal || '',
    created_at: order.created_at,
  };
}

function handleSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    filters.page = 1;
    fetchData();
  }, 300);
}

function isStatusActive(val) {
  if (!val) return !filters.statuses.length;
  return filters.statuses.includes(val);
}

function toggleStatus(value) {
  if (!value) {
    filters.statuses = [];
  } else {
    const next = new Set(filters.statuses);
    if (next.has(value)) next.delete(value);
    else next.add(value);
    filters.statuses = Array.from(next);
  }
  filters.page = 1;
  fetchData();
}

function changePage(page) {
  if (page < 1 || page > meta.value.last_page) return;
  filters.page = page;
  fetchData();
}

async function copyTtn(ttn) {
  if (!ttn || typeof navigator === 'undefined' || !navigator.clipboard) return;
  try {
    await navigator.clipboard.writeText(ttn);
  } catch (err) {
    console.error('Не вдалося скопіювати ТТН', err);
  }
}

async function openTagsModal(order) {
  if (!order) return;
  tagsModalOrder.value = order;
  selectedTags.value = order.tags.map((t) => t.id);
  tagsModalOpen.value = true;
  if (!availableTags.value.length) {
    await loadTags();
  }
}

async function loadTags() {
  tagsModalLoading.value = true;
  try {
    const { data } = await fetchTags();
    const list = data?.data || data || [];
    availableTags.value = Array.isArray(list) ? list : [];
  } catch (e) {
    console.error('Не вдалося завантажити теги', e);
    availableTags.value = [];
  } finally {
    tagsModalLoading.value = false;
  }
}

function closeTagsModal() {
  tagsModalOpen.value = false;
  tagsModalOrder.value = null;
  selectedTags.value = [];
}

async function saveTags() {
  if (!tagsModalOrder.value) return;
  try {
    const { data } = await updateOrderTags(tagsModalOrder.value.id, selectedTags.value);
    const updatedTags = data?.data || data || [];
    tagsModalOrder.value.tags = updatedTags;
    const idx = orders.value.findIndex((o) => o.id === tagsModalOrder.value.id);
    if (idx !== -1) orders.value[idx].tags = updatedTags;
    closeTagsModal();
  } catch (e) {
    console.error('Не вдалося оновити теги', e);
    alert('Не вдалося оновити теги');
  }
}

async function handleDelete(order) {
  if (!order?.id) return;
  const confirmed = window.confirm(`Видалити замовлення #${order.order_number || order.id}?`);
  if (!confirmed) return;
  deletingId.value = order.id;
  try {
    await deleteOrder(order.id);
    orders.value = orders.value.filter((o) => o.id !== order.id);
    // оновлюємо лічильник
    meta.value.total = Math.max(0, (meta.value.total || 1) - 1);
    if (!orders.value.length && meta.value.current_page > 1) {
      filters.page = Math.max(1, filters.page - 1);
      await fetchData();
    }
  } catch (error) {
    console.error('Не вдалося видалити замовлення', error);
    alert('Не вдалося видалити замовлення');
  } finally {
    deletingId.value = null;
  }
}

async function openStatusesModal(order) {
  if (!order) return;
  statusesOrder.value = order;
  selectedStatusId.value = order.status_id || null;
  statusesModalOpen.value = true;
  if (!statuses.value.length) {
    await loadStatuses();
  }
}

async function loadStatuses() {
  statusesModalLoading.value = true;
  try {
    const { data } = await fetchStatuses({ type: 'order' });
    const list = data?.data || data || [];
    statuses.value = Array.isArray(list) ? list : [];
    statusChips.value = [
      { value: '', label: 'Всі', icon: 'bi-grid', color: null },
      ...statuses.value.map((st) => ({
        value: st.code,
        label: st.name,
        icon: st.icon,
        color: st.color,
      })),
    ];
  } catch (e) {
    console.error('Не вдалося завантажити статуси', e);
    statuses.value = [];
  } finally {
    statusesModalLoading.value = false;
  }
}

function closeStatusesModal() {
  statusesModalOpen.value = false;
  statusesOrder.value = null;
  selectedStatusId.value = null;
}

async function saveStatus() {
  if (!statusesOrder.value || !selectedStatusId.value) return;
  try {
    const { data } = await updateOrderStatus(statusesOrder.value.id, selectedStatusId.value);
    const status = data?.data || data || {};
    const idx = orders.value.findIndex((o) => o.id === statusesOrder.value.id);
    const target = idx !== -1 ? orders.value[idx] : statusesOrder.value;
    target.status_id = status.id;
    target.status_key = status.code;
    target.status = status.name;
    target.status_icon = status.icon;
    target.status_color = status.color;
    closeStatusesModal();
  } catch (e) {
    console.error('Не вдалося оновити статус', e);
    alert('Не вдалося оновити статус');
  }
}

function toggleRow(id) {
  if (expandedRows.value.has(id)) expandedRows.value.delete(id);
  else expandedRows.value.add(id);
}

function getStatusClass(status) {
  const map = {
    new: 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
    in_process: 'bg-success-subtle text-success-emphasis border-success-subtle',
    confirmed: 'bg-info-subtle text-info-emphasis border-info-subtle',
    in_progress: 'bg-primary-subtle text-primary-emphasis border-primary-subtle',
    in_work: 'bg-primary-subtle text-primary-emphasis border-primary-subtle',
    pending: 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
    packed: 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle',
    delivered: 'bg-success-subtle text-success-emphasis border-success-subtle',
    completed: 'bg-success-subtle text-success-emphasis border-success-subtle',
    done: 'bg-success-subtle text-success-emphasis border-success-subtle',
    cancelled: 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle',
    canceled: 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle',
    shipped: 'bg-indigo-subtle text-indigo-emphasis border-indigo-subtle',
    returned: 'bg-danger-subtle text-danger-emphasis border-danger-subtle',
  };
  return map[status] || 'bg-light text-dark border-light-subtle';
}

function getStatusStyle(order) {
  const color = order?.status_color || statusColorMap[order?.status_key];
  if (!color) return {};
  return {
    backgroundColor: color,
    borderColor: color,
    color: '#0f172a',
  };
}

function getStatusIcon(status) {
  const map = {
    new: 'bi-circle',
    confirmed: 'bi-check-circle',
    pending: 'bi-hourglass-split',
    packed: 'bi-box-seam',
    delivered: 'bi-bag-check',
    in_progress: 'bi-hourglass-split',
    in_work: 'bi-hourglass-split',
    done: 'bi-check2-circle',
    completed: 'bi-check2-circle',
    canceled: 'bi-x-circle',
    cancelled: 'bi-x-circle',
    shipped: 'bi-truck',
  };
  return map[status] || 'bi-dot';
}

const statusColorMap = {
  pending: '#fcd5b5',
  in_process: '#c7f2d4',
  in_progress: '#c7f2d4',
  confirmed: '#b2ecf7',
  packed: '#e4e6e9',
  shipped: '#dcd9ff',
  delivered: '#c6f1d4',
  cancelled: '#e4e6e9',
  canceled: '#e4e6e9',
  returned: '#ffd6d6',
};

function getPaymentClass(status) {
  const map = {
    paid: 'bg-success-subtle text-success-emphasis border-success-subtle',
    unpaid: 'bg-danger-subtle text-danger-emphasis border-danger-subtle',
    prepayment: 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
    refund: 'bg-dark-subtle text-dark-emphasis border-dark-subtle',
  };
  return map[status] || 'bg-light text-dark border-light-subtle';
}

function getPaymentIcon(status) {
  const map = {
    paid: 'bi-check-circle',
    unpaid: 'bi-x-circle',
    prepayment: 'bi-cash-stack',
    refund: 'bi-arrow-counterclockwise',
  };
  return map[status] || 'bi-dot';
}

function formatCurrency(value, currency = 'UAH') {
  return Number(value ?? 0).toLocaleString('uk-UA', { style: 'currency', currency });
}

function formatDate(value) {
  if (!value) return '';
  try {
    return new Date(value).toLocaleString('uk-UA', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch {
    return value;
  }
}

function buildPhotoUrl(path) {
  if (!path) return '';
  if (path.startsWith('http')) return path;
  let clean = path.replace(/^\//, '');
  if (clean.startsWith('public/')) {
    clean = clean.replace(/^public\//, '');
  }
  const urlPath = clean.startsWith('storage/') ? `/${clean}` : `/storage/${clean}`;
  return urlPath;
}

onMounted(fetchData);
onMounted(loadStatuses);
</script>

<style scoped>
/* Компактні чіпси статусів */
.status-chip {
  background: #f8fafc;
  border: 1px solid #5481bb;
  padding: 4px 10px;
  border-radius: 6px;
  font-size: 0.90rem;
  font-weight: 500;
  color: #64748b;
  white-space: nowrap;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  gap: 5px;
}

.status-chip:hover {
  background: #f1f5f9;
}

.status-chip.active {
  background: #0f172a;
  color: #fff;
  border-color: #0f172a;
}

/* Приховати смугу прокрутки, але залишити можливість скролити вбік */
.no-scrollbar::-webkit-scrollbar {
  display: none;
}
.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}

  /* Змінні краще винести в глобальний CSS, але тут ми їх прив'яжемо до хоста або workspace */
  .workspace {
        --bg: #f6f7fb;
        --card: #ffffff;
        --ink: #111827;
        --muted: rgba(17,24,39,.60);
        --line: rgba(17,24,39,.10);
        --line2: rgba(17,24,39,.07);
        --shadow: 0 18px 55px rgba(17,24,39,.10);
        --shadow2: 0 10px 26px rgba(17,24,39,.08);
        --r16: 16px;
        --r22: 22px;
        --accent: #6d5efc;
        --mint: #27c2a0;
        --warn: #ffb020;
        --danger: #ff4d6d;
      }
  
      .workspace {
        background:
          radial-gradient(1200px 450px at 8% 0%, rgba(109,94,252,.12), transparent 60%),
          radial-gradient(900px 360px at 92% 10%, rgba(39,194,160,.10), transparent 55%),
          var(--bg);
        color: var(--ink);
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
      }
  
      /* Layout */
      .app{
        display:flex;
        min-height:100vh;
      }
  
      .sidebar{
        width: 220px;
        background: linear-gradient(180deg, rgba(17,24,39,.98), rgba(17,24,39,.92));
        color: rgba(255,255,255,.92);
        border-right: 1px solid rgba(255,255,255,.08);
      }
  
      .sidebar .brand{
        padding: 16px 14px 8px;
        display:flex;
        align-items:center;
        gap:.65rem;
        user-select:none;
      }
      .brand-badge{
        width: 38px; height: 38px;
        border-radius: 14px;
        background: linear-gradient(135deg, rgba(109,94,252,.95), rgba(39,194,160,.92));
        box-shadow: 0 16px 28px rgba(0,0,0,.25);
        display:flex; align-items:center; justify-content:center;
        font-weight:900;
      }
      .sidebar .navlink{
        display:flex;
        align-items:center;
        gap:.7rem;
        padding: .5rem .7rem;
        margin: .12rem .5rem;
        border-radius: 14px;
        color: rgba(255,255,255,.70);
        text-decoration:none;
        transition: all .14s ease;
      }
      .sidebar .navlink:hover{
        background: rgba(255,255,255,.08);
        color: rgba(255,255,255,.92);
      }
      .sidebar .navlink.active{
        background: rgba(109,94,252,.18);
        border: 1px solid rgba(109,94,252,.25);
        color: rgba(255,255,255,.95);
      }
      .sidebar .navlink i{ font-size: 1.1rem; }
  
      .sidebar .footer{
        margin-top:auto;
        padding: 14px 18px;
        color: rgba(255,255,255,.55);
        font-size: .85rem;
        border-top: 1px solid rgba(255,255,255,.08);
      }
  
      .workspace{
        flex: 1;
        min-width: 0;
        display:flex;
        flex-direction: column;
      }
  
      /* Top header */
      .topbar{
        position: sticky;
        top: 0;
        z-index: 50;
        background: linear-gradient(180deg, rgba(255,255,255,.92), rgba(255,255,255,.86));
        backdrop-filter: blur(10px);
        border-bottom: 1px solid var(--line2);
      }
      .topbar-inner{
        padding: 16px 16px;
        display:flex;
        gap: 12px;
        align-items:center;
        justify-content: space-between;
      }
      .page-title{
        display:flex;
        flex-direction: column;
        gap:2px;
      }
      .page-title h1{
        font-size: 1.1rem;
        font-weight: 900;
        margin: 0;
        letter-spacing:.2px;
      }
      .page-title .sub{
        font-size: .86rem;
        color: var(--muted);
      }
  
      .top-actions{
        display:flex;
        gap: 10px;
        align-items:center;
        flex-wrap: wrap;
      }
  
      .chip{
        display:inline-flex;
        align-items:center;
        gap:.45rem;
        padding: .38rem .65rem;
        border-radius: 999px;
        border: 1px solid var(--line);
        background: rgba(255,255,255,.92);
        font-weight: 800;
        font-size: .82rem;
        color: rgba(17,24,39,.80);
        user-select:none;
        cursor:pointer;
      }
      .chip.active{
        border-color: rgba(109,94,252,.35);
        background: rgba(109,94,252,.10);
      }
  
      .cardish{
        background: rgba(255,255,255,.88);
        border: 1px solid var(--line2);
        border-radius: var(--r22);
        box-shadow: var(--shadow2);
      }
  
      /* Table */
      .table-wrap{
        margin: 16px;
        padding: 14px;
      }
  
      .orders-table{
        margin:0;
        /* reset bootstrap table variables to avoid tinted green backgrounds */
        --bs-table-bg: #fff;
        --bs-table-accent-bg: transparent;
        --bs-table-striped-bg: #fff;
        --bs-table-hover-bg: #f8f9fa;
        --bs-table-border-color: var(--line2);
      }
      .orders-table thead th{
        position: sticky;
        top: auto; /* below sticky topbar */
        z-index: 10;
        background: linear-gradient(180deg, rgba(255,255,255,.98), rgba(255,255,255,.92));
        border-bottom: 1px solid var(--line2);
        font-size: .75rem;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: var(--muted);
        padding-top: .7rem;
        padding-bottom: .7rem;
        white-space: nowrap;
      }
      .orders-table tbody td{
        border-color: var(--line2);
  
        white-space: normal;
        vertical-align: middle;
        background: #fff;
      }
      .w-nowrap{ white-space: nowrap; }
      .orders-table tbody tr:hover{
        background: rgba(109,94,252,.04);
      }
  
      /* Small pill badges */
      .pill{
        display:inline-flex; align-items:center; gap:.4rem;
        padding: .28rem .55rem;
        border-radius: 999px;
        border: 1px solid var(--line);
        background: rgba(255,255,255,.92);
        
        font-size:.78rem;
      }
      .pill.success{ border-color: rgba(39,194,160,.30); background: rgba(39,194,160,.14); }
      .pill.warn{ border-color: rgba(255,176,32,.35); background: rgba(255,176,32,.16); }
      .pill.danger{ border-color: rgba(255,77,109,.30); background: rgba(255,77,109,.14); }
  
      /* Expand button */
      .exp-btn{
        border-radius: 12px;
        border: 1px solid rgba(109,94,252,.25);
        background: rgba(109,94,252,.08);
      }
      .exp-btn .bi{ transition: transform .18s ease; color: rgba(109,94,252,.95); }
      .exp-btn[aria-expanded="true"] .bi{ transform: rotate(90deg); }
  
      /* Details (row expansion) */
      tr.details-row > td{ padding: 0; border-top: 0; }
      .details-wrap{
        padding: 12px 16px 18px;
        background: linear-gradient(180deg, rgba(109,94,252,.05), rgba(39,194,160,.04));
        border-top: 1px solid var(--line2);
      }
      .details-card{
        background: linear-gradient(180deg, rgba(255,255,255,.97), rgba(255,255,255,.90));
        border: 1px solid rgba(255,255,255,.40);
        border-radius: var(--r22);
        box-shadow: var(--shadow);
        overflow:hidden;
      }
  .section-title{
    font-weight: 900;
    letter-spacing: .2px;
  }
  .muted{ color: var(--muted); }
  
  @media (min-width: 992px){
    .col-lg-50{ flex: 0 0 50%; max-width: 50%; }
  }
  
  /* Delivery info: fixed height + 2 columns on lg+ */
  @media (min-width: 992px){
    .delivery-info{
      max-height: 220px;
          overflow-y: auto;
          display: grid;
          grid-template-columns: 1fr 1fr;
          column-gap: 16px;
          row-gap: 12px;
        }
      }
  
      /* Products block */
      .prod-thumb{
        width:70px; height:70px; border-radius: 12px;
        background: rgba(109,94,252,.12);
        border: 1px solid var(--line);
        display:flex; align-items:center; justify-content:center;
        overflow:hidden;
      }
      .prod-thumb img{
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
      }
      .prod-table th{
        font-size: .75rem;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: var(--muted);
        white-space: nowrap;
      }
      .prod-summary{
        background: rgba(17,24,39,.03);
        border: 1px solid var(--line2);
        border-radius: 16px;
      }
  
      /* Responsive: hide sidebar on small */
      @media (max-width: 991.98px){
        .sidebar{ display:none; }
        .orders-table thead th{ top: 70px; }
      }
    
      /* --- Row Expansion columns: 30/30/30 (visual thirds) --- */
      @media (min-width: 992px){
        .col-lg-33{ flex: 0 0 33.3333%; max-width: 33.3333%; }
      }
  
      /* --- Fix: product table header overlay --- */
      .prod-table thead th{
        position: static !important;
        top: auto !important;
        z-index: auto !important;
        background: rgba(109,94,252,.06);
        border-bottom: 1px solid var(--line2);
        padding-top: .75rem;
        padding-bottom: .75rem;
        vertical-align: middle;
      }
      .prod-table tbody td{
        padding-top: .85rem;
        padding-bottom: .85rem;
        white-space: nowrap;
        vertical-align: middle;
      }
      .prod-table td:nth-child(3){ white-space: normal; } /* allow name to wrap */
  
  
      /* --- FIX: prevent header overlay in main orders table --- */
      .orders-table thead th{
        position: static !important;
        top: auto !important;
        z-index: auto !important;
        box-shadow: none !important;
      }
  
  
      /* --- Colored tags (communication) --- */
      .tagset{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
      .tag-icon{
        display:inline-flex;
        align-items:center;
        gap: 5px;
        padding: 5px 8px;
        border-radius: 8px;
        border: 2px solid rgba(17,24,39,.14);
        background: rgba(255,255,255,.92);
        box-shadow: 0 10px 24px rgba(17,24,39,.08);
        font-weight: 500;
        font-size: .7rem;
        color: rgba(17,24,39,.9);
      }
      .tag-icon i{ font-size: .85rem; }
      .tag-text{ white-space: nowrap; font-size: .7rem; }
      .tag-red{ border-color: rgba(255,77,109,.55); background: rgba(255,77,109,.10); color: rgba(255,77,109,.95); }
      .tag-blue{ border-color: rgba(59,130,246,.55); background: rgba(59,130,246,.10); color: rgba(59,130,246,.95); }
      .tag-amber{ border-color: rgba(255,176,32,.55); background: rgba(255,176,32,.14); color: rgba(168,92,0,.95); }
  
      /* compact tags for list rows */
      .tag-mini{
        display:inline-flex; align-items:center; gap:4px;
        padding: 4px 7px;
        border-radius: 8px;
        border: 1.5px solid rgba(17,24,39,.14);
        background: rgba(255,255,255,.92);
        font-weight: 500;
        font-size: .66rem;
        color: rgba(17,24,39,.78);
      }
      .tag-mini i{ font-size: .82rem; }
      .tag-mini.tag-red{ border-color: rgba(255,77,109,.45); background: rgba(255,77,109,.10); color: rgba(255,77,109,.95); }
      .tag-mini.tag-blue{ border-color: rgba(59,130,246,.45); background: rgba(59,130,246,.10); color: rgba(59,130,246,.95); }
      .tag-mini.tag-amber{ border-color: rgba(255,176,32,.45); background: rgba(255,176,32,.14); color: rgba(168,92,0,.95); }
  
      .tag-check{
        cursor: pointer;
        transition: border-color .15s ease, box-shadow .15s ease;
      }
      .tag-check:hover{
        border-color: rgba(109,94,252,.35);
        box-shadow: 0 8px 20px rgba(17,24,39,.08);
      }
  
      .btn-link-style{
        background: none;
        border: none;
      
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
      }
      .btn-link-style:hover{
        opacity: .9;
        box-shadow: 0 6px 18px rgba(17,24,39,.08);
      }
  
  
      /* --- Product thumbnails in list rows --- */
      .thumbstack{
        display:flex;
        align-items:center;
        gap: 8px;
        max-width: 220px;
        overflow:hidden;
      }
      .thumb{
        width: 72px; height: 72px;
        border-radius: 6px;
        border: 1.5px solid rgba(17,24,39,.12);
        background: rgba(255,255,255,.96);
        box-shadow: 0 14px 30px rgba(17,24,39,.12);
        display:inline-flex; align-items:center; justify-content:center;
        overflow:hidden;
      }
      .thumb + .thumb{ margin-left: -10px; } /* slight overlap */
      .thumb img{ width:100%; height:100%; object-fit:cover; display:block; }
      .thumb .bi{ font-size: 1.8rem; color: rgba(109,94,252,.95); }
      .thumb-more{
        background: rgba(17,24,39,.08);
        color: rgba(17,24,39,.75);
        font-weight: 800;
        font-size: .95rem;
      }
  
      .pill-soft-success{
        background: rgba(34,197,94,.12);
        color: rgba(22,163,74,.95);
        border: 1px solid rgba(34,197,94,.25);
        font-weight: 700;
      }
  
      .info-list{
        display: flex;
        flex-direction: column;
        gap: 10px;
      }
      .info-row{
        display: flex;
        justify-content: space-between;
        gap: 12px;
        font-size: .9rem;
      }
      .info-row.column{
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
      }
      .info-row .muted{
        color: rgba(17,24,39,.55);
        font-weight: 600;
      }
  
  
      /* --- Slight font downscale for denser UI --- */
      .workspace { font-size: 14.5px; }
      .orders-table tbody td{ font-size: .92rem; }
      .orders-table thead th{ font-size: .70rem; }
      .section-title{ font-size: .95rem; }
      .pill{ font-size: .74rem; }
      .chip{ font-size: .78rem; }
  
  
      /* restore text inside tags */
      .orders-table .tag-mini{
        font-size: .66rem !important;
        padding: 2px 4px !important;
        min-width: unset;
        height: auto;
      }
      .visually-hidden{
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0,0,0,0);
        white-space: nowrap;
        border: 0;
      }
  
  
      /* --- Extra tag colors --- */
      .tag-teal{
        border-color: rgba(45,212,191,.55);
        background: rgba(45,212,191,.12);
        color: rgba(15,118,110,.95);
      }
      .tag-blue-outline{
        border-color: rgba(59,130,246,.65);
        background: rgba(59,130,246,.10);
        color: rgba(37,99,235,.95);
      }
  
  
  /* --- LIST: make product column visible & stable --- */
  @media (min-width: 992px){
    .orders-table{ table-layout: fixed; width:100%; }
    .orders-table th, .orders-table td{ padding-left:14px; padding-right:14px; }
    /* ensure product column has room */
    .orders-table th:nth-child(6), .orders-table td:nth-child(6){ width: 200px; }
  }
  
  
  </style>
  
