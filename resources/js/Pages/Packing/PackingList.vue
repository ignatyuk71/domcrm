<template>
  <div class="packing-shell">
    <main class="main-content">
      
      <!-- Статистика зверху -->
      <section class="stats-overview mb-4">
        <div class="stat-card-modern success">
          <div class="stat-info">
            <div class="stat-label">Всього запаковано</div>
            <div class="stat-value">{{ historyOrders.length }}</div>
          </div>
          <div class="stat-bg-icon"><i class="bi bi-box-seam"></i></div>
        </div>
        
        <div class="stat-card-modern primary">
          <div class="stat-info">
            <div class="stat-label">Залишилось у черзі</div>
            <div class="stat-value">{{ pendingOrdersCount }}</div>
          </div>
          <div class="stat-bg-icon"><i class="bi bi-list-task"></i></div>
        </div>
        
        <div class="stat-card-modern danger" v-if="urgentCount > 0">
          <div class="stat-info">
            <div class="stat-label">Терміново</div>
            <div class="stat-value">{{ urgentCount }}</div>
          </div>
          <div class="stat-bg-icon pulse-icon"><i class="bi bi-exclamation-circle"></i></div>
        </div>
      </section>

      <!-- Панель керування (Пошук + Оновлення) -->
      <div class="control-panel mb-4">
        <div class="d-flex align-items-center gap-3 flex-wrap control-left">
          
          <div class="search-wrapper">
            <i class="bi bi-search"></i>
            <input 
              v-model="searchQuery" 
              type="text" 
              placeholder="Номер замовлення або місто..."
            />
          </div>

          <div class="settings-group">
             <label class="auto-refresh-switch" title="Автоматично оновлювати список кожні 30 сек">
               <input type="checkbox" v-model="autoRefreshEnabled">
               <span class="switch-slider"></span>
               <span class="switch-label">Авто-оновлення</span>
             </label>
          </div>
        </div>

        <div class="actions-group">
          <button class="btn-refresh" @click="refreshData" :disabled="loading" title="Оновити дані вручну">
            <i class="bi bi-arrow-repeat" :class="{ 'spin': loading }"></i>
          </button>
          
          <button class="btn-main-action" :disabled="pendingOrdersCount === 0" @click="startPackingFirst">
            <span class="btn-content">
              <i class="bi bi-play-fill"></i> Почати пакувати
            </span>
          </button>
        </div>
      </div>

      <!-- Список замовлень -->
      <div class="orders-container">
        <TransitionGroup name="stagger">
          <div
            v-for="(order, index) in filteredOrders"
            :key="order.id"
            class="order-row-modern"
            :class="{
              'is-priority': order.is_priority && isPending(order),
              'is-processing': isProcessing(order),
              'is-packed': isPacked(order)
            }"
          >
            <!-- Червона смужка для пріоритетних -->
            <div v-if="order.is_priority && !isPacked(order)" class="priority-strip"></div>

            <div class="order-main-content">
              
              <!-- ID та Місто -->
              <div class="order-identity">
                <div class="d-flex align-items-center gap-2 mb-1 identity-top">
                  <span class="order-id">#{{ order.order_number }}</span>
                  
                  <span v-if="isProcessing(order)" class="badge-status processing">
                    <i class="bi bi-lightning-fill"></i> У роботі
                  </span>
                  <span v-else-if="isPacked(order)" class="badge-status packed">
                    <i class="bi bi-check-lg"></i> Запаковано
                  </span>
                  <span v-else class="badge-status pending">Черга</span>
                </div>
                <div class="order-sub">
                   <i class="bi bi-geo-alt-fill"></i> {{ order.delivery?.city_name || 'Місто не вказано' }}
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
                  ></div>
                  <div v-if="order.items?.length > 3" class="avatar-more">+{{ order.items.length - 3 }}</div>
                </div>
                <div class="items-count-label">
                  {{ order.items?.length }} {{ declension(order.items?.length, ['товар', 'товари', 'товарів']) }}
                </div>
              </div>

              <!-- Час / Статус -->
              <div class="order-timing">
                <div class="timing-label">Статус</div>
                <div class="timing-val">
                  <span v-if="isPacked(order)" class="text-success">
                    <i class="bi bi-clock-history"></i> {{ formatTime(order.packed_at) }}
                  </span>
                  <span v-else-if="isProcessing(order)" class="text-processing">
                    Пакується
                    <span v-if="order.packer?.name">• {{ order.packer.name }}</span>
                    <span v-if="order.active_packing_session?.started_at">• {{ formatTime(order.active_packing_session.started_at) }}</span>
                  </span>
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
                <div v-else-if="isProcessing(order)" class="processing-actions">
                  <button class="btn-action-primary continue" @click="startPacking(order.id)">
                    Продовжити <i class="bi bi-arrow-right"></i>
                  </button>
                  <button v-if="order.can_release" class="btn-action-link" @click.stop="releasePacking(order)">
                    <i class="bi bi-unlock me-1"></i> Забрати
                  </button>
                </div>
                <button v-else class="btn-action-primary" @click="startPacking(order.id)">
                  Пакувати
                </button>
              </div>
            </div>
          </div>
        </TransitionGroup>

        <!-- Порожній стан -->
        <div v-if="!filteredOrders.length && !loading" class="empty-state-modern">
          <div class="empty-icon"><i class="bi bi-inbox"></i></div>
          <h3>Список порожній</h3>
          <p>Немає жодного замовлення.</p>
        </div>
      </div>
    </main>

    <Transition name="fade">
      <div v-if="showDetailsModal" class="packing-modal-overlay" @click.self="closeDetails">
        <div class="packing-modal">
          <div class="modal-header">
            <div>
              <div class="modal-title">Запаковане замовлення</div>
              <div class="modal-subtitle">
                №{{ selectedOrderNumber }}
                <span v-if="selectedPackedAt">• {{ formatDateTime(selectedPackedAt) }}</span>
              </div>
            </div>
            <button class="modal-close" @click="closeDetails" aria-label="Закрити">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <div class="modal-body">
            <div class="modal-grid">
              <div class="modal-section">
                <div class="section-title">Доставка</div>
                <div class="detail-row">
                  <span class="detail-label">Пакувальник</span>
                  <span class="detail-value">{{ selectedPackerName }}</span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Час пакування</span>
                  <span class="detail-value">{{ selectedPackedAt ? formatDateTime(selectedPackedAt) : '—' }}</span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">ТТН</span>
                  <span class="detail-value">{{ selectedDelivery.ttn || '—' }}</span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Служба</span>
                  <span class="detail-value">{{ selectedDeliveryService }}</span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Одержувач</span>
                  <span class="detail-value">{{ selectedRecipient }}</span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Телефон</span>
                  <span class="detail-value">{{ selectedRecipientPhone }}</span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Місто</span>
                  <span class="detail-value">{{ selectedDelivery.city_name || '—' }}</span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Відділення</span>
                  <span class="detail-value">{{ selectedDelivery.warehouse_name || '—' }}</span>
                </div>
              </div>

              <div class="modal-section">
                <div class="section-title">Товари</div>
                <div class="modal-items">
                  <div v-for="(item, idx) in selectedItems" :key="idx" class="modal-item">
                    <div class="modal-item-thumb" :style="itemThumbStyle(item)"></div>
                    <div class="modal-item-info">
                      <div class="modal-item-title">{{ itemTitle(item) }}</div>
                      <div class="modal-item-meta">
                        <span>Колір: {{ itemColor(item) }}</span>
                        <span>Розмір: {{ itemSize(item) }}</span>
                        <span>SKU: {{ itemSku(item) }}</span>
                      </div>
                    </div>
                    <div class="modal-item-qty">x{{ itemQty(item) }}</div>
                  </div>
                  <div v-if="!selectedItems.length" class="modal-empty">Товари відсутні</div>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-actions">
            <button class="btn-modal-secondary" @click="closeDetails">Закрити</button>
            <button
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
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import axios from 'axios';

// --- State ---
const orders = ref([]);
const historyOrders = ref([]); // Запаковані сьогодні (але ще не відправлені)
const loading = ref(true);
const searchQuery = ref('');
const autoRefreshEnabled = ref(true);
const showDetailsModal = ref(false);
const selectedOrder = ref(null);
const printingSelected = ref(false);

let refreshInterval = null;

// --- Helpers ---
// Логіка статусів: 
// Pending = 4 (Упакування)
// Processing = 'processing' (Локальний статус блокування)
// Packed = 12 (Запаковано)
const isPending = (o) => o.packing_status === 'pending' || (!o.packing_status && o.status_id !== 12); 
const isProcessing = (o) => o.packing_status === 'processing';
const isPacked = (o) => o.packing_status === 'packed' || !!o.packed_at || o.status_id === 12;

// Шукаємо замовлення, яке я вже почав, але не закінчив
const myActiveOrder = computed(() => orders.value.find(o => isProcessing(o)));

const pendingOrdersCount = computed(() => orders.value.filter(o => isPending(o)).length);
const urgentCount = computed(() => orders.value.filter(o => o.is_priority && isPending(o)).length);
const selectedItems = computed(() => Array.isArray(selectedOrder.value?.items) ? selectedOrder.value.items : []);
const selectedDelivery = computed(() => selectedOrder.value?.delivery || {});
const selectedOrderNumber = computed(() => selectedOrder.value?.order_number || selectedOrder.value?.id || '—');
const selectedPackedAt = computed(() => selectedOrder.value?.packed_at || selectedOrder.value?.updated_at || null);
const selectedCustomer = computed(() => selectedOrder.value?.customer || {});
const selectedPackerName = computed(() => selectedOrder.value?.packer?.name || '—');
const selectedDeliveryService = computed(() => (
  selectedDelivery.value?.carrier ||
  selectedDelivery.value?.delivery_type ||
  selectedDelivery.value?.service_type ||
  '—'
));
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
      if (isPacked(o)) return 3;
      return 4;
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

const startPacking = async (id) => {
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
  }
};

const startPackingFirst = () => {
  const next = myActiveOrder.value || filteredOrders.value.find(o => isPending(o));
  if (next) startPacking(next.id);
};

const releasePacking = async (order) => {
  if (!order?.id) return;
  const confirmed = confirm('Зняти пакувальника і повернути замовлення в чергу?');
  if (!confirmed) return;

  try {
    const { data } = await axios.post(`/packing/${order.id}/release`);
    if (data?.success) {
      refreshData();
    }
  } catch (err) {
    alert(err.response?.data?.error || 'Помилка розблокування');
  }
};

const openDetails = (order) => {
  selectedOrder.value = order;
  showDetailsModal.value = true;
};

const closeDetails = () => {
  showDetailsModal.value = false;
  selectedOrder.value = null;
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

const normalizeImageUrl = (raw) => {
  if (!raw) return '';
  if (raw.startsWith('http') || raw.startsWith('/')) return raw;
  const clean = raw.replace(/^\/+/, '');
  return clean.startsWith('storage/') ? `/${clean}` : `/storage/${clean}`;
};

const itemThumbs = (items = []) => items.slice(0, 3).map(i => ({
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
const itemQty = (item) => Number(item?.qty || 1);
const itemImage = (item) => normalizeImageUrl(
  item?.product?.main_photo_url ||
  item?.product?.main_photo_path ||
  item?.photo_url ||
  item?.image_path ||
  item?.photo ||
  ''
);
const itemThumbStyle = (item) => {
  const src = itemImage(item);
  return src ? { backgroundImage: `url(${src})` } : {};
};

onMounted(() => {
  fetchAll();
  setupAutoRefresh();
});

onUnmounted(() => {
  if (refreshInterval) clearInterval(refreshInterval);
});
</script>

<style scoped>
/* --- Global --- */
.packing-shell {
  background-color: #f1f5f9;
  min-height: 100vh;
  color: #1e293b;
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
  padding-bottom: 3rem;
}

.main-content {
  max-width: 1400px;
  margin: 0 auto;
  padding: 1.5rem;
}

/* --- Stats --- */
.stats-overview {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 1.25rem;
}
.stat-card-modern {
  background: #fff;
  padding: 1.5rem;
  border-radius: 16px;
  display: flex; justify-content: space-between; align-items: center;
  position: relative; overflow: hidden;
  border: 1px solid #e2e8f0;
  box-shadow: 0 2px 4px -1px rgba(0,0,0,0.06);
}
.stat-info { z-index: 2; position: relative; }
.stat-label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em; }
.stat-value { font-size: 2.25rem; font-weight: 800; line-height: 1.1; margin-top: 0.25rem; }
.stat-bg-icon { font-size: 3.5rem; opacity: 0.1; position: absolute; right: 10px; bottom: -5px; transform: rotate(-15deg); z-index: 1; }
.stat-card-modern.success .stat-value, .stat-card-modern.success .stat-bg-icon { color: #059669; }
.stat-card-modern.primary .stat-value, .stat-card-modern.primary .stat-bg-icon { color: #2563eb; }
.stat-card-modern.danger .stat-value { color: #dc2626; }
.stat-card-modern.danger .stat-bg-icon { color: #dc2626; opacity: 0.15; }
.pulse-icon { animation: pulse 2s infinite; }
@keyframes pulse { 0% { transform: scale(1) rotate(-15deg); } 50% { transform: scale(1.1) rotate(-15deg); } 100% { transform: scale(1) rotate(-15deg); } }

/* --- Control Panel --- */
.control-panel {
  background: #fff; padding: 1rem 1.5rem; border-radius: 16px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
  border: 1px solid #e2e8f0;
  display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;
}
.control-left { flex-grow: 1; }
.search-wrapper { position: relative; flex-grow: 1; max-width: 350px; }
.search-wrapper i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
.search-wrapper input {
  width: 100%; border: 1px solid #94a3b8; padding: 0.7rem 1rem 0.7rem 2.8rem;
  border-radius: 12px; font-size: 0.95rem; outline: none; transition: 0.2s; background: #f8fafc;
}
.search-wrapper input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }

/* Settings Switch */
.settings-group { margin-left: 1rem; }
.auto-refresh-switch { display: flex; align-items: center; cursor: pointer; gap: 8px; font-size: 0.85rem; color: #475569; font-weight: 600; }
.auto-refresh-switch input { display: none; }
.switch-slider {
  width: 36px; height: 20px; background: #cbd5e1; border-radius: 20px; position: relative; transition: 0.3s;
}
.switch-slider::before {
  content: ''; position: absolute; width: 16px; height: 16px; background: #fff;
  border-radius: 50%; top: 2px; left: 2px; transition: 0.3s; box-shadow: 0 1px 2px rgba(0,0,0,0.2);
}
.auto-refresh-switch input:checked + .switch-slider { background: #3b82f6; }
.auto-refresh-switch input:checked + .switch-slider::before { transform: translateX(16px); }

/* Actions */
.actions-group { display: flex; align-items: center; gap: 0.75rem; }
.btn-refresh {
  background: #fff; border: 1px solid #cbd5e1; width: 46px; height: 46px;
  border-radius: 12px; color: #64748b; transition: 0.2s; cursor: pointer;
  display: flex; align-items: center; justify-content: center; font-size: 1.2rem;
}
.btn-refresh:hover { background: #f1f5f9; color: #334155; }
.btn-main-action {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  color: #fff; border: none; padding: 0 1.75rem; height: 46px;
  border-radius: 12px; font-weight: 700; font-size: 1rem;
  box-shadow: 0 4px 6px -1px rgba(217, 119, 6, 0.3); transition: 0.2s; cursor: pointer;
}
.btn-main-action:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 8px 15px -3px rgba(217, 119, 6, 0.4); }
.btn-main-action:disabled { opacity: 0.7; cursor: not-allowed; filter: grayscale(1); background: #cbd5e1; box-shadow: none; }

/* --- Orders --- */
.order-row-modern {
  background: #fff; border-radius: 16px; margin-bottom: 1rem;
  border: 1px solid #e2e8f0; position: relative; overflow: hidden;
  transition: 0.25s; box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
.order-row-modern:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.06); border-color: #cbd5e1; }
.order-row-modern.is-packed { background: #f0fdf4; border-color: #86efac; opacity: 0.95; }
.order-row-modern.is-processing { border: 2px solid #f59e0b; background: #fff; box-shadow: 0 4px 20px rgba(245, 158, 11, 0.15); z-index: 10; }
.priority-strip { position: absolute; left: 0; top: 0; bottom: 0; width: 6px; background: #ef4444; z-index: 10; }

.order-main-content {
  display: grid; grid-template-columns: minmax(220px, 260px) 1fr minmax(140px, auto) 170px;
  align-items: center; padding: 1.25rem 1.5rem; gap: 1.5rem;
}
.order-id { font-size: 1.35rem; font-weight: 800; color: #1e293b; letter-spacing: -0.02em; }
.order-sub { font-size: 0.9rem; color: #64748b; font-weight: 500; display: flex; align-items: center; }
.order-sub i { margin-right: 6px; color: #94a3b8; }
.badge-status {
  font-size: 0.7rem; font-weight: 800; padding: 0.35rem 0.65rem;
  border-radius: 8px; text-transform: uppercase; display: inline-flex; align-items: center; gap: 5px; margin-left: auto;
}
.badge-status.processing { background: #fffbeb; color: #b45309; border: 1px solid #fcd34d; }
.badge-status.packed { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.badge-status.pending { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }

.order-items-preview { display: flex; flex-direction: column; justify-content: center; }
.thumb-stack { display: flex; align-items: center; height: 50px; }
.avatar {
  width: 48px; height: 48px; border-radius: 12px; border: 2px solid #fff;
  margin-left: -14px; background-position: center; background-color: #f1f5f9;
  box-shadow: 0 2px 5px rgba(0,0,0,0.08); transition: 0.2s;
}
.avatar:first-child { margin-left: 0; }
.thumb-stack:hover .avatar { margin-left: -5px; transform: scale(1.05); }
.avatar-more {
  width: 40px; height: 40px; border-radius: 10px; background: #f1f5f9;
  border: 2px solid #fff; margin-left: -10px; display: flex; align-items: center; justify-content: center;
  font-size: 0.8rem; font-weight: 700; color: #64748b; z-index: 5;
}
.items-count-label { font-size: 0.8rem; color: #94a3b8; margin-top: 6px; font-weight: 500; }
.opacity-50 { opacity: 0.6; filter: grayscale(0.5); }

.timing-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: #94a3b8; margin-bottom: 2px; }
.timing-val { font-weight: 600; font-size: 0.95rem; }
.text-processing { color: #d97706; font-weight: 700; }
.text-waiting { color: #64748b; }
.text-success { color: #059669; }

.btn-action-primary {
  background: #1e293b; color: #fff; border: none; padding: 0.75rem 1rem;
  border-radius: 12px; font-weight: 700; font-size: 0.95rem; transition: 0.2s; width: 100%; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}
.btn-action-primary:hover { background: #0f172a; transform: translateY(-1px); box-shadow: 0 5px 12px rgba(0,0,0,0.15); }
.btn-action-primary.continue { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
.btn-action-secondary {
  background: #fff;
  color: #0f172a;
  border: 1px solid #cbd5e1;
  padding: 0.7rem 1rem;
  border-radius: 12px;
  font-weight: 700;
  font-size: 0.95rem;
  width: 100%;
  cursor: pointer;
  transition: 0.2s;
  box-shadow: 0 2px 5px rgba(0,0,0,0.08);
}
.btn-action-secondary:hover { background: #f8fafc; border-color: #94a3b8; }
.stamp-done { color: #10b981; font-size: 2.5rem; text-align: center; opacity: 0.5; }
.processing-actions { display: flex; flex-direction: column; gap: 0.4rem; }
.btn-action-link {
  border: none;
  background: transparent;
  color: #b45309;
  font-weight: 700;
  font-size: 0.85rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.btn-action-link:hover { color: #92400e; text-decoration: underline; }

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
  width: min(980px, 100%);
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

.modal-body { padding: 1.5rem; }
.modal-grid {
  display: grid;
  grid-template-columns: minmax(260px, 1fr) minmax(320px, 1.6fr);
  gap: 1.5rem;
}
.modal-section {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 1rem;
}
.section-title {
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #64748b;
  font-weight: 700;
  margin-bottom: 0.75rem;
}
.detail-row {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.35rem 0;
  border-bottom: 1px dashed #e2e8f0;
}
.detail-row:last-child { border-bottom: none; }
.detail-label { color: #64748b; font-weight: 600; font-size: 0.85rem; }
.detail-value { color: #0f172a; font-weight: 700; text-align: right; }

.modal-items { display: flex; flex-direction: column; gap: 0.75rem; }
.modal-item {
  display: grid;
  grid-template-columns: 56px 1fr auto;
  gap: 0.75rem;
  align-items: center;
  padding: 0.6rem;
  border-radius: 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
}
.modal-item-thumb {
  width: 56px;
  height: 56px;
  border-radius: 12px;
  background: #e2e8f0;
  background-size: cover;
  background-position: center;
}
.modal-item-title { font-weight: 700; color: #0f172a; }
.modal-item-meta {
  font-size: 0.82rem;
  color: #64748b;
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.8rem;
}
.modal-item-qty { font-weight: 800; color: #0f172a; }
.modal-empty { color: #94a3b8; font-size: 0.9rem; text-align: center; padding: 0.5rem 0; }

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

@media (max-width: 900px) {
  .modal-grid { grid-template-columns: 1fr; }
}

/* Mobile */
@media (max-width: 1024px) {
  .order-main-content { grid-template-columns: 1fr 1fr; gap: 1rem; }
  .order-actions { grid-column: 2; grid-row: 1 / span 2; display: flex; flex-direction: column; justify-content: center; }
}
@media (max-width: 640px) {
  .order-main-content { grid-template-columns: 1fr; text-align: center; padding: 1rem; }
  .order-identity { justify-content: center; flex-direction: column; align-items: center; }
  .identity-top { width: 100%; justify-content: space-between; }
  .badge-status { position: relative; margin: 0; }
  .order-items-preview, .thumb-stack { align-items: center; justify-content: center; }
  .order-actions { grid-column: 1; grid-row: auto; }
  .priority-strip { width: 100%; height: 4px; bottom: auto; top: 0; left: 0; }
  .control-panel { flex-direction: column; align-items: stretch; }
  .control-left { flex-direction: column; align-items: stretch; gap: 1rem; }
  .search-wrapper, .actions-group { width: 100%; max-width: none; }
  .settings-group { margin: 0; justify-content: space-between; }
  .btn-main-action { flex: 1; justify-content: center; }
}
</style>
