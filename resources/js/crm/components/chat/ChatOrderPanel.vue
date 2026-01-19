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
              <i class="bi bi-lightning-charge-fill"></i>
            </div>
            <div class="title-stack">
              <h3>Оформлення</h3>
              <div class="stepper-dots">
                <span class="dot active"></span>
                <span class="dot" :class="{ active: hasDraft }"></span>
                <span class="dot"></span>
              </div>
            </div>
          </div>
          
          <div class="header-right-group">
             <button class="action-circle-btn" @click="handleMinimize"><i class="bi bi-fullscreen-exit"></i></button>
             <button class="action-circle-btn delete" @click="handleClose"><i class="bi bi-trash3"></i></button>
          </div>
        </header>

        <div class="panel-scroller custom-scrollbar">
          
          <transition name="zoom-in">
            <div v-if="!hasDraft" class="empty-state-card" @click="openPicker">
               <div class="animated-blob">
                 <i class="bi bi-plus-lg"></i>
               </div>
               <h4>Почніть з вибору товарів</h4>
               <p>Натисніть сюди, щоб переглянути каталог</p>
            </div>
          </transition>

          <div v-if="hasDraft" class="order-flow-container">
            
            <section class="flow-section">
              <div class="section-heading">
                <span>ВИБРАНІ ПОЗИЦІЇ</span>
                <button class="text-btn" @click="openPicker">+ Додати ще</button>
              </div>

              <transition-group name="list-stagger" tag="div" class="cart-items-list">
                <div v-for="(item, index) in orderDraft.items" :key="item.id" class="elite-cart-card">
                  <img :src="item.image" class="card-img" alt="product" />
                  
                  <div class="card-main">
                    <div class="card-header-row">
                      <span class="p-title">{{ item.title }}</span>
                      <button class="p-delete" @click="removeSingleItem(index)"><i class="bi bi-x"></i></button>
                    </div>

                    <div class="card-controls-row">
                      <div class="mini-stepper">
                        <button @click="item.qty > 1 ? item.qty-- : null" :disabled="item.qty <= 1">−</button>
                        <span class="qty">{{ item.qty }}</span>
                        <button @click="item.qty++">+</button>
                      </div>
                      <div class="p-price-total">
                        {{ formatMoney(item.price * item.qty) }} <b>грн</b>
                      </div>
                    </div>
                  </div>
                </div>
              </transition-group>
            </section>

            <section class="flow-section">
               <div class="section-heading">ДОСТАВКА</div>
               <div class="mock-input-card">
                  <i class="bi bi-truck"></i>
                  <span>Виберіть відділення Нової Пошти...</span>
                  <i class="bi bi-chevron-right ms-auto"></i>
               </div>
            </section>

          </div>
        </div>

        <footer class="elite-footer">
          <div class="summary-box">
             <div class="summary-info">
                <span>Разом до сплати:</span>
                <div class="total-amount-glow">{{ formatMoney(totalAmount) }} ₴</div>
             </div>
             <button class="btn-checkout-shimmer" :disabled="!hasDraft" @click="handleSaved">
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
          <div class="picker-header-elite">
             <div class="picker-title-group">
                <h4>Каталог капців</h4>
                <p>{{ mockProducts.length }} одиниць доступно</p>
             </div>
             <button class="close-picker-btn" @click="productPickerOpen = false"><i class="bi bi-x-lg"></i></button>
          </div>

          <div class="picker-body-list custom-scrollbar">
             <div v-for="p in mockProducts" :key="p.id" 
                  class="picker-row" 
                  :class="{ selected: selectedProductIds.includes(p.id) }"
                  @click="toggleProductSelection(p)">
                
                <div class="checkbox-wrapper">
                   <div class="custom-cb" :class="{ checked: selectedProductIds.includes(p.id) }">
                      <i class="bi bi-check-lg"></i>
                   </div>
                </div>

                <div class="p-visual">
                   <img :src="p.image" alt="p">
                </div>

                <div class="p-content">
                   <div class="p-top-line">
                      <span class="p-name">{{ p.title }}</span>
                      <span class="p-price">{{ formatMoney(p.price) }} ₴</span>
                   </div>
                   <div class="p-bottom-line">
                      <span class="p-sku">Арт: {{ p.sku }}</span>
                      <span class="p-stock-badge" :class="{ warning: p.stock < 3 }">
                         Склад: {{ p.stock }} шт.
                      </span>
                   </div>
                </div>
             </div>
          </div>

          <div class="picker-footer-elite">
             <button class="btn-text-only" @click="productPickerOpen = false">Скасувати</button>
             <button class="btn-confirm-elite" :disabled="selectedProductIds.length === 0" @click="handleAddProducts">
               Додати в замовлення ({{ selectedProductIds.length }})
             </button>
          </div>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  customer: { type: Object, default: null },
  orderDraft: { type: Object, required: true },
});

const emit = defineEmits(['close', 'saved', 'minimize']);

const productPickerOpen = ref(false);
const selectedProductIds = ref([]);

const mockProducts = [
  { id: 1, title: 'Домашні капці "Пухнастики" Рожеві', sku: 'KAP-001', price: 450, stock: 12, image: 'https://picsum.photos/id/102/100/100' },
  { id: 2, title: 'Капці з вушками "Зайчик" Сірі', sku: 'KAP-002', price: 520, stock: 2, image: 'https://picsum.photos/id/103/100/100' },
  { id: 3, title: 'Шкарпетки "Тепло" (3 пари)', sku: 'SCK-001', price: 199, stock: 45, image: 'https://picsum.photos/id/107/100/100' },
  { id: 4, title: 'Капці чоловічі "Класик" Сині', sku: 'KAP-003', price: 480, stock: 5, image: 'https://picsum.photos/id/119/100/100' },
];

const hasDraft = computed(() => props.orderDraft.items?.length > 0);
const totalAmount = computed(() => (props.orderDraft.items || []).reduce((sum, item) => sum + (item.price * item.qty), 0));

const openPicker = () => { selectedProductIds.value = props.orderDraft.items.map(item => item.id); productPickerOpen.value = true; };
const toggleProductSelection = (p) => { const i = selectedProductIds.value.indexOf(p.id); if (i > -1) selectedProductIds.value.splice(i, 1); else selectedProductIds.value.push(p.id); };

const handleAddProducts = () => {
  const newItems = mockProducts.filter(p => selectedProductIds.value.includes(p.id)).map(p => {
    const existing = props.orderDraft.items.find(item => item.id === p.id);
    return { id: p.id, title: p.title, sku: p.sku, price: p.price, image: p.image, qty: existing ? existing.qty : 1 };
  });
  props.orderDraft.items = newItems;
  productPickerOpen.value = false;
};

const removeSingleItem = (index) => { props.orderDraft.items.splice(index, 1); };
const handleClose = () => { emit('close'); };
const handleMinimize = () => { emit('minimize'); };
const handleSaved = () => { emit('saved'); };
const formatMoney = (v) => Number(v || 0).toFixed(2);
</script>

<style scoped>
/* ELITE LAYOUT */
.ultra-backdrop { position: fixed; inset: 0; background: rgba(10, 15, 30, 0.4); z-index: 99998; backdrop-filter: blur(12px); }
.order-panel-elite { position: fixed; top: 0; right: 0; width: 480px; max-width: 100%; height: 100vh; background: #ffffff; z-index: 99999; display: flex; flex-direction: column; box-shadow: -20px 0 80px rgba(0, 0, 0, 0.2); }

/* HEADER */
.elite-header { padding: 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; background: #fff; }
.header-left-group { display: flex; align-items: center; gap: 16px; }
.status-icon-glow { width: 48px; height: 48px; background: #f1f5f9; color: #cbd5e1; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 24px; transition: 0.4s; }
.status-icon-glow.has-items { background: #f5f3ff; color: #a78bfb; box-shadow: 0 0 15px rgba(167, 139, 251, 0.3); }

.title-stack h3 { font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; }
.stepper-dots { display: flex; gap: 4px; margin-top: 4px; }
.stepper-dots .dot { width: 14px; height: 4px; border-radius: 2px; background: #e2e8f0; transition: 0.3s; }
.stepper-dots .dot.active { background: #a78bfb; width: 24px; }

.header-right-group { display: flex; gap: 8px; }
.action-circle-btn { width: 36px; height: 36px; border-radius: 50%; border: none; background: #f8fafc; color: #94a3b8; cursor: pointer; transition: 0.3s; }
.action-circle-btn:hover { background: #f1f5f9; color: #1e293b; transform: rotate(15deg); }
.action-circle-btn.delete:hover { background: #fef2f2; color: #ef4444; }

/* EMPTY STATE */
.panel-scroller { flex: 1; padding: 24px; overflow-y: auto; background: #fafafa; }
.empty-state-card { background: #fff; border: 2px dashed #cbd5e1; border-radius: 32px; padding: 60px 40px; text-align: center; cursor: pointer; transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
.empty-state-card:hover { border-color: #a78bfb; transform: scale(1.02); box-shadow: 0 20px 40px rgba(167, 139, 251, 0.1); }
.animated-blob { width: 64px; height: 64px; background: #f5f3ff; color: #a78bfb; border-radius: 40% 60% 60% 40% / 60% 30% 70% 40%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 28px; animation: morph 4s ease-in-out infinite; }
@keyframes morph { 0% { border-radius: 40% 60% 60% 40% / 60% 30% 70% 40%; } 50% { border-radius: 30% 60% 70% 40% / 50% 60% 30% 60%; } 100% { border-radius: 40% 60% 60% 40% / 60% 30% 70% 40%; } }

/* CART CARDS */
.section-heading { font-size: 11px; font-weight: 800; color: #94a3b8; letter-spacing: 0.1em; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; }
.text-btn { background: none; border: none; color: #a78bfb; font-weight: 700; cursor: pointer; font-size: 11px; }

.elite-cart-card { background: #fff; border-radius: 20px; padding: 12px; display: flex; gap: 14px; margin-bottom: 12px; border: 1px solid #f1f5f9; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
.card-img { width: 60px; height: 60px; border-radius: 14px; object-fit: cover; background: #f8fafc; }
.card-main { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: space-between; }
.card-header-row { display: flex; justify-content: space-between; align-items: center; }
.p-title { font-size: 13px; font-weight: 700; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.p-delete { background: none; border: none; color: #cbd5e0; cursor: pointer; font-size: 18px; line-height: 1; }
.p-delete:hover { color: #f43f5e; }

.card-controls-row { display: flex; justify-content: space-between; align-items: center; margin-top: 4px; }
.mini-stepper { display: flex; align-items: center; background: #f8fafc; border-radius: 10px; height: 26px; border: 1px solid #edf2f7; }
.mini-stepper button { width: 26px; border: none; background: transparent; cursor: pointer; font-weight: 700; color: #64748b; }
.mini-stepper button:hover { color: #a78bfb; }
.qty { width: 26px; text-align: center; font-size: 12px; font-weight: 800; }
.p-price-total { font-size: 15px; font-weight: 800; color: #0f172a; }
.p-price-total b { font-size: 10px; opacity: 0.5; font-weight: 600; }

.mock-input-card { background: #fff; padding: 16px; border-radius: 18px; border: 1px solid #f1f5f9; display: flex; align-items: center; gap: 12px; color: #94a3b8; font-size: 13px; font-weight: 600; cursor: pointer; }

/* FOOTER */
.elite-footer { padding: 24px; border-top: 1px solid #f1f5f9; background: #fff; }
.summary-box { background: #0f172a; border-radius: 24px; padding: 12px 12px 12px 24px; display: flex; align-items: center; justify-content: space-between; }
.summary-info span { font-size: 11px; color: #94a3b8; font-weight: 700; text-transform: uppercase; }
.total-amount-glow { color: #fff; font-size: 22px; font-weight: 900; letter-spacing: -0.02em; }

.btn-checkout-shimmer { background: #a78bfb; border: none; border-radius: 18px; height: 48px; padding: 0 24px; color: #fff; font-weight: 900; font-size: 13px; letter-spacing: 0.05em; cursor: pointer; position: relative; overflow: hidden; transition: 0.3s; }
.btn-checkout-shimmer:hover { transform: scale(1.03); background: #9061f9; }
.shimmer-line { position: absolute; top: 0; left: -100%; width: 50%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent); animation: shimmer 2s infinite; }
@keyframes shimmer { 100% { left: 200%; } }

/* PICKER OVERLAY */
.picker-overlay-elite { position: fixed; inset: 0; background: rgba(5, 10, 20, 0.7); z-index: 100000; backdrop-filter: blur(10px); display: flex; align-items: center; justify-content: center; }
.picker-window { background: #fff; width: 620px; max-width: 95%; height: 80vh; border-radius: 36px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 40px 100px rgba(0,0,0,0.5); }

.picker-row { display: flex; align-items: center; padding: 16px 24px; cursor: pointer; border-bottom: 1px solid #f8fafc; transition: 0.2s; }
.picker-row:hover, .picker-row.selected { background: #f5f3ff; }
.custom-cb { width: 22px; height: 22px; border: 2px solid #cbd5e1; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: transparent; transition: 0.2s; }
.custom-cb.checked { background: #a78bfb; border-color: #a78bfb; color: #fff; }
.p-visual img { width: 50px; height: 50px; border-radius: 12px; object-fit: cover; margin-left: 16px; }
.p-content { flex: 1; margin-left: 16px; }
.p-top-line { display: flex; justify-content: space-between; font-weight: 700; font-size: 14px; }
.p-bottom-line { display: flex; gap: 12px; font-size: 11px; color: #94a3b8; margin-top: 2px; }
.p-stock-badge.warning { color: #f43f5e; font-weight: 700; }

/* TRANSITIONS */
.slide-premium-elastic-enter-active, .slide-premium-elastic-leave-active { transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-premium-elastic-enter-from, .slide-premium-elastic-leave-to { transform: translateX(100%); }

@media (max-width: 768px) {
  .order-panel-elite { width: 100%; border-radius: 0; }
  .picker-window { width: 100%; height: 100%; border-radius: 0; }
}
</style>