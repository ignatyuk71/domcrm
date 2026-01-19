<template>
  <teleport to="body">
    <transition name="fade">
      <div v-show="open" class="offcanvas-backdrop-slim" @click="handleMinimize"></div>
    </transition>

    <transition name="slide-global">
      <div v-show="open" class="order-panel-micro">
        
        <header class="micro-header">
          <div class="header-info">
            <i class="bi bi-bag-check-fill header-icon"></i>
            <div class="title-group">
              <h3>Замовлення</h3>
              <span v-if="hasDraft" class="micro-draft-status">Чернетка</span>
            </div>
          </div>
          <div class="header-actions">
            <button class="btn-tiny" @click="handleMinimize"><i class="bi bi-fullscreen-exit"></i></button>
            <button class="btn-tiny delete" @click="handleClose"><i class="bi bi-trash3"></i></button>
          </div>
        </header>

        <div class="micro-body custom-scrollbar">
          
          <section class="micro-section">
            <div class="section-top">
              <span class="label">ТОВАРИ</span>
              <button class="btn-add-inline" @click="openPicker">+ Додати</button>
            </div>

            <div v-if="!hasDraft" class="empty-compact" @click="openPicker">
               <i class="bi bi-plus-circle"></i> Оберіть товари
            </div>

            <div v-else class="micro-cart-list">
              <div v-for="(item, index) in orderDraft.items" :key="item.id" class="micro-item">
                <img :src="item.image" class="micro-thumb" />
                <div class="micro-content">
                  <div class="micro-row-1">
                    <span class="micro-name">{{ item.title }}</span>
                    <button class="micro-del" @click="removeSingleItem(index)"><i class="bi bi-x"></i></button>
                  </div>
                  <div class="micro-row-2">
                    <div class="micro-stepper">
                      <button @click="item.qty > 1 ? item.qty-- : null">−</button>
                      <span class="qty">{{ item.qty }}</span>
                      <button @click="item.qty++">+</button>
                    </div>
                    <span class="micro-price">{{ formatMoney(item.price * item.qty) }} ₴</span>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section class="micro-section">
            <div class="section-top"><span class="label">ДОСТАВКА</span></div>
            <div class="micro-mock-field">
              <i class="bi bi-truck"></i>
              <span>Виберіть відділення...</span>
            </div>
          </section>

          <section class="micro-section">
            <div class="section-top"><span class="label">ОПЛАТА</span></div>
            <div class="micro-mock-field">
              <i class="bi bi-credit-card"></i>
              <span>Оберіть спосіб...</span>
            </div>
          </section>

        </div>

        <footer class="micro-footer">
          <div class="micro-summary">
            <span>Разом:</span>
            <strong>{{ formatMoney(totalAmount) }} ₴</strong>
          </div>
          <button class="btn-elite-save" :disabled="!hasDraft" @click="handleSaved">
            ЗБЕРЕГТИ ЗАМОВЛЕННЯ
          </button>
        </footer>

      </div>
    </transition>

    <transition name="fade">
      <div v-if="productPickerOpen" class="picker-overlay-slim" @click.self="productPickerOpen = false">
        <div class="picker-window-compact">
          <div class="picker-header">
            <h4>Каталог</h4>
            <button class="btn-close-modal" @click="productPickerOpen = false"><i class="bi bi-x-lg"></i></button>
          </div>
          <div class="picker-scroll custom-scrollbar">
            <div v-for="p in mockProducts" :key="p.id" 
                 class="p-row-compact" 
                 :class="{ selected: selectedProductIds.includes(p.id) }"
                 @click="toggleProductSelection(p)">
              <div class="p-checkbox" :class="{ checked: selectedProductIds.includes(p.id) }">
                <i class="bi bi-check-lg" v-if="selectedProductIds.includes(p.id)"></i>
              </div>
              <img :src="p.image" class="p-img-min" />
              <div class="p-text-min">
                <div class="p-name-min">{{ p.title }}</div>
                <div class="p-sku-min">Арт: {{ p.sku }} • Склад: {{ p.stock }}</div>
              </div>
              <div class="p-price-min">{{ formatMoney(p.price) }} ₴</div>
            </div>
          </div>
          <div class="picker-foot">
            <button class="btn-confirm-compact" :disabled="selectedProductIds.length === 0" @click="handleAddProducts">
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
  { id: 1, title: 'Капці "Пухнастики" Рожеві', sku: 'KAP-001', price: 450, stock: 12, image: 'https://picsum.photos/id/102/60/60' },
  { id: 2, title: 'Капці "Зайчик" Сірі', sku: 'KAP-002', price: 520, stock: 2, image: 'https://picsum.photos/id/103/60/60' },
  { id: 3, title: 'Шкарпетки "Тепло" (3 пари)', sku: 'SCK-001', price: 199, stock: 45, image: 'https://picsum.photos/id/107/60/60' },
  { id: 4, title: 'Капці чоловічі "Класик"', sku: 'KAP-003', price: 480, stock: 5, image: 'https://picsum.photos/id/119/60/60' },
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
/* COMPACT LAYOUT */
.offcanvas-backdrop-slim { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 99998; backdrop-filter: blur(4px); }
.order-panel-micro { position: fixed; top: 0; right: 0; width: 400px; max-width: 100%; height: 100vh; background: #fff; z-index: 99999; display: flex; flex-direction: column; box-shadow: -10px 0 30px rgba(0,0,0,0.1); }

/* SLIM HEADER */
.micro-header { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
.header-info { display: flex; align-items: center; gap: 8px; }
.header-icon { color: #a78bfb; font-size: 16px; }
.title-group h3 { font-size: 14px; font-weight: 800; color: #1e293b; margin: 0; }
.micro-draft-status { font-size: 9px; font-weight: 700; color: #10b981; text-transform: uppercase; display: flex; align-items: center; gap: 4px; }
.btn-tiny { border: none; background: #f8fafc; width: 26px; height: 26px; border-radius: 6px; color: #94a3b8; cursor: pointer; transition: 0.2s; }
.btn-tiny.delete:hover { background: #fef2f2; color: #f43f5e; }

/* COMPACT BODY */
.micro-body { flex: 1; padding: 12px; overflow-y: auto; background: #fcfcfd; }
.micro-section { margin-bottom: 16px; }
.section-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.label { font-size: 10px; font-weight: 800; color: #94a3b8; letter-spacing: 0.05em; }
.btn-add-inline { background: none; border: none; color: #a78bfb; font-weight: 700; font-size: 11px; cursor: pointer; }

/* CART ITEM COMPACT */
.micro-cart-list { background: #fff; border: 1px solid #f1f5f9; border-radius: 12px; overflow: hidden; }
.micro-item { display: flex; gap: 10px; padding: 8px 10px; border-bottom: 1px solid #f8fafc; }
.micro-thumb { width: 36px; height: 36px; border-radius: 6px; object-fit: cover; background: #f8fafc; }
.micro-content { flex: 1; min-width: 0; }
.micro-row-1 { display: flex; justify-content: space-between; align-items: flex-start; }
.micro-name { font-size: 12px; font-weight: 600; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.micro-del { background: none; border: none; color: #cbd5e0; cursor: pointer; font-size: 14px; padding: 0; }
.micro-row-2 { display: flex; justify-content: space-between; align-items: center; margin-top: 2px; }
.micro-stepper { display: flex; align-items: center; border: 1px solid #f1f5f9; border-radius: 4px; height: 20px; }
.micro-stepper button { width: 20px; border: none; background: transparent; font-weight: bold; font-size: 12px; color: #94a3b8; cursor: pointer; }
.qty { width: 18px; text-align: center; font-size: 11px; font-weight: 700; }
.micro-price { font-size: 12px; font-weight: 700; color: #1e293b; }

/* EMPTY STATE */
.empty-compact { border: 1.5px dashed #e2e8f0; border-radius: 12px; padding: 20px; text-align: center; font-size: 12px; color: #94a3b8; cursor: pointer; }

/* MOCK FIELDS */
.micro-mock-field { background: #fff; border: 1px solid #f1f5f9; padding: 10px; border-radius: 10px; display: flex; align-items: center; gap: 8px; color: #cbd5e0; font-size: 12px; font-weight: 600; }

/* COMPACT FOOTER */
.micro-footer { padding: 12px 16px; border-top: 1px solid #f1f5f9; background: #fff; }
.micro-summary { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.micro-summary span { font-size: 11px; font-weight: 700; color: #64748b; }
.micro-summary strong { font-size: 16px; font-weight: 800; color: #a78bfb; }
.btn-elite-save { width: 100%; height: 42px; background: #a78bfb; color: #fff; border: none; border-radius: 10px; font-weight: 800; font-size: 12px; cursor: pointer; transition: 0.3s; }
.btn-elite-save:hover:not(:disabled) { background: #9061f9; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(167, 139, 251, 0.3); }

/* PICKER COMPACT */
.picker-overlay-slim { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 100000; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
.picker-window-compact { background: #fff; width: 480px; max-width: 95%; max-height: 80vh; border-radius: 20px; display: flex; flex-direction: column; overflow: hidden; }
.p-row-compact { display: flex; align-items: center; gap: 12px; padding: 10px 16px; cursor: pointer; border-bottom: 1px solid #f8fafc; }
.p-checkbox { width: 18px; height: 18px; border: 2px solid #cbd5e1; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 10px; }
.p-checkbox.checked { background: #a78bfb; border-color: #a78bfb; }
.p-img-min { width: 36px; height: 36px; border-radius: 4px; object-fit: cover; }
.p-text-min { flex: 1; }
.p-name-min { font-size: 12px; font-weight: 700; color: #1e293b; }
.p-sku-min { font-size: 10px; color: #94a3b8; }
.p-price-min { font-size: 13px; font-weight: 800; color: #1e293b; }

@media (max-width: 768px) {
  .order-panel-micro { width: 100%; border-radius: 0; }
}
</style>