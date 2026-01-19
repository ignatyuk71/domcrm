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
          
          <transition name="zoom-in">
            <div v-if="!hasDraft" class="empty-state-card" @click="openPicker">
               <div class="animated-blob">
                 <i class="bi bi-plus-lg"></i>
               </div>
               <h4>Додати товари</h4>
               <p>Натисніть для вибору моделей</p>
            </div>
          </transition>

          <div v-if="hasDraft" class="order-flow-container">
            
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
                      <button class="btn-delete-accessible" @click="removeSingleItem(index)" title="Видалити товар">
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
               <div class="delivery-trigger-card payment-block-accent" @click="openPaymentModal">
                  <div class="delivery-icon payment-green-icon">
                    <i class="bi bi-credit-card-2-back"></i>
                  </div>
                  <div class="delivery-info">
                    <span class="delivery-label-green">Фінанси</span>
                    
                    <div v-if="orderDraft.payment?.method" class="delivery-details-stack">
                      <div class="delivery-row city-text">{{ getPaymentLabel(orderDraft.payment.method) }}</div>
                      <div v-if="orderDraft.payment.method === 'prepay'" class="delivery-row point-text">
                        Сума авансу: {{ orderDraft.payment.prepay_amount }} грн
                      </div>
                      <div class="delivery-row payer-text" v-else>Повна сума до сплати</div>
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
             <button class="btn-checkout-premium" :disabled="!hasDraft" @click="handleSaved">
               <span>ПІДТВЕРДИТИ</span>
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
             <button class="close-picker-btn" @click="productPickerOpen = false"><i class="bi bi-x-lg"></i></button>
          </div>

          <div class="picker-search-bar">
             <i class="bi bi-search"></i>
             <input type="text" v-model="searchQuery" placeholder="Пошук товару..." />
          </div>

          <div class="picker-body-list custom-scrollbar">
             <div v-for="p in mockProducts" :key="p.id" 
                  class="picker-row-modern" 
                  :class="{ active: selectedProductIds.includes(p.id) }"
                  @click="toggleProductSelection(p)">
                
                <div class="cb-modern" :class="{ checked: selectedProductIds.includes(p.id) }">
                   <i class="bi bi-check-lg" v-if="selectedProductIds.includes(p.id)"></i>
                </div>

                <img :src="p.image" class="p-img-refined" alt="p">

                <div class="p-data">
                   <div class="p-row">
                      <span class="p-name">{{ p.title }}</span>
                      <span class="p-price">{{ formatMoney(p.price) }} ₴</span>
                   </div>
                   <div class="p-row-meta">
                      <span class="p-sku">Арт: {{ p.sku }}</span>
                      <span class="p-stock" :class="{ low: p.stock < 3 }">Залишок: {{ p.stock }} шт.</span>
                   </div>
                </div>
             </div>
          </div>

          <div class="picker-footer-refined">
             <button class="btn-cancel-light" @click="productPickerOpen = false">Скасувати</button>
             <button class="btn-add-highlight" :disabled="selectedProductIds.length === 0" @click="handleAddProducts">
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
import ChatOrderDeliveryModal from '@/crm/components/chat/ChatOrderDeliveryModal.vue';
import ChatOrderPaymentModal from '@/crm/components/chat/ChatOrderPaymentModal.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  customer: { type: Object, default: null },
  orderDraft: { type: Object, required: true },
});

const emit = defineEmits(['close', 'saved', 'minimize']);

const productPickerOpen = ref(false);
const selectedProductIds = ref([]);
const deliveryModalOpen = ref(false);
const paymentModalOpen = ref(false);
const searchQuery = ref('');

// ТЕСТОВІ ТОВАРИ
const mockProducts = [
  { id: 1, title: 'Домашні капці "Пухнастики" Рожеві', sku: 'KAP-001', price: 450, stock: 12, image: '[https://picsum.photos/id/102/100/100](https://picsum.photos/id/102/100/100)' },
  { id: 2, title: 'Капці з вушками "Зайчик" Сірі', sku: 'KAP-002', price: 520, stock: 2, image: '[https://picsum.photos/id/103/100/100](https://picsum.photos/id/103/100/100)' },
  { id: 3, title: 'Шкарпетки "Тепло" (3 пари)', sku: 'SCK-001', price: 199, stock: 45, image: '[https://picsum.photos/id/107/100/100](https://picsum.photos/id/107/100/100)' },
  { id: 4, title: 'Капці чоловічі "Класик" Сині', sku: 'KAP-003', price: 480, stock: 5, image: '[https://picsum.photos/id/119/100/100](https://picsum.photos/id/119/100/100)' },
];

const hasDraft = computed(() => props.orderDraft.items?.length > 0);
const totalAmount = computed(() => (props.orderDraft.items || []).reduce((sum, item) => sum + (item.price * item.qty), 0));
const formatMoney = (v) => Number(v || 0).toFixed(2);
const formatCourierAddress = (d) => [d.street_name, d.building && `б.${d.building}`, d.apartment && `кв.${d.apartment}`].filter(Boolean).join(', ');

const getPaymentLabel = (method) => {
  const labels = { 'cod': 'Накладений платіж', 'card': 'На рахунок IBAN', 'prepay': 'Часткова передоплата' };
  return labels[method] || '';
};

const openPicker = () => { selectedProductIds.value = props.orderDraft.items.map(i => i.id); productPickerOpen.value = true; };
const toggleProductSelection = (p) => { const i = selectedProductIds.value.indexOf(p.id); if (i > -1) selectedProductIds.value.splice(i, 1); else selectedProductIds.value.push(p.id); };

const handleAddProducts = () => {
  const newItems = mockProducts.filter(p => selectedProductIds.value.includes(p.id)).map(p => {
    const existing = props.orderDraft.items.find(i => i.id === p.id);
    return { ...p, qty: existing ? existing.qty : 1 };
  });
  props.orderDraft.items.splice(0, props.orderDraft.items.length, ...newItems);
  productPickerOpen.value = false;
};

const removeSingleItem = (index) => props.orderDraft.items.splice(index, 1);
const openDeliveryModal = () => { deliveryModalOpen.value = true; };
const openPaymentModal = () => { paymentModalOpen.value = true; };
const handleClose = () => { emit('close'); };
const handleMinimize = () => { emit('minimize'); };
const handleSaved = () => { emit('saved'); };
</script>

<style scoped>
/* Стилі доставки та оплати */
.delivery-details-stack { display: flex; flex-direction: column; margin-top: 4px; gap: 1px; }
.delivery-row { line-height: 1.2; }
.city-text { font-size: 14px; font-weight: 800; color: #1e293b; }
.point-text { font-size: 12px; color: #64748b; font-weight: 600; line-height: 1.2; }
.payer-text { font-size: 11px; color: #94a3b8; font-weight: 700; text-transform: uppercase; margin-top: 2px; }
.payer-text span { color: #a78bfb; }

.payment-block-accent { background: rgba(16, 185, 129, 0.05) !important; border-color: rgba(16, 185, 129, 0.15) !important; }
.payment-green-icon { background: #fff; color: #10b981 !important; }
.delivery-label-green { font-size: 13px; font-weight: 900; color: #10b981; text-transform: uppercase; letter-spacing: 0.02em; }

/* Решта стилів */
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

/* PRODUCT MODAL */
.picker-overlay-elite { position: fixed; inset: 0; background: rgba(5, 10, 20, 0.6); z-index: 100000; backdrop-filter: blur(8px); display: flex; align-items: center; justify-content: center; }
.picker-window { background: #fff; width: 560px; max-width: 95%; height: 80vh; border-radius: 30px; display: flex; flex-direction: column; overflow: hidden; }
.picker-header-refined { padding: 20px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
.picker-title-group h4 { font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; }
.picker-search-bar { padding: 0 24px 16px; position: relative; }
.picker-search-bar input { width: 100%; padding: 12px 12px 12px 40px; border: 2px solid #f1f5f9; border-radius: 14px; font-size: 14px; outline: none; transition: 0.3s; background: #f8fafc; }
.picker-search-bar input:focus { border-color: #a78bfb; background: #fff; }
.picker-search-bar i { position: absolute; left: 38px; top: 50%; transform: translateY(-70%); color: #94a3b8; }
.picker-body-list { flex: 1; overflow-y: auto; }
.picker-row-modern { display: flex; align-items: center; padding: 14px 24px; cursor: pointer; border-bottom: 1px solid #f8fafc; transition: 0.2s; gap: 16px; }
.picker-row-modern:hover { background: #f5f3ff; }
.cb-modern { width: 22px; height: 22px; border: 2px solid #cbd5e1; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #fff; }
.picker-row-modern.active .cb-modern { background: #a78bfb; border-color: #a78bfb; }
.p-img-refined { width: 52px; height: 52px; border-radius: 10px; object-fit: cover; }
.p-data { flex: 1; min-width: 0; }
.p-row { display: flex; justify-content: space-between; align-items: center; }
.p-name { font-size: 14px; font-weight: 700; color: #1e293b; }
.p-price { font-size: 15px; font-weight: 800; color: #0f172a; }
.p-row-meta { display: flex; gap: 12px; font-size: 11px; color: #94a3b8; margin-top: 4px; }
.p-stock.low-stock { color: #f43f5e; font-weight: 700; }
.picker-footer-refined { padding: 16px 24px; border-top: 1px solid #f1f5f9; display: flex; gap: 12px; background: #fff; }
.btn-cancel-light { flex: 1; border: 1.5px solid #edf2f7; background: #fff; height: 46px; border-radius: 14px; color: #64748b; font-weight: 700; cursor: pointer; }
.btn-add-highlight { flex: 2; background: #a78bfb; border: none; height: 46px; border-radius: 14px; color: #fff; font-weight: 800; cursor: pointer; }

/* Transitions */
.slide-premium-elastic-enter-active, .slide-premium-elastic-leave-active { transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-premium-elastic-enter-from, .slide-premium-elastic-leave-to { transform: translateX(100%); }

@media (max-width: 768px) {
  .order-panel-elite, .picker-window { width: 100%; border-radius: 0; }
}
</style>