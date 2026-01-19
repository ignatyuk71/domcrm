<template>
  <teleport to="body">
    <transition name="fade">
      <div v-show="open" class="offcanvas-backdrop" @click="handleMinimize"></div>
    </transition>

    <transition name="slide-global">
      <div v-show="open" class="order-offcanvas-global">
        <div class="offcanvas-header">
          <div class="header-left">
            <i class="bi bi-bag-check-fill header-icon"></i>
            <div class="header-meta">
              <h3>Нове замовлення</h3>
              <span v-if="hasDraft" class="draft-pill">
                <span class="pulse-dot"></span> Чернетка
              </span>
            </div>
          </div>
          <div class="header-actions">
            <button class="btn-icon btn-minimize" type="button" @click="handleMinimize" title="Згорнути">
              <i class="bi bi-fullscreen-exit"></i>
            </button>
            <button class="btn-icon btn-trash" type="button" @click="handleClose" title="Видалити">
              <i class="bi bi-trash3"></i>
            </button>
          </div>
        </div>

        <div class="offcanvas-body custom-scrollbar">
          <div v-if="!hasDraft" class="selection-trigger-area" @click="openPicker">
            <div class="trigger-content">
              <div class="icon-circle">
                <i class="bi bi-plus-lg"></i>
              </div>
              <p>Вибрати товари</p>
            </div>
          </div>

          <div class="order-block" v-if="hasDraft">
            <div class="block-title">Товари у замовленні</div>

            <div class="cart-list">
              <div v-for="(item, index) in orderDraft.items" :key="item.id" class="cart-item">
                <img :src="item.image" alt="product" class="cart-thumb">
                
                <div class="cart-content">
                  <div class="cart-row-top">
                    <div class="cart-title">{{ item.title }}</div>
                    <button class="btn-remove-tiny" @click="removeSingleItem(index)">
                      <i class="bi bi-x"></i>
                    </button>
                  </div>

                  <div class="cart-row-bottom">
                    <div class="quantity-control-mini">
                      <button @click="item.qty > 1 ? item.qty-- : null" :disabled="item.qty <= 1">-</button>
                      <span class="qty-val">{{ item.qty }}</span>
                      <button @click="item.qty++">+</button>
                    </div>
                    <div class="item-price-sum">
                      {{ formatMoney(item.price * item.qty) }} грн
                    </div>
                  </div>
                </div>
              </div>

              <button class="btn-add-more-small" @click="openPicker">
                <i class="bi bi-plus-circle"></i> Додати ще товар
              </button>
            </div>

            <div class="order-total">
              <span>Загальна сума:</span>
              <strong class="total-price">{{ formatMoney(totalAmount) }} грн</strong>
            </div>
          </div>
        </div>

        <div class="offcanvas-footer">
          <button class="btn-save-modern" :disabled="!hasDraft" @click="handleSaved">
            <span>Оформити замовлення</span>
          </button>
        </div>
      </div>
    </transition>

    <transition name="fade">
      <div v-if="productPickerOpen" class="picker-overlay" @click.self="productPickerOpen = false">
        <div class="product-selection-modal">
          <div class="modal-header">
            <h4>Каталог товарів</h4>
            <button class="btn-close-modal" @click="productPickerOpen = false">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>
          <div class="modal-body custom-scrollbar">
            <div class="product-list">
              <div v-for="product in mockProducts" :key="product.id" 
                   class="selection-list-item" 
                   :class="{ 'is-selected': selectedProductIds.includes(product.id) }" 
                   @click="toggleProductSelection(product)">
                <div class="checkbox-ui" :class="{ 'checked': selectedProductIds.includes(product.id) }">
                  <i class="bi bi-check-lg" v-if="selectedProductIds.includes(product.id)"></i>
                </div>
                <img :src="product.image" alt="p" class="item-img">
                <div class="item-details">
                  <div class="item-name">{{ product.title }}</div>
                  <div class="item-meta">Арт: {{ product.sku }} • Залишок: {{ product.stock }}</div>
                </div>
                <div class="item-price">{{ formatMoney(product.price) }} ₴</div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn-cancel" @click="productPickerOpen = false">Скасувати</button>
            <button class="btn-confirm-selection" :disabled="selectedProductIds.length === 0" @click="handleAddProducts">
              Додати ({{ selectedProductIds.length }})
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
/* ПОВЕРНУВ ПОВНУ ВИСОТУ ТА Z-INDEX */
.offcanvas-backdrop { 
  position: fixed; inset: 0; 
  background: rgba(15, 23, 42, 0.4); z-index: 200000; backdrop-filter: blur(4px); 
}
.order-offcanvas-global { 
  position: fixed; inset: 0; width: 100%; height: 100vh; 
  background: #ffffff; z-index: 200001; display: flex; flex-direction: column; 
  box-shadow: none; 
}

/* HEADER */
.offcanvas-header { padding: 18px 24px; border-bottom: 1px solid #edf2f7; display: flex; align-items: center; justify-content: space-between; background: #fff; }
.header-left { display: flex; align-items: center; gap: 10px; }
.header-icon { color: #a78bfb; font-size: 22px; }
.header-meta h3 { font-size: 16px; font-weight: 800; color: #1e293b; margin: 0; }

.draft-pill { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; color: #10b981; text-transform: uppercase; background: #ecfdf5; padding: 3px 10px; border-radius: 20px; }
.pulse-dot { width: 6px; height: 6px; background: #10b981; border-radius: 50%; animation: pulse 1.5s infinite; }
@keyframes pulse { 0% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.3); opacity: 0.5; } 100% { transform: scale(1); opacity: 1; } }

.header-actions { display: flex; gap: 8px; }
.btn-icon { border: none; width: 36px; height: 36px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; }
.btn-minimize { background: #f1f5f9; color: #64748b; }
.btn-minimize:hover { transform: scale(1.1); background: #e2e8f0; }
.btn-trash { background: #fff1f2; color: #f43f5e; }
.btn-trash:hover { transform: rotate(10deg); background: #ffe4e6; }

/* SELECTION TRIGGER */
.selection-trigger-area { 
  background: #ffffff; border: 2px dashed #cbd5e1; border-radius: 16px; padding: 25px; 
  text-align: center; cursor: pointer; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
  margin: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
}
.selection-trigger-area:hover { border-color: #a78bfb; background: #f5f3ff; transform: translateY(-3px); }
.selection-trigger-area:active { transform: scale(0.96); }
.icon-circle { width: 44px; height: 44px; background: #f1f5f9; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; color: #94a3b8; transition: 0.3s; }
.selection-trigger-area:hover .icon-circle { background: #a78bfb; color: #fff; transform: rotate(90deg); }

/* COMPACT LIST */
.offcanvas-body { flex: 1; padding: 0 24px 24px; overflow-y: auto; background: #f8fafc; }
.order-block { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; margin-top: 10px; }
.block-title { font-size: 11px; text-transform: uppercase; color: #94a3b8; font-weight: 800; letter-spacing: 0.05em; margin-bottom: 12px; }

.cart-item { display: flex; gap: 12px; padding-bottom: 12px; margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; transition: 0.2s; }
.cart-thumb { width: 48px; height: 48px; border-radius: 10px; object-fit: cover; background: #f1f5f9; }
.cart-content { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; }

.cart-row-top { display: flex; justify-content: space-between; align-items: center; }
.cart-title { font-size: 13px; font-weight: 700; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.btn-remove-tiny { background: none; border: none; color: #cbd5e0; cursor: pointer; padding: 2px; transition: 0.2s; }
.btn-remove-tiny:hover { color: #f43f5e; transform: scale(1.2); }

.cart-row-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; }
.quantity-control-mini { display: flex; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; height: 26px; }
.quantity-control-mini button { width: 26px; border: none; background: #f8fafc; cursor: pointer; transition: 0.2s; font-weight: 800; color: #64748b; }
.quantity-control-mini button:hover:not(:disabled) { background: #a78bfb; color: white; }
.qty-val { width: 30px; text-align: center; font-size: 12px; font-weight: 800; line-height: 26px; }

.item-price-sum { font-size: 14px; font-weight: 800; color: #1e293b; }

.btn-add-more-small { 
  background: #f5f3ff; border: 1px solid #ddd6fe; color: #7c3aed; width: 100%; 
  padding: 10px; border-radius: 12px; font-weight: 700; cursor: pointer; 
  margin-top: 5px; font-size: 12px; transition: all 0.2s ease;
}
.btn-add-more-small:hover { background: #7c3aed; color: #fff; transform: translateY(-2px); }

.order-total { display: flex; justify-content: space-between; align-items: center; border-top: 2px solid #f1f5f9; padding-top: 15px; margin-top: 15px; }
.total-price { color: #a78bfb; font-size: 20px; font-weight: 900; }

/* FOOTER & ANIMATED SAVE BUTTON */
.offcanvas-footer { padding: 20px 24px; border-top: 1px solid #edf2f7; background: #ffffff; }
.btn-save-modern { 
  position: relative; background: #a78bfb; color: #ffffff; border: none; 
  border-radius: 12px; height: 52px; width: 100%; cursor: pointer; 
  font-weight: 800; font-size: 15px; overflow: hidden; transition: all 0.3s ease; 
}
.btn-save-modern:hover:not(:disabled) { background: #9061f9; transform: translateY(-2px); box-shadow: 0 10px 20px rgba(167, 139, 251, 0.3); }
.btn-save-modern:active { transform: scale(0.98); }
.btn-save-modern:disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }

/* Shimmer effect for save button */
.btn-save-modern::after {
  content: ''; position: absolute; top: -50%; left: -60%; width: 20%; height: 200%;
  background: rgba(255, 255, 255, 0.2); transform: rotate(30deg);
  transition: all 0.5s ease;
}
.btn-save-modern:hover::after { left: 150%; transition: all 0.8s ease; }

/* PICKER MODAL */
.picker-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 200002; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(3px); }
.product-selection-modal { background: #fff; width: 580px; max-width: 95%; max-height: 85vh; border-radius: 28px; display: flex; flex-direction: column; overflow: hidden; }

/* ANIMATIONS */
.slide-global-enter-active, .slide-global-leave-active { transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-global-enter-from, .slide-global-leave-to { transform: translateX(100%); }
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

@media (max-width: 768px) {
  .order-offcanvas-global { width: 100%; border-radius: 0; }
}
</style>
