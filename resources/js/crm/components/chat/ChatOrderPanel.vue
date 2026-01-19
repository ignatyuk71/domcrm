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
              <button class="btn-open-selection" type="button" @click="openProductModal">
                <i class="bi bi-plus-circle-fill"></i> Вибрати товари
              </button>
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
            </div>
            
            <div v-else class="empty-cart-placeholder" @click="openProductModal">
              <i class="bi bi-basket3"></i>
              <p>Натисніть, щоб вибрати товари</p>
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
            <h4>Виберіть товари</h4>
            <button class="btn-close-modal" @click="closeProductModal"><i class="bi bi-x-lg"></i></button>
          </div>
          
          <div class="modal-body custom-scrollbar">
            <div class="product-grid">
              <div 
                v-for="product in availableProducts" 
                :key="product.id" 
                class="selection-card"
                :class="{ 'selected': isSelected(product) }"
                @click="toggleProductSelection(product)"
              >
                <div class="selection-check">
                  <i class="bi" :class="isSelected(product) ? 'bi-check-circle-fill' : 'bi-circle'"></i>
                </div>
                <img :src="product.image" alt="p" class="selection-img">
                <div class="selection-info">
                  <div class="p-name">{{ product.name }}</div>
                  <div class="p-sku">Арт: {{ product.sku }}</div>
                  <div class="p-stock">В наявності: <b>{{ product.stock }} шт.</b></div>
                  <div class="p-price">{{ formatPrice(product.price) }}</div>
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
              Додати вибрані ({{ tempSelected.length }})
            </button>
          </div>
        </div>
      </div>
    </transition>

  </teleport>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';

const props = defineProps({
  customer: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const isVisible = ref(false);
const isProductModalVisible = ref(false);
const cartItems = ref([]);
const tempSelected = ref([]); // Тимчасовий список вибраних у модалці

// Тестові товари з бази (Mock)
const availableProducts = [
  { id: 101, name: 'Домашні капці "Пухнастики" Рожеві', sku: 'SLP-PNK-38', price: 450, stock: 5, image: 'https://picsum.photos/id/102/150/150' },
  { id: 102, name: 'Капці з вушками Зайчик Сірі', sku: 'SLP-GRY-40', price: 520, stock: 2, image: 'https://picsum.photos/id/103/150/150' },
  { id: 103, name: 'Набір шкарпеток "Тепло" (3 пари)', sku: 'SCK-SET-01', price: 199, stock: 10, image: 'https://picsum.photos/id/107/150/150' },
  { id: 104, name: 'Капці чоловічі "Спорт"', sku: 'SLP-MN-42', price: 480, stock: 3, image: 'https://picsum.photos/id/119/150/150' },
  { id: 105, name: 'Дитячі капці "Левеня"', sku: 'SLP-KID-25', price: 350, stock: 8, image: 'https://picsum.photos/id/146/150/150' },
];

onMounted(() => {
  setTimeout(() => { isVisible.value = true; }, 10);
});

const handleClose = () => {
  isVisible.value = false;
  setTimeout(() => { emit('close'); }, 300);
};

const handleSaved = () => {
  emit('saved');
  handleClose();
};

// --- МОДАЛЬНЕ ВІКНО ---
const openProductModal = () => {
  // Копіюємо існуючі id, щоб вони були виділені в модалці
  tempSelected.value = cartItems.value.map(item => ({...item}));
  isProductModalVisible.value = true;
};

const closeProductModal = () => {
  isProductModalVisible.value = false;
};

const isSelected = (product) => {
  return tempSelected.value.some(p => p.id === product.id);
};

const toggleProductSelection = (product) => {
  const index = tempSelected.value.findIndex(p => p.id === product.id);
  if (index > -1) {
    tempSelected.value.splice(index, 1);
  } else {
    tempSelected.value.push({ ...product, quantity: 1 });
  }
};

const confirmSelection = () => {
  // Оновлюємо кошик вибраними товарами
  // Якщо товар вже був у кошику, залишаємо його кількість, якщо ні - додаємо новий
  const newCart = tempSelected.value.map(tempItem => {
    const existing = cartItems.value.find(c => c.id === tempItem.id);
    return existing ? existing : tempItem;
  });
  
  cartItems.value = newCart;
  closeProductModal();
};

// --- КОШИК ---
const canSave = computed(() => cartItems.value.length > 0);
const calculateTotal = computed(() => cartItems.value.reduce((total, item) => total + (item.price * item.quantity), 0));
const formatPrice = (value) => new Intl.NumberFormat('uk-UA', { style: 'currency', currency: 'UAH' }).format(value);

const removeItem = (index) => { cartItems.value.splice(index, 1); };
const incrementQty = (item) => { item.quantity++; };
const decrementQty = (item) => { if (item.quantity > 1) item.quantity--; };

</script>

<style scoped>
/* === OFFCANVAS STYLES === */
.offcanvas-backdrop {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  background: rgba(0, 0, 0, 0.4); z-index: 1000; backdrop-filter: blur(4px);
}

.order-offcanvas-global {
  position: fixed; top: 0; right: 0;
  width: 450px; max-width: 100%; height: 100vh; background: #ffffff; z-index: 1001;
  display: flex; flex-direction: column; box-shadow: -10px 0 30px rgba(0, 0, 0, 0.15);
}

.offcanvas-header {
  padding: 16px; border-bottom: 1px solid #edf2f7; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
}
.header-left { display: flex; align-items: center; gap: 10px; }
.header-icon { color: #a78bfb; font-size: 18px; }
.offcanvas-header h3 { font-size: 16px; font-weight: 700; margin: 0; }

.offcanvas-body { flex: 1; padding: 20px; overflow-y: auto; background: #f8fafc; display: flex; flex-direction: column; gap: 20px; }

/* СЕКЦІЯ ТОВАРІВ */
.section-header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
.section-title { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0; }

.btn-open-selection {
  background: #f5f3ff; color: #7c3aed; border: 1px solid #ddd6fe; padding: 8px 14px; border-radius: 10px;
  font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: 0.2s;
}
.btn-open-selection:hover { background: #7c3aed; color: #fff; }

.cart-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px; display: flex; flex-direction: column; gap: 10px; margin-bottom: 10px; }
.cart-item-main { display: flex; align-items: center; gap: 12px; }
.cart-thumb { width: 50px; height: 50px; border-radius: 8px; object-fit: cover; }
.cart-item-info { flex: 1; }
.cart-item-name { font-size: 13px; font-weight: 600; color: #1e293b; }
.cart-item-price-single { font-size: 12px; color: #64748b; }
.btn-remove-item { border: none; background: none; color: #ef4444; cursor: pointer; padding: 5px; }

.cart-item-controls { display: flex; align-items: center; justify-content: space-between; border-top: 1px dashed #f1f5f9; padding-top: 10px; }
.quantity-control { display: flex; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; }
.quantity-control button { width: 28px; height: 28px; border: none; background: #f8fafc; cursor: pointer; }
.qty-input { width: 35px; border: none; text-align: center; font-size: 13px; font-weight: 600; }

.empty-cart-placeholder { border: 2px dashed #e2e8f0; padding: 30px; text-align: center; border-radius: 15px; color: #94a3b8; cursor: pointer; }
.empty-cart-placeholder i { font-size: 30px; margin-bottom: 10px; display: block; }

.summary-section { background: #fff; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0; }
.summary-row { display: flex; justify-content: space-between; font-weight: 700; font-size: 16px; }
.total-price { color: #a78bfb; }

/* === МОДАЛЬНЕ ВІКНО ВИБОРУ === */
.modal-overlay {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  background: rgba(0,0,0,0.5); z-index: 2000; display: flex; align-items: center; justify-content: center;
}
.product-selection-modal {
  background: #fff; width: 600px; max-width: 95%; max-height: 85vh; border-radius: 20px;
  display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}
.modal-header { padding: 20px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
.modal-header h4 { margin: 0; font-size: 18px; font-weight: 800; color: #1e293b; }
.btn-close-modal { border: none; background: #f1f5f9; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; }

.modal-body { flex: 1; padding: 20px; overflow-y: auto; background: #f9fafb; }

/* СІТКА ТОВАРІВ У МОДАЛЦІ */
.product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 15px; }
.selection-card {
  background: #fff; border: 2px solid #fff; border-radius: 15px; padding: 12px; cursor: pointer;
  transition: 0.2s; position: relative; display: flex; flex-direction: column; align-items: center; text-align: center;
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
}
.selection-card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
.selection-card.selected { border-color: #a78bfb; background: #f5f3ff; }

.selection-check { position: absolute; top: 8px; right: 8px; font-size: 18px; color: #cbd5e1; }
.selected .selection-check { color: #a78bfb; }

.selection-img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 10px; margin-bottom: 10px; }
.p-name { font-size: 13px; font-weight: 700; color: #1e293b; height: 38px; overflow: hidden; margin-bottom: 5px; }
.p-sku { font-size: 11px; color: #94a3b8; margin-bottom: 3px; }
.p-stock { font-size: 11px; color: #64748b; margin-bottom: 5px; }
.p-price { font-size: 14px; font-weight: 800; color: #a78bfb; }

.modal-footer { padding: 15px 20px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 12px; }
.btn-cancel { background: #fff; border: 1px solid #e2e8f0; padding: 10px 20px; border-radius: 10px; cursor: pointer; font-weight: 600; }
.btn-confirm-selection { background: #a78bfb; color: #fff; border: none; padding: 10px 20px; border-radius: 10px; cursor: pointer; font-weight: 700; }
.btn-confirm-selection:disabled { background: #e2e8f0; cursor: not-allowed; }

/* FOOTER OFFCANVAS */
.offcanvas-footer { padding: 16px; border-top: 1px solid #edf2f7; background: #ffffff; }
.btn-save-modern {
  background: #a78bfb; color: #ffffff; border: none; border-radius: 8px; height: 44px;
  width: 100%; cursor: pointer; font-weight: 600; transition: 0.3s;
}

/* ANIMATIONS */
.slide-global-enter-active, .slide-global-leave-active { transition: 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-global-enter-from, .slide-global-leave-to { transform: translateX(100%); }

.modal-fade-enter-active, .modal-fade-leave-active { transition: opacity 0.3s ease; }
.modal-fade-enter-from, .modal-fade-leave-to { opacity: 0; }

.custom-scrollbar::-webkit-scrollbar { width: 5px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; }

@media (max-width: 768px) {
  .product-selection-modal { width: 100%; height: 100%; max-height: 100%; border-radius: 0; }
  .product-grid { grid-template-columns: 1fr 1fr; }
}
</style>