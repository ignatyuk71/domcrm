<template>
  <div class="packing-shell">
    <main class="main-content">
      
      <!-- Статистика зверху -->
      <section class="stats-overview" aria-label="Підсумки пакування">
        <div class="stat-card-modern success" title="Ваші запаковані за сьогодні, які ще не відправлені">
          <div class="stat-info">
            <div class="stat-label">Всього запаковано</div>
            <div class="stat-value">{{ historyOrders.length }}</div>
          </div>
          <div class="stat-bg-icon" aria-hidden="true"><i class="bi bi-box-seam"></i></div>
        </div>
        
        <div class="stat-card-modern primary queue-stat-card">
          <div class="stat-info">
            <div class="stat-label">Залишилось у черзі</div>
            <div class="stat-value">{{ queueOrdersCount }}</div>
            <div v-if="urgentCount > 0" class="urgent-count">Терміново: {{ urgentCount }}</div>
          </div>
          <button
            type="button"
            class="btn-sewing-summary"
            title="Показати, що треба пошити"
            @click="openSewingModal"
          >
            <i class="bi bi-scissors"></i>
            <span>Пошиття</span>
          </button>
        </div>
      </section>

      <!-- Панель керування (Пошук + Оновлення) -->
      <div class="control-panel">
        <div class="control-left">
          <div class="control-fields">
          <div class="search-wrapper">
            <label class="visually-hidden" for="packing-search">Пошук за номером замовлення або містом</label>
            <div class="search-input-wrap">
              <i class="bi bi-search" aria-hidden="true"></i>
              <input
                id="packing-search"
                v-model="searchQuery"
                type="text"
                placeholder="Номер замовлення або місто"
              />
              <button
                v-if="searchQuery"
                type="button"
                class="search-clear"
                title="Очистити пошук"
                aria-label="Очистити пошук"
                @click="clearSearch"
              >
                <i class="bi bi-x-lg"></i>
              </button>
            </div>
          </div>

          <button type="button" class="btn-refresh" @click="refreshData" :disabled="loading" title="Оновити дані вручну" aria-label="Оновити список">
            <i class="bi bi-arrow-repeat" :class="{ 'spin': loading }" aria-hidden="true"></i>
          </button>
          <div class="settings-group">
            <label class="auto-refresh-switch" title="Автоматично оновлювати список кожні 30 сек">
              <input type="checkbox" v-model="autoRefreshEnabled">
              <span class="switch-slider" aria-hidden="true"></span>
              <span class="switch-label">Автооновлення</span>
            </label>
            <span class="switch-state" :class="{ active: autoRefreshEnabled }">
              {{ autoRefreshEnabled ? 'Кожні 30 с' : 'Вимкнено' }}
            </span>
          </div>
          </div>
        </div>

        <div class="actions-group">
          <button type="button" class="btn-main-action" :disabled="loading || pendingOrdersCount === 0 || isStarting" @click="startPackingFirst">
            <i class="bi bi-play-fill" aria-hidden="true"></i>
            <span>Пакувати чергу</span>
            <span class="queue-count">{{ pendingOrdersCount }}</span>
          </button>
          <button
            type="button"
            class="btn-deferred-action"
            :disabled="loading || isStarting || deferredOrders.length === 0"
            @click="startDeferredPacking"
          >
            <i class="bi bi-box-seam" aria-hidden="true"></i>
            <span>Пакувати відкладені</span>
            <span class="deferred-count">{{ deferredOrders.length }}</span>
          </button>
        </div>
      </div>

      <div v-if="deferredMessage" class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="status">
        <span>{{ deferredMessage }}</span>
        <button v-if="deferredRunId" class="btn btn-sm btn-outline-dark" :disabled="isStarting" @click="continueDeferredPacking">
          Спробувати ще раз
        </button>
      </div>

      <!-- Список замовлень -->
      <div class="orders-list-heading">
        <span>{{ searchQuery ? 'Знайдено' : 'Замовлення' }} · {{ filteredOrders.length }}</span>
        <span>У порядку пакування</span>
      </div>
      <div class="orders-container">
        <TransitionGroup name="stagger">
          <div
            v-for="order in filteredOrders"
            :key="order.id"
            class="order-row-modern"
            :class="{
              'is-priority': order.is_priority && isPending(order),
              'is-packed': isPacked(order),
              'is-skipped': isSkipped(order)
            }"
          >
            <!-- Червона смужка для пріоритетних -->
            <div v-if="order.is_priority && isPending(order)" class="priority-strip"></div>

            <div class="order-main-content">
              
              <!-- ID та Місто -->
              <div class="order-identity">
                <div class="d-flex align-items-center gap-2 mb-1 identity-top">
                  <span class="order-id">#{{ order.order_number }}</span>
                  
                  <span v-if="isPacked(order)" class="badge-status packed">
                    <i class="bi bi-check-lg"></i> Запаковано
                  </span>
                  <span v-else-if="isProcessing(order)" class="badge-status pending">У роботі</span>
                  <span v-else-if="isPending(order)" class="badge-status pending">Черга</span>
                </div>
                <div v-if="order.items?.length" class="order-goods-summary">{{ orderGoodsSummary(order) }}</div>
                <div class="order-sub">
                   <i class="bi bi-geo-alt-fill"></i>
                   <span class="order-sub-text" :title="orderLocation(order)">{{ orderLocation(order) }}</span>
                </div>
                <div v-if="hasOrderContact(order)" class="order-contact-compact">
                  <span v-if="orderContactName(order)">{{ orderContactName(order) }}</span>
                  <span v-if="orderContactName(order) && orderContactPhone(order)" class="contact-separator">•</span>
                  <span v-if="orderContactPhone(order)">{{ formatPhoneDisplay(orderContactPhone(order)) }}</span>
                </div>
              </div>

              <!-- Мініатюри товарів -->
              <div class="order-items-preview">
                <div class="thumb-stack" :class="{ 'opacity-50': isPacked(order) }">
                  <div
                    v-for="(item, idx) in itemThumbs(order.items)"
                    :key="idx"
                    class="avatar"
                    :style="thumbStyle(item)"
                    role="img"
                    :aria-label="item.src ? item.title : 'Фото товару відсутнє'"
                  ><i v-if="!item.src" class="bi bi-image" aria-hidden="true"></i></div>
                  <div v-if="order.items?.length > 3" class="avatar-more">+{{ order.items.length - 3 }}</div>
                </div>
                <div class="items-count-label">
                  {{ order.items?.length || 0 }} {{ declension(order.items?.length || 0, ['товар', 'товари', 'товарів']) }}
                </div>
              </div>

              <!-- Час / Статус -->
              <div class="order-timing">
                <div class="timing-label">Статус</div>
                <div class="timing-val">
                  <span v-if="isPacked(order)" class="text-success">
                    <i class="bi bi-clock-history"></i> {{ formatTime(order.packed_at) }}
                  </span>
                  <span v-else-if="isSkipped(order)" class="text-skipped">Відкладено до готовності</span>
                  <span v-else-if="isProcessing(order)" class="text-waiting">Пакування розпочато</span>
                  <span v-else class="text-waiting">{{ formatAge(order.created_at) }} очікує</span>
                </div>
              </div>

              <!-- Кнопка дії -->
              <div class="order-actions">
                <button
                  v-if="isPacked(order)"
                  class="btn-action-secondary"
                  @click.stop="openDetails(order)"
                >
                  <i class="bi bi-info-circle me-1"></i> Деталі
                </button>
                <div v-else class="queue-actions">
                  <button class="btn-action-primary" :disabled="isStarting" @click="startPacking(order.id)">
                    Пакувати
                  </button>
                  <button class="btn-action-secondary" @click.stop="openDetails(order)">
                    Переглянути
                  </button>
                </div>
              </div>
            </div>
          </div>
        </TransitionGroup>

        <!-- Порожній стан -->
        <div v-if="!filteredOrders.length && !loading" class="empty-state-modern">
          <div class="empty-icon"><i class="bi bi-inbox"></i></div>
          <h3>{{ searchQuery ? 'Нічого не знайдено' : 'Список порожній' }}</h3>
          <p>{{ searchQuery ? 'Спробуйте інший номер замовлення або місто.' : 'Немає жодного замовлення.' }}</p>
        </div>
      </div>
    </main>

    <Transition name="fade">
      <div v-if="showDetailsModal" class="packing-modal-overlay" @click.self="closeDetails">
        <div class="packing-modal">
          <div class="modal-header">
            <div>
              <div class="modal-title">{{ modalTitle }}</div>
              <div class="modal-subtitle">
                №{{ selectedOrderNumber }}
                <span v-if="selectedOrderMoment">• {{ selectedOrderMoment }}</span>
              </div>
            </div>
            <button class="modal-close" @click="closeDetails" aria-label="Закрити">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <div class="modal-body">
            <div class="details-layout">
              <aside class="details-side">
                <div class="side-details">
                  <div class="side-detail-row">
                    <span>Одержувач</span>
                    <strong>{{ selectedRecipient }}</strong>
                  </div>
                  <div class="side-detail-row">
                    <span>Телефон</span>
                    <strong>{{ selectedRecipientPhone }}</strong>
                  </div>
                  <div class="side-detail-row">
                    <span>Місто</span>
                    <strong>{{ selectedDelivery.city_name || '—' }}</strong>
                  </div>
                  <div class="side-detail-row">
                    <span>Відділення</span>
                    <strong>{{ selectedDelivery.warehouse_name || '—' }}</strong>
                  </div>
                  <div class="side-detail-row" v-if="selectedIsPacked">
                    <span>Пакувальник</span>
                    <strong>{{ selectedPackerName }}</strong>
                  </div>
                  <div class="side-detail-row" v-if="selectedIsPacked">
                    <span>ТТН</span>
                    <strong>{{ selectedDelivery.ttn || '—' }}</strong>
                  </div>
                </div>
              </aside>

              <section class="details-main">
                <div class="details-main-head">
                  <div class="details-main-title">{{ selectedIsPacked ? 'Склад замовлення' : 'Що пакувати' }}</div>
                  <div class="details-main-subtitle">Перевір фото, колір, розмір і кількість кожної пари</div>
                </div>

                <div class="product-list">
                  <article
                    v-for="(item, idx) in selectedItems"
                    :key="idx"
                    class="product-card"
                  >
                    <div class="product-photo">
                      <img
                        v-if="itemImage(item)"
                        :src="itemImage(item)"
                        :alt="itemTitle(item)"
                        loading="lazy"
                      />
                      <div v-else class="product-photo-empty">
                        <i class="bi bi-image"></i>
                      </div>
                    </div>

                    <div class="product-content">
                      <div class="product-title">{{ itemTitle(item) }}</div>
                      <div class="product-specs">
                        <span v-if="itemType(item) !== '—'">Тип: {{ itemType(item) }}</span>
                        <span>Колір: {{ itemColor(item) }}</span>
                        <span>Розмір: {{ itemSize(item) }}</span>
                        <span>SKU: {{ itemSku(item) }}</span>
                      </div>
                    </div>

                    <div class="product-qty">
                      {{ itemQtyPairs(item) }}
                    </div>
                  </article>
                  <div v-if="!selectedItems.length" class="modal-empty">Товари відсутні</div>
                </div>
              </section>
            </div>
          </div>

          <div class="modal-actions">
            <button class="btn-modal-secondary" @click="closeDetails">Закрити</button>
            <button
              v-if="selectedIsPacked"
              class="btn-modal-primary"
              :disabled="!canPrintSelected || printingSelected"
              @click="printSelectedTtn"
            >
              <i class="bi bi-printer me-1"></i>
              {{ printingSelected ? 'Відкриваю...' : 'Друк накладної' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <Transition name="fade">
      <div v-if="showSewingModal" class="packing-modal-overlay" @click.self="closeSewingModal">
        <div class="packing-modal sewing-modal">
          <div class="modal-header">
            <div>
              <div class="modal-title">Пошиття</div>
              <div class="modal-subtitle">
                Черга + відкладені: {{ sewingOrdersCount }} {{ declension(sewingOrdersCount, ['замовлення', 'замовлення', 'замовлень']) }},
                {{ sewingTotalPairs }} {{ declension(sewingTotalPairs, ['пара', 'пари', 'пар']) }}
              </div>
            </div>
            <button class="modal-close" @click="closeSewingModal" aria-label="Закрити">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <div class="modal-body">
            <div class="sewing-list">
              <article
                v-for="item in sewingSummary"
                :key="item.key"
                class="sewing-row"
              >
                <div class="sewing-photo">
                  <img
                    v-if="item.image"
                    :src="item.image"
                    :alt="item.title"
                    loading="lazy"
                  />
                  <div v-else class="product-photo-empty">
                    <i class="bi bi-image"></i>
                  </div>
                </div>

                <div class="sewing-content">
                  <div class="sewing-title">{{ item.title }}</div>
                  <div class="sewing-specs">
                    <span v-if="item.type !== '—'">Тип: {{ item.type }}</span>
                    <span>Колір: {{ item.color }}</span>
                    <span>Розмір: {{ item.size }}</span>
                  </div>
                </div>

                <div class="sewing-qty">
                  {{ item.qty }} {{ declension(item.qty, ['пара', 'пари', 'пар']) }}
                </div>
              </article>

              <div v-if="!sewingSummary.length" class="modal-empty">
                Немає товарів у черзі або відкладених замовленнях
              </div>
            </div>
          </div>

          <div class="modal-actions">
            <button class="btn-modal-secondary" @click="closeSewingModal">Закрити</button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import axios from 'axios';
import { createDeferredRun, startNextDeferredOrder, disposeDeferredRun } from './deferredPackingQueue';

// --- State ---
const orders = ref([]);
const historyOrders = ref([]); // Запаковані сьогодні (але ще не відправлені)
const loading = ref(true);
const searchQuery = ref('');
const autoRefreshEnabled = ref(true);
const showDetailsModal = ref(false);
const showSewingModal = ref(false);
const selectedOrder = ref(null);
const printingSelected = ref(false);
const isStarting = ref(false);
const deferredRunId = ref(null);
const deferredMessage = ref('');

let refreshInterval = null;

// --- Helpers ---
// Логіка статусів будується на packing_status, щоб не залежати від ID довідника.
const isPending = (o) => o.packing_status === 'pending' || !o.packing_status;
const isProcessing = (o) => o.packing_status === 'processing';
const isSkipped = (o) => o.packing_status === 'skipped';
const isPacked = (o) => o.packing_status === 'packed' || !!o.packed_at;
const compareDeferredOrders = (a, b) => (
  new Date(b.updated_at || b.created_at) - new Date(a.updated_at || a.created_at) || Number(b.id) - Number(a.id)
);
// Кнопка запускає всі жовті замовлення, незалежно від поточного пошуку.
const deferredOrders = computed(() => orders.value.filter(isSkipped).sort(compareDeferredOrders));

// Шукаємо замовлення, яке я вже почав, але не закінчив
const myActiveOrder = computed(() => orders.value.find(o => isProcessing(o)));

const queueOrdersCount = computed(() => orders.value.filter(o => !isPacked(o)).length);
const pendingOrdersCount = computed(() => orders.value.filter(o => isPending(o)).length);
const urgentCount = computed(() => orders.value.filter(o => o.is_priority && isPending(o)).length);
const sewingOrders = computed(() => orders.value.filter(o => isPending(o) || isSkipped(o)));
const sewingOrdersCount = computed(() => sewingOrders.value.length);
const selectedItems = computed(() => Array.isArray(selectedOrder.value?.items) ? selectedOrder.value.items : []);
const selectedDelivery = computed(() => selectedOrder.value?.delivery || {});
const selectedOrderNumber = computed(() => selectedOrder.value?.order_number || selectedOrder.value?.id || '—');
const selectedPackedAt = computed(() => selectedOrder.value?.packed_at || selectedOrder.value?.updated_at || null);
const selectedIsPacked = computed(() => isPacked(selectedOrder.value || {}));
const selectedOrderMoment = computed(() => {
  const raw = selectedIsPacked.value
    ? selectedPackedAt.value
    : (selectedOrder.value?.created_at || selectedOrder.value?.updated_at || null);

  return raw ? formatDateTime(raw) : '—';
});
const modalTitle = computed(() => selectedIsPacked.value ? 'Запаковане замовлення' : 'Товари до пакування');
const selectedCustomer = computed(() => selectedOrder.value?.customer || {});
const selectedPackerName = computed(() => selectedOrder.value?.packer?.name || '—');
const selectedRecipient = computed(() => {
  const deliveryName = selectedDelivery.value?.recipient_name;
  const customerName = [selectedCustomer.value?.first_name, selectedCustomer.value?.last_name]
    .filter(Boolean)
    .join(' ')
    .trim();
  return deliveryName || customerName || '—';
});
const selectedRecipientPhone = computed(() => (
  selectedDelivery.value?.recipient_phone ||
  selectedCustomer.value?.phone ||
  selectedOrder.value?.phone ||
  '—'
));
const canPrintSelected = computed(() => Boolean(selectedOrder.value?.id && selectedDelivery.value?.ttn));

const sewingSummary = computed(() => {
  const groups = new Map();

  sewingOrders.value.forEach((order) => {
    const items = Array.isArray(order?.items) ? order.items : [];

    items.forEach((item) => {
      const title = itemTitle(item);
      const type = itemType(item);
      const color = itemColor(item);
      const size = itemSize(item);
      const key = [
        item?.product_id || title,
        item?.product_variant_id || size,
        type,
        color,
        size,
      ].join('|');

      if (!groups.has(key)) {
        groups.set(key, {
          key,
          title,
          type,
          color,
          size,
          image: itemImage(item),
          qty: 0,
        });
      }

      groups.get(key).qty += itemQty(item);
    });
  });

  return Array.from(groups.values()).sort((a, b) => {
    const titleCompare = a.title.localeCompare(b.title, 'uk');
    if (titleCompare !== 0) return titleCompare;

    const colorCompare = a.color.localeCompare(b.color, 'uk');
    if (colorCompare !== 0) return colorCompare;

    return String(a.size).localeCompare(String(b.size), 'uk', { numeric: true });
  });
});

const sewingTotalPairs = computed(() => sewingSummary.value.reduce((sum, item) => sum + item.qty, 0));

// Фільтрація та сортування
const filteredOrders = computed(() => {
  // Об'єднуємо активні та історію
  const source = [...orders.value, ...historyOrders.value];
  let result = source;
  
  // Пошук
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    result = source.filter(o => 
      String(o.order_number).toLowerCase().includes(q) ||
      (o.delivery?.city_name || '').toLowerCase().includes(q)
    );
  }

  // Сортування
  return result.sort((a, b) => {
    // 1. Статус (В роботі -> Черга -> Запаковані)
    const getStatusWeight = (o) => {
      if (isProcessing(o)) return 1;
      if (isPending(o)) return 2;
      if (isSkipped(o)) return 3;
      if (isPacked(o)) return 4;
      return 5;
    };
    const wA = getStatusWeight(a);
    const wB = getStatusWeight(b);
    if (wA !== wB) return wA - wB;

    // 2. Внутрішнє сортування
    if (isPacked(a)) {
      // Запаковані: нові зверху (за часом пакування)
      const dateA = new Date(a.packed_at || a.updated_at).getTime();
      const dateB = new Date(b.packed_at || b.updated_at).getTime();
      return dateB - dateA;
    }
    if (isPending(a)) {
      // Черга: спочатку пріоритетні, потім старіші
      if (a.is_priority !== b.is_priority) return a.is_priority ? -1 : 1;
      return new Date(a.created_at) - new Date(b.created_at);
    }
    if (isSkipped(a)) {
      // Відкладені залишаємо внизу активного списку, новіші вище.
      return compareDeferredOrders(a, b);
    }
    return 0;
  });
});

// --- Sound Logic (Web Audio API) ---
const playSound = (type = 'success') => {
  try {
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) return;
    const ctx = new AudioContext();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    
    osc.connect(gain);
    gain.connect(ctx.destination);
    
    if (type === 'success') {
      osc.type = 'sine';
      osc.frequency.setValueAtTime(800, ctx.currentTime);
      osc.frequency.exponentialRampToValueAtTime(1200, ctx.currentTime + 0.1);
      gain.gain.setValueAtTime(0.1, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.1);
      osc.start(ctx.currentTime);
      osc.stop(ctx.currentTime + 0.15);
    } else {
      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(150, ctx.currentTime);
      gain.gain.setValueAtTime(0.1, ctx.currentTime);
      gain.gain.linearRampToValueAtTime(0.01, ctx.currentTime + 0.2);
      osc.start(ctx.currentTime);
      osc.stop(ctx.currentTime + 0.25);
    }
  } catch (e) {
    console.error('Audio error', e);
  }
};

// --- Actions ---
const fetchAll = async () => {
  loading.value = true;
  try {
    const [resList, resHistory] = await Promise.all([
      axios.get('/api/packing/list'),
      axios.get('/api/packing/history')
    ]);
    orders.value = Array.isArray(resList.data) ? resList.data : [];
    historyOrders.value = Array.isArray(resHistory.data) ? resHistory.data : [];
  } catch (e) {
    console.error('Помилка завантаження', e);
  } finally {
    loading.value = false;
  }
};

const refreshData = () => fetchAll();
const clearSearch = () => {
  searchQuery.value = '';
};

const startPacking = async (id) => {
  if (isStarting.value) return;
  isStarting.value = true;
  try {
    const { data } = await axios.post(`/packing/${id}/start`);
    if (data?.success) {
      playSound('success');
      // Переходимо на сторінку пакування
      window.location.href = `/packing/${id}`;
    }
  } catch (err) {
    playSound('error');
    alert(err.response?.data?.error || 'Помилка доступу');
    refreshData();
  } finally {
    isStarting.value = false;
  }
};

const continueDeferredPacking = async () => {
  if (isStarting.value || !deferredRunId.value) return;
  isStarting.value = true;
  deferredMessage.value = '';
  try {
    const url = await startNextDeferredOrder(deferredRunId.value);
    if (url) {
      window.location.href = url;
    } else {
      disposeDeferredRun(deferredRunId.value);
      deferredRunId.value = null;
      deferredMessage.value = 'Доступних відкладених замовлень більше немає. Список оновлено.';
      await fetchAll();
    }
  } catch (error) {
    deferredMessage.value = error.response?.data?.error || 'Не вдалося відкрити наступне відкладене замовлення. Спробуйте ще раз.';
  } finally {
    isStarting.value = false;
  }
};

const startDeferredPacking = async () => {
  if (isStarting.value || !deferredOrders.value.length) return;
  try {
    deferredRunId.value = createDeferredRun(deferredOrders.value);
  } catch (error) {
    deferredMessage.value = 'Не вдалося зберегти прохід у браузері. Дозвольте зберігання даних для цього сайту.';
    return;
  }
  await continueDeferredPacking();
};

const startPackingFirst = () => {
  const next = myActiveOrder.value || filteredOrders.value.find(o => isPending(o));
  if (next) startPacking(next.id);
};

const openDetails = (order) => {
  selectedOrder.value = order;
  showDetailsModal.value = true;
};

const closeDetails = () => {
  showDetailsModal.value = false;
  selectedOrder.value = null;
};

const openSewingModal = () => {
  showSewingModal.value = true;
};

const closeSewingModal = () => {
  showSewingModal.value = false;
};

const printSelectedTtn = async () => {
  const orderId = selectedOrder.value?.id;
  if (!orderId) return;

  printingSelected.value = true;
  try {
    const { data } = await axios.get(`/orders/${orderId}/print-ttn`);
    if (data?.print_url) {
      window.open(data.print_url, '_blank');
    } else {
      alert(data?.message || 'Немає посилання для друку');
    }
  } catch (err) {
    alert(err.response?.data?.message || 'Помилка друку');
  } finally {
    printingSelected.value = false;
  }
};

// --- Auto Refresh ---
const setupAutoRefresh = () => {
  if (refreshInterval) clearInterval(refreshInterval);
  if (autoRefreshEnabled.value) {
    refreshInterval = setInterval(() => {
      // Тихе оновлення
      axios.get('/api/packing/list').then(res => {
        if(Array.isArray(res.data)) orders.value = res.data;
      });
      // Історія теж оновлюється, щоб прибирати відправлені
      axios.get('/api/packing/history').then(res => {
        if(Array.isArray(res.data)) historyOrders.value = res.data;
      });
    }, 30000); // 30 сек
  }
};

watch(autoRefreshEnabled, setupAutoRefresh);

// --- Formats ---
const formatAge = (d) => {
  if (!d) return '—';
  const diff = Math.floor((new Date() - new Date(d)) / 60000);
  if (diff < 1) return 'Щойно';
  if (diff < 60) return `${diff} хв`;
  const h = Math.floor(diff / 60);
  if (h < 24) return `${h} год`;
  return `${Math.floor(h / 24)} дн`;
};

const formatTime = (d) => d ? new Date(d).toLocaleTimeString('uk-UA', { hour: '2-digit', minute:'2-digit' }) : '';
const formatDateTime = (d) => d ? new Date(d).toLocaleString('uk-UA', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
}) : '';

const declension = (number, titles) => {  
    const cases = [2, 0, 1, 1, 1, 2];  
    return titles[(number % 100 > 4 && number % 100 < 20) ? 2 : cases[(number % 10 < 5) ? number % 10 : 5]];  
}

const normalizePhone = (value = '') => String(value).replace(/\D+/g, '');

const formatPhoneDisplay = (value = '') => {
  const raw = String(value).trim();
  const digits = normalizePhone(raw);

  if (digits.length === 12 && digits.startsWith('380')) {
    return `+${digits.slice(0, 2)} (${digits.slice(2, 5)}) ${digits.slice(5, 8)}-${digits.slice(8, 10)}-${digits.slice(10, 12)}`;
  }

  if (digits.length === 10 && digits.startsWith('0')) {
    return `+38 (${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6, 8)}-${digits.slice(8, 10)}`;
  }

  return raw;
};

const orderContactName = (order) => {
  const customerName = [
    order?.customer?.first_name,
    order?.customer?.last_name,
  ].filter(Boolean).join(' ').trim();

  return (
    order?.delivery?.recipient_name ||
    customerName ||
    order?.customer?.full_name ||
    ''
  );
};

const orderContactPhone = (order) => (
  order?.delivery?.recipient_phone ||
  order?.customer?.phone ||
  order?.phone ||
  ''
);

const hasOrderContact = (order) => Boolean(orderContactName(order) || orderContactPhone(order));

const orderLocation = (order) => {
  const parts = [
    order?.delivery?.city_name,
    order?.delivery?.warehouse_name,
  ].filter(Boolean);

  return parts.length ? parts.join(' • ') : 'Місто не вказано';
};

const normalizeImageUrl = (raw) => {
  if (!raw) return '';
  if (raw.startsWith('http') || raw.startsWith('/')) return raw;
  const clean = raw.replace(/^\/+/, '');
  return clean.startsWith('storage/') ? `/${clean}` : `/storage/${clean}`;
};

const itemThumbs = (items = []) => (Array.isArray(items) ? items : []).slice(0, 3).map(i => ({
  title: itemTitle(i),
  src: normalizeImageUrl(
    i?.product?.main_photo_url || i?.product?.main_photo_path || i?.photo_url || i?.image_path || i?.photo || ''
  ),
}));

const thumbStyle = (i) => i.src
  ? { backgroundImage: `url(${i.src})`, backgroundSize: 'cover' }
  : { backgroundColor: '#e2e8f0' };

const itemTitle = (item) => item?.product_title || item?.product?.title || 'Товар';
const itemColor = (item) => item?.color || item?.product?.color?.name || '—';
const itemSize = (item) => item?.size || item?.variant?.size || '—';
const itemSku = (item) => item?.sku || item?.variant?.sku || item?.product?.sku || '—';
const itemType = (item) => item?.variant?.title || item?.product?.category?.name || item?.product?.type || item?.type || '—';
const itemQty = (item) => Number(item?.qty || 1);
const orderGoodsSummary = (order) => {
  const first = order.items?.[0];
  if (!first) return '';
  const details = [itemTitle(first), itemColor(first), itemSize(first)].filter(value => value !== '—');
  details.push(`${itemQty(first)} шт.`);
  if (order.items.length > 1) details.push(`ще ${order.items.length - 1}`);
  return details.join(' · ');
};
const itemQtyPairs = (item) => {
  const qty = itemQty(item);
  return `${qty} ${declension(qty, ['пара', 'пари', 'пар'])}`;
};
const itemImage = (item) => normalizeImageUrl(
  item?.product?.main_photo_url ||
  item?.product?.main_photo_path ||
  item?.photo_url ||
  item?.image_path ||
  item?.photo ||
  ''
);

onMounted(() => {
  fetchAll();
  setupAutoRefresh();
});

onUnmounted(() => {
  if (refreshInterval) clearInterval(refreshInterval);
});
</script>

<style scoped>
/* Компактне оформлення списку пакування. */
.packing-shell {
  --packing-bg: #f3f5f8;
  --packing-paper: #fff;
  --packing-text: #202b3d;
  --packing-muted: #637187;
  --packing-line: #dfe5ed;
  --packing-amber: #a95c04;
  --packing-mark: #e6a130;
  background: var(--packing-bg);
  min-height: 100vh;
  color: var(--packing-text);
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
  font-size: 0.875rem;
  padding-bottom: 2rem;
}
.main-content { max-width: 1400px; margin: 0 auto; padding: 1.25rem; }
.packing-shell button:disabled { opacity: .46; cursor: not-allowed; }
.packing-shell button:focus-visible { outline: 2px solid #2563eb; outline-offset: 3px; }
.stats-overview { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; margin-bottom: .875rem; }
.stat-card-modern {
  display: flex; align-items: center; justify-content: space-between; gap: .75rem;
  padding: 1rem; background: var(--packing-paper); border: 1px solid var(--packing-line); border-radius: 12px;
}
.stat-info { min-width: 0; }
.stat-label { color: var(--packing-muted); font-size: .7rem; font-weight: 600; letter-spacing: .035em; text-transform: uppercase; }
.stat-value { margin-top: .375rem; font-size: 1.875rem; font-weight: 600; line-height: 1.1; font-variant-numeric: tabular-nums; }
.stat-bg-icon { display: grid; place-items: center; flex: none; width: 38px; height: 38px; border-radius: 10px; background: #eef2f6; font-size: 1rem; }
.stat-card-modern.success .stat-value, .stat-card-modern.success .stat-bg-icon { color: #147659; }
.stat-card-modern.primary .stat-value { color: #2563eb; }
.urgent-count { margin-top: .375rem; color: #b91c1c; font-size: .75rem; font-weight: 600; }
.btn-sewing-summary {
  display: inline-flex; align-items: center; justify-content: center; gap: .5rem; flex: none;
  min-height: 42px; padding: .5rem .625rem; border: 1px solid #d3e1f8; border-radius: 8px;
  background: #eff5ff; color: #2563eb; font-size: .75rem; font-weight: 600; cursor: pointer;
}
.btn-sewing-summary:hover { background: #dbeafe; }
.control-panel { display: grid; gap: .75rem; padding: .875rem; border: 1px solid var(--packing-line); border-radius: 12px; background: var(--packing-paper); }
.control-left, .search-wrapper { min-width: 0; }
.control-fields { display: flex; align-items: center; gap: .625rem; flex-wrap: wrap; }
.search-wrapper { flex: 1; }
.search-input-wrap { position: relative; }
.search-input-wrap > .bi-search { position: absolute; top: 50%; left: 12px; transform: translateY(-50%); color: var(--packing-muted); }
.search-wrapper input {
  width: 100%; min-width: 0; min-height: 42px; padding: .625rem 2.5rem .625rem 2.25rem;
  border: 1px solid var(--packing-line); border-radius: 8px; background: var(--packing-paper); color: var(--packing-text); font-size: .875rem;
}
.search-wrapper input::placeholder { color: var(--packing-muted); }
.search-wrapper input:focus { outline: 2px solid #2563eb; outline-offset: 2px; }
.search-clear {
  position: absolute; right: 3px; top: 50%; transform: translateY(-50%);
  display: grid; place-items: center; width: 36px; height: 36px; border: 0; border-radius: 6px; color: var(--packing-muted); background: var(--packing-paper);
}
.search-clear:hover { background: #eef2f6; }
.settings-group { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; padding-left: .5rem; }
.auto-refresh-switch { position: relative; display: flex; align-items: center; gap: 7px; min-height: 42px; font-size: .75rem; color: var(--packing-muted); cursor: pointer; }
.auto-refresh-switch input { position: absolute; width: 1px; height: 1px; opacity: 0; }
.switch-slider { position: relative; width: 32px; height: 18px; border-radius: 18px; background: #94a3b8; transition: background .2s; }
.switch-slider::before { content: ''; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%; background: #fff; transition: transform .2s; }
.auto-refresh-switch input:checked + .switch-slider { background: #2563eb; }
.auto-refresh-switch input:checked + .switch-slider::before { transform: translateX(14px); }
.auto-refresh-switch input:focus-visible + .switch-slider { outline: 2px solid #2563eb; outline-offset: 3px; }
.switch-state { font-size: .75rem; color: var(--packing-muted); }
.switch-state.active { color: #147659; }
.actions-group { display: flex; gap: .625rem; flex-wrap: wrap; }
.btn-refresh { display: grid; place-items: center; flex: none; width: 42px; height: 42px; border: 1px solid var(--packing-line); border-radius: 8px; background: var(--packing-paper); color: var(--packing-muted); font-size: 1.1rem; }
.btn-refresh:hover:not(:disabled) { background: #eef2f6; }
.btn-main-action, .btn-deferred-action {
  display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
  min-height: 42px; padding: .5625rem .875rem; border: 1px solid var(--packing-text); border-radius: 8px;
  background: var(--packing-text); color: #fff; font-size: .875rem; font-weight: 600; line-height: 1.4;
}
.btn-main-action:hover:not(:disabled) { background: #334155; }
.btn-deferred-action { border-color: var(--packing-mark); background: #fff8e8; color: var(--packing-amber); }
.btn-deferred-action:hover:not(:disabled) { background: #ffedc6; }
.queue-count, .deferred-count { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; min-height: 22px; padding: 0 .3rem; border-radius: 5px; font-size: .75rem; font-variant-numeric: tabular-nums; }
.queue-count { background: #ffffff26; }
.deferred-count { background: #ffe4a5; }
.orders-list-heading { display: flex; justify-content: space-between; flex-wrap: wrap; gap: .5rem; margin: 1rem .125rem .5rem; font-size: .75rem; color: var(--packing-muted); }
.order-row-modern { position: relative; margin-bottom: .5625rem; border: 1px solid var(--packing-line); border-left: 3px solid var(--packing-line); border-radius: 10px; background: var(--packing-paper); }
.order-row-modern:hover { box-shadow: 0 2px 7px #202b3d0a; }
.order-row-modern.is-skipped { border-left-color: var(--packing-mark); }
.order-row-modern.is-packed { border-left-color: #52a88a; background: #f6fbf8; }
.priority-strip { position: absolute; left: -3px; top: 8px; bottom: 8px; width: 3px; background: #dc2626; border-radius: 3px; }
.order-main-content {
  display: grid; grid-template-columns: minmax(0, 1fr) 110px minmax(130px, 180px) 120px;
  grid-template-areas: 'identity preview status actions'; align-items: center; gap: .875rem; padding: 1rem;
}
.order-identity { grid-area: identity; min-width: 0; }
.order-id { font-size: 1rem; font-weight: 600; color: var(--packing-text); overflow-wrap: anywhere; }
.identity-top { flex-wrap: wrap; }
.order-goods-summary { margin: .25rem 0; font-size: .875rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.order-sub { display: flex; align-items: center; min-width: 0; font-size: .75rem; color: var(--packing-muted); }
.order-sub i { flex: none; margin-right: 5px; }
.order-sub-text { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.order-contact-compact { margin-top: .2rem; color: var(--packing-muted); font-size: .75rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.contact-separator { margin: 0 .28rem; }
.badge-status { display: inline-flex; align-items: center; gap: 5px; padding: .2rem .4rem; border-radius: 5px; font-size: .6875rem; font-weight: 500; }
.badge-status.packed { background: #e1f3e9; color: #166534; }
.badge-status.pending { background: #eef2f6; color: var(--packing-muted); }
.order-items-preview { grid-area: preview; display: flex; flex-direction: column; align-items: center; justify-content: center; min-width: 0; }
.thumb-stack { display: flex; align-items: center; height: 56px; }
.avatar { display: grid; place-items: center; flex: none; width: 56px; height: 56px; margin-left: -28px; border: 3px solid #fff; border-radius: 14px; background-position: center; background-color: #eef2f6; color: var(--packing-muted); box-shadow: 0 2px 7px #202b3d12; }
.avatar:first-child { margin-left: 0; }
.avatar-more { display: grid; place-items: center; width: 34px; height: 34px; margin-left: -28px; z-index: 1; border: 2px solid #fff; border-radius: 8px; background: #eef2f6; font-size: .75rem; color: var(--packing-muted); }
.items-count-label { margin-top: 6px; color: var(--packing-muted); font-size: .75rem; }
.order-timing { grid-area: status; min-width: 0; }
.timing-label { margin-bottom: 5px; color: var(--packing-muted); font-size: .6875rem; font-weight: 500; letter-spacing: .03em; text-transform: uppercase; }
.timing-val { font-size: .875rem; font-weight: 500; }
.text-skipped { color: var(--packing-amber); }
.text-waiting { color: var(--packing-muted); }
.text-success { color: #147659; }
.order-actions { grid-area: actions; min-width: 0; }
.btn-action-primary, .btn-action-secondary { display: inline-flex; align-items: center; justify-content: center; width: 100%; min-height: 42px; padding: .5625rem .75rem; border: 1px solid var(--packing-text); border-radius: 8px; background: var(--packing-text); color: #fff; font-size: .875rem; font-weight: 600; }
.btn-action-primary:hover:not(:disabled) { background: #334155; }
.btn-action-secondary { min-height: 34px; padding: .4375rem .5rem; border-color: transparent; background: transparent; color: var(--packing-muted); font-size: .75rem; font-weight: 500; }
.btn-action-secondary:hover { background: #eef2f6; color: var(--packing-text); }
.queue-actions { display: flex; flex-direction: column; gap: 3px; }
.stamp-done { color: #10b981; font-size: 2.5rem; text-align: center; opacity: 0.5; }
.spin { animation: rotation 1s infinite linear; }
@keyframes rotation { from {transform: rotate(0deg);} to {transform: rotate(359deg);} }
.empty-state-modern { text-align: center; padding: 4rem 0; color: #94a3b8; }
.empty-icon { font-size: 4rem; margin-bottom: 1rem; opacity: 0.3; }
.stagger-enter-active, .stagger-leave-active { transition: all 0.3s ease; }
.stagger-enter-from, .stagger-leave-to { opacity: 0; transform: translateY(15px); }

/* Modal */
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.packing-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  z-index: 1000;
}

.packing-modal {
  width: min(1180px, 100%);
  background: #fff;
  border-radius: 20px;
  box-shadow: 0 25px 60px rgba(15, 23, 42, 0.2);
  border: 1px solid #e2e8f0;
  overflow: hidden;
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid #e2e8f0;
  background: #f8fafc;
}
.modal-title { font-size: 1.1rem; font-weight: 800; color: #0f172a; }
.modal-subtitle { font-size: 0.85rem; color: #64748b; margin-top: 0.15rem; }
.modal-close {
  border: none;
  background: #fff;
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #475569;
}
.modal-close:hover { background: #f1f5f9; }

.modal-body { padding: 1.25rem; }
.details-layout {
  display: grid;
  grid-template-columns: minmax(300px, 340px) minmax(0, 1fr);
  gap: 1rem;
}
.details-side {
  border: 1px solid #e2e8f0;
  border-radius: 18px;
  padding: 1rem;
  background: linear-gradient(180deg, #fffbeb 0%, #ffffff 58%);
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
}
.side-details {
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  background: #fff;
  padding: 0.7rem 0.8rem;
}
.side-detail-row {
  display: flex;
  flex-direction: column;
  gap: 0.18rem;
  padding: 0.45rem 0;
  border-bottom: 1px dashed #e2e8f0;
}
.side-detail-row:last-child {
  border-bottom: none;
}
.side-detail-row span {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #94a3b8;
  font-weight: 700;
}
.side-detail-row strong {
  font-size: 0.92rem;
  color: #0f172a;
  word-break: break-word;
}
.details-main {
  border: 1px solid #e2e8f0;
  border-radius: 18px;
  background: #fff;
  padding: 1rem;
}
.details-main-head {
  margin-bottom: 0.9rem;
}
.details-main-title {
  font-size: 1rem;
  font-weight: 900;
  color: #0f172a;
}
.details-main-subtitle {
  font-size: 0.82rem;
  color: #64748b;
  margin-top: 0.15rem;
}
.product-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-height: 60vh;
  overflow: auto;
  padding-right: 0.2rem;
}
.product-card {
  display: grid;
  grid-template-columns: 116px 1fr auto;
  gap: 0.8rem;
  align-items: center;
  border: 1px solid #f59e0b;
  border-radius: 16px;
  background: #fffdfa;
  padding: 0.7rem;
}
.product-photo {
  width: 116px;
  height: 116px;
  border-radius: 14px;
  overflow: hidden;
  background: #e2e8f0;
  border: 1px solid #cbd5e1;
}
.product-photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.product-photo-empty {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #94a3b8;
  font-size: 1.5rem;
}
.product-title {
  font-size: 1.1rem;
  font-weight: 900;
  color: #0f172a;
  line-height: 1.2;
}
.product-specs {
  margin-top: 0.35rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 0.6rem;
  font-size: 0.86rem;
  color: #475569;
}
.product-specs span {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  padding: 0.23rem 0.55rem;
}
.product-qty {
  min-width: 106px;
  text-align: center;
  border-radius: 999px;
  padding: 0.58rem 0.8rem;
  background: #f59e0b;
  color: #fff;
  font-size: 1rem;
  font-weight: 900;
  box-shadow: 0 8px 16px -10px rgba(217, 119, 6, 0.65);
}
.modal-empty {
  color: #94a3b8;
  font-size: 0.94rem;
  text-align: center;
  padding: 1rem 0;
}
.sewing-modal {
  width: min(960px, 100%);
}
.sewing-list {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  max-height: 65vh;
  overflow: auto;
  padding-right: 0.2rem;
}
.sewing-row {
  display: grid;
  grid-template-columns: 84px minmax(0, 1fr) auto;
  gap: 0.85rem;
  align-items: center;
  border: 1px solid #dbeafe;
  border-radius: 16px;
  background: #f8fbff;
  padding: 0.75rem;
}
.sewing-photo {
  width: 84px;
  height: 84px;
  border-radius: 14px;
  overflow: hidden;
  background: #e2e8f0;
  border: 1px solid #cbd5e1;
}
.sewing-photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.sewing-title {
  font-size: 1rem;
  font-weight: 900;
  color: #0f172a;
  line-height: 1.2;
}
.sewing-specs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 0.5rem;
  margin-top: 0.4rem;
  font-size: 0.84rem;
  color: #475569;
}
.sewing-specs span {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  padding: 0.22rem 0.52rem;
}
.sewing-qty {
  min-width: 118px;
  text-align: center;
  border-radius: 999px;
  background: #2563eb;
  color: #fff;
  padding: 0.62rem 0.8rem;
  font-size: 1rem;
  font-weight: 900;
  box-shadow: 0 8px 16px -10px rgba(37, 99, 235, 0.7);
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  padding: 1rem 1.5rem 1.5rem;
  border-top: 1px solid #e2e8f0;
  background: #f8fafc;
}
.btn-modal-secondary,
.btn-modal-primary {
  border-radius: 12px;
  padding: 0.65rem 1.2rem;
  font-weight: 700;
  border: 1px solid transparent;
  cursor: pointer;
}
.btn-modal-secondary {
  background: #fff;
  border-color: #cbd5e1;
  color: #0f172a;
}
.btn-modal-secondary:hover { background: #f1f5f9; }
.btn-modal-primary {
  background: #0f172a;
  color: #fff;
}
.btn-modal-primary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

@media (max-width: 1080px) {
  .details-layout {
    grid-template-columns: 1fr;
  }
  .details-side {
    order: 2;
  }
  .details-main {
    order: 1;
  }
}
@media (max-width: 900px) {
  .product-card {
    grid-template-columns: 96px 1fr;
    grid-template-rows: auto auto;
    align-items: start;
  }
  .product-photo {
    width: 96px;
    height: 96px;
  }
  .product-qty {
    grid-column: 1 / -1;
    justify-self: start;
  }
  .sewing-row {
    grid-template-columns: 76px 1fr;
  }
  .sewing-photo {
    width: 76px;
    height: 76px;
  }
  .sewing-qty {
    grid-column: 1 / -1;
    justify-self: start;
  }
}

/* На широкому екрані вільна колонка відділяє фото й статус від кнопок. */
@media (min-width: 1000px) {
  .order-main-content {
    grid-template-columns: minmax(0, 1.6fr) 110px 180px minmax(32px, .65fr) 120px;
    grid-template-areas: 'identity preview status . actions';
  }
}

/* На планшеті статус і фото залишаються поруч із дією. */
@media (max-width: 760px) {
  .order-main-content { grid-template-columns: 110px minmax(0, 1fr) 120px; grid-template-areas: 'identity identity identity' 'preview status actions'; gap: .75rem; }
  .order-items-preview { justify-self: start; }
  .settings-group { flex-basis: 100%; padding-left: 0; }
}
@media (max-width: 600px) {
  .stats-overview { grid-template-columns: minmax(0, 1fr); gap: .5625rem; }
}
@media (max-width: 480px) {
  .main-content { padding: .625rem; }
  .stat-card-modern { padding: .8125rem .875rem; }
  .order-main-content { grid-template-columns: 110px minmax(0, 1fr); grid-template-areas: 'identity identity' 'preview status' 'actions actions'; padding: .875rem; }
  .queue-actions { flex-direction: row; align-items: center; gap: .5rem; }
  .btn-action-primary, .btn-action-secondary { width: auto; }
  .actions-group > button { flex: 1 1 220px; }
  .search-wrapper input { font-size: 1rem; }
  .order-contact-compact { white-space: normal; overflow-wrap: anywhere; }
}
@media (pointer: coarse) {
  .packing-shell button, .auto-refresh-switch { min-height: 44px; }
  .search-clear, .btn-refresh { min-width: 44px; }
  .search-wrapper input { min-height: 48px; padding-right: 3rem; }
}
@media (prefers-reduced-motion: reduce) {
  .stagger-enter-active, .stagger-leave-active, .fade-enter-active, .fade-leave-active { transition: none; }
  .switch-slider, .switch-slider::before { transition: none; }
  .spin { animation: none; }
}
@media (max-width: 640px) {
  .details-main { padding: 0.8rem; }
  .details-side { padding: 0.8rem; }
  .product-card {
    grid-template-columns: 1fr;
    gap: 0.55rem;
  }
  .product-photo {
    width: 100%;
    max-width: 180px;
    height: 180px;
  }
  .product-qty {
    width: 100%;
    justify-self: stretch;
  }
  .sewing-row {
    grid-template-columns: 1fr;
    gap: 0.55rem;
  }
  .sewing-photo {
    width: 100%;
    max-width: 160px;
    height: 160px;
  }
  .sewing-qty {
    width: 100%;
    justify-self: stretch;
  }
  .modal-actions {
    padding: 0.9rem 1rem 1.1rem;
  }
}
</style>
