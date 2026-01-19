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
          <button 
            class="selection-trigger-area" 
            type="button" 
            @click="openPicker"
          >
            <div class="trigger-info">
              <div class="trigger-title">Додати товари до замовлення</div>
              <div class="trigger-subtitle">Відкрити каталог доступних моделей</div>
            </div>
            <div class="trigger-icon-box">
              <i class="bi bi-plus-lg"></i>
            </div>
          </button>

          <div class="order-block">
            <div class="block-header">
              <div class="block-title">Кошик замовлення</div>
              <span class="items-count" v-if="hasDraft">{{ orderDraft.items.length }} поз.</span>
            </div>

            <div v-if="!orderDraft.items.length" class="empty-cart">
              <i class="bi bi-basket3"></i>
              <p>Натисніть на поле вище, щоб обрати товари</p>
            </div>

            <div v-else class="cart-list">
              <div v-for="(item, index) in orderDraft.items" :key="item.id" class="cart-item">
                <img :src="item.image" class="cart-thumb" alt="product" />
                <div class="cart-info">
                  <div class="cart-title">{{ item.title }}</div>
                  <div class="cart-sku">Арт: {{ item.sku }}</div>
                </div>
                <div class="cart-meta">
                  <button class="btn-remove-mini" @click="removeSingleItem(index)">
                    <i class="bi bi-x"></i>
                  </button>
                  <div class="qty-controls">
                    <button type="button" class="qty-btn" @click="item.qty > 1 ? item.qty-- : null">-</button>
                    <span class="qty-value">{{ item.qty }}</span>
                    <button type="button" class="qty-btn" @click="item.qty++">+</button>
                  </div>
                  <div class="item-total">{{ formatMoney(item.price * item.qty) }} грн</div>
                </div>
              </div>
              
              <button class="btn-add-more" type="button" @click="openPicker">
                <i class="bi bi-plus"></i> Додати ще товар
              </button>
            </div>

            <div class="order-total" v-if="hasDraft">
              <span>Разом до сплати</span>
              <strong class="total-sum">{{ formatMoney(totalAmount) }} грн</strong>
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
        <transition name="slide-up">
          <div class="picker-panel">
            <div class="picker-header">
              <div class="picker-header-info">
                <div class="picker-title">Каталог товарів</div>
                <div class="picker-subtitle">Оберіть одну або кілька моделей</div>
              </div>
              <button class="btn-close-modal" type="button" @click="productPickerOpen = false">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>

            <div class="picker-body custom-scrollbar">
              <div class="picker-list">
                <label 
                  v-for="product in mockProducts" 
                  :key="product.id" 
                  class="picker-item"
                  :class="{ active: selectedProductIds.includes(product.id) }"
                >
                  <div class="picker-checkbox">
                    <input type="checkbox" v-model="selectedProductIds" :value="product.id" />
                    <div class="checkbox-custom">
                      <i class="bi bi-check-lg"></i>
                    </div>
                  </div>
                  <img :src="product.image" class="picker-thumb-img" alt="p" />
                  <div class="picker-info">
                    <div class="picker-name">{{ product.title }}</div>
                    <div class="picker-sku">Арт: {{ product.sku }}</div>
                    <div class="picker-stock" :class="{ low: product.stock < 3 }">
                      На складі: {{ product.stock }} шт.
                    </div>
                  </div>
                  <div class="picker-price-box">
                    <div class="picker-price">{{ formatMoney(product.price) }} ₴</div>
                  </div>
                </label>
              </div>
            </div>

            <div class="picker-footer">
              <button class="btn-cancel" @click="productPickerOpen = false">Скасувати</button>
              <button
                class="btn-save-modern btn-add-confirm"
                type="button"
                :disabled="selectedProductIds.length === 0"
                @click="handleAddProducts"
              >
                Додати до замовлення ({{ selectedProductIds.length }})
              </button>
            </div>
          </div>
        </transition>
      </div>
    </transition>
  </teleport>
</template>

<script setup>
import { computed, ref, reactive } from 'vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  customer: { type: Object, default: null },
  orderDraft: { type: Object, required: true },
});

const emit = defineEmits(['close', 'saved', 'minimize']);

// --- ТЕСТОВІ ДАНІ ТОВАРІВ ---
const mockProducts = [
  { id: 1, title: 'Домашні капці "Пухнастики" Рожеві', sku: 'KAP-001', price: 450, stock: 12, image: 'https://picsum.photos/id/102/100/100' },
  { id: 2, title: 'Капці з вушками "Зайчик" Сірі', sku: 'KAP-002', price: 520, stock: 2, image: 'https://picsum.photos/id/103/100/100' },
  { id: 3, title: 'Шкарпетки "Тепло" (3 пари)', sku: 'SCK-001', price: 199, stock: 45, image: 'https://picsum.photos/id/107/100/100' },
  { id: 4, title: 'Капці чоловічі "Класик" Сині', sku: 'KAP-003', price: 480, stock: 5, image: 'https://picsum.photos/id/119/100/100' },
];

const productPickerOpen = ref(false);
const selectedProductIds = ref([]);

const hasDraft = computed(() => props.orderDraft.items?.length > 0);
const totalAmount = computed(() =>
  (props.orderDraft.items || []).reduce((sum, item) => sum + (item.price * item.qty), 0)
);

const openPicker = () => {
  // Синхронізуємо вибрані ID з тим, що вже є в кошику
  selectedProductIds.value = props.orderDraft.items.map(item => item.id);
  productPickerOpen.value = true;
};

const handleAddProducts = () => {
  // Створюємо нові елементи кошика на основі вибору
  const newItems = mockProducts
    .filter(p => selectedProductIds.value.includes(p.id))
    .map(p => {
      // Якщо товар вже був у кошику, зберігаємо його кількість
      const existing = props.orderDraft.items.find(item => item.id === p.id);
      return {
        id: p.id,
        title: p.title,
        sku: p.sku,
        price: p.price,
        image: p.image,
        qty: existing ? existing.qty : 1,
        key: Date.now() + p.id // унікальний ключ для списку
      };
    });
  
  // Оновлюємо кошик у батька
  props.orderDraft.items = newItems;
  productPickerOpen.value = false;
};

const removeSingleItem = (index) => {
  props.orderDraft.items.splice(index, 1);
};

const handleClose = () => {
  productPickerOpen.value = false;
  emit('close');
};

const handleMinimize = () => {
  productPickerOpen.value = false;
  emit('minimize');
};

const handleSaved = () => {
  emit('saved');
};

const formatMoney = (value) => Number(value || 0).toFixed(2);
</script>

<style scoped>
/* --- OFFCANVAS BASE --- */
.offcanvas-backdrop { position: fixed; top: var(--crm-header-height, 100px); left: 0; right: 0; height: calc(100vh - var(--crm-header-height, 100px)); background: rgba(15, 23, 42, 0.4); z-index: 1000; backdrop-filter: blur(2px); }
.order-offcanvas-global { position: fixed; top: var(--crm-header-height, 100px); right: 0; width: 450px; max-width: 100%; height: calc(100vh - var(--crm-header-height, 100px)); background: #ffffff; z-index: 1001; display: flex; flex-direction: column; box-shadow: -10px 0 30px rgba(0, 0, 0, 0.1); }

.offcanvas-header { padding: 16px 20px; border-bottom: 1px solid #edf2f7; display: flex; align-items: center; justify-content: space-between; background: #ffffff; }
.header-left { display: flex; align-items: center; gap: 12px; }
.header-icon { color: #a78bfb; font-size: 20px; }
.header-meta h3 { font-size: 16px; font-weight: 700; color: #1f2937; margin: 0; }

.draft-pill { display: flex; align-items: center; gap: 6px; background: #f0fdf4; color: #16a34a; border-radius: 999px; padding: 2px 10px; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-top: 2px; }
.pulse-dot { width: 6px; height: 6px; background: #16a34a; border-radius: 50%; animation: pulse 2s infinite; }
@keyframes pulse { 0% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(1.2); } 100% { opacity: 1; transform: scale(1); } }

.header-actions { display: flex; align-items: center; gap: 8px; }
.btn-minimize { background: #f1f5f9; border: none; width: 34px; height: 34px; border-radius: 10px; color: #64748b; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.btn-close-panel { background: #fff1f2; border: none; width: 34px; height: 34px; border-radius: 10px; color: #f43f5e; cursor: pointer; display: flex; align-items: center; justify-content: center; }

/* --- TRIGGER AREA --- */
.selection-trigger-area {
  width: 100%; border: 2px dashed #e2e8f0; border-radius: 16px; padding: 24px; text-align: left; cursor: pointer; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  display: flex; align-items: center; justify-content: space-between; background: #ffffff; margin-bottom: 20px; outline: none;
}
.selection-trigger-area:hover { border-color: #a78bfb; background: #fcfaff; transform: translateY(-2px); }
.selection-trigger-area:active { transform: scale(0.97); background: #f5f3ff; }

.trigger-title { font-size: 15px; font-weight: 700; color: #1f2937; margin-bottom: 4px; }
.trigger-subtitle { font-size: 12px; color: #94a3b8; }
.trigger-icon-box { width: 40px; height: 40px; background: #f5f3ff; color: #a78bfb; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; }

/* --- CART LIST --- */
.order-block { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; display: flex; flex-direction: column; gap: 16px; }
.block-header { display: flex; justify-content: space-between; align-items: center; }
.block-title { font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; font-weight: 700; }
.items-count { font-size: 12px; color: #a78bfb; font-weight: 600; }

.empty-cart { color: #94a3b8; font-size: 14px; text-align: center; padding: 40px 0; }
.empty-cart i { font-size: 32px; display: block; margin-bottom: 12px; opacity: 0.4; }

.cart-list { display: flex; flex-direction: column; gap: 14px; }
.cart-item { display: grid; grid-template-columns: 50px 1fr auto; gap: 14px; align-items: center; position: relative; padding-bottom: 14px; border-bottom: 1px solid #f1f5f9; }
.cart-item:last-child { border-bottom: none; padding-bottom: 0; }

.cart-thumb { width: 50px; height: 50px; border-radius: 10px; object-fit: cover; background: #f8fafc; }
.cart-title { font-size: 14px; font-weight: 600; color: #1f2937; line-height: 1.3; }
.cart-sku { font-size: 11px; color: #94a3b8; margin-top: 2px; }

.cart-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
.btn-remove-mini { border: none; background: none; color: #cbd5e0; cursor: pointer; padding: 2px; transition: 0.2s; }
.btn-remove-mini:hover { color: #f43f5e; }

.qty-controls { display: flex; align-items: center; background: #f8fafc; border-radius: 8px; padding: 2px; border: 1px solid #e2e8f0; }
.qty-btn { width: 28px; height: 28px; border-radius: 6px; border: none; background: #ffffff; color: #64748b; font-weight: 700; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
.qty-value { min-width: 30px; text-align: center; font-size: 13px; font-weight: 700; color: #1e293b; }
.item-total { font-size: 14px; font-weight: 700; color: #1f2937; }

.btn-add-more { background: transparent; border: 1.5px solid #a78bfb; color: #a78bfb; border-radius: 10px; padding: 8px; font-size: 13px; font-weight: 700; cursor: pointer; transition: 0.2s; }
.btn-add-more:hover { background: #f5f3ff; }

.order-total { display: flex; align-items: center; justify-content: space-between; border-top: 1px solid #e2e8f0; padding-top: 14px; margin-top: 4px; font-size: 14px; }
.total-sum { font-size: 18px; color: #a78bfb; }

/* --- PRODUCT PICKER (LIST VIEW) --- */
.picker-overlay { position: fixed; top: var(--crm-header-height, 100px); left: 0; right: 0; height: calc(100vh - var(--crm-header-height, 100px)); background: rgba(15, 23, 42, 0.6); z-index: 2000; display: flex; align-items: flex-end; justify-content: center; backdrop-filter: blur(4px); }
.picker-panel { background: #ffffff; width: 550px; max-width: 100%; height: 85vh; border-radius: 24px 24px 0 0; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 -10px 40px rgba(0,0,0,0.2); }

.picker-header { padding: 20px 24px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; }
.picker-title { font-size: 18px; font-weight: 800; color: #1f2937; }
.picker-subtitle { font-size: 13px; color: #94a3b8; }
.btn-close-modal { background: #f1f5f9; border: none; width: 36px; height: 36px; border-radius: 50%; color: #64748b; cursor: pointer; }

.picker-body { flex: 1; padding: 12px 0; overflow-y: auto; background: #ffffff; }
.picker-list { display: flex; flex-direction: column; }

.picker-item {
  display: grid; grid-template-columns: 40px 60px 1fr auto; gap: 16px; align-items: center; padding: 12px 24px; cursor: pointer; transition: 0.2s; border-bottom: 1px solid #f8fafc;
}
.picker-item:hover { background: #fcfaff; }
.picker-item.active { background: #f5f3ff; }

.picker-checkbox { display: flex; align-items: center; justify-content: center; position: relative; }
.picker-checkbox input { position: absolute; opacity: 0; cursor: pointer; }
.checkbox-custom { width: 22px; height: 22px; border: 2px solid #cbd5e1; border-radius: 7px; display: flex; align-items: center; justify-content: center; color: transparent; transition: 0.2s; }
.picker-item.active .checkbox-custom { background: #a78bfb; border-color: #a78bfb; color: white; }

.picker-thumb-img { width: 60px; height: 60px; border-radius: 12px; object-fit: cover; background: #f1f5f9; }
.picker-info { display: flex; flex-direction: column; gap: 4px; }
.picker-name { font-size: 14px; font-weight: 700; color: #1f2937; }
.picker-sku { font-size: 12px; color: #94a3b8; }
.picker-stock { font-size: 12px; color: #10b981; font-weight: 600; }
.picker-stock.low { color: #f43f5e; }
.picker-price { font-size: 15px; font-weight: 800; color: #1e293b; }

.picker-footer { padding: 16px 24px; border-top: 1px solid #e2e8f0; display: flex; gap: 12px; }
.btn-cancel { flex: 1; height: 44px; border-radius: 12px; border: 1px solid #e2e8f0; background: #fff; font-weight: 600; color: #64748b; cursor: pointer; }
.btn-add-confirm { flex: 2; height: 44px; }

/* Transitions */
.slide-up-enter-active, .slide-up-leave-active { transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-up-enter-from, .slide-up-leave-to { transform: translateY(100%); }

.custom-scrollbar::-webkit-scrollbar { width: 5px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

@media (max-width: 768px) {
  .order-offcanvas-global { width: 100%; }
  .picker-panel { width: 100%; height: 100vh; border-radius: 0; }
  .picker-item { padding: 14px 16px; gap: 12px; }
}
</style>
