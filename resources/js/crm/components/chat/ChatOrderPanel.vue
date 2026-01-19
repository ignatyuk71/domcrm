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
            <button class="btn-minimize" type="button" @click="handleMinimize" title="Згорнути">
              <i class="bi bi-fullscreen-exit"></i>
            </button>
            <button class="btn-close-panel" type="button" @click="handleClose" title="Видалити чернетку">
              <i class="bi bi-trash3"></i>
            </button>
          </div>
        </div>

        <div class="offcanvas-body custom-scrollbar">
          <div 
            v-if="!hasDraft" 
            class="selection-trigger-area" 
            @click="openPicker"
          >
            <div class="trigger-content">
              <div class="icon-circle">
                <i class="bi bi-plus-lg"></i>
              </div>
              <p>Натисніть, щоб вибрати товари</p>
              <span>Відкриється список доступних моделей</span>
            </div>
          </div>

          <div class="order-block" v-if="hasDraft">
            <div class="block-header">
              <div class="block-title">Товари у замовленні</div>
              <span class="items-count">{{ orderDraft.items.length }} поз.</span>
            </div>

            <div class="cart-list">
              <div v-for="(item, index) in orderDraft.items" :key="item.id" class="cart-item">
                <div class="cart-item-main">
                   <img :src="item.image" alt="product" class="cart-thumb">
                   <div class="cart-item-info">
                     <div class="cart-item-name">{{ item.title }}</div>
                     <div class="cart-item-price-single">{{ formatMoney(item.price) }} / шт.</div>
                   </div>
                   <button class="btn-remove-item" type="button" @click="removeSingleItem(index)">
                     <i class="bi bi-x-lg"></i>
                   </button>
                </div>
                <div class="cart-item-controls">
                   <div class="quantity-control">
                     <button type="button" @click="item.qty > 1 ? item.qty-- : null" :disabled="item.qty <= 1">-</button>
                     <input type="number" v-model.number="item.qty" min="1" class="qty-input">
                     <button type="button" @click="item.qty++">+</button>
                   </div>
                   <div class="cart-item-sum">
                     {{ formatMoney(item.price * item.qty) }} грн
                   </div>
                </div>
              </div>

              <button class="btn-add-more" type="button" @click="openPicker">
                <i class="bi bi-plus"></i> Додати ще товар
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
            <div class="header-with-subtitle">
              <h4>Виберіть товари</h4>
              <span class="modal-subtitle">Доступно {{ mockProducts.length }} позицій</span>
            </div>
            <button class="btn-close-modal" @click="productPickerOpen = false">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>
          
          <div class="modal-body custom-scrollbar">
            <div class="product-list">
              <div 
                v-for="product in mockProducts" 
                :key="product.id" 
                class="selection-list-item"
                :class="{ 'is-selected': selectedProductIds.includes(product.id) }"
                @click="toggleProductSelection(product)"
              >
                <div class="item-checkbox">
                  <div class="checkbox-ui" :class="{ 'checked': selectedProductIds.includes(product.id) }">
                    <i class="bi bi-check-lg" v-if="selectedProductIds.includes(product.id)"></i>
                  </div>
                </div>
                
                <img :src="product.image" alt="p" class="item-img">
                
                <div class="item-details">
                  <div class="item-name">{{ product.title }}</div>
                  <div class="item-meta">
                    <span class="sku">Арт: {{ product.sku }}</span>
                    <span class="divider">•</span>
                    <span class="stock" :class="{ 'low-stock': product.stock < 3 }">
                      Залишок: {{ product.stock }} шт.
                    </span>
                  </div>
                </div>

                <div class="item-price-box">
                  <div class="item-price">{{ formatMoney(product.price) }} ₴</div>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button class="btn-cancel" @click="productPickerOpen = false">Скасувати</button>
            <button 
              class="btn-confirm-selection" 
              :disabled="selectedProductIds.length === 0"
              @click="handleAddProducts"
            >
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
const totalAmount = computed(() =>
  (props.orderDraft.items || []).reduce((sum, item) => sum + (item.price * item.qty), 0)
);

const openPicker = () => {
  selectedProductIds.value = props.orderDraft.items.map(item => item.id);
  productPickerOpen.value = true;
};

const toggleProductSelection = (product) => {
  const index = selectedProductIds.value.indexOf(product.id);
  if (index > -1) selectedProductIds.value.splice(index, 1);
  else selectedProductIds.value.push(product.id);
};

const handleAddProducts = () => {
  const newItems = mockProducts
    .filter(p => selectedProductIds.value.includes(p.id))
    .map(p => {
      const existing = props.orderDraft.items.find(item => item.id === p.id);
      return {
        id: p.id,
        title: p.title,
        sku: p.sku,
        price: p.price,
        image: p.image,
        qty: existing ? existing.qty : 1
      };
    });
  
  props.orderDraft.items = newItems;
  productPickerOpen.value = false;
};

const removeSingleItem = (index) => { props.orderDraft.items.splice(index, 1); };
const handleClose = () => { emit('close'); };
const handleMinimize = () => { emit('minimize'); };
const handleSaved = () => { emit('saved'); };
const formatMoney = (value) => Number(value || 0).toFixed(2);
</script>

<style scoped>
/* ВИПРАВЛЕНО: Відступи від шапки CRM */
/* Припустимо, шапка вашої CRM має висоту 100px (як на скріншоті) */
.offcanvas-backdrop { 
  position: fixed; 
  top: 100px; /* Змініть це значення під вашу шапку */
  left: 0; 
  right: 0;
  height: calc(100vh - 100px); 
  background: rgba(15, 23, 42, 0.4); 
  z-index: 1000; 
  backdrop-filter: blur(4px); 
}

.order-offcanvas-global { 
  position: fixed; 
  top: 100px; /* Змініть це значення під вашу шапку */
  right: 0; 
  width: 450px; 
  max-width: 100%; 
  height: calc(100vh - 100px); 
  background: #ffffff; 
  z-index: 1001; 
  display: flex; 
  flex-direction: column; 
  box-shadow: -10px 0 30px rgba(0, 0, 0, 0.1); 
}

/* Інші стилі залишаються як були */
.offcanvas-header { padding: 16px 20px; border-bottom: 1px solid #edf2f7; display: flex; align-items: center; justify-content: space-between; }
.header-left { display: flex; align-items: center; gap: 10px; }
.header-icon { color: #a78bfb; font-size: 18px; }
.offcanvas-header h3 { font-size: 16px; font-weight: 700; color: #1f2937; margin: 0; }
.draft-pill { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; color: #10b981; text-transform: uppercase; background: #ecfdf5; padding: 2px 8px; border-radius: 20px; }
.pulse-dot { width: 6px; height: 6px; background: #10b981; border-radius: 50%; animation: pulse 2s infinite; }
@keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
.header-actions { display: flex; gap: 8px; }
.btn-minimize, .btn-close-panel { border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
.btn-minimize { background: #f1f5f9; color: #64748b; }
.btn-close-panel { background: #fff1f2; color: #f43f5e; }
.selection-trigger-area { background: #ffffff; border: 2px dashed #e2e8f0; border-radius: 20px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); margin: 20px; }
.selection-trigger-area:hover { border-color: #a78bfb; background: #f5f3ff; transform: translateY(-2px); }
.selection-trigger-area:active { transform: scale(0.97); }
.icon-circle { width: 50px; height: 50px; background: #f1f5f9; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: #94a3b8; transition: 0.3s; }
.selection-trigger-area:hover .icon-circle { background: #a78bfb; color: #fff; transform: rotate(90deg); }
.offcanvas-body { flex: 1; padding: 0 20px 20px; overflow-y: auto; background: #f8fafc; }
.order-block { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); margin-top: 20px; }
.block-title { font-size: 12px; text-transform: uppercase; color: #94a3b8; font-weight: 700; margin-bottom: 12px; }
.cart-item { border-bottom: 1px solid #f8fafc; padding-bottom: 12px; margin-bottom: 12px; }
.cart-item-main { display: flex; align-items: center; gap: 12px; }
.cart-thumb { width: 50px; height: 50px; border-radius: 10px; object-fit: cover; }
.cart-item-info { flex: 1; }
.cart-item-name { font-size: 14px; font-weight: 600; color: #1e293b; }
.cart-item-price-single { font-size: 12px; color: #64748b; }
.btn-remove-item { border: none; background: none; color: #f87171; cursor: pointer; padding: 5px; }
.cart-item-controls { display: flex; align-items: center; justify-content: space-between; margin-top: 10px; }
.quantity-control { display: flex; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #f8fafc; }
.quantity-control button { width: 28px; height: 28px; border: none; background: transparent; cursor: pointer; font-weight: bold; }
.qty-input { width: 35px; border: none; text-align: center; font-size: 13px; font-weight: 700; background: transparent; }
.btn-add-more { background: transparent; border: 1.5px solid #a78bfb; color: #a78bfb; width: 100%; padding: 10px; border-radius: 12px; font-weight: 700; cursor: pointer; margin-top: 10px; }
.order-total { display: flex; justify-content: space-between; border-top: 1px solid #f1f5f9; padding-top: 12px; margin-top: 12px; }
.total-price { color: #a78bfb; font-size: 18px; font-weight: 800; }
.offcanvas-footer { padding: 16px; border-top: 1px solid #edf2f7; background: #ffffff; }
.btn-save-modern { background: #a78bfb; color: #ffffff; border: none; border-radius: 10px; height: 44px; width: 100%; cursor: pointer; font-weight: 700; transition: 0.3s; }
.btn-save-modern:disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }
.picker-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 2000; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
.product-selection-modal { background: #fff; width: 550px; max-width: 95%; max-height: 80vh; border-radius: 24px; display: flex; flex-direction: column; overflow: hidden; }
.modal-header { padding: 20px 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
.selection-list-item { display: flex; align-items: center; padding: 12px 24px; cursor: pointer; transition: 0.2s; border-bottom: 1px solid #f8fafc; gap: 16px; }
.selection-list-item:hover, .selection-list-item.is-selected { background: #f5f3ff; }
.checkbox-ui { width: 22px; height: 22px; border: 2px solid #cbd5e1; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #fff; }
.checkbox-ui.checked { background: #a78bfb; border-color: #a78bfb; }
.item-img { width: 50px; height: 50px; border-radius: 10px; object-fit: cover; }
.item-details { flex: 1; }
.item-name { font-size: 14px; font-weight: 700; color: #1e293b; }
.item-meta { font-size: 12px; color: #64748b; display: flex; gap: 8px; }
.item-price { font-size: 15px; font-weight: 800; color: #1e293b; }
.modal-footer { padding: 16px 24px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 12px; }
.btn-cancel { background: #fff; border: 1px solid #e2e8f0; padding: 10px 20px; border-radius: 12px; cursor: pointer; color: #64748b; }
.btn-confirm-selection { background: #a78bfb; color: #fff; border: none; padding: 10px 20px; border-radius: 12px; cursor: pointer; font-weight: 700; }
.slide-global-enter-active, .slide-global-leave-active { transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-global-enter-from, .slide-global-leave-to { transform: translateX(100%); }

@media (max-width: 768px) {
  .order-offcanvas-global, .product-selection-modal { width: 100%; height: 100%; border-radius: 0; top: 0; }
  .offcanvas-backdrop { top: 0; height: 100vh; }
}
</style>