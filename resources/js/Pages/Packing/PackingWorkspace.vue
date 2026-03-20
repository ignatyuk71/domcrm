<template>
  <div class="packing-app">
    <!-- Верхня навігація (Оптимізована) -->
    <nav class="navbar modern-navbar">
      <div class="container-fluid d-flex justify-content-between align-items-center px-lg-4 position-relative">
        
        <!-- Лого + Кнопка Назад (Ліва частина) -->
        <div class="d-flex align-items-center gap-3">
          <a href="/packing/list" class="brand-logo-modern text-decoration-none" title="Повернутися до списку">
            <i class="bi bi-chevron-left"></i>
          </a>
          <div class="nav-divider d-none d-sm-block"></div>
          <div class="nav-context d-none d-sm-block">
            <div class="context-label">Робоче місце</div>
            <div class="context-title">Пакування</div>
          </div>
        </div>

        <!-- Центральна Інформація (Номер замовлення) -->
        <div class="nav-center-info d-none d-md-flex flex-column align-items-center">
           <div class="nav-order-num">Замовлення <span class="font-monospace">#{{ order.number }}</span></div>
           <div class="nav-order-city" v-if="order.city">
             <i class="bi bi-geo-alt-fill text-danger opacity-75"></i> {{ order.city }}
           </div>
        </div>
        
        <!-- Прогрес віджет (Лічильник товарів) -->
        <div class="progress-pill-modern" :class="{ 'is-done': isAllChecked }">
          <div class="chart-wrapper">
             <svg viewBox="0 0 36 36" class="circular-chart-sm">
               <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
               <path class="circle" :stroke-dasharray="`${packingProgress}, 100`" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
             </svg>
             <div class="check-icon-overlay" v-if="isAllChecked"><i class="bi bi-check"></i></div>
          </div>
          <div class="pill-content">
             <div class="pill-label">Зібрано позицій</div>
             <div class="pill-value">
               <span :class="{'text-success': isAllChecked}">{{ checkedCount }}</span> 
               <span class="text-muted fw-normal">/</span> 
               {{ products.length }}
             </div>
          </div>
        </div>

      </div>
    </nav>

    <div class="main-layout container-fluid">
      <div class="row h-100 g-0">
        
        <!-- ЛІВА ЧАСТИНА: Список товарів -->
        <div class="col-12 col-lg-8 product-section">
          <div class="section-header d-flex justify-content-between align-items-end">
            <div>
              <h1 class="page-heading" :class="{ 'is-done': isAllChecked }">{{ sectionHeading }}</h1>
              <div class="section-subheading" :class="{ 'is-done': isAllChecked }">{{ sectionSubtitle }}</div>
            </div>
            <!-- Додатковий прогрес бар для мобільних -->
            <div class="d-md-none fw-bold fs-5">
              {{ checkedCount }} / {{ products.length }}
            </div>
          </div>

          <!-- Прогрес бар смужка -->
          <div class="progress mt-3 mb-4" style="height: 6px; border-radius: 3px;">
            <div class="progress-bar bg-success transition-width" role="progressbar" :style="{ width: packingProgress + '%' }"></div>
          </div>

          <div class="product-grid">
            <TransitionGroup name="list">
              <div 
                v-for="prod in products" 
                :key="prod.id"
                class="modern-card"
                :class="{ 'is-completed': prod.checked }"
                @click="toggleCheck(prod)"
              >
                <div class="card-status-strip"></div>
                
                <div class="card-body d-flex align-items-center gap-4">
                  <!-- Фото -->
                  <div class="prod-img-wrapper" @click.stop="openImage(prod)">
                    <img v-if="prod.image" :src="prod.image" loading="lazy" />
                    <div v-else class="placeholder-img"><i class="bi bi-image"></i></div>
                    <div class="zoom-icon"><i class="bi bi-arrows-angle-expand"></i></div>
                  </div>

                  <!-- Інфо -->
                  <div class="prod-content flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                       <h3 class="prod-title">{{ prod.name }}</h3>
                       <span class="sku-badge">{{ prod.sku }}</span>
                    </div>
                    
                    <div class="specs-grid">
                      <div class="spec-item">
                        <span class="spec-label">Колір</span>
                        <div class="d-flex align-items-center gap-2">
                          <span class="color-dot" :class="prod.colorClass"></span>
                          <span class="spec-val">{{ prod.color }}</span>
                        </div>
                      </div>
                      <div class="spec-item">
                        <span class="spec-label">Розмір</span>
                        <span class="spec-val size-val">{{ prod.size }}</span>
                      </div>
                    </div>
                  </div>

                  <!-- Чекбокс -->
                  <div class="action-area">
                    <div class="check-circle">
                      <i class="bi bi-check-lg"></i>
                    </div>
                  </div>
                </div>
              </div>
            </TransitionGroup>
          </div>
        </div>

        <!-- ПРАВА ЧАСТИНА: Інформаційний блок -->
        <div class="col-12 col-lg-4 info-sidebar">
          <div class="sidebar-content d-flex flex-column">
            
            <div class="info-header mb-3">
              <h5 class="fw-bold m-0">Дані замовлення</h5>
            </div>

            <!-- Важливе повідомлення (Коментар) -->
            <div v-if="order.comment" class="alert alert-warning border-0 shadow-sm mb-4 d-flex gap-3 align-items-start">
              <i class="bi bi-chat-right-text-fill fs-4 mt-1"></i>
              <div>
                <div class="fw-bold text-uppercase small mb-1 opacity-75">Коментар менеджера</div>
                <div class="lh-sm fw-bold">{{ order.comment }}</div>
              </div>
            </div>

            <!-- Список даних -->
            <div class="data-list compact-list">
              
              <!-- Замовлення -->
              <div class="data-row">
                <div class="d-label">Замовлення</div>
                <div class="d-val d-flex align-items-center gap-2">
                   <span class="text-dark fw-black">№{{ order.number }}</span>
                   <span class="badge bg-warning text-dark border border-warning border-opacity-25">{{ order.deliveryService }}</span>
                </div>
              </div>

              <!-- Оплата (Нове) -->
              <div class="data-row">
                <div class="d-label">Оплата</div>
                <div class="d-val">
                   <span v-if="order.isCod" class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25">Накладений</span>
                   <span v-else class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25">Оплачено</span>
                </div>
              </div>

              <!-- ТТН -->
              <div class="data-row clickable" @click="copyText(order.ttn)" title="Копіювати">
                <div class="d-label">ТТН <i class="bi bi-copy ms-1 opacity-50"></i></div>
                <div class="d-val font-monospace">{{ order.ttn }}</div>
              </div>

              <!-- Телефон -->
              <div class="data-row clickable" @click="copyText(order.phone)" title="Копіювати">
                <div class="d-label">Телефон <i class="bi bi-copy ms-1 opacity-50"></i></div>
                <div class="d-val font-monospace">{{ order.phone }}</div>
              </div>

              <!-- Одержувач -->
              <div class="data-row">
                <div class="d-label">Одержувач</div>
                <div class="d-val text-truncate">{{ order.recipient }}</div>
              </div>

              <!-- Місто -->
              <div class="data-row">
                <div class="d-label">Місто</div>
                <div class="d-val">{{ order.city }}</div>
              </div>

              <!-- Відділення -->
              <div class="data-row border-0 pb-0">
                <div class="d-label">Відділення</div>
                <div class="d-val small lh-sm">{{ order.branch }}</div>
              </div>

            </div>

            <!-- БЛОК КНОПОК -->
            <div class="actions-footer mt-4">
               
               <!-- Кнопки: ТТН + Друк -->
               <div class="d-flex flex-column gap-2 mb-3">
                 <button 
                    class="btn-soft-massive w-100" 
                    :disabled="!isAllChecked"
                    data-bs-toggle="modal" 
                    data-bs-target="#ttnModal" 
                    @click="registerAction"
                 >
                   <div class="d-flex align-items-center gap-3 w-100 justify-content-center">
                     <i class="bi bi-qr-code-scan"></i>
                     <span>Показати ТТН</span>
                   </div>
                 </button>
                 <button 
                    class="btn-soft-massive w-100" 
                    :disabled="!isAllChecked"
                    :class="{ 'printing': isPrinting }"
                    @click="handleInvoicePrint"
                 >
                   <div class="d-flex align-items-center gap-3 w-100 justify-content-center">
                     <i class="bi" :class="isPrinting ? 'bi-hourglass-split spin' : 'bi-printer'"></i>
                     <span>{{ isPrinting ? 'Відкрити накладну' : 'Друк накладної' }}</span>
                   </div>
                 </button>
               </div>

               <!-- Основна дія -->
               <button 
                  class="btn-brand-accent w-100 mb-3"
                  :disabled="!canFinishPacking"
                  @click="finishPacking"
               >
                  <div class="btn-inner">
                    <i class="bi" :class="canFinishPacking ? 'bi-check-circle-fill' : (isAllChecked ? 'bi-printer' : 'bi-box-seam')"></i>
                    <div class="d-flex flex-column align-items-start lh-1">
                      <span class="main-text">{{ mainButtonText }}</span>
                      
                      <span class="sub-text" v-if="canFinishPacking">→ Наступне замовлення</span>
                      <span class="sub-text" v-else-if="isAllChecked">Роздрукуйте або відкрийте ТТН</span>
                      <span class="sub-text" v-else>{{ checkedCount }} з {{ products.length }} готово</span>
                    </div>
                  </div>
               </button>

               <!-- Повідомити про проблему -->
               <button class="btn-skip-massive w-100" data-bs-toggle="modal" data-bs-target="#skipModal">
                 <div class="btn-skip-inner">
                   <div class="btn-skip-icon">
                     <i class="bi bi-exclamation-octagon"></i>
                   </div>
                   <div class="btn-skip-copy">
                     <span class="skip-main-text">Повідомити про проблему</span>
                     <span class="skip-sub-text">Нема товару, брак або пересорт</span>
                   </div>
                 </div>
               </button>
            </div>

          </div>
        </div>

      </div>
    </div>

    <!-- Image Viewer -->
    <Transition name="fade">
      <div v-if="showImageModal" class="lightbox" @click="closeImage">
        <button class="close-lightbox"><i class="bi bi-x-lg"></i></button>
        <img :src="selectedImage" @click.stop />
      </div>
    </Transition>

    <!-- ===== LOADING NEXT ORDER SCREEN ===== -->
    <Transition name="fade">
      <div v-if="isLoadingNext" class="success-screen">
        <div class="success-content text-center">
          <div class="success-icon mb-4">
             <i class="bi bi-check-lg"></i>
          </div>
          <h2 class="fw-bold mb-2">Замовлення закрито!</h2>
          <p class="text-muted mb-4">Перехід до наступного замовлення...</p>
          
          <div class="loading-spinner-wrapper">
             <div class="spinner-border text-primary" role="status"></div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ===== SUCCESS SCREEN (Shift Finished) ===== -->
    <Transition name="fade">
      <div v-if="isShiftFinished" class="success-screen">
        <div class="success-content text-center">
          <div class="success-icon mb-4">
            <i class="bi bi-check-lg"></i>
          </div>
          <h1 class="display-4 fw-black mb-3">Зміна завершена! 🎉</h1>
          <p class="fs-4 text-muted mb-5">Всі замовлення успішно опрацьовано.</p>
          
          <div class="stats-card mb-5">
            <div class="stat-box">
              <div class="stat-val text-success">{{ stats.packed }}</div>
              <div class="stat-lbl">Запаковано</div>
            </div>
            <div class="stat-divider"></div>
            <div class="stat-box">
              <div class="stat-val">{{ stats.total }}</div>
              <div class="stat-lbl">Всього</div>
            </div>
          </div>

          <a href="/packing/list" class="btn btn-dark btn-lg rounded-pill px-5 py-3 fw-bold">
            <i class="bi bi-arrow-left me-2"></i> Повернутися до списку
          </a>
        </div>
        
        <!-- Confetti decoration -->
        <div class="confetti c1">🎊</div>
        <div class="confetti c2">✨</div>
        <div class="confetti c3">🎉</div>
      </div>
    </Transition>

    <!-- Modals -->
    <!-- TTN Modal -->
    <div class="modal fade" id="ttnModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
          <div class="modal-body text-center p-5 bg-white position-relative">
            <button type="button" class="btn-close position-absolute top-0 end-0 m-4" data-bs-dismiss="modal"></button>
            <h6 class="text-uppercase text-muted letter-spacing-2 mb-4 fw-bold">Сканування ТТН</h6>
            
            <div class="ttn-display-large mb-5" @click="copyText(order.ttn)">
              <span class="ttn-head">{{ ttnParts.head }}</span>
              <span class="ttn-tail">{{ ttnParts.tail }}</span>
            </div>

            <button class="btn-brand-accent w-100 py-3 rounded-4" @click="copyText(order.ttn)">
              <i class="bi bi-clipboard me-2"></i> Копіювати
            </button>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Invoice Modal -->
    <div class="modal fade" id="invoiceModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4">
           <div class="modal-header border-0 pb-0"><h5 class="fw-bold ps-3 pt-3">Накладна</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
           <div class="modal-body p-4 text-center">
             <div class="bg-light rounded-3 p-5 border border-dashed d-flex flex-column align-items-center">
               <i class="bi bi-file-pdf display-1 text-danger mb-3"></i>
               <h5>Попередній перегляд PDF</h5>
             </div>
             <button class="btn-brand-accent w-100 py-3 mt-4 rounded-4" @click="handleInvoicePrint"><i class="bi bi-printer me-2"></i>Друкувати</button>
           </div>
        </div>
      </div>
    </div>

    <!-- Skip Modal -->
    <div class="modal fade" id="skipModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 skip-modal-content">
           <div class="modal-header border-0 pb-0">
             <h5 class="fw-bold text-danger">Відкласти замовлення?</h5>
             <button class="btn-close" data-bs-dismiss="modal"></button>
           </div>
           <div class="modal-body p-4 pt-3">
             <div class="skip-modal-text">Замовлення буде відкладене.</div>
             <div class="skip-modal-hint">Ви зможете повернутися до нього пізніше.</div>
             <div class="skip-confirm-actions mt-4">
               <button class="btn-skip-cancel" data-bs-dismiss="modal">Повернутися</button>
               <button class="btn-skip-confirm" data-bs-dismiss="modal" @click="skipOrder">Відкласти</button>
             </div>
           </div>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

const props = defineProps({
  order: { type: Object, default: null }
});

const products = ref([]);
const showImageModal = ref(false);
const selectedImage = ref(null);
const hasActionTaken = ref(false); 
const isPrinting = ref(false);
const isShiftFinished = ref(false); 
const isLoadingNext = ref(false);

const stats = ref({ packed: 0, total: 0 });

const normalizeImageUrl = (raw) => {
  if (!raw) return null;
  if (raw.startsWith('http') || raw.startsWith('/')) return raw;
  const clean = raw.replace(/^\/+/, '');
  return clean.startsWith('storage/') ? `/${clean}` : `/storage/${clean}`;
};

const colorClassFor = (color) => {
  const v = (color || '').toLowerCase();
  if (v.includes('чорн') || v.includes('black')) return 'bg-dark';
  if (v.includes('біл') || v.includes('white')) return 'bg-light border';
  if (v.includes('сір') || v.includes('gray') || v.includes('grey')) return 'bg-secondary';
  if (v.includes('син') || v.includes('blue')) return 'bg-primary';
  if (v.includes('зел') || v.includes('green')) return 'bg-success';
  if (v.includes('черв') || v.includes('red')) return 'bg-danger';
  if (v.includes('жовт') || v.includes('yellow')) return 'bg-warning';
  return 'bg-secondary';
};

const buildOrderView = (src) => {
  const delivery = src?.delivery || {};
  const customer = src?.customer || {};
  const recipient = delivery.recipient_name || [customer.first_name, customer.last_name].filter(Boolean).join(' ').trim() || '—';
  const branch = delivery.warehouse_name || [delivery.street_name, delivery.building, delivery.apartment].filter(Boolean).join(', ') || '—';
  const deliveryService = delivery.carrier || delivery.delivery_type || delivery.service_type || '—';
  
  // Визначаємо тип оплати (приклад логіки)
  const paymentStatus = src?.payment_status || '';
  const isCod = !paymentStatus || paymentStatus === 'not_paid' || (src?.payment?.method === 'cod');

  return {
    id: src?.id || null,
    number: src?.order_number || '—',
    ttn: delivery.ttn || '—',
    phone: delivery.recipient_phone || customer.phone || '—',
    recipient,
    city: delivery.city_name || '—',
    branch,
    deliveryService,
    comment: src?.comment_internal || src?.comment || '', // Внутрішній коментар
    isCod: isCod // Чи це накладений платіж
  };
};

const buildProducts = (src) => {
  const items = Array.isArray(src?.items) ? src.items : [];
  const result = [];

  items.forEach((item) => {
    const product = item?.product;
    const variant = item?.variant;
    const qty = Number(item?.qty || 1);
    const baseName = product?.title || 'Товар';
    const name = qty > 1 ? `${baseName} (x${qty})` : baseName;
    const image = normalizeImageUrl(product?.main_photo_url || null);

    result.push({
      id: item?.id,
      sku: variant?.sku || '—',
      name,
      color: product?.color?.name || '—',
      size: variant?.size || '—',
      checked: false,
      colorClass: colorClassFor(product?.color?.name),
      image
    });
  });

  return result;
};

const order = computed(() => buildOrderView(props.order));

watch(
  () => props.order,
  (next) => {
    products.value = buildProducts(next);
  },
  { immediate: true }
);

// --- Computed ---
const checkedCount = computed(() => products.value.filter(p => p.checked).length);
const isAllChecked = computed(() => products.value.length > 0 && checkedCount.value === products.value.length);
const packingProgress = computed(() => {
  const total = products.value.length;
  return total ? (checkedCount.value / total) * 100 : 0;
});

const canFinishPacking = computed(() => isAllChecked.value && hasActionTaken.value);

const mainButtonText = computed(() => {
  if (!isAllChecked.value) return 'ЗБЕРІТЬ ТОВАРИ';
  if (!hasActionTaken.value) return 'РОЗДРУКУЙТЕ ТТН';
  return 'ЗАПАКОВАНО';
});

const sectionHeading = computed(() => (
  isAllChecked.value ? 'Замовлення зібране' : 'Збір замовлення'
));

const sectionSubtitle = computed(() => {
  if (!isAllChecked.value) {
    return 'Перевірте та відскануйте товари';
  }

  if (!hasActionTaken.value) {
    return 'Відкрийте або роздрукуйте ТТН, щоб завершити пакування';
  }

  return 'Усе готово для завершення і переходу до наступного замовлення';
});

const ttnParts = computed(() => {
  const t = order.value.ttn.replace(/\s/g, '');
  if (t.length <= 4) return { head: '', tail: t };
  const head = t.slice(0, -4);
  const tail = t.slice(-4);
  const formattedHead = head.replace(/(\d{4})(?=\d)/g, '$1 ');
  return { head: formattedHead, tail };
});

// --- Methods ---
const toggleCheck = (prod) => { prod.checked = !prod.checked; };
const openImage = (prod) => { if (prod.image) { selectedImage.value = prod.image; showImageModal.value = true; } };
const closeImage = () => { showImageModal.value = false; };
const formatTTN = (ttn) => ttn.replace(/(\d{4})(?=\d)/g, '$1 ');

const registerAction = () => {
  hasActionTaken.value = true;
};

const copyText = async (text) => {
  try { await navigator.clipboard.writeText(text); alert('Скопійовано!'); } catch (e) {}
};

// --- SILENT PRINT LOGIC ---
const printViaLocalAgent = async (pdfUrl) => {
  try {
    await axios.post('http://localhost:4000/print', { url: pdfUrl });
    return true;
  } catch (err) {
    return false;
  }
};

const handleInvoicePrint = async () => {
  isPrinting.value = true;
  registerAction();
  
  try {
    const orderId = order.value.id;
    if (!orderId) {
      isPrinting.value = false;
      return;
    }
    
    const { data } = await axios.get(`/orders/${orderId}/print-ttn`);
    
    if (data?.print_url) {
      const isSilent = await printViaLocalAgent(data.print_url);
      if (!isSilent) {
        window.open(data.print_url, '_blank');
      }
    } else {
      alert(data?.message || 'Немає посилання для друку');
    }
  } catch (err) {
    alert('Помилка отримання накладної');
  } finally {
    isPrinting.value = false;
  }
};

const finishPacking = async () => {
  if (!canFinishPacking.value) return;
  isLoadingNext.value = true;

  try {
    const orderId = order.value.id;
    if (!orderId) return;
    
    await axios.post(`/packing/${orderId}/finish`);
    const { data: listData } = await axios.get('/api/packing/list');
    
    const nextOrder = Array.isArray(listData) 
      ? listData.find(o => String(o.id) !== String(orderId) && (o.packing_status === 'pending' || !o.packing_status))
      : null;

    setTimeout(async () => {
        if (nextOrder) {
            window.location.href = `/packing/${nextOrder.id}`;
        } else {
            isLoadingNext.value = false;
            try {
               const { data: historyData } = await axios.get('/api/packing/history');
               const count = Array.isArray(historyData) ? historyData.length : 0;
               stats.value.packed = count;
               stats.value.total = count; 
            } catch (e) {}
            isShiftFinished.value = true;
        }
    }, 1500);

  } catch (err) {
    isLoadingNext.value = false;
    alert(err.response?.data?.error || 'Помилка завершення пакування');
  }
};

const skipOrder = async () => {
  try {
    const orderId = order.value.id;
    if (!orderId) return;
    await axios.post(`/packing/${orderId}/problem`);
    
    const { data: listData } = await axios.get('/api/packing/list');
    const nextOrder = Array.isArray(listData) 
      ? listData.find(o => String(o.id) !== String(orderId) && (o.packing_status === 'pending' || !o.packing_status))
      : null;

    if (nextOrder) {
      window.location.href = `/packing/${nextOrder.id}`;
    } else {
      window.location.href = '/packing/list';
    }
  } catch (err) {
    alert(err.response?.data?.error || 'Помилка повідомлення про проблему');
  }
};

const invoiceButtonSelector = '#invoiceModal .btn-brand-accent';
let invoiceButton = null;

onMounted(() => {
  invoiceButton = document.querySelector(invoiceButtonSelector);
  if (invoiceButton) {
    invoiceButton.addEventListener('click', handleInvoicePrint);
  }
});

onUnmounted(() => {
  if (invoiceButton) {
    invoiceButton.removeEventListener('click', handleInvoicePrint);
  }
  invoiceButton = null;
});
</script>

<style scoped>
/* --- VARIABLES --- */
.packing-app {
  --bg-body: #f6f7fb;
  --surface: #ffffff;
  --ink: #111827;
  --muted: rgba(17,24,39,.60);
  --border: rgba(17,24,39,.10);
  --accent: #ffb020;
  --accent-ink: #3a2b00;
  --mint: #27c2a0;
  --mint-light: #e0f2f1;
  --danger: #ff4d6d;
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
  background-color: var(--bg-body);
  color: var(--ink);
  min-height: 100vh;
}

/* --- SUCCESS SCREEN --- */
.success-screen {
  position: fixed; inset: 0; z-index: 2000;
  background: white;
  display: flex; align-items: center; justify-content: center;
}
.success-content { max-width: 500px; padding: 2rem; position: relative; z-index: 10; }
.success-icon {
  width: 120px; height: 120px; border-radius: 50%;
  background: var(--mint); color: white;
  display: flex; align-items: center; justify-content: center;
  font-size: 4rem; margin: 0 auto;
  box-shadow: 0 20px 40px rgba(39, 194, 160, 0.4);
  animation: popIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
@keyframes popIn { from { transform: scale(0); opacity: 0; } to { transform: scale(1); opacity: 1; } }

.stats-card {
  background: #f8fafc; border-radius: 24px; padding: 1.5rem;
  display: flex; justify-content: space-around; align-items: center;
}
.stat-box { text-align: center; }
.stat-val { font-size: 2.5rem; font-weight: 900; line-height: 1; }
.stat-lbl { text-transform: uppercase; font-size: 0.75rem; color: var(--muted); font-weight: 700; margin-top: 5px; }
.stat-divider { width: 1px; height: 40px; background: #e2e8f0; }

/* Confetti */
.confetti { position: absolute; font-size: 2rem; animation: float 3s infinite ease-in-out; }
.c1 { top: 20%; left: 20%; animation-delay: 0s; }
.c2 { top: 30%; right: 20%; animation-delay: 1s; }
.c3 { bottom: 20%; left: 50%; animation-delay: 0.5s; }
@keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-20px); } }

/* --- NAVBAR (MODERN) --- */
.modern-navbar {
  background: rgba(255, 255, 255, 0.85);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid rgba(0,0,0,0.05);
  height: 80px;
  position: sticky; top: 0; z-index: 100;
}

.brand-logo-modern {
  width: 44px; height: 44px;
  background: var(--ink);
  color: #fff;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.4rem;
  box-shadow: 0 4px 10px rgba(0,0,0,0.1);
}

.nav-divider { width: 1px; height: 24px; background: #e2e8f0; }

.context-label { font-size: 0.7rem; text-transform: uppercase; color: var(--muted); font-weight: 700; }
.context-title { font-size: 1.1rem; font-weight: 800; color: var(--ink); line-height: 1; }

.nav-center-info { position: absolute; left: 50%; transform: translateX(-50%); }
.nav-order-num { font-weight: 700; color: var(--muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
.nav-order-num span { color: var(--ink); font-weight: 900; font-size: 1rem; }
.nav-order-city { font-size: 0.8rem; color: var(--ink); font-weight: 600; opacity: 0.8; }

.progress-pill-modern {
  background: #fff;
  border: 1px solid #e2e8f0;
  padding: 6px 16px 6px 8px;
  border-radius: 99px;
  display: flex; align-items: center; gap: 10px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.02);
  transition: 0.3s;
}
.progress-pill-modern.is-done { border-color: var(--mint); background: #f0fdf4; }

.chart-wrapper { width: 32px; height: 32px; position: relative; }
.circular-chart-sm { width: 100%; height: 100%; }
.pill-content { display: flex; flex-direction: column; line-height: 1.1; }
.pill-label { font-size: 0.65rem; color: var(--muted); text-transform: uppercase; font-weight: 700; }
.pill-value { font-size: 0.9rem; font-weight: 800; color: var(--ink); }

.check-icon-overlay {
  position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
  color: var(--mint); font-size: 1.2rem;
}

.circle-bg { fill: none; stroke: #e2e8f0; stroke-width: 3.8; }
.circle { fill: none; stroke: var(--mint); stroke-width: 3.8; stroke-linecap: round; stroke-dasharray: var(--p), 100; transition: stroke-dasharray 0.5s ease; }

/* --- LAYOUT --- */
.main-layout { height: calc(100vh - 80px); }
.product-section { padding: 1.5rem 2rem; overflow-y: auto; height: 100%; }
.info-sidebar { background: var(--surface); border-left: 1px solid var(--border); height: 100%; overflow-y: auto; display: flex; flex-direction: column; }
.sidebar-content { padding: 1.5rem 2rem; height: auto; min-height: 100%; }

.compact-list { display: flex; flex-direction: column; }
.data-row { display: flex; justify-content: space-between; align-items: baseline; padding: 0.6rem 0; border-bottom: 1px solid var(--border); font-size: 0.95rem; }
.data-row.clickable { cursor: pointer; }
.data-row.clickable:hover .d-val { color: #2563eb; }

.d-label { font-size: 0.75rem; color: var(--muted); font-weight: 700; text-transform: uppercase; flex-shrink: 0; width: 35%; }
.d-val { font-weight: 700; text-align: right; color: var(--ink); flex-grow: 1; word-break: break-word; }
.font-monospace { font-family: 'SF Mono', 'Roboto Mono', monospace; letter-spacing: -0.03em; font-size: 1.05rem; }

.btn-soft-massive {
  background: white; border: 1px solid var(--border);
  color: var(--ink);
  border-radius: 14px; padding: 1rem 1.5rem; font-weight: 800; font-size: 1rem;
  display: flex; align-items: center; justify-content: center;
  transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); min-height: 60px;
}
.btn-soft-massive i { font-size: 1.4rem; color: #6b7280; }
.btn-soft-massive:hover { background: #f9fafb; transform: translateY(-2px); box-shadow: 0 8px 12px rgba(0,0,0,0.05); }
.btn-soft-massive.printing { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; cursor: wait; }
.btn-soft-massive:disabled { opacity: 0.5; background-color: #f3f4f6; cursor: not-allowed; box-shadow: none; transform: none; }

.btn-brand-accent {
  background: var(--accent); color: var(--accent-ink); border: none; border-radius: 16px; padding: 1.2rem;
  width: 100%; font-weight: 900; transition: all 0.2s; box-shadow: 0 10px 20px rgba(255, 176, 32, 0.25);
  position: relative; overflow: hidden;
}
.btn-brand-accent:disabled { background: #e5e7eb; color: #9ca3af; box-shadow: none; cursor: not-allowed; }
.btn-brand-accent:not(:disabled):hover { transform: translateY(-2px); box-shadow: 0 15px 30px rgba(255, 176, 32, 0.35); background: #ffbb33; }

.btn-inner { display: flex; align-items: center; justify-content: center; gap: 12px; }
.btn-inner i { font-size: 2rem; }
.main-text { font-size: 1.2rem; display: block; }
.sub-text { font-size: 0.8rem; font-weight: 600; opacity: 0.8; display: block; }

.btn-skip-massive {
  background: linear-gradient(135deg, #fff7ed 0%, #fff1f2 100%);
  border: 1px solid #fbcfe8;
  color: #9f1239;
  border-radius: 18px;
  padding: 1rem 1.1rem;
  font-weight: 800;
  font-size: 0.95rem;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: 0.2s;
  min-height: 72px;
  box-shadow: 0 10px 24px rgba(190, 24, 93, 0.08);
}
.btn-skip-massive:hover {
  background: linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%);
  border-color: #f9a8d4;
  transform: translateY(-2px);
  box-shadow: 0 16px 32px rgba(190, 24, 93, 0.12);
}
.btn-skip-inner {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.9rem;
  text-align: left;
}
.btn-skip-icon {
  width: 46px;
  height: 46px;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.72);
  border: 1px solid rgba(244, 114, 182, 0.22);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  color: #be123c;
  font-size: 1.25rem;
}
.btn-skip-copy {
  min-width: 0;
  display: flex;
  flex-direction: column;
  line-height: 1.05;
}
.skip-main-text {
  font-size: 1.02rem;
  font-weight: 900;
  letter-spacing: -0.01em;
}
.skip-sub-text {
  margin-top: 0.22rem;
  font-size: 0.82rem;
  font-weight: 700;
  color: rgba(159, 18, 57, 0.72);
}
.skip-modal-content {
  border-radius: 24px;
}
.skip-modal-text {
  font-size: 1.08rem;
  font-weight: 800;
  color: #1f2937;
  line-height: 1.35;
}
.skip-modal-hint {
  margin-top: 0.45rem;
  font-size: 0.95rem;
  font-weight: 600;
  color: #6b7280;
  line-height: 1.45;
}
.skip-confirm-actions {
  display: flex;
  gap: 0.75rem;
}
.skip-confirm-actions > * {
  flex: 1 1 0;
}
.btn-skip-cancel {
  min-height: 60px;
  border-radius: 14px;
  border: 1px solid #dbe3ee;
  background: #fff;
  color: #475569;
  font-weight: 800;
  transition: 0.2s;
}
.btn-skip-cancel:hover {
  background: #f8fafc;
  border-color: #cbd5e1;
}
.btn-skip-confirm {
  min-height: 60px;
  border-radius: 14px;
  border: 1px solid #e11d48;
  background: #e11d48;
  color: #fff;
  font-weight: 800;
  transition: 0.2s;
}
.btn-skip-confirm:hover {
  background: #be123c;
  border-color: #be123c;
}

.product-grid { display: flex; flex-direction: column; gap: 1.25rem; margin-top: 1rem; }
.page-heading {
  margin: 0;
  font-size: clamp(2.4rem, 3.2vw, 3.35rem);
  font-weight: 900;
  letter-spacing: -0.04em;
  line-height: 0.98;
  color: var(--ink);
  transition: color 0.2s ease;
}
.page-heading.is-done {
  color: #059669;
}
.section-subheading {
  margin-top: 0.65rem;
  color: var(--muted);
  font-weight: 800;
  font-size: 1.02rem;
  transition: color 0.2s ease;
}
.section-subheading.is-done {
  color: #0f766e;
}

.modern-card {
  background: var(--surface); border-radius: 20px; padding: 0; position: relative;
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
  cursor: pointer; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; border: 1px solid var(--border);
}
.modern-card:hover { transform: translateY(-3px); box-shadow: 0 12px 20px -5px rgba(0,0,0,0.08); border-color: #d1d5db; }
.modern-card.is-completed { opacity: 1; background: #f0fdf4; transform: scale(0.995); box-shadow: none; border: 1px solid #86efac; }
.modern-card.is-completed .card-status-strip { background: var(--mint); }
.modern-card.is-completed .prod-img-wrapper img { filter: grayscale(0.5); opacity: 0.9; }
.modern-card.is-completed .prod-title { color: #4b5563; text-decoration: line-through; }
.modern-card.is-completed .spec-label { color: #6b7280; }
.modern-card.is-completed .spec-val { color: #4b5563; }

.card-status-strip { position: absolute; left: 0; top: 0; bottom: 0; width: 8px; background: var(--accent); transition: 0.3s; }
.card-body { padding: 1.5rem; padding-left: 2rem; }

.prod-img-wrapper {
  width: 120px; height: 120px; border-radius: 16px; overflow: hidden; position: relative; background: #f3f4f6; flex-shrink: 0; border: 1px solid rgba(0,0,0,0.05);
}
.prod-img-wrapper img { width: 100%; height: 100%; object-fit: cover; }
.placeholder-img { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #cbd5e1; }
.zoom-icon { position: absolute; inset: 0; background: rgba(0,0,0,0.3); color: white; display: flex; align-items: center; justify-content: center; opacity: 0; transition: 0.2s; font-size: 2rem; }
.prod-img-wrapper:hover .zoom-icon { opacity: 1; }

.prod-title { font-size: 1.4rem; font-weight: 900; margin: 0; line-height: 1.2; }
.sku-badge { font-family: monospace; font-size: 0.9rem; background: #f3f4f6; padding: 4px 8px; border-radius: 6px; color: var(--muted); letter-spacing: -0.5px; }

.specs-grid { display: flex; gap: 2rem; margin-top: 1rem; }
.spec-label { font-size: 0.8rem; text-transform: uppercase; color: var(--muted); display: block; margin-bottom: 4px; font-weight: 700; }
.spec-val { font-weight: 800; font-size: 1.1rem; }
.size-val { font-size: 1.4rem; }
.color-dot { width: 16px; height: 16px; border-radius: 50%; display: inline-block; border: 1px solid rgba(0,0,0,0.1); }

.check-circle {
  width: 56px; height: 56px; border-radius: 50%; border: 4px solid #e5e7eb; color: transparent;
  display: flex; align-items: center; justify-content: center; font-size: 2rem; transition: 0.2s;
}
.modern-card.is-completed .check-circle { background: var(--mint); border-color: var(--mint); color: white; }

.lightbox {
  position: fixed; inset: 0; background: rgba(255,255,255,0.95); z-index: 2000;
  display: flex; align-items: center; justify-content: center; backdrop-filter: blur(5px);
}
.lightbox img { max-width: 90vw; max-height: 90vh; border-radius: 12px; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
.close-lightbox { position: absolute; top: 20px; right: 30px; background: none; border: none; font-size: 3rem; color: var(--ink); }

.ttn-display-large {
  font-family: 'SF Mono', 'Roboto Mono', monospace; font-weight: 900; color: var(--ink);
  display: flex; align-items: baseline; justify-content: center; gap: 15px; cursor: pointer; transition: 0.2s;
}
.ttn-display-large:hover { opacity: 0.8; }
.ttn-head { font-size: 2.5rem; opacity: 0.5; letter-spacing: -1px; }
.ttn-tail { font-size: 5rem; line-height: 1; color: var(--ink); letter-spacing: -2px; }

.list-enter-active, .list-leave-active { transition: all 0.4s ease; }
.list-enter-from, .list-leave-to { opacity: 0; transform: translateX(-20px); }
.spin { animation: spin 1s linear infinite; }
@keyframes spin { from {transform:rotate(0deg);} to {transform:rotate(360deg);} }
.transition-width { transition: width 0.3s ease-out; }

@media (max-width: 991px) {
  .main-layout .row { flex-direction: column; }
  .info-sidebar { height: auto; border-left: none; border-top: 1px solid var(--border); }
  .product-section { padding: 1rem; height: auto; overflow: visible; }
  .sidebar-content { padding: 1.5rem; }
  .navbar { padding: 0 1rem; }
  .ttn-head { font-size: 1.5rem; }
  .ttn-tail { font-size: 3rem; }
}
</style>
