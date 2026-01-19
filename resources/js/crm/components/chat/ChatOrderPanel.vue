<template>
  <teleport to="body">
    <transition name="fade-overlay">
      <div v-show="open" class="ultra-backdrop" @click="handleMinimize"></div>
    </transition>

    <transition name="slide-premium-elastic">
      <div v-show="open" class="order-panel-elite">
        
        <header class="elite-header">
          <div class="header-left-group">
            <div class="status-icon-glow" :class="{ 'has-items': hasDraft }">
              <i class="bi bi-bag-check-fill"></i>
            </div>
            <div class="title-stack">
              <h3>Замовлення</h3>
              <div class="draft-badge" v-if="hasDraft">
                <span class="pulse-dot"></span> Чернетка
              </div>
            </div>
          </div>
          
          <div class="header-right-group">
             <button class="action-circle-btn" @click="handleMinimize" title="Згорнути"><i class="bi bi-fullscreen-exit"></i></button>
             <button class="action-circle-btn delete" @click="handleClose" title="Видалити"><i class="bi bi-trash3"></i></button>
          </div>
        </header>

        <div class="panel-scroller custom-scrollbar">

          <div v-if="isSuccess" class="order-success-view">
            <div class="success-icon">
              <i class="bi bi-check-circle-fill"></i>
            </div>
            <h4>Замовлення оформлено!</h4>
            <div class="success-meta">
              <div>Номер: <strong>{{ submitState.orderNumber || submitState.orderId }}</strong></div>
              <div>Сума: <strong>{{ formatMoney(submitState.totalAmount || totalAmount) }} ₴</strong></div>
            </div>
            <div class="success-actions">
              <button class="btn-success-secondary" type="button" @click="goToOrder">Детальніше</button>
              <button class="btn-success-primary" type="button" @click="handleCloseSuccess">Закрити</button>
            </div>
          </div>
          
          <transition v-else name="zoom-in">
            <div v-if="!hasDraft" class="empty-state-card" @click="openPicker">
               <div class="animated-blob">
                 <i class="bi bi-plus-lg"></i>
               </div>
               <h4>Додати товари</h4>
               <p>Натисніть для вибору моделей</p>
            </div>
          </transition>

          <div v-if="hasDraft && !isSuccess" class="order-flow-container">
            
            <section class="flow-section">
              <div class="section-heading">
                <span>ВИБРАНІ МОДЕЛІ</span>
                <button class="add-more-btn-outlined" @click="openPicker">
                  <i class="bi bi-plus-circle"></i> Додати ще
                </button>
              </div>

              <div class="cart-items-list">
                <div v-for="(item, index) in orderDraft.items" :key="item.id" class="elite-cart-card">
                  <img :src="item.image" class="card-img" alt="p" />
                  <div class="card-main">
                    <div class="card-header-row">
                      <span class="p-title">{{ item.title }}</span>
                      <button class="btn-delete-accessible" @click="removeSingleItem(index)">
                        <i class="bi bi-x-lg"></i>
                      </button>
                    </div>
                    <div class="card-controls-row">
                      <div class="mini-stepper">
                        <button @click="item.qty > 1 ? item.qty-- : null" :disabled="item.qty <= 1">−</button>
                        <span class="qty">{{ item.qty }}</span>
                        <button @click="item.qty++">+</button>
                      </div>
                      <div class="p-price-total">{{ formatMoney(item.price * item.qty) }} <b>грн</b></div>
                    </div>
                  </div>
                </div>
              </div>
            </section>

            <section class="flow-section">
               <div class="section-heading">ДАНІ ДОСТАВКИ</div>
               <div class="delivery-trigger-card" @click="openDeliveryModal">
                  <div class="delivery-icon">
                    <i class="bi bi-truck"></i>
                  </div>
                  <div class="delivery-info">
                    <span class="delivery-label-red">Нова Пошта</span>
                    
                    <div v-if="orderDraft.delivery && orderDraft.delivery.city_name" class="delivery-details-stack">
                      <div class="delivery-row city-text">{{ orderDraft.delivery.city_name }}</div>
                      <div class="delivery-row point-text">
                        {{ orderDraft.delivery.delivery_type === 'courier' 
                           ? formatCourierAddress(orderDraft.delivery) 
                           : orderDraft.delivery.warehouse_name }}
                      </div>
                      <div class="delivery-row payer-text">
                        Платник: <span>{{ orderDraft.delivery.payer === 'sender' ? 'Відправник' : 'Отримувач' }}</span>
                      </div>
                    </div>
                    
                    <span v-else class="delivery-placeholder">Оберіть місто та відділення...</span>
                  </div>
                  <i class="bi bi-chevron-right arrow-icon"></i>
               </div>
            </section>

            <section class="flow-section">
               <div class="section-heading">ОПЛАТА</div>
               <div class="delivery-trigger-card" @click="openPaymentModal">
                  <div class="delivery-icon">
                    <i class="bi bi-cash-stack"></i>
                  </div>
                  <div class="delivery-info">
                    <span class="delivery-label-red" style="color: #10b981;">Оплата</span>

                    <div v-if="hasPayment" class="delivery-details-stack">
                      <div class="delivery-row city-text">{{ paymentMethodLabel }}</div>
                      <div class="delivery-row point-text">{{ paymentDetail }}</div>
                    </div>

                    <span v-else class="delivery-placeholder">Оберіть спосіб оплати...</span>
                  </div>
                  <i class="bi bi-chevron-right arrow-icon"></i>
               </div>
            </section>

          </div>
        </div>

        <footer class="elite-footer">
          <div class="summary-box">
             <div class="summary-info">
                <span>До сплати:</span>
                <div class="total-amount-glow">{{ formatMoney(totalAmount) }} ₴</div>
             </div>
             <button class="btn-checkout-premium" :disabled="!canSubmit || isSubmitting || isSuccess" @click="handleSubmit">
               <span v-if="!isSubmitting">ПІДТВЕРДИТИ</span>
               <span v-else class="btn-spinner"></span>
               <div class="shimmer-line"></div>
             </button>
          </div>
        </footer>

      </div>
    </transition>

    <transition name="modal-spring">
        <div v-if="productPickerOpen" class="picker-overlay-elite" @click.self="productPickerOpen = false">
        <div class="picker-window">
          <div class="picker-header-refined">
             <div class="picker-title-group">
                <h4>Каталог продукції</h4>
             </div>
             <div class="picker-search">
               <i class="bi bi-search"></i>
               <input
                 v-model="productSearch"
                 type="text"
                 placeholder="Пошук за назвою або артикулом..."
               />
             </div>
             <button class="close-picker-btn" @click="productPickerOpen = false"><i class="bi bi-x-lg"></i></button>
          </div>
          <div class="picker-body-list custom-scrollbar">
             <div v-if="productsLoading" class="picker-loading">
               <div class="spinner-border text-primary"></div>
               <span>Завантаження каталогу...</span>
             </div>
             <div v-else-if="!products.length" class="picker-empty">
               Нічого не знайдено
             </div>
             <div v-else v-for="p in products" :key="p.id" 
                  class="picker-row-modern" 
                  :class="{ active: selectedProductIds.includes(p.id) }"
                  @click="toggleProductSelection(p)">
                <div class="cb-modern" :class="{ checked: selectedProductIds.includes(p.id) }">
                   <i class="bi bi-check-lg" v-if="selectedProductIds.includes(p.id)"></i>
                </div>
                <img :src="p.main_photo_url || placeholderImage" class="p-img-refined" />
                <div class="p-data">
                   <div class="p-row">
                      <span class="p-name">{{ p.title }}</span>
                      <span class="p-price">{{ formatMoney(p.sale_price) }} ₴</span>
                   </div>
                   <div class="p-meta">
                     <span class="p-sku">{{ p.sku || '—' }}</span>
                     <span class="p-stock" :class="{ 'low-stock': isLowStock(p) }">
                       {{ p.stock_qty ?? 0 }} шт.
                     </span>
                   </div>
                </div>
             </div>
          </div>
          <div class="picker-footer-refined">
             <button class="btn-cancel-light" @click="productPickerOpen = false">Скасувати</button>
             <button class="btn-add-highlight" :disabled="!selectedProductIds.length" @click="handleAddProducts">
               Додати ({{ selectedProductIds.length }})
             </button>
          </div>
        </div>
      </div>
    </transition>

    <ChatOrderDeliveryModal
      :open="deliveryModalOpen"
      v-model="orderDraft.delivery"
      @close="deliveryModalOpen = false"
    />

    <ChatOrderPaymentModal
      :open="paymentModalOpen"
      v-model="orderDraft.payment"
      @close="paymentModalOpen = false"
    />
  </teleport>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import ChatOrderDeliveryModal from '@/crm/components/chat/ChatOrderDeliveryModal.vue';
import ChatOrderPaymentModal from '@/crm/components/chat/ChatOrderPaymentModal.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  customer: { type: Object, default: null },
  orderDraft: { type: Object, required: true },
  submitState: { type: Object, default: () => ({ status: 'idle' }) },
});

const emit = defineEmits(['close', 'submit', 'minimize', 'close-success']);

const productPickerOpen = ref(false);
const selectedProductIds = ref([]);
const deliveryModalOpen = ref(false);
const paymentModalOpen = ref(false);
const productSearch = ref('');
const products = ref([]);
const productsLoading = ref(false);
const placeholderImage = 'https://via.placeholder.com/96x96?text=%20';
let searchTimer = null;

const fetchProducts = async (query = '') => {
  productsLoading.value = true;
  try {
    const { data } = await axios.get('/products', { params: query ? { q: query } : {} });
    products.value = Array.isArray(data) ? data : [];
  } catch (e) {
    console.error('Product fetch failed', e);
    products.value = [];
  } finally {
    productsLoading.value = false;
  }
};

watch(productSearch, (val) => {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    fetchProducts(val.trim());
  }, 400);
});

const hasDraft = computed(() => props.orderDraft.items?.length > 0);
const isSubmitting = computed(() => props.submitState?.status === 'loading');
const isSuccess = computed(() => props.submitState?.status === 'success');
const canSubmit = computed(() => {
  if (!props.orderDraft.items?.length) return false;
  const delivery = props.orderDraft.delivery || {};
  if (!props.orderDraft.payment?.method) return false;
  if (!delivery.delivery_type) return false;
  if (!delivery.city_ref) return false;
  if (delivery.delivery_type === 'warehouse' && !delivery.warehouse_ref) return false;
  return true;
});
const totalAmount = computed(() => (props.orderDraft.items || []).reduce((sum, item) => sum + (item.price * item.qty), 0));
const hasPayment = computed(() => !!props.orderDraft.payment?.method);

const paymentMeta = {
  cod: { label: 'Накладений платіж', desc: 'Оплата при отриманні' },
  card: { label: 'На рахунок IBAN', desc: 'Повна передоплата' },
  prepay: { label: 'Часткова передоплата', desc: 'Аванс + накладений' },
};

const paymentMethodLabel = computed(() => paymentMeta[props.orderDraft.payment?.method]?.label || '');
const paymentDetail = computed(() => {
  const method = props.orderDraft.payment?.method;
  if (!method) return '';
  if (method === 'prepay') {
    return `Сума авансу: ${formatMoney(props.orderDraft.payment?.prepay_amount)} грн`;
  }
  return paymentMeta[method]?.desc || '';
});

const formatCourierAddress = (d) => [d.street_name, d.building && `буд. ${d.building}`, d.apartment && `кв. ${d.apartment}`].filter(Boolean).join(', ');

const openPicker = () => { 
  selectedProductIds.value = props.orderDraft.items.map(item => item.id); 
  productPickerOpen.value = true; 
  if (!products.value.length) fetchProducts('');
};

const toggleProductSelection = (p) => { 
  const i = selectedProductIds.value.indexOf(p.id); 
  if (i > -1) selectedProductIds.value.splice(i, 1); 
  else selectedProductIds.value.push(p.id); 
};

const handleAddProducts = () => {
  const newItems = products.value.filter(p => selectedProductIds.value.includes(p.id)).map(p => {
    const existing = props.orderDraft.items.find(item => item.id === p.id);
    const price = Number(p.sale_price || 0);
    return {
      id: p.id,
      product_id: p.id,
      title: p.title,
      sku: p.sku,
      price,
      qty: existing ? existing.qty : 1,
      total: price * (existing ? existing.qty : 1),
      image: p.main_photo_url || '',
    };
  });
  // Пряма мутація масиву для реактивності
  props.orderDraft.items.splice(0, props.orderDraft.items.length, ...newItems);
  productPickerOpen.value = false;
};

const removeSingleItem = (index) => { props.orderDraft.items.splice(index, 1); };
const openDeliveryModal = () => { deliveryModalOpen.value = true; };
const openPaymentModal = () => { paymentModalOpen.value = true; };
const handleClose = () => { emit('close'); };
const handleMinimize = () => { emit('minimize'); };
const handleSubmit = () => { if (canSubmit.value) emit('submit'); };
const handleCloseSuccess = () => { emit('close-success'); };
const goToOrder = () => {
  const id = props.submitState?.orderId || props.submitState?.orderNumber;
  if (id) window.location.href = `/orders/${id}`;
};
const formatMoney = (v) => Number(v || 0).toFixed(2);
const isLowStock = (p) => {
  const stock = Number(p.stock_qty ?? 0);
  const min = Number(p.min_stock ?? 0);
  if (stock <= 0) return true;
  if (min > 0 && stock <= min) return true;
  return false;
};
</script>

<style scoped>
/* Додані стилі для 3-рядкового виводу */
.delivery-details-stack {
  display: flex;
  flex-direction: column;
  margin-top: 4px;
}
.delivery-row {
  line-height: 1.2;
}
.city-text {
  font-size: 14px;
  font-weight: 800;
  color: #1e293b;
}
.point-text {
  font-size: 12px;
  color: #64748b;
  font-weight: 600;
}
.payer-text {
  font-size: 11px;
  color: #94a3b8;
  font-weight: 700;
  text-transform: uppercase;
  margin-top: 2px;
}
.payer-text span {
  color: #a78bfb;
}

/* Решта стилів з Ultra V2 */
.ultra-backdrop { position: fixed; inset: 0; background: rgba(10, 15, 30, 0.4); z-index: 99998; backdrop-filter: blur(12px); }
.order-panel-elite { position: fixed; top: 0; right: 0; width: 480px; max-width: 100%; height: 100vh; background: #ffffff; z-index: 99999; display: flex; flex-direction: column; box-shadow: -20px 0 80px rgba(0, 0, 0, 0.2); }
.elite-header { padding: 20px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
.header-left-group { display: flex; align-items: center; gap: 14px; }
.status-icon-glow { width: 44px; height: 44px; background: #f1f5f9; color: #cbd5e1; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
.status-icon-glow.has-items { background: #f5f3ff; color: #a78bfb; box-shadow: 0 0 15px rgba(167, 139, 251, 0.3); }
.title-stack h3 { font-size: 17px; font-weight: 800; color: #0f172a; margin: 0; }
.draft-badge { display: flex; align-items: center; gap: 5px; font-size: 10px; font-weight: 800; color: #10b981; text-transform: uppercase; background: #ecfdf5; padding: 2px 8px; border-radius: 20px; margin-top: 3px; }
.pulse-dot { width: 6px; height: 6px; background: #10b981; border-radius: 50%; animation: pulse-mini 2s infinite; }
@keyframes pulse-mini { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
.header-right-group { display: flex; gap: 8px; }
.action-circle-btn { width: 34px; height: 34px; border-radius: 50%; border: none; background: #f8fafc; color: #94a3b8; cursor: pointer; transition: 0.3s; }
.action-circle-btn:hover { background: #f1f5f9; color: #1e293b; transform: rotate(15deg); }
.action-circle-btn.delete:hover { background: #fef2f2; color: #ef4444; }
.panel-scroller { flex: 1; padding: 24px; overflow-y: auto; background: #fafafa; }
.empty-state-card { background: #fff; border: 2px dashed #cbd5e1; border-radius: 24px; padding: 50px 30px; text-align: center; cursor: pointer; transition: 0.4s; }
.empty-state-card:hover { border-color: #a78bfb; transform: translateY(-3px); }
.animated-blob { width: 56px; height: 56px; background: #f5f3ff; color: #a78bfb; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 24px; }
.section-heading { font-size: 11px; font-weight: 800; color: #94a3b8; letter-spacing: 0.08em; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; }
.add-more-btn-outlined { background: #f5f3ff; border: 1.5px solid #a78bfb; color: #a78bfb; font-weight: 800; cursor: pointer; font-size: 11px; padding: 6px 12px; border-radius: 10px; display: flex; align-items: center; gap: 6px; transition: 0.3s; }
.add-more-btn-outlined:hover { background: #a78bfb; color: #fff; }
.elite-cart-card { background: #fff; border-radius: 16px; padding: 12px; display: flex; gap: 12px; margin-bottom: 10px; border: 1px solid #f1f5f9; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
.card-img { width: 52px; height: 52px; border-radius: 10px; object-fit: cover; background: #f8fafc; }
.card-main { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
.card-header-row { display: flex; justify-content: space-between; align-items: center; }
.p-title { font-size: 13px; font-weight: 700; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.btn-delete-accessible { background: #fff; border: 1.5px solid #fecaca; color: #ef4444; width: 28px; height: 28px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; font-size: 14px; }
.btn-delete-accessible:hover { background: #ef4444; color: #fff; border-color: #ef4444; }
.card-controls-row { display: flex; justify-content: space-between; align-items: center; margin-top: 5px; }
.mini-stepper { display: flex; align-items: center; background: #f8fafc; border-radius: 8px; height: 24px; border: 1px solid #edf2f7; }
.mini-stepper button { width: 24px; border: none; background: transparent; cursor: pointer; color: #64748b; font-weight: bold; }
.qty { width: 24px; text-align: center; font-size: 11px; font-weight: 800; }
.p-price-total { font-size: 14px; font-weight: 800; color: #0f172a; }
.p-price-total b { font-size: 10px; opacity: 0.5; }
.delivery-trigger-card { background: rgba(167, 139, 251, 0.08); border: 1.5px solid rgba(167, 139, 251, 0.2); border-radius: 18px; padding: 16px; display: flex; align-items: center; gap: 14px; cursor: pointer; transition: 0.3s; }
.delivery-trigger-card:hover { background: rgba(167, 139, 251, 0.15); border-color: #a78bfb; }
.delivery-icon { width: 40px; height: 40px; background: #fff; color: #a78bfb; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
.delivery-info { display: flex; flex-direction: column; flex: 1; }
.delivery-label-red { font-size: 13px; font-weight: 900; color: #dc2626; text-transform: uppercase; letter-spacing: 0.02em; }
.delivery-placeholder { font-size: 12px; color: #7c3aed; opacity: 0.8; font-weight: 600; margin-top: 2px; }
.arrow-icon { color: #a78bfb; opacity: 0.5; font-size: 18px; }
.elite-footer { padding: 20px 24px; border-top: 1px solid #f1f5f9; background: #fff; }
.summary-box { background: #0f172a; border-radius: 20px; padding: 10px 10px 10px 20px; display: flex; align-items: center; justify-content: space-between; }
.summary-info span { font-size: 10px; color: #94a3b8; font-weight: 700; text-transform: uppercase; }
.total-amount-glow { color: #fff; font-size: 20px; font-weight: 900; }
.btn-checkout-premium { background: #a78bfb; border: none; border-radius: 16px; height: 48px; padding: 0 20px; color: #fff; font-weight: 900; font-size: 13px; cursor: pointer; position: relative; overflow: hidden; transition: 0.3s; }
.btn-checkout-premium:hover { background: #9061f9; transform: translateY(-2px); }
.btn-spinner { width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.35); border-top-color: #fff; border-radius: 50%; display: inline-block; animation: spin 0.8s linear infinite; }

.order-success-view { background: #ffffff; border-radius: 24px; padding: 40px 24px; text-align: center; border: 1px solid #f1f5f9; box-shadow: 0 10px 20px rgba(15, 23, 42, 0.06); }
.order-success-view h4 { margin: 14px 0 10px; font-size: 18px; font-weight: 800; color: #0f172a; }
.order-success-view .success-icon { width: 58px; height: 58px; border-radius: 50%; background: #ecfdf5; color: #10b981; display: inline-flex; align-items: center; justify-content: center; font-size: 28px; }
.order-success-view .success-meta { margin-top: 10px; color: #475569; font-size: 14px; display: flex; flex-direction: column; gap: 6px; }
.order-success-view .success-actions { margin-top: 18px; display: flex; gap: 10px; justify-content: center; }
.btn-success-primary { background: #10b981; color: #fff; border: none; height: 40px; border-radius: 12px; padding: 0 16px; font-weight: 800; cursor: pointer; }
.btn-success-secondary { background: #f1f5f9; color: #0f172a; border: none; height: 40px; border-radius: 12px; padding: 0 16px; font-weight: 800; cursor: pointer; }

/* PRODUCT MODAL */
.picker-overlay-elite { position: fixed; inset: 0; background: rgba(5, 10, 20, 0.6); z-index: 100000; backdrop-filter: blur(8px); display: flex; align-items: center; justify-content: center; }
.picker-window { background: #fff; width: 560px; max-width: 95%; height: 80vh; border-radius: 30px; display: flex; flex-direction: column; overflow: hidden; }
.picker-header-refined { padding: 20px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
.picker-title-group h4 { font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; }
.picker-search { flex: 1; margin: 0 16px; position: relative; }
.picker-search i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
.picker-search input { width: 100%; height: 38px; border-radius: 12px; border: 1px solid #e2e8f0; padding: 0 12px 0 34px; font-size: 13px; outline: none; }
.picker-body-list { flex: 1; overflow-y: auto; }
.picker-loading, .picker-empty { padding: 24px; text-align: center; color: #94a3b8; display: flex; flex-direction: column; gap: 8px; align-items: center; }
.picker-row-modern { display: flex; align-items: center; padding: 14px 24px; cursor: pointer; border-bottom: 1px solid #f8fafc; transition: 0.2s; gap: 16px; }
.cb-modern { width: 20px; height: 20px; border: 2px solid #cbd5e1; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #fff; }
.picker-row-modern.active .cb-modern { background: #a78bfb; border-color: #a78bfb; }
.p-img-refined { width: 48px; height: 48px; border-radius: 10px; object-fit: cover; }
.p-data { flex: 1; min-width: 0; }
.p-row { display: flex; justify-content: space-between; align-items: center; }
.p-name { font-size: 14px; font-weight: 700; color: #1e293b; }
.p-price { font-size: 15px; font-weight: 800; color: #0f172a; }
.p-meta { display: flex; justify-content: space-between; font-size: 11px; color: #94a3b8; margin-top: 4px; }
.p-stock.low-stock { color: #ef4444; font-weight: 700; }
.picker-footer-refined { padding: 16px 24px; border-top: 1px solid #f1f5f9; display: flex; gap: 12px; background: #fff; }
.btn-cancel-light { flex: 1; border: 1.5px solid #edf2f7; background: #fff; height: 46px; border-radius: 14px; color: #64748b; font-weight: 700; cursor: pointer; }
.btn-add-highlight { flex: 2; background: #a78bfb; border: none; height: 46px; border-radius: 14px; color: #fff; font-weight: 800; cursor: pointer; }
.btn-add-highlight:disabled { opacity: 0.6; cursor: not-allowed; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
