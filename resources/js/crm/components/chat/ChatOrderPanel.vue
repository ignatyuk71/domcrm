<template>
  <teleport to="body">
    <transition name="fade">
      <div v-show="isVisible" class="offcanvas-backdrop" @click="handleMinimize"></div>
    </transition>

    <transition name="slide-global">
      <div v-show="isVisible" class="order-offcanvas-global">
        <div class="offcanvas-header">
          <div class="header-left">
            <div class="draft-indicator" v-if="orderDraft.items.length > 0">
              <span class="dot"></span> Чернетка
            </div>
            <h3>Нове замовлення</h3>
          </div>
          <div class="header-actions">
            <button class="btn-minimize" @click="handleMinimize" title="Згорнути і подивитись чат">
              <i class="bi bi-fullscreen-exit"></i>
            </button>
            <button class="btn-close-panel" @click="confirmCancel">
              <i class="bi bi-trash3"></i>
            </button>
          </div>
        </div>

        <div class="offcanvas-body custom-scrollbar">
          <div class="cart-section">
            <h4 class="section-title">Товари</h4>

            <div v-if="orderDraft.items.length > 0" class="cart-items-list">
              <div v-for="(item, index) in orderDraft.items" :key="item.id" class="cart-item">
                <div class="cart-item-main">
                   <img :src="item.image" alt="product" class="cart-thumb">
                   <div class="cart-item-info">
                     <div class="cart-item-name">{{ item.name }}</div>
                     <div class="cart-item-price-single">{{ formatPrice(item.price) }}</div>
                   </div>
                   <button class="btn-remove-item" @click="removeItem(index)">
                     <i class="bi bi-x-lg"></i>
                   </button>
                </div>
                <div class="cart-item-controls">
                   <div class="quantity-control">
                     <button @click="decrementQty(item)" :disabled="item.quantity <= 1">-</button>
                     <input type="number" v-model.number="item.quantity" min="1" class="qty-input">
                     <button @click="incrementQty(item)">+</button>
                   </div>
                   <div class="cart-item-sum">{{ formatPrice(item.price * item.quantity) }}</div>
                </div>
              </div>
              <button class="btn-add-more-modern" @click="openProductModal">
                <i class="bi bi-plus-lg"></i> Додати ще один товар
              </button>
            </div>
            
            <div v-else class="selection-trigger-area" @click="openProductModal">
              <div class="trigger-content">
                <div class="icon-circle"><i class="bi bi-search"></i></div>
                <p>Виберіть товари для замовлення</p>
                <span>Дані зберігаються автоматично</span>
              </div>
            </div>
          </div>

          <div v-if="orderDraft.items.length > 0" class="summary-section">
            <div class="summary-row">
              <span>Всього до сплати:</span>
              <span class="total-price">{{ formatPrice(calculateTotal) }}</span>
            </div>
          </div>
        </div>

        <div class="offcanvas-footer">
          <button class="btn-save-modern" :disabled="orderDraft.items.length === 0" @click="handleSaved">
            Оформити замовлення
          </button>
        </div>
      </div>
    </transition>

    <transition name="modal-fade">
      <div v-if="isProductModalVisible" class="modal-overlay" @click.self="closeProductModal">
        <div class="product-selection-modal">
          <div class="modal-header">
            <h4>Каталог товарів</h4>
            <button class="btn-close-modal" @click="closeProductModal"><i class="bi bi-x-lg"></i></button>
          </div>
          <div class="modal-body custom-scrollbar">
            <div class="product-list-container">
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
                    <span>Арт: {{ product.sku }}</span>
                    <span class="stock-label">На складі: {{ product.stock }}</span>
                  </div>
                </div>
                <div class="item-price">{{ formatPrice(product.price) }}</div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn-confirm-selection" @click="confirmSelection">
              Додати вибрані ({{ tempSelected.length }})
            </button>
          </div>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';

const props = defineProps({
  isVisible: Boolean, // Керується ззовні через v-show
  customer: Object
});

const emit = defineEmits(['close', 'saved', 'minimize']);

const isProductModalVisible = ref(false);
const tempSelected = ref([]);

// Цей об'єкт замовлення не зникає, поки ви не видалите його або не збережете замовлення
const orderDraft = reactive({
  items: []
});

const availableProducts = [
  { id: 101, name: 'Домашні капці "Пухнастики" Рожеві', sku: 'SLP-PNK-38', price: 450, stock: 12, image: 'https://picsum.photos/id/102/80/80' },
  { id: 102, name: 'Капці з вушками Зайчик Сірі', sku: 'SLP-GRY-40', price: 520, stock: 2, image: 'https://picsum.photos/id/103/80/80' },
  { id: 103, name: 'Шкарпетки "Тепло"', sku: 'SCK-01', price: 199, stock: 45, image: 'https://picsum.photos/id/107/80/80' }
];

const handleMinimize = () => { emit('minimize'); };
const confirmCancel = () => { if (confirm('Видалити чернетку замовлення?')) { orderDraft.items = []; emit('close'); } };

const openProductModal = () => { tempSelected.value = [...orderDraft.items]; isProductModalVisible.value = true; };
const closeProductModal = () => { isProductModalVisible.value = false; };
const isSelected = (product) => tempSelected.value.some(p => p.id === product.id);

const toggleProductSelection = (product) => {
  const index = tempSelected.value.findIndex(p => p.id === product.id);
  if (index > -1) tempSelected.value.splice(index, 1);
  else tempSelected.value.push({ ...product, quantity: 1 });
};

const confirmSelection = () => { orderDraft.items = [...tempSelected.value]; closeProductModal(); };

const calculateTotal = computed(() => orderDraft.items.reduce((t, i) => t + (i.price * i.quantity), 0));
const formatPrice = (v) => new Intl.NumberFormat('uk-UA', { style: 'currency', currency: 'UAH' }).format(v);
const removeItem = (index) => { orderDraft.items.splice(index, 1); };
const incrementQty = (item) => { item.quantity++; };
const decrementQty = (item) => { if (item.quantity > 1) item.quantity--; };
const handleSaved = () => { emit('saved', orderDraft); orderDraft.items = []; };
</script>

<style scoped>
/* Стилі Offcanvas */
.offcanvas-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 1000; backdrop-filter: blur(4px); }
.order-offcanvas-global { position: fixed; top: 0; right: 0; width: 450px; max-width: 100%; height: 100vh; background: #fff; z-index: 1001; display: flex; flex-direction: column; box-shadow: -10px 0 30px rgba(0,0,0,0.1); }

/* Індикатор чернетки */
.draft-indicator { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; color: #10b981; text-transform: uppercase; background: #ecfdf5; padding: 2px 8px; border-radius: 20px; margin-bottom: 4px; }
.draft-indicator .dot { width: 6px; height: 6px; background: #10b981; border-radius: 50%; animation: pulse 1.5s infinite; }

@keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }

.header-actions { display: flex; gap: 8px; }
.btn-minimize { background: #f1f5f9; border: none; width: 32px; height: 32px; border-radius: 8px; color: #64748b; cursor: pointer; }
.btn-minimize:hover { background: #e2e8f0; color: #1e293b; }

/* Поле вибору */
.selection-trigger-area { background: #fff; border: 2px dashed #e2e8f0; border-radius: 20px; padding: 30px; text-align: center; cursor: pointer; transition: 0.2s; }
.selection-trigger-area:hover { border-color: #a78bfb; background: #f5f3ff; }
.selection-trigger-area:active { transform: scale(0.97); }

.btn-add-more-modern { background: #f5f3ff; border: 1.5px solid #ddd6fe; color: #7c3aed; width: 100%; padding: 12px; border-radius: 12px; font-weight: 700; cursor: pointer; margin-top: 15px; font-size: 13px; }

/* Список товарів у модалці */
.selection-list-item { display: flex; align-items: center; padding: 12px 20px; cursor: pointer; border-bottom: 1px solid #f8fafc; gap: 15px; }
.selection-list-item:hover { background: #f5f3ff; }
.selection-list-item.is-selected { background: #f5f3ff; }
.item-img { width: 44px; height: 44px; border-radius: 8px; object-fit: cover; }
.item-name { font-size: 14px; font-weight: 700; color: #1e293b; }
.item-meta { font-size: 12px; color: #94a3b8; display: flex; gap: 10px; }
.checkbox-ui { width: 20px; height: 20px; border: 2px solid #cbd5e1; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #fff; }
.checkbox-ui.checked { background: #a78bfb; border-color: #a78bfb; }

.modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 2000; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
.product-selection-modal { background: #fff; width: 500px; max-width: 95%; max-height: 80vh; border-radius: 24px; display: flex; flex-direction: column; overflow: hidden; }

/* Анімації */
.slide-global-enter-active, .slide-global-leave-active { transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-global-enter-from, .slide-global-leave-to { transform: translateX(100%); }

@media (max-width: 768px) {
  .order-offcanvas-global { width: 100%; }
  .product-selection-modal { width: 100%; height: 100%; max-height: 100%; border-radius: 0; }
}
</style>