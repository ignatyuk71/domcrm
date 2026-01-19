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
            <button class="btn-minimize" type="button" @click="handleMinimize"><i class="bi bi-fullscreen-exit"></i></button>
            <button class="btn-close-panel" type="button" @click="handleClose"><i class="bi bi-trash3"></i></button>
          </div>
        </div>

        <div class="offcanvas-body custom-scrollbar">
          <div v-if="!hasDraft" class="selection-trigger-area" @click="openPicker">
            <div class="trigger-content">
              <div class="icon-circle"><i class="bi bi-plus-lg"></i></div>
              <p>Виберіть товари</p>
            </div>
          </div>

          <div class="order-block" v-if="hasDraft">
            <div class="block-header">
              <div class="block-title">Товари у замовленні</div>
            </div>

            <div class="cart-list">
              <div v-for="(item, index) in orderDraft.items" :key="item.id" class="cart-item">
                <img :src="item.image" alt="product" class="cart-thumb">
                
                <div class="cart-content">
                  <div class="cart-row-top">
                    <div class="cart-title">{{ item.title }}</div>
                    <button class="btn-remove-tiny" type="button" @click="removeSingleItem(index)">
                      <i class="bi bi-x-lg"></i>
                    </button>
                  </div>

                  <div class="cart-row-bottom">
                    <div class="quantity-control-mini">
                      <button type="button" @click="item.qty > 1 ? item.qty-- : null" :disabled="item.qty <= 1">-</button>
                      <span class="qty-val">{{ item.qty }}</span>
                      <button type="button" @click="item.qty++">+</button>
                    </div>
                    <div class="item-price-sum">
                      {{ formatMoney(item.price * item.qty) }} грн
                    </div>
                  </div>
                </div>
              </div>

              <button class="btn-add-more-small" type="button" @click="openPicker">
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
          <button class="btn-save-modern" type="button" :disabled="!hasDraft" @click="handleSaved">
            Оформити замовлення
          </button>
        </div>
      </div>
    </transition>

    <transition name="fade">
      <div v-if="productPickerOpen" class="picker-overlay" @click.self="productPickerOpen = false">
        <div class="product-selection-modal">
          <div class="modal-header">
            <h4>Каталог товарів</h4>
            <button class="btn-close-modal" @click="productPickerOpen = false"><i class="bi bi-x-lg"></i></button>
          </div>
          <div class="modal-body custom-scrollbar">
            <div class="product-list">
              <div v-for="product in mockProducts" :key="product.id" class="selection-list-item" :class="{ 'is-selected': selectedProductIds.includes(product.id) }" @click="toggleProductSelection(product)">
                <div class="checkbox-ui" :class="{ 'checked': selectedProductIds.includes(product.id) }"><i class="bi bi-check-lg" v-if="selectedProductIds.includes(product.id)"></i></div>
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
            <button class="btn-confirm-selection" :disabled="selectedProductIds.length === 0" @click="handleAddProducts">Додати ({{ selectedProductIds.length }})</button>
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
/* --- БАЗОВІ СТИЛІ OFFCANVAS --- */
.offcanvas-backdrop { position: fixed; top: 100px; left: 0; right: 0; height: calc(100vh - 100px); background: rgba(15, 23, 42, 0.4); z-index: 1000; backdrop-filter: blur(4px); }
.order-offcanvas-global { position: fixed; top: 100px; right: 0; width: 420px; max-width: 100%; height: calc(100vh - 100px); background: #ffffff; z-index: 1001; display: flex; flex-direction: column; box-shadow: -10px 0 30px rgba(0, 0, 0, 0.1); }

.offcanvas-header { padding: 12px 16px; border-bottom: 1px solid #edf2f7; display: flex; align-items: center; justify-content: space-between; }
.header-left { display: flex; align-items: center; gap: 8px; }
.header-icon { color: #a78bfb; font-size: 18px; }
.header-meta h3 { font-size: 14px; font-weight: 700; color: #1f2937; margin: 0; }

.draft-pill { display: flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; color: #10b981; text-transform: uppercase; background: #ecfdf5; padding: 1px 6px; border-radius: 12px; }
.pulse-dot { width: 5px; height: 5px; background: #10b981; border-radius: 50%; animation: pulse 2s infinite; }
@keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }

.header-actions { display: flex; gap: 6px; }
.btn-minimize, .btn-close-panel { border: none; width: 28px; height: 28px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.btn-minimize { background: #f1f5f9; color: #64748b; }
.btn-close-panel { background: #fff1f2; color: #f43f5e; }

/* --- ТРИГЕР ВИБОРУ --- */
.selection-trigger-area { background: #fff; border: 1.5px dashed #e2e8f0; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: 0.2s; margin: 16px; }
.selection-trigger-area:hover { border-color: #a78bfb; background: #f5f3ff; }
.icon-circle { width: 36px; height: 36px; background: #f1f5f9; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; color: #94a3b8; }
.selection-trigger-area p { font-size: 13px; font-weight: 700; color: #1e293b; margin: 0; }

/* --- СПИСОК ТОВАРІВ (КОМПАКТНИЙ) --- */
.offcanvas-body { flex: 1; padding: 0 16px 16px; overflow-y: auto; background: #f8fafc; }
.order-block { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); margin-top: 16px; }
.block-title { font-size: 11px; text-transform: uppercase; color: #94a3b8; font-weight: 700; margin-bottom: 10px; }

.cart-item { display: flex; gap: 10px; padding-bottom: 10px; margin-bottom: 10px; border-bottom: 1px solid #f8fafc; }
.cart-item:last-child { border-bottom: none; margin-bottom: 0; }

.cart-thumb { width: 45px; height: 45px; border-radius: 8px; object-fit: cover; background: #f1f5f9; flex-shrink: 0; }
.cart-content { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: space-between; }

.cart-row-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.cart-title { font-size: 12px; font-weight: 600; color: #1e293b; line-height: 1.3; }
.btn-remove-tiny { background: none; border: none; color: #cbd5e0; cursor: pointer; padding: 2px; font-size: 14px; }
.btn-remove-tiny:hover { color: #f43f5e; }

.cart-row-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 4px; }

.quantity-control-mini { display: flex; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; background: #f8fafc; }
.quantity-control-mini button { width: 22px; height: 22px; border: none; background: transparent; cursor: pointer; font-size: 14px; color: #64748b; font-weight: bold; }
.quantity-control-mini button:hover { background: #fff; color: #a78bfb; }
.qty-val { width: 24px; text-align: center; font-size: 11px; font-weight: 700; line-height: 22px; color: #1e293b; }

.item-price-sum { font-size: 13px; font-weight: 700; color: #1e293b; }

/* КНОПКА ДОДАТИ ЩЕ */
.btn-add-more-small { background: #f5f3ff; border: 1px solid #ddd6fe; color: #7c3aed; width: 100%; padding: 6px; border-radius: 8px; font-weight: 700; cursor: pointer; margin-top: 10px; font-size: 11px; display: flex; align-items: center; justify-content: center; gap: 6px; }
.btn-add-more-small:hover { background: #7c3aed; color: #fff; }

.order-total { display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f1f5f9; padding-top: 10px; margin-top: 10px; }
.order-total span { font-size: 12px; color: #64748b; font-weight: 600; }
.total-price { color: #a78bfb; font-size: 16px; font-weight: 800; }

/* FOOTER */
.offcanvas-footer { padding: 12px 16px; border-top: 1px solid #edf2f7; background: #ffffff; }
.btn-save-modern { background: #a78bfb; color: #ffffff; border: none; border-radius: 8px; height: 40px; width: 100%; cursor: pointer; font-weight: 700; font-size: 13px; transition: 0.3s; }
.btn-save-modern:disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }

/* МОДАЛКА ВИБОРУ */
.picker-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 2000; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
.product-selection-modal { background: #fff; width: 500px; max-width: 95%; max-height: 80vh; border-radius: 20px; display: flex; flex-direction: column; overflow: hidden; }
.modal-header { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
.modal-header h4 { font-size: 15px; font-weight: 700; margin: 0; }
.selection-list-item { display: flex; align-items: center; padding: 10px 20px; cursor: pointer; border-bottom: 1px solid #f8fafc; gap: 12px; }
.checkbox-ui { width: 18px; height: 18px; border: 2px solid #cbd5e1; border-radius: 5px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 12px; }
.checkbox-ui.checked { background: #a78bfb; border-color: #a78bfb; }
.item-img { width: 40px; height: 40px; border-radius: 6px; object-fit: cover; }
.item-name { font-size: 13px; font-weight: 700; }
.item-meta { font-size: 11px; color: #94a3b8; }
.item-price { font-size: 14px; font-weight: 800; color: #1e293b; margin-left: auto; }

/* ANIMATIONS */
.slide-global-enter-active, .slide-global-leave-active { transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-global-enter-from, .slide-global-leave-to { transform: translateX(100%); }
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

@media (max-width: 768px) {
  .order-offcanvas-global, .product-selection-modal { width: 100%; height: 100%; border-radius: 0; top: 0; }
  .offcanvas-backdrop { top: 0; height: 100vh; }
}
</style>