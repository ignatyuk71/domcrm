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
          
          <div class="search-section">
            <div class="search-input-wrapper">
              <i class="bi bi-search search-icon"></i>
              <input 
                type="text" 
                class="search-input" 
                placeholder="Пошук товару за назвою або артикулом..."
                v-model="searchQuery"
              >
              <button v-if="searchQuery" class="clear-search-btn" @click="searchQuery = ''">
                <i class="bi bi-x-circle-fill"></i>
              </button>
            </div>

            <div v-if="searchQuery && mockSearchResults.length > 0" class="search-results-list">
              <div v-for="product in mockSearchResults" :key="product.id" class="product-card-mini">
                <img :src="product.image" alt="product" class="product-thumb">
                <div class="product-details">
                  <div class="product-name">{{ product.name }}</div>
                  <div class="product-sku">Арт: {{ product.sku }} | Залишок: {{ product.stock }} шт</div>
                  <div class="product-price">{{ formatPrice(product.price) }}</div>
                </div>
                <button class="btn-add-product" type="button" @click="addItemToCart(product)">
                  <i class="bi bi-plus-lg"></i> Додати
                </button>
              </div>
            </div>
            <div v-else-if="searchQuery && mockSearchResults.length === 0" class="no-results">
              Товарів не знайдено
            </div>
          </div>

          <div class="cart-section">
            <h4 class="section-title">
              Вибрані товари 
              <span v-if="cartItems.length" class="badge-count">{{ cartItems.length }}</span>
            </h4>

            <div v-if="cartItems.length > 0" class="cart-items-list">
              <div v-for="(item, index) in cartItems" :key="item.id" class="cart-item">
                <div class="cart-item-main">
                   <img :src="item.image" alt="product" class="cart-thumb">
                   <div class="cart-item-info">
                     <div class="cart-item-name">{{ item.name }}</div>
                     <div class="cart-item-price-single">{{ formatPrice(item.price) }} / шт.</div>
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
            
            <div v-else class="empty-cart-placeholder">
              <i class="bi bi-basket3"></i>
              <p>Додайте товари до замовлення</p>
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
  </teleport>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';

const props = defineProps({
  customer: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

// Стан видимості для анімації
const isVisible = ref(false);

onMounted(() => {
  // Невелика затримка перед показом для плавного запуску анімації
  setTimeout(() => {
    isVisible.value = true;
  }, 10);
});

const handleClose = () => {
  isVisible.value = false;
  // Чекаємо завершення анімації перед тим, як компонент буде знищено батьком
  setTimeout(() => {
    emit('close');
  }, 300); // Має співпадати з часом transition в CSS
};

const handleSaved = () => {
  // Тут буде логіка відправки на сервер
  console.log('Saving order with items:', cartItems.value);
  emit('saved');
  handleClose();
};

// --- ЛОГІКА ТОВАРІВ (ЗАГЛУШКИ) ---

const searchQuery = ref('');
const cartItems = ref([]);

// Імітація даних з бази
const mockSearchResults = computed(() => {
  if (!searchQuery.value) return [];
  // Просто повертаємо статичний список для візуалізації, якщо щось введено
  return [
    { id: 101, name: 'Домашні капці "Пухнастики" Рожеві', sku: 'SLP-PNK-38', price: 450, stock: 5, image: 'https://picsum.photos/id/102/60/60' },
    { id: 102, name: 'Капці з вушками Зайчик Сірі', sku: 'SLP-GRY-40', price: 520, stock: 2, image: 'https://picsum.photos/id/103/60/60' },
    { id: 103, name: 'Набір шкарпеток "Тепло" (3 пари)', sku: 'SCK-SET-01', price: 199, stock: 10, image: 'https://picsum.photos/id/107/60/60' },
  ];
});

const canSave = computed(() => cartItems.value.length > 0);

const calculateTotal = computed(() => {
  return cartItems.value.reduce((total, item) => total + (item.price * item.quantity), 0);
});

// Helper для форматування ціни
const formatPrice = (value) => {
  return new Intl.NumberFormat('uk-UA', { style: 'currency', currency: 'UAH' }).format(value);
};

// Дії з кошиком
const addItemToCart = (product) => {
  const existingItem = cartItems.value.find(item => item.id === product.id);
  if (existingItem) {
    existingItem.quantity++;
  } else {
    cartItems.value.push({ ...product, quantity: 1 });
  }
  // Очищаємо пошук після додавання (опціонально)
  // searchQuery.value = ''; 
};

const removeItem = (index) => {
  cartItems.value.splice(index, 1);
};

const incrementQty = (item) => {
  item.quantity++;
};

const decrementQty = (item) => {
  if (item.quantity > 1) {
    item.quantity--;
  }
};

</script>

<style scoped>
/* === GLOBAL OFFCANVAS STYLES === */
.offcanvas-backdrop {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  background: rgba(0, 0, 0, 0.3); z-index: 1000; backdrop-filter: blur(2px);
}

.order-offcanvas-global {
  position: fixed; top: 0; right: 0;
  width: 450px; /* Трохи ширше для зручності товарів */
  max-width: 100%; height: 100vh; background: #ffffff; z-index: 1001;
  display: flex; flex-direction: column; box-shadow: -10px 0 30px rgba(0, 0, 0, 0.15);
}

/* HEADER */
.offcanvas-header {
  padding: 16px; border-bottom: 1px solid #edf2f7; display: flex; align-items: center; justify-content: space-between; background: #ffffff; flex-shrink: 0;
}
.header-left { display: flex; align-items: center; gap: 10px; }
.header-icon { color: #a78bfb; font-size: 18px; }
.offcanvas-header h3 { font-size: 16px; font-weight: 700; color: #1f2937; margin: 0; }
.btn-close-panel {
  background: #f1f5f9; border: none; width: 32px; height: 32px; border-radius: 8px;
  color: #64748b; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s;
}
.btn-close-panel:hover { background: #fee2e2; color: #ef4444; }

/* BODY (SCROLLABLE) */
.offcanvas-body {
  flex: 1; padding: 20px; overflow-y: auto; background: #f8fafc;
  display: flex; flex-direction: column; gap: 24px; /* Відступи між секціями */
}

/* --- СЕКЦІЯ ПОШУКУ --- */
.search-section { display: flex; flex-direction: column; gap: 12px; }
.search-input-wrapper { position: relative; display: flex; align-items: center; }
.search-icon { position: absolute; left: 12px; color: #94a3b8; }
.search-input {
  width: 100%; padding: 10px 36px 10px 36px; border: 1px solid #e2e8f0; border-radius: 10px;
  font-size: 14px; outline: none; transition: border-color 0.2s;
}
.search-input:focus { border-color: #a78bfb; background: #fff; }
.clear-search-btn { position: absolute; right: 10px; border: none; background: none; color: #cbd5e0; cursor: pointer; }
.clear-search-btn:hover { color: #94a3b8; }

/* Результати пошуку (міні-картки) */
.search-results-list { display: flex; flex-direction: column; gap: 8px; max-height: 250px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; padding: 8px; }
.product-card-mini { display: flex; align-items: center; gap: 12px; padding: 8px; border-radius: 8px; transition: background 0.2s; border-bottom: 1px solid #f1f5f9; }
.product-card-mini:last-child { border-bottom: none; }
.product-card-mini:hover { background: #f8fafc; }
.product-thumb { width: 50px; height: 50px; border-radius: 8px; object-fit: cover; border: 1px solid #edf2f7; }
.product-details { flex: 1; min-width: 0; }
.product-name { font-size: 14px; font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.product-sku { font-size: 12px; color: #94a3b8; margin-top: 2px; }
.product-price { font-size: 14px; font-weight: 700; color: #1e293b; margin-top: 4px; }
.btn-add-product {
  background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7; padding: 6px 12px; border-radius: 8px;
  font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: 0.2s;
}
.btn-add-product:hover { background: #16a34a; color: #fff; }
.no-results { text-align: center; color: #94a3b8; padding: 12px; font-size: 14px; }

/* --- СЕКЦІЯ КОШИКА --- */
.section-title { font-size: 16px; font-weight: 700; color: #1e293b; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px; }
.badge-count { background: #a78bfb; color: white; font-size: 12px; padding: 2px 8px; border-radius: 10px; }
.cart-items-list { display: flex; flex-direction: column; gap: 12px; }
.cart-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px; display: flex; flex-direction: column; gap: 12px; }
.cart-item-main { display: flex; align-items: flex-start; gap: 12px; }
.cart-thumb { width: 60px; height: 60px; border-radius: 8px; object-fit: cover; border: 1px solid #edf2f7; }
.cart-item-info { flex: 1; min-width: 0; }
.cart-item-name { font-size: 14px; font-weight: 600; color: #1e293b; line-height: 1.4; margin-bottom: 4px; }
.cart-item-price-single { font-size: 13px; color: #64748b; }
.btn-remove-item { background: none; border: none; color: #ef4444; cursor: pointer; padding: 4px; opacity: 0.7; transition: 0.2s; }
.btn-remove-item:hover { opacity: 1; background: #fef2f2; border-radius: 6px; }

.cart-item-controls { display: flex; align-items: center; justify-content: space-between; border-top: 1px dashed #edf2f7; padding-top: 12px; }
.quantity-control { display: flex; align-items: center; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
.quantity-control button { background: #f8fafc; border: none; width: 32px; height: 32px; font-weight: 600; color: #64748b; cursor: pointer; }
.quantity-control button:hover:not(:disabled) { background: #e2e8f0; color: #1e293b; }
.quantity-control button:disabled { opacity: 0.5; cursor: not-allowed; }
.qty-input { width: 40px; height: 32px; border: none; text-align: center; font-size: 14px; font-weight: 600; outline: none; -moz-appearance: textfield; }
.qty-input::-webkit-outer-spin-button, .qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.cart-item-sum { font-size: 15px; font-weight: 700; color: #1e293b; }

.empty-cart-placeholder { text-align: center; color: #94a3b8; padding: 30px 0; border: 2px dashed #e2e8f0; border-radius: 12px; }
.empty-cart-placeholder i { font-size: 32px; margin-bottom: 8px; display: block; color: #cbd5e0; }
.empty-cart-placeholder p { margin: 0; font-size: 14px; }

/* --- ПІДСУМОК --- */
.summary-section { background: #fff; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0; }
.summary-row { display: flex; justify-content: space-between; align-items: center; font-size: 16px; font-weight: 700; color: #1e293b; }
.total-price { color: #a78bfb; font-size: 18px; }

/* FOOTER */
.offcanvas-footer {
  padding: 16px; border-top: 1px solid #edf2f7; background: #ffffff; flex-shrink: 0;
}

.btn-save-modern {
  background: #a78bfb; color: #ffffff; border: none; border-radius: 8px; height: 44px;
  display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 15px; font-weight: 600;
  width: 100%; cursor: pointer; transition: all 0.3s ease;
}
.btn-save-modern:hover:not(:disabled) { background: #9061f9; box-shadow: 0 4px 12px rgba(167, 139, 251, 0.3); }
.btn-save-modern:disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; box-shadow: none; }

/* ANIMATION */
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.slide-global-enter-active, .slide-global-leave-active { transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-global-enter-from, .slide-global-leave-to { transform: translateX(100%); }

/* SCROLLBAR */
.custom-scrollbar::-webkit-scrollbar { width: 5px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 20px; border: 2px solid transparent; background-clip: content-box; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background-color: #a0aec0; }

@media (max-width: 768px) {
  .order-offcanvas-global { width: 100%; }
  input, select, textarea { font-size: 16px; } /* iOS zoom fix */
}
</style>