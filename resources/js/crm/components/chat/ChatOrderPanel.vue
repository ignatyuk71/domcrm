<template>
  <teleport to="body">
    <transition name="fade-fast">
      <div v-show="open" class="offcanvas-backdrop-premium" @click="handleMinimize"></div>
    </transition>

    <transition name="slide-premium">
      <div v-show="open" class="order-panel-container">
        
        <div class="panel-header">
          <div class="header-main">
            <div class="header-icon-wrap">
              <i class="bi bi-bag-heart-fill"></i>
            </div>
            <div class="header-text">
              <h3>Нове замовлення</h3>
              <div v-if="hasDraft" class="draft-indicator-modern">
                <span class="pulse-dot"></span>
                <span>Чернетка збережена</span>
              </div>
            </div>
          </div>
          <div class="header-controls">
            <button class="control-btn minimize" @click="handleMinimize" title="Згорнути">
              <i class="bi bi-dash-lg"></i>
            </button>
            <button class="control-btn close" @click="handleClose" title="Видалити">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>
        </div>

        <div class="panel-body custom-scrollbar">
          
          <transition name="zoom">
            <div v-if="!hasDraft" class="premium-trigger" @click="openPicker">
              <div class="trigger-inner">
                <div class="plus-icon-anim">
                  <i class="bi bi-plus-lg"></i>
                </div>
                <p>Додати перший товар</p>
                <span>Натисніть для вибору з каталогу</span>
              </div>
            </div>
          </transition>

          <div v-if="hasDraft" class="order-content-wrap">
            <div class="section-label">Ваш вибір</div>
            
            <div class="cart-items-wrapper">
              <div v-for="(item, index) in orderDraft.items" :key="item.id" class="premium-cart-item">
                <div class="item-img-container">
                  <img :src="item.image" alt="thumb">
                </div>
                
                <div class="item-main-info">
                  <div class="item-header-row">
                    <span class="item-name">{{ item.title }}</span>
                    <button class="item-delete-btn" @click="removeSingleItem(index)">
                      <i class="bi bi-trash3"></i>
                    </button>
                  </div>

                  <div class="item-actions-row">
                    <div class="modern-qty-selector">
                      <button @click="item.qty > 1 ? item.qty-- : null" :disabled="item.qty <= 1">−</button>
                      <span class="qty-number">{{ item.qty }}</span>
                      <button @click="item.qty++">+</button>
                    </div>
                    <div class="item-price-display">
                      {{ formatMoney(item.price * item.qty) }} <span>грн</span>
                    </div>
                  </div>
                </div>
              </div>

              <button class="btn-add-more-modern" @click="openPicker">
                <i class="bi bi-plus-circle"></i> Додати ще позицію
              </button>
            </div>

            <div class="total-summary-card">
              <div class="summary-line">
                <span>Кількість позицій:</span>
                <b>{{ orderDraft.items.length }}</b>
              </div>
              <div class="summary-line total">
                <span>Загальна сума:</span>
                <span class="total-amount">{{ formatMoney(totalAmount) }} ₴</span>
              </div>
            </div>
          </div>
        </div>

        <div class="panel-footer">
          <button class="btn-primary-shimmer" :disabled="!hasDraft" @click="handleSaved">
            <div class="btn-content">
              <span>ОФОРМИТИ ЗАМОВЛЕННЯ</span>
              <i class="bi bi-arrow-right-short"></i>
            </div>
          </button>
        </div>
      </div>
    </transition>

    <transition name="modal-bounce">
      <div v-if="productPickerOpen" class="picker-modal-overlay" @click.self="productPickerOpen = false">
        <div class="picker-content">
          <div class="picker-top">
            <div class="picker-title-block">
              <h4>Оберіть товари</h4>
              <span>{{ mockProducts.length }} доступно для замовлення</span>
            </div>
            <button class="close-picker" @click="productPickerOpen = false">
              <i class="bi bi-x"></i>
            </button>
          </div>
          
          <div class="picker-middle custom-scrollbar">
            <div class="picker-list-compact">
              <div v-for="p in mockProducts" :key="p.id" 
                   class="list-item-modern" 
                   :class="{ active: selectedProductIds.includes(p.id) }" 
                   @click="toggleProductSelection(p)">
                
                <div class="check-box-modern" :class="{ checked: selectedProductIds.includes(p.id) }">
                  <i class="bi bi-check-lg"></i>
                </div>
                
                <div class="p-img-wrap">
                  <img :src="p.image" alt="p">
                </div>
                
                <div class="p-info-wrap">
                  <div class="p-name">{{ p.title }}</div>
                  <div class="p-meta">
                    Арт: {{ p.sku }} <span class="stock-pill" :class="{ low: p.stock < 3 }">Склад: {{ p.stock }}</span>
                  </div>
                </div>
                
                <div class="p-price-wrap">{{ formatMoney(p.price) }} ₴</div>
              </div>
            </div>
          </div>

          <div class="picker-bottom">
            <button class="btn-secondary" @click="productPickerOpen = false">Скасувати</button>
            <button class="btn-primary-compact" :disabled="selectedProductIds.length === 0" @click="handleAddProducts">
              Додати вибрані ({{ selectedProductIds.length }})
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
const handleAddProducts = () => { const newItems = mockProducts.filter(p => selectedProductIds.value.includes(p.id)).map(p => { const existing = props.orderDraft.items.find(item => item.id === p.id); return { id: p.id, title: p.title, sku: p.sku, price: p.price, image: p.image, qty: existing ? existing.qty : 1 }; }); props.orderDraft.items = newItems; productPickerOpen.value = false; };
const removeSingleItem = (index) => { props.orderDraft.items.splice(index, 1); };
const handleClose = () => { emit('close'); };
const handleMinimize = () => { emit('minimize'); };
const handleSaved = () => { emit('saved'); };
const formatMoney = (v) => Number(v || 0).toFixed(2);
</script>

<style scoped>
/* PREMIUM LAYERS */
.offcanvas-backdrop-premium { 
  position: fixed; top: 0; left: 0; right: 0; height: 100vh; 
  background: rgba(15, 23, 42, 0.5); z-index: 99998; 
  backdrop-filter: blur(8px); 
}
.order-panel-container { 
  position: fixed; top: 0; right: 0; width: 480px; max-width: 100%; height: 100vh; 
  background: #ffffff; z-index: 99999; display: flex; flex-direction: column; 
  box-shadow: -20px 0 60px rgba(0, 0, 0, 0.25); 
}

/* HEADER */
.panel-header { padding: 20px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
.header-main { display: flex; align-items: center; gap: 14px; }
.header-icon-wrap { width: 44px; height: 44px; background: #f5f3ff; color: #a78bfb; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
.header-text h3 { font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; }

.draft-indicator-modern { display: flex; align-items: center; gap: 6px; margin-top: 2px; }
.draft-indicator-modern span { font-size: 12px; font-weight: 600; color: #10b981; text-transform: uppercase; letter-spacing: 0.02em; }
.pulse-dot { width: 8px; height: 8px; background: #10b981; border-radius: 50%; box-shadow: 0 0 10px rgba(16, 185, 129, 0.4); animation: pulse-premium 2s infinite; }

@keyframes pulse-premium { 0% { transform: scale(0.95); opacity: 0.8; } 50% { transform: scale(1.2); opacity: 0.3; } 100% { transform: scale(0.95); opacity: 0.8; } }

.header-controls { display: flex; gap: 10px; }
.control-btn { border: none; width: 34px; height: 34px; border-radius: 10px; cursor: pointer; transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1); display: flex; align-items: center; justify-content: center; }
.control-btn.minimize { background: #f8fafc; color: #64748b; }
.control-btn.minimize:hover { background: #e2e8f0; transform: translateY(-2px); }
.control-btn.close { background: #fef2f2; color: #f43f5e; }
.control-btn.close:hover { background: #fee2e2; transform: scale(1.1) rotate(90deg); }

/* BODY */
.panel-body { flex: 1; padding: 24px; overflow-y: auto; background: #fafafa; }

/* PREMIUM TRIGGER */
.premium-trigger { 
  background: #fff; border: 2px dashed #cbd5e1; border-radius: 24px; padding: 50px 30px; 
  text-align: center; cursor: pointer; transition: all 0.4s ease; 
  box-shadow: 0 10px 20px rgba(0,0,0,0.02);
}
.premium-trigger:hover { border-color: #a78bfb; transform: translateY(-5px); box-shadow: 0 20px 40px rgba(167, 139, 251, 0.1); }
.plus-icon-anim { width: 56px; height: 56px; background: #f5f3ff; color: #a78bfb; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 24px; transition: 0.4s; }
.premium-trigger:hover .plus-icon-anim { transform: rotate(180deg) scale(1.1); background: #a78bfb; color: #fff; }
.premium-trigger p { font-size: 16px; font-weight: 700; color: #1e293b; margin: 0; }
.premium-trigger span { font-size: 13px; color: #94a3b8; display: block; margin-top: 4px; }

/* CART ITEMS (COMPACT PREMIUM) */
.section-label { font-size: 12px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 16px; }
.premium-cart-item { 
  background: #fff; border-radius: 18px; padding: 12px; display: flex; gap: 14px; 
  margin-bottom: 12px; border: 1px solid #f1f5f9; box-shadow: 0 4px 6px rgba(0,0,0,0.02);
}
.item-img-container { width: 64px; height: 64px; border-radius: 12px; overflow: hidden; background: #f8fafc; flex-shrink: 0; }
.item-img-container img { width: 100%; height: 100%; object-fit: cover; }

.item-main-info { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: space-between; }
.item-header-row { display: flex; justify-content: space-between; align-items: flex-start; }
.item-name { font-size: 14px; font-weight: 700; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.item-delete-btn { background: none; border: none; color: #cbd5e0; cursor: pointer; padding: 2px; transition: 0.2s; }
.item-delete-btn:hover { color: #f43f5e; transform: scale(1.2); }

.item-actions-row { display: flex; justify-content: space-between; align-items: center; }
.modern-qty-selector { display: flex; align-items: center; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; height: 28px; }
.modern-qty-selector button { width: 28px; border: none; background: transparent; cursor: pointer; font-size: 16px; font-weight: 700; color: #64748b; }
.modern-qty-selector button:hover:not(:disabled) { color: #a78bfb; }
.qty-number { width: 30px; text-align: center; font-size: 13px; font-weight: 800; color: #0f172a; }

.item-price-display { font-size: 15px; font-weight: 800; color: #0f172a; }
.item-price-display span { font-size: 11px; color: #94a3b8; font-weight: 600; }

.btn-add-more-modern { 
  width: 100%; padding: 12px; background: #f5f3ff; border: 1px solid #ddd6fe; 
  color: #7c3aed; border-radius: 14px; font-weight: 700; font-size: 13px; 
  cursor: pointer; transition: 0.3s; margin-top: 8px;
}
.btn-add-more-modern:hover { background: #7c3aed; color: #fff; transform: translateY(-2px); }

/* SUMMARY CARD */
.total-summary-card { 
  background: #0f172a; border-radius: 20px; padding: 20px; margin-top: 24px; color: #fff; 
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
}
.summary-line { display: flex; justify-content: space-between; font-size: 14px; opacity: 0.8; margin-bottom: 12px; }
.summary-line.total { border-top: 1px solid rgba(255,255,255,0.1); padding-top: 15px; margin-top: 15px; opacity: 1; }
.total-amount { font-size: 22px; font-weight: 900; color: #a78bfb; }

/* FOOTER & SHIMMER BUTTON */
.panel-footer { padding: 24px; border-top: 1px solid #f1f5f9; background: #fff; }
.btn-primary-shimmer { 
  width: 100%; height: 56px; background: #a78bfb; border: none; border-radius: 16px; 
  cursor: pointer; position: relative; overflow: hidden; transition: 0.4s;
}
.btn-primary-shimmer:hover:not(:disabled) { transform: translateY(-3px); box-shadow: 0 15px 30px rgba(167, 139, 251, 0.4); }
.btn-primary-shimmer:disabled { background: #e2e8f0; cursor: not-allowed; }

.btn-content { display: flex; align-items: center; justify-content: center; gap: 8px; color: #fff; font-weight: 900; font-size: 15px; letter-spacing: 0.05em; }

.btn-primary-shimmer::after {
  content: ''; position: absolute; top: -50%; left: -60%; width: 25%; height: 200%;
  background: rgba(255, 255, 255, 0.3); transform: rotate(35deg);
  animation: shimmer-premium 3s infinite;
}
@keyframes shimmer-premium { 0% { left: -60%; } 20% { left: 120%; } 100% { left: 120%; } }

/* PICKER MODAL PREMIUM */
.picker-modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.6); z-index: 100000; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
.picker-content { background: #fff; width: 600px; max-width: 95%; max-height: 85vh; border-radius: 32px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 30px 70px rgba(0,0,0,0.4); }

.list-item-modern { 
  display: grid; grid-template-columns: 40px 60px 1fr auto; gap: 16px; 
  align-items: center; padding: 16px 24px; cursor: pointer; 
  border-bottom: 1px solid #f8fafc; transition: 0.3s;
}
.list-item-modern:hover { background: #f5f3ff; }
.list-item-modern.active { background: #f5f3ff; }

.check-box-modern { width: 24px; height: 24px; border: 2px solid #cbd5e1; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: transparent; transition: 0.2s; }
.check-box-modern.checked { background: #a78bfb; border-color: #a78bfb; color: white; }

.stock-pill { padding: 2px 8px; border-radius: 6px; background: #f0fdf4; color: #16a34a; font-size: 10px; font-weight: 700; }
.stock-pill.low { background: #fef2f2; color: #f43f5e; }

/* TRANSITIONS */
.fade-fast-enter-active, .fade-fast-leave-active { transition: opacity 0.2s; }
.slide-premium-enter-active, .slide-premium-leave-active { transition: transform 0.5s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-premium-enter-from, .slide-premium-leave-to { transform: translateX(100%); }

@media (max-width: 768px) {
  .order-panel-container { width: 100%; border-radius: 0; }
}
</style>