<template>
  <teleport to="body">
    <transition name="fade">
      <div v-if="isVisible" class="offcanvas-backdrop" @click="handleClose"></div>
    </transition>

    <transition name="slide-global">
      <div v-if="isVisible" class="order-offcanvas-global">
        <div class="offcanvas-header">
          <div class="header-left">
            <i class="bi bi-bag-check-fill header-icon"></i>
            <h3>Нове замовлення</h3>
          </div>
          <button class="btn-close-panel" type="button" @click="handleClose">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>

        <div class="offcanvas-body custom-scrollbar">
          
          <div class="cart-section">
            <div class="section-header-row">
              <h4 class="section-title">Товари у замовленні</h4>
            </div>

            <div v-if="cartItems.length > 0" class="cart-items-list">
              <div v-for="(item, index) in cartItems" :key="item.id" class="cart-item">
                <div class="cart-item-main">
                   <img :src="item.image" alt="product" class="cart-thumb">
                   <div class="cart-item-info">
                     <div class="cart-item-name">{{ item.name }}</div>
                     <div class="cart-item-price-single">{{ formatPrice(item.price) }}</div>
                   </div>
                   <button class="btn-remove-item" type="button" @click="removeItem(index)">
                     <i class="bi bi-trash3"></i>
                   </button>
                </div>
                <div class="cart-item-controls">
                   <div class="quantity-control">
                     <button type="button" @click="decrementQty(item)" :disabled="item.quantity <= 1">-</button>
                     <input type="number" v-model.number="item.quantity" min="1" class="qty-input">
                     <button type="button" @click="incrementQty(item)">+</button>
                   </div>
                   <div class="cart-item-sum">
                     {{ formatPrice(item.price * item.quantity) }}
                   </div>
                </div>
              </div>

              <button class="btn-add-more" @click="openProductModal">
                <i class="bi bi-plus-lg"></i> Додати ще товар
              </button>
            </div>
            
            <div v-else class="selection-trigger-area" @click="openProductModal">
              <div class="trigger-content">
                <div class="icon-circle">
                  <i class="bi bi-plus-lg"></i>
                </div>
                <p>Натисніть, щоб вибрати товари</p>
                <span>Відкриється список доступних моделей</span>
              </div>
            </div>
          </div>
          
          <div v-if="cartItems.length > 0" class="summary-section">
            <div class="summary-row">
              <span>Загальна сума:</span>
              <span class="total-price">{{ formatPrice(calculateTotal) }}</span>
            </div>
          </div>

        </div>

        <div class="offcanvas-footer">
          <button class="btn-save-modern" type="button" :disabled="!canSave" @click="handleSaved">
            Зберегти замовлення
          </button>
        </div>
      </div>
    </transition>

    <transition name="modal-fade">
      <div v-if="isProductModalVisible" class="modal-overlay" @click.self="closeProductModal">
        <div class="product-selection-modal">
          <div class="modal-header">
            <div class="header-with-subtitle">
              <h4>Виберіть товари</h4>
              <span class="modal-subtitle">Доступно {{ availableProducts.length }} позицій</span>
            </div>
            <button class="btn-close-modal" @click="closeProductModal"><i class="bi bi-x-lg"></i></button>
          </div>
          
          <div class="modal-body custom-scrollbar">
            <div class="product-list">
              <div 
                v-for="product in availableProducts" 
                :key="product.id" 
                class="selection-list-item"
                :class="{ 'is-selected': isSelected(product) }"
                @click="toggleProductSelection(product)"
              >
                <div class="item-checkbox">
                  <div class="checkbox-ui" :class="{ 'checked': isSelected(product) }">
                    <i class="bi bi-check-lg" v-if="isSelected(product)"></i>
                  </div>
                </div>
                
                <img :src="product.image" alt="p" class="item-img">
                
                <div class="item-details">
                  <div class="item-name">{{ product.name }}</div>
                  <div class="item-meta">
                    <span class="sku">Арт: {{ product.sku }}</span>
                    <span class="divider">•</span>
                    <span class="stock" :class="{ 'low-stock': product.stock < 3 }">
                      Залишок: {{ product.stock }} шт.
                    </span>
                  </div>
                </div>

                <div class="item-price-box">
                  <div class="item-price">{{ formatPrice(product.price) }}</div>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button class="btn-cancel" @click="closeProductModal">Скасувати</button>
            <button 
              class="btn-confirm-selection" 
              :disabled="tempSelected.length === 0"
              @click="confirmSelection"
            >
              Додати в замовлення ({{ tempSelected.length }})
            </button>
          </div>
        </div>
      </div>
    </transition>

  </teleport>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';

const props = defineProps({ customer: Object });
const emit = defineEmits(['close', 'saved']);

const isVisible = ref(false);
const isProductModalVisible = ref(false);
const cartItems = ref([]);
const tempSelected = ref([]);

const availableProducts = [
  { id: 101, name: 'Домашні капці "Пухнастики" Рожеві', sku: 'SLP-PNK-38', price: 450, stock: 12, image: 'https://picsum.photos/id/102/100/100' },
  { id: 102, name: 'Капці з вушками Зайчик Сірі', sku: 'SLP-GRY-40', price: 520, stock: 1, image: 'https://picsum.photos/id/103/100/100' },
  { id: 103, name: 'Набір шкарпеток "Тепло" (3 пари)', sku: 'SCK-SET-01', price: 199, stock: 45, image: 'https://picsum.photos/id/107/100/100' },
  { id: 104, name: 'Капці чоловічі "Спорт"', sku: 'SLP-MN-42', price: 480, stock: 5, image: 'https://picsum.photos/id/119/100/100' },
  { id: 105, name: 'Дитячі капці "Левеня"', sku: 'SLP-KID-25', price: 350, stock: 8, image: 'https://picsum.photos/id/146/100/100' },
];

onMounted(() => { setTimeout(() => { isVisible.value = true; }, 10); });
const handleClose = () => { isVisible.value = false; setTimeout(() => { emit('close'); }, 300); };
const handleSaved = () => { emit('saved'); handleClose(); };

const openProductModal = () => {
  tempSelected.value = cartItems.value.map(item => ({...item}));
  isProductModalVisible.value = true;
};
const closeProductModal = () => { isProductModalVisible.value = false; };
const isSelected = (product) => tempSelected.value.some(p => p.id === product.id);

const toggleProductSelection = (product) => {
  const index = tempSelected.value.findIndex(p => p.id === product.id);
  if (index > -1) tempSelected.value.splice(index, 1);
  else tempSelected.value.push({ ...product, quantity: 1 });
};

const confirmSelection = () => {
  const newCart = tempSelected.value.map(tempItem => {
    const existing = cartItems.value.find(c => c.id === tempItem.id);
    return existing ? existing : tempItem;
  });
  cartItems.value = newCart;
  closeProductModal();
};

const canSave = computed(() => cartItems.value.length > 0);
const calculateTotal = computed(() => cartItems.value.reduce((total, item) => total + (item.price * item.quantity), 0));
const formatPrice = (value) => new Intl.NumberFormat('uk-UA', { style: 'currency', currency: 'UAH' }).format(value);
const removeItem = (index) => { cartItems.value.splice(index, 1); };
const incrementQty = (item) => { item.quantity++; };
const decrementQty = (item) => { if (item.quantity > 1) item.quantity--; };
</script>

<style scoped>
/* === OFFCANVAS STYLES === */
.offcanvas-backdrop { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.4); z-index: 1000; backdrop-filter: blur(4px); }
.order-offcanvas-global { position: fixed; top: 0; right: 0; width: 450px; max-width: 100%; height: 100vh; background: #ffffff; z-index: 1001; display: flex; flex-direction: column; box-shadow: -10px 0 30px rgba(0, 0, 0, 0.1); }
.offcanvas-header { padding: 16px 20px; border-bottom: 1px solid #edf2f7; display: flex; align-items: center; justify-content: space-between; }
.header-left { display: flex; align-items: center; gap: 10px; }
.header-icon { color: #a78bfb; font-size: 18px; }
.offcanvas-body { flex: 1; padding: 20px; overflow-y: auto; background: #f8fafc; }

/* АНІМОВАНЕ ПОЛЕ ВИБОРУ */
.selection-trigger-area {
  background: #ffffff; border: 2px dashed #e2e8f0; border-radius: 20px; padding: 40px 20px; text-align: center; cursor: pointer;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden;
}
.selection-trigger-area:hover { border-color: #a78bfb; background: #f5f3ff; transform: translateY(-2px); }
.selection-trigger-area:active { transform: scale(0.98); background: #ede9fe; }

.trigger-content .icon-circle {
  width: 50px; height: 50px; background: #f1f5f9; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;
  color: #94a3b8; transition: 0.3s;
}
.selection-trigger-area:hover .icon-circle { background: #a78bfb; color: #fff; transform: rotate(90deg); }
.trigger-content p { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 5px; }
.trigger-content span { font-size: 13px; color: #94a3b8; }

/* СПИСОК ВИБРАНИХ В КЕРУВАННІ */
.cart-items-list { display: flex; flex-direction: column; gap: 12px; }
.cart-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
.cart-item-main { display: flex; align-items: center; gap: 12px; }
.cart-thumb { width: 50px; height: 50px; border-radius: 10px; object-fit: cover; }
.cart-item-info { flex: 1; }
.cart-item-name { font-size: 14px; font-weight: 600; color: #1e293b; }
.cart-item-price-single { font-size: 12px; color: #64748b; margin-top: 2px; }
.btn-remove-item { border: none; background: none; color: #f87171; cursor: pointer; padding: 5px; border-radius: 6px; }
.btn-remove-item:hover { background: #fef2f2; }
.cart-item-controls { display: flex; align-items: center; justify-content: space-between; border-top: 1px solid #f8fafc; padding-top: 10px; margin-top: 10px; }
.quantity-control { display: flex; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #f8fafc; }
.quantity-control button { width: 30px; height: 30px; border: none; background: transparent; cursor: pointer; font-weight: bold; color: #64748b; }
.quantity-control button:hover { background: #fff; color: #a78bfb; }
.qty-input { width: 35px; border: none; text-align: center; font-size: 13px; font-weight: 700; background: transparent; }
.btn-add-more { background: transparent; border: 1.5px solid #a78bfb; color: #a78bfb; width: 100%; padding: 10px; border-radius: 12px; font-weight: 700; cursor: pointer; margin-top: 10px; font-size: 13px; }

.summary-section { background: #fff; padding: 16px; border-radius: 16px; border: 1px solid #e2e8f0; margin-top: 20px; }
.summary-row { display: flex; justify-content: space-between; font-weight: 800; font-size: 16px; }
.total-price { color: #a78bfb; }

/* === МОДАЛКА СПИСКОМ === */
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); z-index: 2000; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
.product-selection-modal { background: #fff; width: 550px; max-width: 95%; max-height: 80vh; border-radius: 24px; display: flex; flex-direction: column; overflow: hidden; }
.modal-header { padding: 20px 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
.modal-subtitle { font-size: 12px; color: #94a3b8; font-weight: 500; }
.btn-close-modal { border: none; background: #f1f5f9; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; }

.modal-body { flex: 1; padding: 10px 0; overflow-y: auto; background: #fff; }

/* LIST VIEW STYLES */
.product-list { display: flex; flex-direction: column; }
.selection-list-item {
  display: flex; align-items: center; padding: 12px 24px; cursor: pointer; transition: 0.2s; border-bottom: 1px solid #f8fafc; gap: 16px;
}
.selection-list-item:hover { background: #f5f3ff; }
.selection-list-item.is-selected { background: #f5f3ff; }

.item-checkbox { flex-shrink: 0; }
.checkbox-ui { width: 22px; height: 22px; border: 2px solid #cbd5e1; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #fff; transition: 0.2s; }
.checkbox-ui.checked { background: #a78bfb; border-color: #a78bfb; }

.item-img { width: 50px; height: 50px; border-radius: 10px; object-fit: cover; flex-shrink: 0; background: #f1f5f9; }
.item-details { flex: 1; min-width: 0; }
.item-name { font-size: 14px; font-weight: 700; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.item-meta { font-size: 12px; color: #64748b; margin-top: 3px; display: flex; align-items: center; gap: 6px; }
.divider { color: #e2e8f0; }
.low-stock { color: #f87171; font-weight: 600; }

.item-price-box { flex-shrink: 0; text-align: right; }
.item-price { font-size: 15px; font-weight: 800; color: #1e293b; }

.modal-footer { padding: 16px 24px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 12px; }
.btn-cancel { background: #fff; border: 1px solid #e2e8f0; padding: 10px 20px; border-radius: 12px; cursor: pointer; font-weight: 600; color: #64748b; }
.btn-confirm-selection { background: #a78bfb; color: #fff; border: none; padding: 10px 20px; border-radius: 12px; cursor: pointer; font-weight: 700; transition: 0.2s; }
.btn-confirm-selection:hover:not(:disabled) { background: #9061f9; transform: translateY(-1px); }

/* ANIMATIONS */
.slide-global-enter-active, .slide-global-leave-active { transition: 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-global-enter-from, .slide-global-leave-to { transform: translateX(100%); }
.modal-fade-enter-active, .modal-fade-leave-active { transition: opacity 0.2s ease; }
.modal-fade-enter-from, .modal-fade-leave-to { opacity: 0; }

.custom-scrollbar::-webkit-scrollbar { width: 5px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; }

@media (max-width: 768px) {
  .product-selection-modal { width: 100%; height: 100%; max-height: 100%; border-radius: 0; }
  .selection-list-item { padding: 15px 20px; }
}
</style>