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
              <span v-if="hasDraft" class="draft-pill">Чернетка</span>
            </div>
          </div>
          <div class="header-actions">
            <button class="btn-ghost" type="button" @click="handleMinimize">Згорнути</button>
            <button class="btn-close-panel" type="button" @click="handleClose">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>
        </div>

        <div class="offcanvas-body">
          <button class="selection-trigger-area" type="button" @click="productPickerOpen = true">
            <div>
              <div class="trigger-title">Додати товари</div>
              <div class="trigger-subtitle">Пошук за назвою або артикулом</div>
            </div>
            <i class="bi bi-plus-circle"></i>
          </button>

          <div class="order-block">
            <div class="block-title">Кошик</div>
            <div v-if="!orderDraft.items.length" class="empty-cart">
              Поки що немає товарів.
            </div>

            <div v-else class="cart-list">
              <div v-for="item in orderDraft.items" :key="item.key" class="cart-item">
                <div class="cart-thumb">
                  <i class="bi bi-image"></i>
                </div>
                <div class="cart-info">
                  <div class="cart-title">{{ item.title || 'Товар' }}</div>
                  <div class="cart-sku">{{ item.sku || 'NO-SKU' }}</div>
                </div>
                <div class="cart-meta">
                  <div class="qty-controls">
                    <button type="button" class="qty-btn" disabled>-</button>
                    <span class="qty-value">{{ item.qty || 1 }}</span>
                    <button type="button" class="qty-btn" disabled>+</button>
                  </div>
                  <div class="item-total">{{ formatMoney(itemTotal(item)) }} грн</div>
                </div>
              </div>
            </div>

            <div class="order-total">
              <span>Загальна сума</span>
              <strong>{{ formatMoney(totalAmount) }} грн</strong>
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
      <div v-show="productPickerOpen" class="picker-overlay">
        <div class="picker-panel">
          <div class="picker-header">
            <div>
              <div class="picker-title">Вибір товарів</div>
              <div class="picker-subtitle">Список товарів у вигляді списку</div>
            </div>
            <button class="btn-close-panel" type="button" @click="productPickerOpen = false">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <div class="picker-body">
            <div v-if="!productOptions.length" class="empty-cart">
              Список товарів буде тут.
            </div>
            <div v-else class="picker-list">
              <label v-for="product in productOptions" :key="product.id" class="picker-item">
                <input type="checkbox" v-model="selectedProductIds" :value="product.id" />
                <div class="picker-thumb">
                  <i class="bi bi-image"></i>
                </div>
                <div class="picker-info">
                  <div class="picker-name">{{ product.title }}</div>
                  <div class="picker-sku">{{ product.sku }}</div>
                  <div class="picker-stock" :class="{ low: product.stock < 3 }">
                    Залишок: {{ product.stock }}
                  </div>
                </div>
                <div class="picker-price">{{ formatMoney(product.price) }} грн</div>
              </label>
            </div>
          </div>

          <div class="picker-footer">
            <button
              class="btn-save-modern"
              type="button"
              :disabled="selectedProductIds.length === 0"
              @click="handleAddProducts"
            >
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
const productOptions = ref([]);

const hasDraft = computed(() => props.orderDraft.items?.length > 0);
const totalAmount = computed(() =>
  (props.orderDraft.items || []).reduce((sum, item) => sum + itemTotal(item), 0)
);

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

const handleAddProducts = () => {
  productPickerOpen.value = false;
};

const formatMoney = (value) => {
  const number = Number(value) || 0;
  return number.toFixed(2);
};

const itemTotal = (item) => {
  return (Number(item.qty) || 0) * (Number(item.price) || 0);
};
</script>

<style scoped>
.offcanvas-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.4);
  z-index: 1000;
  backdrop-filter: blur(2px);
}

.order-offcanvas-global {
  position: fixed;
  top: 0;
  right: 0;
  width: 450px;
  max-width: 100%;
  height: 100vh;
  background: #ffffff;
  z-index: 1001;
  display: flex;
  flex-direction: column;
  box-shadow: -10px 0 30px rgba(0, 0, 0, 0.1);
}

.offcanvas-header {
  padding: 16px;
  border-bottom: 1px solid #edf2f7;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 10px;
}

.header-icon { color: #a78bfb; font-size: 18px; }

.header-meta { display: flex; align-items: center; gap: 8px; }

.offcanvas-header h3 {
  font-size: 16px;
  font-weight: 700;
  color: #1f2937;
  margin: 0;
}

.draft-pill {
  background: #fff7ed;
  color: #c2410c;
  border-radius: 999px;
  padding: 2px 8px;
  font-size: 11px;
  font-weight: 700;
}

.header-actions { display: flex; align-items: center; gap: 10px; }

.btn-ghost {
  border: 1px solid #e2e8f0;
  background: #ffffff;
  color: #475569;
  border-radius: 999px;
  padding: 6px 12px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}

.btn-close-panel {
  background: #f1f5f9;
  border: none;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  color: #64748b;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: 0.2s;
}

.btn-close-panel:hover { background: #fee2e2; color: #ef4444; }

.offcanvas-body {
  flex: 1;
  padding: 20px;
  overflow-y: auto;
  background: #f8fafc;
}

.offcanvas-footer {
  padding: 16px;
  border-top: 1px solid #edf2f7;
  background: #ffffff;
}

.selection-trigger-area {
  width: 100%;
  border: 2px dashed #e2e8f0;
  border-radius: 20px;
  padding: 20px;
  text-align: left;
  cursor: pointer;
  transition: 0.2s;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  background: #ffffff;
  margin-bottom: 16px;
}

.selection-trigger-area:hover { border-color: #a78bfb; background: #f5f3ff; }
.selection-trigger-area:active { transform: scale(0.98); }

.trigger-title { font-size: 14px; font-weight: 700; color: #1f2937; }
.trigger-subtitle { font-size: 12px; color: #94a3b8; }

.order-block {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.block-title { font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: #94a3b8; font-weight: 700; }
.empty-cart { color: #94a3b8; font-size: 13px; }
.cart-list { display: flex; flex-direction: column; gap: 12px; }
.cart-item { display: grid; grid-template-columns: 48px 1fr auto; gap: 12px; align-items: center; }

.cart-thumb {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #94a3b8;
}

.cart-info { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.cart-title { font-size: 14px; font-weight: 600; color: #1f2937; }
.cart-sku { font-size: 12px; color: #94a3b8; }

.cart-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }

.qty-controls { display: inline-flex; align-items: center; gap: 6px; }

.qty-btn {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  background: #ffffff;
  color: #64748b;
  font-size: 16px;
}

.qty-value { min-width: 24px; text-align: center; font-weight: 700; color: #1f2937; }
.item-total { font-weight: 700; color: #1f2937; }

.order-total {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-top: 1px solid #e2e8f0;
  padding-top: 10px;
  font-size: 14px;
}

.btn-save-modern {
  background: #a78bfb;
  color: #ffffff;
  border: none;
  border-radius: 8px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 600;
  width: 100%;
  cursor: pointer;
  transition: all 0.3s ease;
}

.btn-save-modern:disabled {
  background: #e2e8f0;
  color: #94a3b8;
  cursor: not-allowed;
  box-shadow: none;
}

.picker-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.6);
  z-index: 2000;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  backdrop-filter: blur(2px);
}

.picker-panel {
  background: #ffffff;
  width: 500px;
  max-width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
}

.picker-header {
  padding: 16px;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.picker-title { font-size: 16px; font-weight: 700; color: #1f2937; }
.picker-subtitle { font-size: 12px; color: #94a3b8; }

.picker-body { flex: 1; padding: 16px; overflow-y: auto; background: #f8fafc; }
.picker-list { display: flex; flex-direction: column; gap: 12px; }

.picker-item {
  display: grid;
  grid-template-columns: 24px 48px 1fr auto;
  gap: 12px;
  align-items: center;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 10px 12px;
  cursor: pointer;
}

.picker-thumb {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #94a3b8;
}

.picker-info { display: flex; flex-direction: column; gap: 4px; }
.picker-name { font-size: 14px; font-weight: 600; color: #1f2937; }
.picker-sku { font-size: 12px; color: #94a3b8; }
.picker-stock { font-size: 12px; color: #64748b; }
.picker-stock.low { color: #dc2626; font-weight: 600; }
.picker-price { font-weight: 700; color: #0f172a; }

.picker-footer { padding: 16px; border-top: 1px solid #e2e8f0; }

.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.slide-global-enter-active, .slide-global-leave-active {
  transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.slide-global-enter-from, .slide-global-leave-to { transform: translateX(100%); }

@media (max-width: 768px) {
  .order-offcanvas-global { width: 100%; }
  .picker-panel { width: 100%; }
  input,
  select,
  textarea {
    font-size: 16px;
  }

  .selection-trigger-area,
  .btn-save-modern,
  .qty-btn,
  .btn-ghost,
  .picker-item {
    min-height: 40px;
  }
}
</style>
